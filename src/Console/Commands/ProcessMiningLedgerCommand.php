<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\CorporationObserverMining;
use MiningManager\Services\Pricing\PriceProviderService;
use MiningManager\Services\Pricing\OreValuationService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Services\Tax\ClassificationEpoch;
use MiningManager\Services\Tax\InvoiceCoverage;
use MiningManager\Http\Controllers\DashboardController;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MiningManager\Services\OreClassifier;

class ProcessMiningLedgerCommand extends Command
{
    protected $signature = 'mining-manager:process-ledger
                            {--observer_id= : Process specific observer/structure}
                            {--character_id= : Process specific character ID}
                            {--days=30 : Number of days to process}
                            {--recalculate : Recalculate prices and taxes for existing entries}';

    protected $description = 'Process corporation observer mining data for COMPLETE moon mining tracking';

    public function handle()
    {
        $lock = Cache::lock('mining-manager:process-ledger', 900);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return self::SUCCESS;
        }

        try {
        // Check feature flag
        $settingsService = app(SettingsManagerService::class);
        $features = $settingsService->getFeatureFlags();
        if (!($features['auto_process_ledger'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║   Mining Manager - Corporation Observer Processing         ║');
        $this->info('║   Tracking ALL miners at your structures - not just SeAT!  ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->line('');

        $observerId = $this->option('observer_id');
        $characterId = $this->option('character_id');
        $days = $this->option('days');
        $recalculate = $this->option('recalculate');
        $cutoffDate = Carbon::now()->subDays($days);

        // Use corporation settings for tax rates (per structure-owner corporation)
        $settingsService = app(SettingsManagerService::class);
        $this->info("💰 Tax rates: per structure-owner corporation from settings (multi-corp support)");

        // Build query for observer data (eager-load observer for corporation_id resolution)
        $query = CorporationObserverMining::with(['character', 'type', 'structure', 'observer'])
            ->where('last_updated', '>=', $cutoffDate);

        if ($observerId) {
            $query->where('observer_id', $observerId);
            $this->info("🔍 Processing observer ID: {$observerId}");
        } else {
            $this->info("🔍 Processing ALL corporation observers");
        }

        if ($characterId) {
            $query->where('character_id', $characterId);
            $this->info("👤 Filtering for character ID: {$characterId}");
        }

        $totalEntries = $query->count();

        if ($totalEntries === 0) {
            $this->warn('⚠️  No observer mining data found for the specified criteria.');
            $this->line('');
            $this->info('Make sure:');
            $this->info('  • Your corporation has structures with mining observers');
            $this->info('  • SeAT has fetched recent observer data');
            $this->info('  • Mining activity has occurred at your structures');
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$totalEntries} observer mining entries");
        $this->line('');

        // Check price cache freshness before processing
        try {
            $priceService = app(PriceProviderService::class);
            // Spot-check a common ore type (Veldspar = 1230) for cache freshness
            $regionId = (int) $settingsService->getSetting('general.default_region_id', 10000002);
            if (!$priceService->isCacheFresh(1230, $regionId)) {
                $this->warn('⚠️  Price cache is STALE — prices may be outdated. Run mining-manager:cache-prices first.');
                Log::warning('Mining Manager: ProcessMiningLedgerCommand running with stale price cache');
            } else {
                $this->info('✅ Price cache is fresh');
            }
        } catch (\Exception $e) {
            $this->warn('⚠️  Could not verify price cache freshness: ' . $e->getMessage());
        }
        $this->line('');

        $processed = 0;
        $updated = 0;
        $billedSkips = 0;
        $lateArrivals = 0;
        $skipped = 0;
        $errors = 0;

        // Track unique observers, miners, quantities for summary
        $uniqueObserverIds = collect();
        $uniqueCharacterIds = collect();
        $totalQuantity = 0;
        $touchedPairsMap = collect();
        $jackpotCheckEntries = collect();

        // Get distinct observer count for display
        $observerCount = (clone $query)->distinct('observer_id')->count('observer_id');
        $this->info("🏗️  Processing {$observerCount} structures");
        $this->line('');

        $progressBar = $this->output->createProgressBar($totalEntries);
        $progressBar->start();

        // No outer DB::beginTransaction.
        //
        // Do NOT wrap the chunk loop in a single transaction. For active corps
        // that's tens of thousands of observer records — it would hold
        // row-level locks on `mining_ledger` for minutes. With Cache::lock=900s on this command and ESI
        // refreshes happening on their own schedules, any concurrent writer
        // (the import-character-mining cron at :20/:50 vs this one at :15/:45,
        // or the dashboard's auto-refresh queries) could hit MySQL's
        // innodb_lock_wait_timeout (default 50s) and fail with confusing
        // deadlock errors.
        //
        // The inner per-entry work is naturally idempotent:
        //   - `MiningLedger::updateOrCreate` is the canonical Eloquent
        //     idempotent insert/update.
        //   - `$existing->update` only fires when the observer quantity
        //     grows (cumulative semantics) or `--recalculate` is set.
        //   - The personal-ESI dedup (`$personalDupe->update/delete`) is
        //     idempotent on its own.
        //
        // A partial failure mid-chunk leaves the DB consistent: the rows
        // that did update have valid data, the rows that didn't get picked
        // up by the next cron run (every 30min). The inner try/catch on
        // line ~286 already handles per-entry failures by incrementing
        // $errors and continuing.
        //
        // The fatal-error catch block at the bottom is preserved (without
        // the now-meaningless DB::rollBack) so a crashing chunk callback
        // still cleanly releases the Cache::lock and reports the error.
        //
        // Capture the singleton's active corp BEFORE the loop so we can
        // restore it in `finally` regardless of how this method exits.
        // Without this, the loop's setActiveCorporation calls leak context to
        // the next caller in the same PHP process — invisible on web
        // requests (each request gets a fresh container) but real on
        // queue workers (Laravel's persistent worker reuses the process
        // across many jobs sequentially).
        $previousActiveCorp = $settingsService->getActiveCorporation();
        try {
            // Process entries in chunks, grouped by observer within each chunk
            $query->orderBy('observer_id')->chunk(500, function ($chunk) use (
                $settingsService, $recalculate, $cutoffDate,
                &$processed, &$updated, &$skipped, &$errors,
                &$uniqueObserverIds, &$uniqueCharacterIds, &$totalQuantity,
                &$touchedPairsMap, &$jackpotCheckEntries, &$billedSkips, &$lateArrivals,
                $progressBar
            ) {
            $byObserver = $chunk->groupBy('observer_id');

            foreach ($byObserver as $obsId => $entries) {
                // Resolve structure owner corporation from observer relationship
                $structureCorpId = $entries->first()->corporation_id; // via getCorporationIdAttribute accessor

                // Switch settings context to this structure owner's corporation
                if ($structureCorpId) {
                    $settingsService->setActiveCorporation((int) $structureCorpId);
                } else {
                    $settingsService->setActiveCorporation(null);
                }
                $taxSelector = $settingsService->getTaxSelector();

                foreach ($entries as $entry) {
                // Track aggregates for summary
                $uniqueObserverIds->push($entry->observer_id);
                $uniqueCharacterIds->push($entry->character_id);
                $totalQuantity += $entry->quantity;

                // Track for daily summary generation
                $pairKey = $entry->character_id . '|' . Carbon::parse($entry->last_updated)->toDateString();
                if (!$touchedPairsMap->has($pairKey)) {
                    $touchedPairsMap->put($pairKey, [
                        'character_id' => $entry->character_id,
                        'date' => Carbon::parse($entry->last_updated)->toDateString(),
                    ]);
                }

                // Collect jackpot-relevant entries (moon ores only, lightweight)
                if (\MiningManager\Services\TypeIdRegistry::isMoonOre($entry->type_id)) {
                    $jackpotCheckEntries->push($entry);
                }
                try {
                    // Get structure's solar system
                    $solarSystemId = $entry->structure->solar_system_id ?? null;

                    // Calculate ore values using OreValuationService (daily session pricing)
                    $valuationService = app(OreValuationService::class);
                    $values = $valuationService->calculateOreValue($entry->type_id, $entry->quantity);

                    $unitPrice = $values['unit_price'] ?? 0;
                    $oreValue = $values['ore_value'] ?? 0;
                    $mineralValue = $values['mineral_value'] ?? 0;
                    $totalValue = $values['total_value'] ?? 0;

                    // Classify ore type (must be before tax rate lookup)
                    $isMoonOre = TypeIdRegistry::isMoonOre($entry->type_id);
                    $isIce = TypeIdRegistry::isIce($entry->type_id);
                    $isGas = TypeIdRegistry::isGas($entry->type_id);
                    $isAbyssal = OreClassifier::isAbyssal($entry->type_id);
                    $isTriglavian = TypeIdRegistry::isTriglavianOre($entry->type_id);
                    $oreCategory = $this->classifyOreCategory($entry->type_id);

                    // Get tax rate from this structure owner's corporation settings
                    $taxRate = $this->getTaxRateFromSettings($settingsService, $entry->type_id, $isMoonOre, $isIce, $isGas, $isAbyssal, $isTriglavian, $taxSelector, $structureCorpId);
                    $taxAmount = $totalValue * ($taxRate / 100);

                    // Prepare data
                    $data = [
                        'quantity' => $entry->quantity,
                        'solar_system_id' => $solarSystemId,
                        'unit_price' => $unitPrice,
                        'ore_value' => $oreValue,
                        'mineral_value' => $mineralValue,
                        'total_value' => $totalValue,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $taxAmount,
                        'ore_type' => $oreCategory,
                        'corporation_id' => $structureCorpId,
                        'is_taxable' => true,
                        'is_moon_ore' => $isMoonOre,
                        'is_ice' => $isIce,
                        'is_gas' => $isGas,
                        'is_abyssal' => $isAbyssal,
                        'is_triglavian' => $isTriglavian,
                        'ore_category' => $oreCategory,
                        'processed_at' => Carbon::now(),
                    ];

                    // Mining that turns up for a period already invoiced was
                    // never in that bill and never will be: the invoice is
                    // pinned, so charging for this would mean re-opening
                    // something the member has settled. It lands exempt, and
                    // says why, rather than carrying a rate nothing collected.
                    if (InvoiceCoverage::coversRow(
                        (int) $entry->character_id,
                        Carbon::parse($entry->last_updated)->toDateString()
                    )) {
                        $data['tax_rate'] = 0;
                        $data['tax_amount'] = 0;
                        $data['is_taxable'] = false;
                        $data['notes'] = 'Arrived after this period was invoiced, so it was not taxed.';
                        $lateArrivals++;
                    }

                    // Unique key columns for updateOrCreate
                    $uniqueKey = [
                        'character_id' => $entry->character_id,
                        'date' => Carbon::parse($entry->last_updated)->toDateString(),
                        'type_id' => $entry->type_id,
                        'solar_system_id' => $solarSystemId,
                        'observer_id' => $entry->observer_id,
                    ];

                    // Check if already processed (for skip vs update logic)
                    $existing = MiningLedger::where('character_id', $entry->character_id)
                        ->whereDate('date', Carbon::parse($entry->last_updated)->toDateString())
                        ->where('type_id', $entry->type_id)
                        ->where('observer_id', $entry->observer_id)
                        ->first();

                    if ($existing) {
                        // Mining from before the classification cutover keeps the
                        // rate and categories it was given at the time. Quantity
                        // and value still update, because observer data is
                        // cumulative, but the rate they are multiplied by does
                        // not move under a member retrospectively.
                        if (ClassificationEpoch::existedBeforeCutover($existing->created_at)) {
                            $frozenRate = (float) $existing->tax_rate;
                            $data['tax_rate'] = $frozenRate;
                            $data['tax_amount'] = $totalValue * ($frozenRate / 100);
                            unset(
                                $data['is_moon_ore'],
                                $data['is_ice'],
                                $data['is_gas'],
                                $data['is_abyssal'],
                                $data['is_triglavian'],
                                $data['ore_category']
                            );
                        }

                        // Observer data is CUMULATIVE — quantity grows as miners mine more.
                        // Always update if quantity increased or if recalculating prices.
                        if ($recalculate || $entry->quantity > $existing->quantity) {
                            $existing->update($data);
                            $updated++;
                        } else {
                            $skipped++;
                        }
                    } else {
                        // Cross-source dedup: adjust personal ESI record if it exists
                        // Character ESI combines ALL mining of a type in a system into one entry,
                        // so if a character mined at both a corp moon and non-corp moon,
                        // we subtract the observer quantity and keep the remainder as untaxed.
                        $personalDupe = MiningLedger::where('character_id', $entry->character_id)
                            ->whereDate('date', Carbon::parse($entry->last_updated)->toDateString())
                            ->where('type_id', $entry->type_id)
                            ->whereNull('observer_id')
                            ->first();

                        // Dedup is a correction, and a correction to mining that
                        // has already been billed is a change to the bill's
                        // evidence. Leave those rows exactly as they were.
                        if ($personalDupe && InvoiceCoverage::coversRow(
                            (int) $personalDupe->character_id,
                            $personalDupe->date
                        )) {
                            $billedSkips++;
                            Log::info('Mining Manager: left a personal ledger row alone, an issued invoice covers it', [
                                'character_id' => $personalDupe->character_id,
                                'date' => (string) $personalDupe->date,
                                'type_id' => $personalDupe->type_id,
                            ]);
                            $personalDupe = null;
                        }

                        if ($personalDupe) {
                            $remainder = $personalDupe->quantity - $entry->quantity;
                            if ($remainder <= 0) {
                                // Observer covers all or more — remove the character entry
                                $personalDupe->delete();
                                Log::debug("Mining Manager: Replaced personal ESI record with observer data for character {$entry->character_id}, type {$entry->type_id}");
                            } else {
                                // Character mined more than observer shows — keep remainder as non-corp mining
                                $valuationService = app(OreValuationService::class);
                                $remainderValues = $valuationService->calculateOreValue($entry->type_id, $remainder);
                                $personalDupe->update([
                                    'quantity' => $remainder,
                                    'unit_price' => $remainderValues['unit_price'] ?? 0,
                                    'ore_value' => $remainderValues['ore_value'] ?? 0,
                                    'mineral_value' => $remainderValues['mineral_value'] ?? 0,
                                    'total_value' => $remainderValues['total_value'] ?? 0,
                                    'tax_rate' => 0,
                                    'tax_amount' => 0,
                                    'processed_at' => Carbon::now(),
                                ]);
                                Log::debug("Mining Manager: Adjusted personal ESI record for character {$entry->character_id}, type {$entry->type_id}: kept {$remainder} units as non-corp mining");
                            }
                        }

                        $ledgerEntry = MiningLedger::updateOrCreate($uniqueKey, $data);
                        $processed++;
                    }

                    $progressBar->advance();

                } catch (\Exception $e) {
                    $this->error("\n❌ Error processing entry for character {$entry->character_id}: {$e->getMessage()}");
                    $errors++;
                    $progressBar->advance();
                }
                } // end inner foreach (entries per observer)
            } // end outer foreach (observers in chunk)
            }); // end chunk callback

            // (No DB::commit — per the no-outer-transaction comment above,
            // each per-entry write committed independently as it ran. The
            // inner per-entry try/catch already prevented any single failure
            // from poisoning the rest of the chunk.)
            $progressBar->finish();

            $this->line('');
            $this->line('');
            $this->info('✅ Processing complete!');
            $this->line('');
            
            $this->table(
                ['Status', 'Count'],
                [
                    ['🆕 New entries created', $processed],
                    ['🔄 Existing entries updated', $updated],
                    ['📌 Arrived after invoicing, exempt', $lateArrivals],
                    ['🔒 Left alone, already invoiced', $billedSkips],
                    ['⏭️  Entries skipped', $skipped],
                    ['❌ Errors', $errors],
                ]
            );

            // Show mining summary
            if ($processed > 0 || $updated > 0) {
                $this->line('');
                $this->info('📈 Mining Summary:');

                $uniqueMiners = $uniqueCharacterIds->unique()->count();
                $uniqueStructures = $uniqueObserverIds->unique()->count();
                // Sum actual per-entry tax amounts from database instead of using a flat rate
                $totalTaxes = MiningLedger::where('character_id', '>', 0)
                    ->whereIn('character_id', $uniqueCharacterIds->unique())
                    ->where('date', '>=', $cutoffDate->toDateString())
                    ->sum('tax_amount');

                $this->line("   • Unique miners tracked: {$uniqueMiners} (including non-registered)");
                $this->line("   • Structures: {$uniqueStructures}");
                $this->line("   • Total quantity: " . number_format($totalQuantity) . " units");
                $this->line("   • Total est. taxes (per settings): " . number_format($totalTaxes, 0) . " ISK");
            }

            if ($errors > 0) {
                $this->line('');
                $this->warn("⚠️  Completed with {$errors} errors. Check logs for details.");
                return Command::FAILURE;
            }

            // Check for jackpot ores in observer entries.
            //
            // BUG FIX 2026-04-27: previously gated on `$processed > 0` which
            // only counted brand-new mining_ledger inserts. After the first
            // run that ingests a chunk's mining, every subsequent run sees
            // the same cumulative observer quantities and takes the "skipped"
            // path → $processed stays 0 → checkForJackpotOres never ran →
            // jackpot alerts never fired even though jackpot ore type IDs
            // were sitting right there in $jackpotCheckEntries.
            //
            // The check function is idempotent (only marks not-yet-flagged
            // extractions, only verifies pending manual reports), so re-running
            // it on every cron tick is safe and free. Cost: one query per
            // unique observer in the entries collection, only when jackpot
            // type IDs are actually present.
            if ($jackpotCheckEntries->isNotEmpty()) {
                $this->checkForJackpotOres($jackpotCheckEntries);
            }

            // Clean up orphaned character-imported entries where observer data exists
            // Character ESI combines all mining of a type in a system, so orphans
            // may contain both corp and non-corp quantities mixed together.
            if ($processed > 0 || $updated > 0) {
                $this->line('');
                $this->info('🔗 Cleaning up character-imported entries with matching observer data...');

                $cleaned = 0;
                $adjusted = 0;
                try {
                    $valuationSvc = app(OreValuationService::class);

                    MiningLedger::whereNull('corporation_id')
                        ->whereNull('observer_id')
                        ->where('is_moon_ore', true)
                        ->where('date', '>=', $cutoffDate->toDateString())
                        ->chunk(500, function ($orphans) use ($valuationSvc, &$cleaned, &$adjusted, &$billedSkips) {
                            foreach ($orphans as $orphan) {
                                // Sum all observer quantities for same character+date+type
                                $observerQty = MiningLedger::where('character_id', $orphan->character_id)
                                    ->whereDate('date', $orphan->date)
                                    ->where('type_id', $orphan->type_id)
                                    ->whereNotNull('observer_id')
                                    ->sum('quantity');

                                if ($observerQty <= 0) {
                                    continue;
                                }

                                if (InvoiceCoverage::coversRow((int) $orphan->character_id, $orphan->date)) {
                                    $billedSkips++;
                                    Log::info('Mining Manager: left an orphaned ledger row alone, an issued invoice covers it', [
                                        'character_id' => $orphan->character_id,
                                        'date' => (string) $orphan->date,
                                        'type_id' => $orphan->type_id,
                                    ]);
                                    continue;
                                }

                                $remainder = $orphan->quantity - $observerQty;
                                if ($remainder <= 0) {
                                    $orphan->delete();
                                    $cleaned++;
                                } else {
                                    // Keep remainder as non-corp mining
                                    $remainderValues = $valuationSvc->calculateOreValue($orphan->type_id, $remainder);
                                    $orphan->update([
                                        'quantity' => $remainder,
                                        'unit_price' => $remainderValues['unit_price'] ?? 0,
                                        'ore_value' => $remainderValues['ore_value'] ?? 0,
                                        'mineral_value' => $remainderValues['mineral_value'] ?? 0,
                                        'total_value' => $remainderValues['total_value'] ?? 0,
                                        'tax_rate' => 0,
                                        'tax_amount' => 0,
                                        'processed_at' => Carbon::now(),
                                    ]);
                                    $adjusted++;
                                }
                            }
                        });

                    if ($cleaned > 0 || $adjusted > 0) {
                        $this->info("   Removed {$cleaned} duplicates, adjusted {$adjusted} entries (kept non-corp remainder).");
                    } else {
                        $this->info("   No orphaned entries to clean up.");
                    }
                } catch (\Exception $e) {
                    $this->warn("   ⚠️  Backfill failed: {$e->getMessage()}");
                    Log::warning('Mining Manager: Corporation ID backfill failed', ['error' => $e->getMessage()]);
                }
            }

            // Auto-generate daily summaries for all dates that were touched
            if ($processed > 0 || $updated > 0) {
                $this->line('');
                $this->info('📊 Updating daily summaries for processed dates...');

                try {
                    $summaryService = app(\MiningManager\Services\Ledger\LedgerSummaryService::class);

                    $summaryCount = 0;
                    foreach ($touchedPairsMap as $pair) {
                        $summaryService->generateDailySummary($pair['character_id'], $pair['date']);
                        $summaryCount++;
                    }

                    $this->info("   Updated {$summaryCount} daily summaries.");
                } catch (\Exception $e) {
                    $this->warn("   ⚠️  Daily summary update failed: {$e->getMessage()}");
                    Log::warning('Mining Manager: Auto daily summary update failed', ['error' => $e->getMessage()]);
                }
            }

            // Clear dashboard cache so new data shows immediately
            \MiningManager\Http\Controllers\DashboardController::clearDashboardCache();
            $this->info("\n✅ Processing complete. Dashboard cache cleared.");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            // No DB::rollBack — there's no outer transaction to roll back
            // (see the no-outer-transaction comment above). Per-entry writes
            // that succeeded before this fatal error stay committed; the
            // next cron tick will re-process anything that didn't land
            // (updateOrCreate is idempotent).
            $progressBar->finish();
            $this->line('');
            $this->line('');
            $this->error("❌ Fatal error: {$e->getMessage()}");
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        } finally {
            // Restore the previous singleton context regardless of how the
            // try block exited. Without this, Laravel's persistent queue
            // worker (which reuses the same PHP process across multiple
            // jobs) would carry the last observer's corp context into the
            // next job, causing settings reads in subsequent jobs to
            // return the wrong corp's values.
            $settingsService->setActiveCorporation($previousActiveCorp);
        }
        } finally {
            $lock->release();
        }
    }

    /**
     * Get tax rate for an ore type from corporation settings.
     * Returns 0 if no corporation is configured or ore type is not taxable.
     *
     * @param SettingsManagerService $settingsService
     * @param int $typeId
     * @param bool $isMoonOre
     * @param bool $isIce
     * @param bool $isGas
     * @param bool $isAbyssal
     * @param bool $isTriglavian
     * @param array $taxSelector
     * @param int|null $structureCorpId
     * @return float Tax rate percentage (0-100)
     */
    private function getTaxRateFromSettings(
        SettingsManagerService $settingsService,
        int $typeId,
        bool $isMoonOre,
        bool $isIce,
        bool $isGas,
        bool $isAbyssal,
        bool $isTriglavian,
        array $taxSelector,
        ?int $structureCorpId
    ): float {
        // No corporation resolved for this structure → 0% tax (statistics only)
        if (!$structureCorpId) {
            return 0.0;
        }

        // Get tax rates from settings (respects active corporation context)
        $taxRates = $settingsService->getTaxRates();

        // Check taxability via tax selector
        if ($isMoonOre) {
            if (!empty($taxSelector['no_moon_ore'])) {
                return 0.0;
            }
            if (empty($taxSelector['all_moon_ore']) && empty($taxSelector['only_corp_moon_ore'])) {
                return 0.0;
            }
            // Get rarity-specific rate
            $rarity = TypeIdRegistry::getMoonOreRarity($typeId);
            if ($rarity) {
                $rarityKey = strtolower($rarity);
                return (float) ($taxRates['moon_ore'][$rarityKey] ?? $taxRates['moon_ore']['r4'] ?? 5.0);
            }
            return (float) ($taxRates['moon_ore']['r4'] ?? 5.0);
        }

        if ($isIce) {
            return ($taxSelector['ice'] ?? true) ? (float) ($taxRates['ice'] ?? 10.0) : 0.0;
        }

        if ($isGas) {
            return ($taxSelector['gas'] ?? false) ? (float) ($taxRates['gas'] ?? 10.0) : 0.0;
        }

        if ($isAbyssal) {
            return ($taxSelector['abyssal_ore'] ?? false) ? (float) ($taxRates['abyssal_ore'] ?? 15.0) : 0.0;
        }

        if ($isTriglavian) {
            return ($taxSelector['triglavian_ore'] ?? false) ? (float) ($taxRates['triglavian_ore'] ?? 10.0) : 0.0;
        }

        // Regular ore
        return ($taxSelector['ore'] ?? true) ? (float) ($taxRates['ore'] ?? 10.0) : 0.0;
    }

    /**
     * Classify ore into a category string for statistics.
     *
     * @param int $typeId
     * @return string
     */
    private function classifyOreCategory(int $typeId): string
    {
        return OreClassifier::category($typeId);
    }

    /**
     * Check if any processed entries contain jackpot ores and flag extractions
     *
     * @param \Illuminate\Support\Collection $observerEntries
     * @return void
     */
    /**
     * Real-time jackpot detection hook — runs at the tail of every
     * process-ledger run (every 30 minutes via the cron).
     *
     * Two paths, same data check:
     *
     *   AUTO-DETECT — extraction was not yet flagged as jackpot. Mark it
     *                 jackpot, mark verified, fire the jackpot_detected
     *                 notification.
     *
     *   USER-REPORT VERIFICATION — extraction was manually reported via the
     *                              "Report Jackpot" button (is_jackpot=true,
     *                              jackpot_verified=null). Real mining data
     *                              IS the verification — flip verified to
     *                              true silently. The user's report already
     *                              fired its own notification when submitted.
     *
     * Window matching (BUG FIX 2026-04-24): uses the plugin's full mining-
     * window expiry (MoonExtraction::getExpiryTime() = fractured_at + 50h,
     * with chunk_arrival + 53h fallback). The previous implementation used
     * natural_decay_time which is the auto-fracture mark (~3h after chunk
     * arrival), so the lookup missed essentially every chunk where mining
     * happened on a day other than chunk-arrival day — including all the
     * normal cases where mining starts after fracture.
     */
    private function checkForJackpotOres($observerEntries)
    {
        try {
            $jackpotTypeIds = TypeIdRegistry::getAllJackpotOres();

            $jackpotEntries = $observerEntries->filter(function ($entry) use ($jackpotTypeIds) {
                return in_array($entry->type_id, $jackpotTypeIds);
            });

            if ($jackpotEntries->isEmpty()) {
                return;
            }

            $this->line('');
            $this->info('⭐ Jackpot ores detected in mining data!');

            // Group by observer/structure
            $byObserver = $jackpotEntries->groupBy('observer_id');
            $jackpotsFound = 0;
            $reportsVerified = 0;

            foreach ($byObserver as $observerId => $entries) {
                // Find extraction for this structure whose mining window
                // includes the entry's date.
                //
                // Loose SQL bound: chunk_arrival within the last 56h of the
                // entry date (max possible window is ~53h: chunk_arrival
                // + 3h auto-fracture + 48h ready + 2h unstable). Tight PHP
                // filter then uses the model's getExpiryTime() helper for
                // the canonical lifecycle-aware end-of-window check.
                //
                // Match BOTH paths in one query:
                //   - is_jackpot=false                    → auto-detect
                //   - is_jackpot=true + verified=null +
                //     jackpot_reported_by set             → user-report verify
                // Use the LATEST mining day at this observer instead of an
                // arbitrary first() pick. Corp observer entries are keyed
                // (character_id, observer_id, type_id, last_updated) — when
                // a structure has mining across multiple days (e.g. continuing
                // through midnight UTC), groupBy('observer_id') merges all
                // those days into one collection. first() returned whichever
                // row happened to be first in iteration order; if that was an
                // older day, the SQL filter `chunk_arrival_time <= entryDate->endOfDay()`
                // could exclude today's chunk and the match silently failed.
                //
                // Using max() guarantees we always anchor on the most recent
                // mining day at this structure, which is the day most likely
                // to fall within the currently-active chunk's window. Same
                // semantics as first() in the simple case (single date),
                // strictly better in the multi-date case.
                $entryDate = $entries->max(fn($e) => Carbon::parse($e->last_updated));
                if (!$entryDate) {
                    continue; // defensive — shouldn't happen with valid data
                }

                $candidates = MoonExtraction::where('structure_id', $observerId)
                    ->where('chunk_arrival_time', '<=', $entryDate->copy()->endOfDay())
                    ->where('chunk_arrival_time', '>=', $entryDate->copy()->subHours(56)->startOfDay())
                    ->whereNotIn('status', ['cancelled', 'expired'])
                    ->where(function ($q) {
                        $q->where('is_jackpot', false)
                          ->orWhere(function ($q2) {
                              $q2->where('is_jackpot', true)
                                 ->whereNull('jackpot_verified')
                                 ->whereNotNull('jackpot_reported_by');
                          });
                    })
                    ->orderBy('chunk_arrival_time', 'desc')
                    ->get();

                $extraction = $candidates->first(function (MoonExtraction $e) use ($entryDate) {
                    $expiry = $e->getExpiryTime();
                    return $expiry && $entryDate->lessThanOrEqualTo($expiry);
                });

                if (!$extraction) {
                    // Diagnostic: jackpot ores detected in observer data but no
                    // matching MoonExtraction. Run a broader query to figure out
                    // WHY the match failed so the operator can correct the
                    // underlying state issue (most common: extraction not yet
                    // imported, chunk arrived outside the 56h window, or the
                    // chunk is already flagged + verified).
                    $diag = MoonExtraction::where('structure_id', $observerId)
                        ->orderByDesc('chunk_arrival_time')
                        ->limit(3)
                        ->get(['id', 'chunk_arrival_time', 'fractured_at', 'status', 'is_jackpot', 'jackpot_verified', 'jackpot_reported_by']);

                    if ($diag->isEmpty()) {
                        $this->warn("  ⚠️  Jackpot ores at structure {$observerId} but NO MoonExtraction row exists for this structure. Run mining-manager:update-extractions to import it.");
                        Log::warning("ProcessMiningLedgerCommand: jackpot ores detected at structure {$observerId} but no MoonExtraction tracked for it");
                    } else {
                        $latest = $diag->first();
                        $reason = match (true) {
                            in_array($latest->status, ['cancelled', 'expired'], true)
                                => "latest extraction status={$latest->status} (chunk_arrival={$latest->chunk_arrival_time})",
                            $latest->is_jackpot && $latest->jackpot_verified === true
                                => "already flagged + verified jackpot — no re-broadcast",
                            $latest->is_jackpot && $latest->jackpot_verified === false
                                => "previously marked as not-jackpot via DetectJackpotsCommand — run --rerun-failed if this is wrong",
                            ($entryDate->copy()->subHours(56)->startOfDay()->gt($latest->chunk_arrival_time))
                                => "latest chunk_arrival ({$latest->chunk_arrival_time}) is older than 56h window from entry date ({$entryDate->toDateString()})",
                            default => "no extraction within active mining window for this entry date",
                        };
                        $this->warn("  ⚠️  Jackpot ores at structure {$observerId} but no auto-detect match: {$reason}");
                        Log::info("ProcessMiningLedgerCommand: jackpot ores at structure {$observerId} but no match — {$reason}", [
                            'observer_id' => $observerId,
                            'entry_date' => $entryDate->toIso8601String(),
                            'window_start' => $entryDate->copy()->subHours(56)->startOfDay()->toIso8601String(),
                            'window_end' => $entryDate->copy()->endOfDay()->toIso8601String(),
                            'latest_extraction' => $latest?->only(['id', 'chunk_arrival_time', 'status', 'is_jackpot', 'jackpot_verified']),
                        ]);
                    }

                    continue;
                }

                // Distinguish auto-detect (was not jackpot) from user-report
                // verification (was already jackpot via manual report).
                $wasAutoDetected = !$extraction->is_jackpot;

                $extraction->is_jackpot = true;
                if (!$extraction->jackpot_detected_at) {
                    $extraction->jackpot_detected_at = now();
                }
                // Real mining data IS the verification. Both paths land here
                // so the daily DetectJackpotsCommand backstop has nothing to
                // do for this extraction.
                $extraction->jackpot_verified = true;
                $extraction->jackpot_verified_at = now();
                $extraction->save();

                if ($wasAutoDetected) {
                    $jackpotsFound++;
                    $this->info("  ⭐ JACKPOT (auto-detected): {$extraction->moon_name} (Structure {$observerId})");
                } else {
                    $reportsVerified++;
                    $this->info("  ✅ JACKPOT (user-report verified): {$extraction->moon_name} (Structure {$observerId})");
                }

                // Notification fires for AUTO-DETECT only. User-reported jackpots
                // already triggered sendJackpotDetected when the user clicked
                // "Report Jackpot" — re-firing here would double-notify the
                // channel for the same chunk.
                if (!$wasAutoDetected) {
                    continue;
                }

                // Get details for notification
                $structureName = DB::table('universe_structures')
                    ->where('structure_id', $observerId)
                    ->value('name') ?? "Structure {$observerId}";

                $systemName = DB::table('universe_structures')
                    ->join('solar_systems', 'universe_structures.solar_system_id', '=', 'solar_systems.system_id')
                    ->where('universe_structures.structure_id', $observerId)
                    ->value('solar_systems.name') ?? 'Unknown System';

                // First miner who mined jackpot ore
                $firstEntry = $entries->first();
                $detectedBy = $firstEntry->character_name ?? "Character {$firstEntry->character_id}";

                // Build the same ore-summary format used by moon_ready
                // notifications. Since a jackpot chunk has +100% variants
                // for ALL its moon ore (binary, not partial), showing the
                // full chunk composition is more useful than showing only
                // mined-so-far quantities.
                $oreSummary = $extraction->buildOreSummary();

                $baseUrl = rtrim(config('app.url', ''), '/');
                $extractionUrl = $baseUrl . '/mining-manager/moon/' . $extraction->id;

                // Send webhook notification
                try {
                    $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
                    // Jackpot-adjusted value — base estimated_value is computed
                    // from ESI's pre-jackpot ore_composition. calculateValueWithJackpotBonus
                    // applies the ~2.0x multiplier for a confirmed jackpot
                    // (every +100% variant reprocesses to ~2x mineral content).
                    $jackpotValue = (int) round(
                        $extraction->calculateValueWithJackpotBonus((float) ($extraction->estimated_value ?? 0))
                    );

                    $notificationService->sendJackpotDetected([
                        'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                        'structure_name' => $structureName,
                        'system_name' => $systemName,
                        'detected_by' => $detectedBy,
                        'estimated_value' => $jackpotValue,
                        'ore_summary' => $oreSummary,
                        'extraction_id' => $extraction->id,
                        'extraction_url' => $extractionUrl,
                    ]);

                    $this->info("  📡 Jackpot notification sent!");
                } catch (\Exception $e) {
                    $this->warn("  ⚠️ Failed to send jackpot notification: {$e->getMessage()}");
                }
            }

            if ($jackpotsFound > 0) {
                $this->info("💎 Found {$jackpotsFound} new auto-detected jackpot(s)!");
            }
            if ($reportsVerified > 0) {
                $this->info("✅ Verified {$reportsVerified} user-reported jackpot(s) in real-time!");
            }
        } catch (\Exception $e) {
            $this->warn("⚠️ Error checking for jackpot ores: {$e->getMessage()}");
        }
    }
}
