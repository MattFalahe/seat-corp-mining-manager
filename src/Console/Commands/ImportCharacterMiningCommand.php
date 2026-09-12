<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Pricing\OreValuationService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Services\Tax\ClassificationEpoch;
use MiningManager\Services\Tax\InvoiceCoverage;
use MiningManager\Services\Ledger\LedgerSummaryService;
use Carbon\Carbon;
use MiningManager\Services\OreClassifier;

class ImportCharacterMiningCommand extends Command
{
    protected $signature = 'mining-manager:import-character-mining
                            {--character_id= : Import specific character ID only}
                            {--days=30 : Number of days to import}
                            {--force : Re-import even if entries already exist}';

    protected $description = 'Import character mining ledger data from SeAT core (belt, anomaly, ice, gas mining)';

    public function handle()
    {
        $lock = Cache::lock('mining-manager:import-character-mining', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return self::SUCCESS;
        }

        try {
        // Check feature flag
        $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
        $features = $settingsService->getFeatureFlags();
        if (!($features['enable_ledger_tracking'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║   Mining Manager - Character Mining Import                 ║');
        $this->info('║   Importing personal mining data from SeAT ESI cache       ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->line('');

        $characterId = $this->option('character_id');
        $days = (int) $this->option('days');
        $force = $this->option('force');
        $frozen = 0;
        $lateArrivals = 0;
        $cutoffDate = Carbon::now()->subDays($days);

        // Check if SeAT's CharacterMining model exists
        if (!class_exists(\Seat\Eveapi\Models\Industry\CharacterMining::class)) {
            $this->error('❌ SeAT CharacterMining model not found. Is SeAT v5.x installed?');
            return Command::FAILURE;
        }

        // Build query for SeAT character mining data
        $query = \Seat\Eveapi\Models\Industry\CharacterMining::where('date', '>=', $cutoffDate->toDateString());

        if ($characterId) {
            $query->where('character_id', $characterId);
            $this->info("👤 Importing for character ID: {$characterId}");
        } else {
            $this->info("👤 Importing for ALL characters with mining data");
        }

        $totalEntries = $query->count();

        if ($totalEntries === 0) {
            $this->warn('⚠️  No character mining data found in SeAT.');
            $this->line('');
            $this->info('This is normal if:');
            $this->info('  • SeAT hasn\'t updated character mining data yet');
            $this->info('  • No characters have mined recently');
            $this->info('  • Characters don\'t have the mining ledger ESI scope');
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$totalEntries} character mining entries (last {$days} days)");
        $this->line('');

        $valuationService = app(OreValuationService::class);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $touchedPairs = collect();

        $progressBar = $this->output->createProgressBar($totalEntries);
        $progressBar->start();

        $query->chunk(500, function ($entries) use (
            $valuationService, $force,
            &$created, &$updated, &$skipped, &$errors, &$frozen, &$lateArrivals,
            &$touchedPairs, $progressBar
        ) {
        foreach ($entries as $entry) {
            try {
                // Skip if observer data already exists for this entry (observer is authoritative)
                $hasObserver = MiningLedger::where('character_id', $entry->character_id)
                    ->whereDate('date', $entry->date)
                    ->where('type_id', $entry->type_id)
                    ->whereNotNull('observer_id')
                    ->exists();

                if ($hasObserver) {
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                // Check for existing personal entry
                $existing = MiningLedger::where('character_id', $entry->character_id)
                    ->whereDate('date', $entry->date)
                    ->where('type_id', $entry->type_id)
                    ->where('solar_system_id', $entry->solar_system_id)
                    ->whereNull('observer_id')
                    ->first();

                if ($existing && !$force) {
                    // Update only if quantity changed
                    if ($existing->quantity != $entry->quantity) {
                        $values = $valuationService->calculateOreValue($entry->type_id, $entry->quantity);
                        $existing->update([
                            'quantity' => $entry->quantity,
                            'unit_price' => $values['unit_price'] ?? 0,
                            'ore_value' => $values['ore_value'] ?? 0,
                            'mineral_value' => $values['mineral_value'] ?? 0,
                            'total_value' => $values['total_value'] ?? 0,
                            'processed_at' => Carbon::now(),
                        ]);
                        $updated++;

                        $pairKey = $entry->character_id . '|' . $entry->date;
                        $touchedPairs->put($pairKey, [
                            'character_id' => $entry->character_id,
                            'date' => $entry->date,
                        ]);
                    } else {
                        $skipped++;
                    }
                    $progressBar->advance();
                    continue;
                }

                // Calculate values
                $values = $valuationService->calculateOreValue($entry->type_id, $entry->quantity);

                // Classify ore
                $isMoonOre = TypeIdRegistry::isMoonOre($entry->type_id);
                $isIce = TypeIdRegistry::isIce($entry->type_id);
                $isGas = TypeIdRegistry::isGas($entry->type_id);
                $isAbyssal = OreClassifier::isAbyssal($entry->type_id);
                $isTriglavian = TypeIdRegistry::isTriglavianOre($entry->type_id);
                $oreCategory = $this->classifyOreCategory($entry->type_id);

                // Delete existing if force mode.
                //
                // Except when the row predates the classification cutover.
                // Deleting and recreating it would hand old mining this
                // version's categories and reset its rate to the column
                // default, which is exactly the retroactive change the cutover
                // exists to prevent. Leave those rows alone; the normal
                // non-force path above still keeps their quantity and value
                // current.
                if ($existing && $force) {
                    if (ClassificationEpoch::existedBeforeCutover($existing->created_at)) {
                        $frozen++;
                        $progressBar->advance();
                        continue;
                    }

                    $existing->delete();
                }

                // Same rule as the observer path: mining that surfaces for a
                // period already invoiced is exempt. is_taxable defaults to
                // true, so without this it would read as taxable at a rate of
                // zero, which explains nothing to whoever is looking at it.
                $lateExempt = InvoiceCoverage::coversRow((int) $entry->character_id, $entry->date);

                if ($lateExempt) {
                    $lateArrivals++;
                }

                MiningLedger::create([
                    'character_id' => $entry->character_id,
                    'date' => $entry->date,
                    'type_id' => $entry->type_id,
                    'quantity' => $entry->quantity,
                    'solar_system_id' => $entry->solar_system_id,
                    'unit_price' => $values['unit_price'] ?? 0,
                    'ore_value' => $values['ore_value'] ?? 0,
                    'mineral_value' => $values['mineral_value'] ?? 0,
                    'total_value' => $values['total_value'] ?? 0,
                    'is_moon_ore' => $isMoonOre,
                    'is_ice' => $isIce,
                    'is_gas' => $isGas,
                    'is_abyssal' => $isAbyssal,
                    'is_triglavian' => $isTriglavian,
                    'ore_category' => $oreCategory,
                    'processed_at' => Carbon::now(),
                    'is_taxable' => ! $lateExempt,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'notes' => $lateExempt
                        ? 'Arrived after this period was invoiced, so it was not taxed.'
                        : null,
                ]);
                $created++;

                $pairKey = $entry->character_id . '|' . $entry->date;
                $touchedPairs->put($pairKey, [
                    'character_id' => $entry->character_id,
                    'date' => $entry->date,
                ]);

            } catch (\Exception $e) {
                $errors++;
                Log::warning("Mining Manager: Import error for character {$entry->character_id}, type {$entry->type_id}: {$e->getMessage()}");
            }

            $progressBar->advance();
        }
        });

        $progressBar->finish();
        $this->line('');
        $this->line('');

        $this->table(
            ['Status', 'Count'],
            [
                ['New entries created', $created],
                ['Existing entries updated', $updated],
                ['Skipped (observer data exists)', $skipped],
                ['Arrived after invoicing, exempt', $lateArrivals],
                ['Errors', $errors],
            ]
        );

        // Only ever non-zero under --force, and worth saying out loud rather
        // than quietly not doing what was asked.
        if ($frozen > 0) {
            $this->line('');
            $this->warn("{$frozen} entr" . ($frozen === 1 ? 'y was' : 'ies were') . " left as they are: they predate the");
            $this->warn('classification cutover, so re-importing them would change the tax');
            $this->warn('categories and rate that mining was already billed on.');
        }

        // Update daily summaries for touched character+date pairs
        if ($touchedPairs->isNotEmpty()) {
            $this->line('');
            $this->info('Updating daily summaries...');

            try {
                $summaryService = app(LedgerSummaryService::class);
                $summaryCount = 0;

                foreach ($touchedPairs as $pair) {
                    $summaryService->generateDailySummary($pair['character_id'], $pair['date']);
                    $summaryCount++;
                }

                $this->info("Updated {$summaryCount} daily summaries.");
            } catch (\Exception $e) {
                $this->warn("Daily summary update failed: {$e->getMessage()}");
            }
        }

        $this->info('Import complete.');

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function classifyOreCategory(int $typeId): string
    {
        return OreClassifier::category($typeId);
    }
}
