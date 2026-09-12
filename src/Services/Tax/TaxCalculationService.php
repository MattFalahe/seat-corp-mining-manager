<?php

namespace MiningManager\Services\Tax;

use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningLedgerDailySummary;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningPriceCache;
use MiningManager\Models\MiningEvent;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Ledger\LedgerSummaryService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Services\Tax\TaxCodeGeneratorService;
use MiningManager\Services\ReprocessingRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Seat\Eveapi\Models\Character\CharacterInfo;
use MiningManager\Services\OreClassifier;

class TaxCalculationService
{
    /**
     * Settings manager service
     *
     * @var SettingsManagerService
     */
    protected $settingsService;

    /**
     * Draws down credit held from earlier overpayments.
     *
     * @var PaymentAllocationService
     */
    protected $allocationService;

    /**
     * Constructor
     *
     * @param SettingsManagerService $settingsService
     * @param PaymentAllocationService $allocationService
     */
    public function __construct(SettingsManagerService $settingsService, PaymentAllocationService $allocationService)
    {
        $this->settingsService = $settingsService;
        $this->allocationService = $allocationService;
    }

    /**
     * Set the corporation context for settings retrieval.
     * This ensures all settings are retrieved for the correct corporation.
     *
     * @param int|null $corporationId
     * @return self
     */
    public function setCorporationContext(?int $corporationId): self
    {
        $this->settingsService->setActiveCorporation($corporationId);
        $this->allocationService->setCorporationContext($corporationId);

        return $this;
    }

    /**
     * Get the current corporation context.
     *
     * @return int|null
     */
    public function getCorporationContext(): ?int
    {
        return $this->settingsService->getActiveCorporation();
    }

    /**
     * Regenerate all daily summaries for a month using current prices and tax rates.
     * Used by the Recalculate button to refresh everything before calculating taxes.
     *
     * @param Carbon $month
     * @param int|null $corporationId Optional filter by corporation
     * @return int Number of summaries regenerated
     */
    public function regenerateMonthSummaries(Carbon $month, ?int $corporationId = null): int
    {
        $summaryService = app(LedgerSummaryService::class);

        $startDate = $month->copy()->startOfMonth();
        $endDate = $month->copy()->endOfMonth()->min(now());

        // Find all characters with mining data in this month
        $query = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at');

        if ($corporationId) {
            $query->where('corporation_id', $corporationId);
        }

        // Pre-compute all character+date pairs that have mining data
        $characterDatePairs = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at')
            ->when($corporationId, fn($q) => $q->where('corporation_id', $corporationId))
            ->select('character_id', DB::raw('DATE(date) as mining_date'))
            ->distinct()
            ->get();

        $count = 0;
        foreach ($characterDatePairs as $pair) {
            $summaryService->generateDailySummary($pair->character_id, $pair->mining_date);
            $count++;
        }

        Log::info("Mining Manager: Regenerated {$count} daily summaries for {$month->format('Y-m')}");
        return $count;
    }

    /**
     * Calculate taxes for a specific period.
     * Primary method that supports all period types (monthly, biweekly, weekly).
     *
     * @param Carbon $startDate Period start date
     * @param Carbon $endDate Period end date
     * @param string $periodType 'monthly', 'biweekly', or 'weekly'
     * @param bool $recalculate
     * @param string|null $triggeredBy
     * @return array
     */
    public function calculateTaxes(Carbon $startDate, Carbon $endDate, string $periodType = 'monthly', bool $recalculate = false, ?string $triggeredBy = null): array
    {
        // Check if any corporation is configured for tax calculation
        if ($this->settingsService->getActiveCorporation() === null) {
            $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
            $configuredCorps = $this->settingsService->getAllCorporations();

            if (!$moonOwnerCorpId && $configuredCorps->isEmpty()) {
                Log::info("Mining Manager: No corporation configured — skipping tax calculation (statistics only)");
                return [
                    'method' => 'none',
                    'count' => 0,
                    'total' => 0,
                    'errors' => [],
                    'message' => 'No corporation configured. Set moon_owner_corporation_id in General settings to enable tax calculation.',
                ];
            }

            if ($moonOwnerCorpId) {
                $this->settingsService->setActiveCorporation((int) $moonOwnerCorpId);
                Log::debug("Mining Manager: Initial corporation context set to moon owner: {$moonOwnerCorpId}");
            }
        }

        $periodHelper = app(TaxPeriodHelper::class);
        $periodLabel = $periodHelper->formatPeriod($startDate, $endDate, $periodType);
        Log::info("Mining Manager: Starting {$periodType} tax calculation for {$periodLabel}" . ($triggeredBy ? " (triggered by: {$triggeredBy})" : ''));

        return $this->calculateAccumulatedTaxes($startDate, $endDate, $periodType, $recalculate, $triggeredBy);
    }

    /**
     * Backward-compatible wrapper: calculate taxes for a calendar month.
     *
     * @param Carbon $month
     * @param bool $recalculate
     * @param string|null $triggeredBy
     * @return array
     */
    public function calculateMonthlyTaxes(Carbon $month, bool $recalculate = false, ?string $triggeredBy = null): array
    {
        $startDate = $month->copy()->startOfMonth();
        $endDate = $month->copy()->endOfMonth();

        return $this->calculateTaxes($startDate, $endDate, 'monthly', $recalculate, $triggeredBy);
    }

    /**
     * Calculate taxes accumulated to main characters.
     * All alts' taxes are summed and assigned to the main character.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param string $periodType
     * @param bool $recalculate
     * @param string|null $triggeredBy
     * @return array
     */
    private function calculateAccumulatedTaxes(Carbon $startDate, Carbon $endDate, string $periodType = 'monthly', bool $recalculate = false, ?string $triggeredBy = null): array
    {
        // Get all characters who should be taxed this period:
        // 1. Characters with entries from Moon Owner Corp's observer (moon mining)
        // 2. Characters with character ledger entries (NULL corp) who belong to a configured corp
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $homeCorporationIds = $this->settingsService->getHomeCorporationIds();

        $query = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at');

        $query->where(function ($q) use ($moonOwnerCorpId, $homeCorporationIds) {
            // Moon observer entries from our structures
            if ($moonOwnerCorpId) {
                $q->where('corporation_id', $moonOwnerCorpId);
            }

            // Character ledger entries for configured corp members
            if (!empty($homeCorporationIds)) {
                $q->orWhere(function ($inner) use ($homeCorporationIds) {
                    $inner->whereNull('corporation_id')
                          ->whereIn('character_id', function ($sub) use ($homeCorporationIds) {
                              $sub->select('character_id')
                                  ->from('character_affiliations')
                                  ->whereIn('corporation_id', $homeCorporationIds);
                          });
                });
            }
        });

        $characters = $query->distinct('character_id')
            ->pluck('character_id');

        // Group characters by main character (user)
        $groupedByMain = $this->groupCharactersByMain($characters);

        // Calculate due date for this period
        $periodHelper = app(TaxPeriodHelper::class);
        $dueDate = $periodHelper->calculateDueDate($endDate);

        // Calendar month for backward compat (charts group by this)
        $calendarMonth = $startDate->copy()->startOfMonth()->format('Y-m-01');

        $calculated = 0;
        $totalTaxAmount = 0;
        $errors = [];

        foreach ($groupedByMain as $mainCharacterId => $characterIds) {
            try {
                DB::transaction(function () use ($mainCharacterId, $characterIds, $startDate, $endDate, $calendarMonth, $periodType, $dueDate, $recalculate, $triggeredBy, &$calculated, &$totalTaxAmount) {
                    // Check if tax already exists for this character + period
                    // Use withTrashed() because soft-deleted records still hold the unique constraint
                    $existingTax = MiningTax::withTrashed()
                        ->where('character_id', $mainCharacterId)
                        ->where(function ($q) use ($startDate, $calendarMonth) {
                            $q->where('period_start', $startDate->format('Y-m-d'))
                              ->orWhere(function ($inner) use ($calendarMonth) {
                                  $inner->where('month', $calendarMonth)
                                        ->whereNull('period_start');
                              });
                        })
                        ->lockForUpdate()
                        ->first();

                    // Restore soft-deleted records so they can be updated
                    if ($existingTax && $existingTax->trashed()) {
                        $existingTax->restore();
                    }

                    if ($existingTax && !$recalculate) {
                        Log::debug("Mining Manager: Skipping main character {$mainCharacterId} - tax already calculated");
                        return;
                    }

                    // Calculate combined tax for all characters in this group
                    $combinedTaxAmount = 0;
                    $taxBreakdown = [];

                    foreach ($characterIds as $characterId) {
                        $characterTax = $this->calculateCharacterTax($characterId, $startDate, $endDate, true);
                        $combinedTaxAmount += $characterTax;

                        if ($characterTax > 0) {
                            $taxBreakdown[] = [
                                'character_id' => $characterId,
                                'tax_amount' => $characterTax,
                            ];
                        }
                    }

                    // Apply minimum tax to the combined total (not per-character)
                    if ($combinedTaxAmount > 0) {
                        $paymentSettings = $this->settingsService->getPaymentSettings();
                        $minimumAmount = (float) ($paymentSettings['minimum_tax_amount'] ?? config('mining-manager.tax_payment.minimum_tax_amount', 1000000));
                        $minimumBehavior = $this->settingsService->getSetting('payment.minimum_tax_behavior', 'exempt');

                        if ($minimumAmount > 0 && $combinedTaxAmount < $minimumAmount) {
                            if ($minimumBehavior === 'enforce') {
                                Log::debug("Mining Manager: Raising accumulated tax for main character {$mainCharacterId} to minimum ({$combinedTaxAmount} -> {$minimumAmount})");
                                $combinedTaxAmount = $minimumAmount;
                            } else {
                                // 'exempt' — no tax below minimum, skip entirely
                                Log::debug("Mining Manager: Main character {$mainCharacterId} tax below minimum ({$combinedTaxAmount} < {$minimumAmount}), exempting");
                                $combinedTaxAmount = 0;
                            }
                        }
                    }

                    if ($combinedTaxAmount <= 0) {
                        Log::debug("Mining Manager: Main character {$mainCharacterId} group has no tax liability");
                        return;
                    }

                    // Create notes explaining the breakdown
                    $notes = "Accumulated tax from " . count($taxBreakdown) . " character(s):\n";
                    foreach ($taxBreakdown as $item) {
                        $charInfo = CharacterInfo::find($item['character_id']);
                        $charName = $charInfo ? $charInfo->name : "Character {$item['character_id']}";
                        $notes .= "- {$charName}: " . number_format($item['tax_amount'], 2) . " ISK\n";
                    }

                    if ($existingTax) {
                        // Same rule as recalculateTax: once a payment code has
                        // gone out or money has arrived, the invoice stands.
                        $frozenReason = $this->invoiceFreezeReason($existingTax);

                        if ($frozenReason !== null) {
                            Log::info('Mining Manager: left an issued tax record untouched during recalculation', [
                                'mining_tax_id' => $existingTax->id,
                                'character_id'  => $mainCharacterId,
                                'reason'        => $frozenReason,
                                'stored'        => (float) $existingTax->amount_owed,
                                'recalculated'  => round($combinedTaxAmount, 2),
                            ]);

                            return;
                        }

                        // Update existing
                        $existingTax->update([
                            'amount_owed' => round($combinedTaxAmount, 2),
                            'calculated_at' => Carbon::now(),
                            'notes' => $notes,
                            'triggered_by' => $triggeredBy ?? $existingTax->triggered_by,
                            'period_type' => $periodType,
                            'period_start' => $startDate->format('Y-m-d'),
                            'period_end' => $endDate->format('Y-m-d'),
                            'due_date' => $dueDate->format('Y-m-d'),
                        ]);
                        Log::info("Mining Manager: Recalculated accumulated tax for main character {$mainCharacterId}: " . number_format($combinedTaxAmount, 2) . " ISK (from " . count($characterIds) . " characters)");
                    } else {
                        // Create new tax record
                        $newTax = MiningTax::create([
                            'character_id' => $mainCharacterId,
                            'month' => $calendarMonth,
                            'period_type' => $periodType,
                            'period_start' => $startDate->format('Y-m-d'),
                            'period_end' => $endDate->format('Y-m-d'),
                            'amount_owed' => round($combinedTaxAmount, 2),
                            'amount_paid' => 0,
                            'status' => 'unpaid',
                            'calculated_at' => Carbon::now(),
                            'due_date' => $dueDate->format('Y-m-d'),
                            'notes' => $notes,
                            'triggered_by' => $triggeredBy,
                        ]);
                        Log::info("Mining Manager: Calculated accumulated tax for main character {$mainCharacterId}: " . number_format($combinedTaxAmount, 2) . " ISK (from " . count($characterIds) . " characters)");

                        // Mint the payment code now, before anything can change
                        // this invoice's status.
                        //
                        // It used to be minted later, by whichever command
                        // happened to pick the record up, and every one of those
                        // filtered on status. Held credit is applied on the next
                        // line, so an invoice it covers even in part was already
                        // 'partial' by the time those filters ran, and fell
                        // through all of them: no code, no invoice, no ping. The
                        // member owed money and heard nothing.
                        //
                        // A code identifies the invoice. Whether anything is
                        // still owed on it is a separate question, and not one
                        // that should decide if it gets an identity at all.
                        try {
                            app(TaxCodeGeneratorService::class)->generateTaxCode($newTax);
                        } catch (\Exception $e) {
                            // Never block a tax record over its code. The invoice
                            // run will still mint one if this failed.
                            Log::warning('Mining Manager: could not mint a tax code at creation', [
                                'mining_tax_id' => $newTax->id,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        // Someone who overpaid last period has the balance
                        // sitting as credit. Draw it down now so the invoice
                        // they are about to be shown is already net of it,
                        // rather than billing them for money we are holding.
                        $creditApplied = $this->allocationService->applyCreditsToTax($newTax);

                        if ($creditApplied > 0) {
                            $newTax->refresh();
                            Log::info("Mining Manager: applied " . number_format($creditApplied, 2) . " ISK of held credit to invoice {$newTax->id}");
                        }

                        // B1a: announce on the cross-plugin event bus so other
                        // plugins (Discord Pings, HR Manager, etc.) can react.
                        // Topics handles publisher attribution, idempotency-key
                        // composition, and Discord sanitization. Standalone-safe:
                        // when MC is not installed, the class_exists guard makes
                        // this a complete no-op.
                        if (class_exists(\ManagerCore\Topics::class)) {
                            \ManagerCore\Topics::publish('mining.tax_created', [
                                'tax_id'         => $newTax->id,
                                'character_id'   => $mainCharacterId,
                                'period'         => $calendarMonth,            // template uses {period}
                                'period_type'    => $periodType,
                                'period_start'   => $startDate->format('Y-m-d'),
                                'period_end'     => $endDate->format('Y-m-d'),
                                'amount_owed'    => round($combinedTaxAmount, 2),
                                'due_date'       => $dueDate->format('Y-m-d'),
                                'corporation_id' => null, // visibility scoping (subscriber may filter)
                                'role_id'        => null,
                            ]);
                        }
                    }

                    $calculated++;
                    $totalTaxAmount += $combinedTaxAmount;
                });

            } catch (\Exception $e) {
                Log::error("Mining Manager: Error calculating accumulated tax for main character {$mainCharacterId}: " . $e->getMessage());
                $errors[] = [
                    'character_id' => $mainCharacterId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $periodLabel = $periodHelper->formatPeriod($startDate, $endDate, $periodType);
        Log::info("Mining Manager: Accumulated tax calculation complete for {$periodLabel}. Calculated: {$calculated} main characters, Total: " . number_format($totalTaxAmount, 2) . " ISK");

        if (!empty($errors)) {
            Log::warning("Mining Manager: Accumulated tax calculation completed with " . count($errors) . " error(s)", [
                'period' => $periodLabel,
                'successful' => $calculated,
                'failed' => count($errors),
                'errors' => array_map(fn($e) => "Character {$e['character_id']}: {$e['error']}", $errors),
            ]);
        }

        return [
            'method' => 'accumulated',
            'count' => $calculated,
            'total' => $totalTaxAmount,
            'errors' => $errors,
        ];
    }

    /**
     * Group characters by their main character (same user).
     * 
     * @param \Illuminate\Support\Collection $characterIds
     * @return array [mainCharacterId => [characterIds]]
     */
    private function groupCharactersByMain($characterIds): array
    {
        $grouped = [];

        // Batch load all character->user->main mappings in one query
        $characterMap = DB::table('refresh_tokens as rt')
            ->leftJoin('users as u', 'rt.user_id', '=', 'u.id')
            ->whereIn('rt.character_id', $characterIds->toArray())
            ->select('rt.character_id', 'rt.user_id', 'u.main_character_id')
            ->get()
            ->keyBy('character_id');

        foreach ($characterIds as $characterId) {
            $mapping = $characterMap->get($characterId);

            if (!$mapping || !$mapping->user_id) {
                // No user association, treat as standalone
                $grouped[$characterId] = array_merge($grouped[$characterId] ?? [], [$characterId]);
                continue;
            }

            $mainCharacterId = $mapping->main_character_id ?? $characterId;

            if (!isset($grouped[$mainCharacterId])) {
                $grouped[$mainCharacterId] = [];
            }

            $grouped[$mainCharacterId][] = $characterId;
        }

        return $grouped;
    }

    /**
     * Get the main character ID for a user.
     * 
     * @param int $userId
     * @return int|null
     */
    private function getMainCharacterForUser(int $userId): ?int
    {
        // Try to get the main character from user settings
        // SeAT stores main character in users table as 'main_character_id'
        $mainCharacterId = DB::table('users')
            ->where('id', $userId)
            ->value('main_character_id');

        if ($mainCharacterId) {
            return $mainCharacterId;
        }

        // Fallback: Get the oldest/first character for this user via refresh_tokens
        $firstCharacter = DB::table('refresh_tokens')
            ->where('user_id', $userId)
            ->orderBy('character_id', 'asc')
            ->value('character_id');

        return $firstCharacter;
    }

    /**
     * Calculate tax for a single character using daily summaries as source of truth.
     *
     * @param int $characterId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param bool $skipMinimum When true, skip minimum tax (used in accumulated mode where minimum applies to combined total)
     * @return float
     */
    private function calculateCharacterTax(int $characterId, Carbon $startDate, Carbon $endDate, bool $skipMinimum = false): float
    {
        // Read from daily summaries — the single source of truth for tax
        $totalTax = (float) MiningLedgerDailySummary::where('character_id', $characterId)
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('total_tax');

        if ($totalTax <= 0) {
            return 0;
        }

        // Check exemption threshold FIRST (before minimum tax)
        $exemptions = $this->settingsService->getExemptions();
        if ($exemptions['enabled'] && $totalTax < $exemptions['threshold']) {
            Log::debug("Mining Manager: Character {$characterId} exempt from tax (tax below threshold: {$totalTax} < {$exemptions['threshold']})");
            return 0;
        }

        // Apply minimum tax only in individual mode (not when accumulating)
        if (!$skipMinimum) {
            $paymentSettings = $this->settingsService->getPaymentSettings();
            $minimumAmount = (float) ($paymentSettings['minimum_tax_amount'] ?? config('mining-manager.tax_payment.minimum_tax_amount', 1000000));
            $minimumBehavior = $this->settingsService->getSetting('payment.minimum_tax_behavior', 'exempt');

            if ($minimumAmount > 0 && $totalTax > 0 && $totalTax < $minimumAmount) {
                if ($minimumBehavior === 'enforce') {
                    Log::debug("Mining Manager: Raising tax for character {$characterId} to minimum ({$totalTax} -> {$minimumAmount})");
                    $totalTax = $minimumAmount;
                } else {
                    // 'exempt' — no tax below minimum
                    Log::debug("Mining Manager: Character {$characterId} tax below minimum ({$totalTax} < {$minimumAmount}), exempting");
                    $totalTax = 0;
                }
            }
        }

        return round($totalTax, 2);
    }

    /**
     * Get the event tax modifier for a specific mining entry.
     * Checks if the character is participating in any active events during this mining entry.
     *
     * When multiple events overlap (same date, same location), the MOST BENEFICIAL
     * modifier for the miner is used (lowest value = most tax discount).
     *
     * Events can be:
     * - Global (corporation_id = null): Applies to all corporations
     * - Corp-specific (corporation_id set): Only applies to that corporation's miners
     *
     * @param int $characterId
     * @param object $entry Mining ledger entry
     * @param int|null $characterCorpId The character's corporation ID for filtering corp-specific events
     * @return int Tax modifier (-100 to +100)
     */
    private function getEventTaxModifier(int $characterId, $entry, ?int $characterCorpId = null): int
    {
        // Find active events during this mining entry's date
        // Order by tax_modifier ASC (best for miner first) and id ASC (tie-breaker)
        $events = MiningEvent::where('status', 'active')
            ->where('start_time', '<=', $entry->date)
            ->where(function ($query) use ($entry) {
                $query->whereNull('end_time')
                      ->orWhere('end_time', '>=', $entry->date);
            })
            ->whereHas('participants', function ($query) use ($characterId) {
                $query->where('character_id', $characterId);
            })
            // Filter by corporation: global events (null) OR character's corporation
            ->where(function ($query) use ($characterCorpId) {
                $query->whereNull('corporation_id')  // Global events
                      ->orWhere('corporation_id', $characterCorpId);  // Corp-specific events
            })
            ->orderBy('tax_modifier', 'asc')  // Best benefit first (lowest = most discount)
            ->orderBy('id', 'asc')            // Tie-breaker: earliest created
            ->get();

        if ($events->isEmpty()) {
            return 0;
        }

        // Find the best (most beneficial) modifier among applicable events
        // Lower modifier = more tax discount for miner (-100 = tax-free, 0 = normal, +100 = double)
        $bestModifier = 0;
        $appliedEvent = null;

        foreach ($events as $event) {
            // Check if the mining entry's system falls within the event's location scope.
            // Resolves constellation/region scopes to system ID lists via mapDenormalize.
            if (!$event->matchesSystem($entry->solar_system_id)) {
                continue;
            }

            // Check if the event's type applies to this ore category.
            // "special" events apply to everything; mining_op → ore,
            // moon_extraction → moon_*, ice_mining → ice, gas_huffing → gas.
            // $entry->ore_category is set by ProcessMiningLedgerCommand
            // (corp observer path) and ImportCharacterMiningCommand
            // (personal character mining path) at ingestion time.
            $oreCategory = $entry->ore_category ?? null;
            if ($oreCategory && !$event->appliesToOreCategory($oreCategory)) {
                continue;
            }

            // Take the most beneficial modifier (lowest value = most tax discount)
            if ($appliedEvent === null || $event->tax_modifier < $bestModifier) {
                $bestModifier = $event->tax_modifier;
                $appliedEvent = $event;
            }
        }

        if ($appliedEvent) {
            Log::debug("Mining Manager: Applying event '{$appliedEvent->name}' tax modifier ({$bestModifier}%) for character {$characterId}", [
                'event_id' => $appliedEvent->id,
                'character_id' => $characterId,
                'mining_date' => $entry->date,
                'solar_system_id' => $entry->solar_system_id ?? null,
            ]);
            return $bestModifier;
        }

        return 0;
    }

    /**
     * Get the value of a single ore entry.
     *
     * Uses the stored total_value from the ledger entry (daily session pricing).
     * This implements "1 day = 1 session" — ore was priced at the day's market rate
     * when processed. If total_value is 0 (unpriced entry), falls back to
     * OreValuationService for on-demand calculation.
     *
     * @param object $entry Mining ledger entry
     * @return float
     */
    private function calculateOreValue($entry): float
    {
        // Use stored daily session value if available (preferred — prices locked at mining date)
        if (isset($entry->total_value) && $entry->total_value > 0) {
            return (float) $entry->total_value;
        }

        // Fallback: calculate on-the-fly for entries that haven't been priced yet
        try {
            $valuationService = app(\MiningManager\Services\Pricing\OreValuationService::class);
            $values = $valuationService->calculateOreValue($entry->type_id, $entry->quantity);

            return $values['total_value'];
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error calculating ore value for type_id {$entry->type_id}", [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    // REMOVED: calculateRefinedMineralValue() method
    // This method was never used. All ore valuation is handled by OreValuationService::calculateOreValue()

    /**
     * Get ore price from cache.
     *
     * @param int $typeId
     * @param int $regionId
     * @param string $priceType
     * @return float|null
     */
    private function getOrePrice(int $typeId, int $regionId, string $priceType): ?float
    {
        $priceCache = MiningPriceCache::where('type_id', $typeId)
            ->where('region_id', $regionId)
            ->latest('cached_at')
            ->first();

        if (!$priceCache) {
            return null;
        }

        return match ($priceType) {
            'buy' => $priceCache->buy_price,
            'average' => $priceCache->average_price,
            default => $priceCache->sell_price,
        };
    }

    /**
     * Get tax rate for specific ore type.
     * Now supports per-corporation tax rates (guest miner multiplier).
     *
     * @param int $typeId
     * @param int|null $characterCorpId Corporation ID of the character being taxed
     * @return float
     */
    public function getTaxRateForOre(int $typeId, ?int $characterCorpId = null, $miningEntry = null): float
    {
        // A configured rate is not the same as a rate that applies. The tax
        // selector decides which categories are charged at all, and this method
        // used to skip that question entirely, so it answered "10%" for ore on
        // an install that taxes no ore. Import and the daily summaries both ask,
        // and the daily summaries are what invoices are built from, so this was
        // the odd one out and the only place the three could disagree.
        //
        // $miningEntry matters for the only_corp_moon_ore rule, which needs to
        // know where the ore came from. Callers that have the row should pass
        // it; without it that rule cannot be evaluated and moon ore falls back
        // to the broader all_moon_ore setting.
        if (! $this->shouldTaxOre($typeId, $miningEntry)) {
            return 0.0;
        }

        // Get tax rates for this corporation (applies guest multiplier if applicable)
        $taxRates = $this->settingsService->getTaxRatesForCorporation($characterCorpId);

        // Check if it's moon ore first
        $moonRarity = $this->getMoonOreRarity($typeId);
        if ($moonRarity) {
            return $taxRates['moon_ore'][$moonRarity];
        }

        // Check other categories
        $category = $this->getOreCategory($typeId);
        return $taxRates[$category] ?? 10.0;
    }

    /**
     * Determine if ore should be taxed based on settings.
     *
     * @param int $typeId
     * @param object|null $miningEntry Optional mining ledger entry for corp moon checking
     * @param array|null $corpMoonCache Pre-loaded corp moon data cache for batch optimization
     * @return bool
     */
    private function shouldTaxOre(int $typeId, $miningEntry = null, ?array $corpMoonCache = null): bool
    {
        $taxSelector = $this->settingsService->getTaxSelector();

        // Check if it's moon ore
        if ($this->isMoonOre($typeId)) {
            // If no_moon_ore is set, skip all moon ore taxation
            if (!empty($taxSelector['no_moon_ore'])) {
                Log::debug("Mining Manager: Skipping moon ore type {$typeId} - no_moon_ore is enabled");
                return false;
            }

            // If only_corp_moon_ore is enabled, check if it's from corp moon
            if ($taxSelector['only_corp_moon_ore'] && $miningEntry) {
                // Use cached corp moon data if available (batch optimization)
                if ($corpMoonCache !== null) {
                    $cacheKey = $this->getCorpMoonCacheKey(
                        $miningEntry->character_id,
                        $typeId,
                        $miningEntry->solar_system_id,
                        $miningEntry->date->format('Y-m-d')
                    );
                    $isCorpMoon = isset($corpMoonCache[$cacheKey]);
                } else {
                    // Fallback to individual query (slower)
                    $isCorpMoon = $this->checkIfCorpMoon(
                        $miningEntry->character_id,
                        $typeId,
                        $miningEntry->solar_system_id,
                        $miningEntry->date->format('Y-m-d')
                    );
                }

                if (!$isCorpMoon) {
                    Log::debug("Mining Manager: Skipping moon ore type {$typeId} - not from corp moon");
                    return false;
                }

                Log::debug("Mining Manager: Taxing moon ore type {$typeId} - confirmed corp moon");
                return true;
            }

            // Otherwise, use the all_moon_ore setting
            return $taxSelector['all_moon_ore'];
        }

        // Check other categories
        $category = $this->getOreCategory($typeId);

        return match($category) {
            'ice' => $taxSelector['ice'],
            'gas' => $taxSelector['gas'],
            'abyssal_ore' => $taxSelector['abyssal_ore'],
            'triglavian_ore' => $taxSelector['triglavian_ore'],
            'ore' => $taxSelector['ore'],
            default => false,
        };
    }

    /**
     * Get moon ore rarity level.
     * Now uses TypeIdRegistry instead of config
     *
     * @param int $typeId
     * @return string|null
     */
    private function getMoonOreRarity(int $typeId): ?string
    {
        // Use TypeIdRegistry for moon ore rarity mapping
        $rarityMap = TypeIdRegistry::getMoonOreRarityMap();
        
        foreach ($rarityMap as $rarity => $typeIds) {
            if (in_array($typeId, $typeIds)) {
                return strtolower($rarity); // Return as lowercase (r64, r32, etc.)
            }
        }
        
        return null;
    }

    /**
     * Get ore category.
     * Now uses TypeIdRegistry instead of config
     *
     * @param int $typeId
     * @return string
     */
    private function getOreCategory(int $typeId): string
    {
        return OreClassifier::taxCategory($typeId);
    }

    /**
     * Check if type is moon ore.
     * Now uses TypeIdRegistry instead of config
     *
     * @param int $typeId
     * @return bool
     */
    private function isMoonOre(int $typeId): bool
    {
        return TypeIdRegistry::isMoonOre($typeId);
    }

    /**
     * Batch load all corp moon data for a character and date range (performance optimization)
     *
     * @param int $characterId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array Keyed by cache key for fast lookups
     */
    private function batchLoadCorpMoonData(int $characterId, Carbon $startDate, Carbon $endDate): array
    {
        try {
            $results = DB::table('corporation_industry_mining_observer_data as d')
                ->select('d.character_id', 'd.type_id', 'u.solar_system_id', 'd.last_updated')
                ->join('universe_structures as u', 'd.observer_id', '=', 'u.structure_id')
                ->where('d.character_id', '=', $characterId)
                ->whereBetween('d.last_updated', [
                    $startDate->format('Y-m-d') . ' 00:00:00',
                    $endDate->format('Y-m-d') . ' 23:59:59'
                ])
                ->get();

            // Build cache keyed by composite key for fast lookups
            $cache = [];
            foreach ($results as $row) {
                $date = substr($row->last_updated, 0, 10); // Extract 'Y-m-d' from timestamp
                $cacheKey = $this->getCorpMoonCacheKey(
                    $row->character_id,
                    $row->type_id,
                    $row->solar_system_id,
                    $date
                );
                $cache[$cacheKey] = true;
            }

            Log::debug("Mining Manager: Batch loaded " . count($cache) . " corp moon entries for character {$characterId}");
            return $cache;
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error batch loading corp moon data: " . $e->getMessage());
            return [];  // Return empty cache on error
        }
    }

    /**
     * Build corp moon cache from a collection of mining entries.
     * Uses batchLoadCorpMoonData for each unique character/date range.
     *
     * @param \Illuminate\Support\Collection $miningData
     * @return array
     */
    private function buildCorpMoonCache($miningData): array
    {
        $cache = [];

        // Group by character_id to batch-load per character
        $grouped = $miningData->groupBy('character_id');

        foreach ($grouped as $characterId => $entries) {
            $dates = $entries->pluck('date');
            $startDate = $dates->min();
            $endDate = $dates->max();

            if ($startDate && $endDate) {
                $charCache = $this->batchLoadCorpMoonData(
                    $characterId,
                    $startDate instanceof \Carbon\Carbon ? $startDate : \Carbon\Carbon::parse($startDate),
                    $endDate instanceof \Carbon\Carbon ? $endDate : \Carbon\Carbon::parse($endDate)
                );
                $cache = array_merge($cache, $charCache);
            }
        }

        return $cache;
    }

    /**
     * Generate cache key for corp moon lookup
     *
     * @param int $characterId
     * @param int $typeId
     * @param int $solarSystemId
     * @param string $date Date in 'Y-m-d' format
     * @return string
     */
    private function getCorpMoonCacheKey(int $characterId, int $typeId, int $solarSystemId, string $date): string
    {
        return "{$characterId}_{$typeId}_{$solarSystemId}_{$date}";
    }

    /**
     * Check if moon ore was mined from YOUR corporation's moon.
     *
     * This method queries the corporation_industry_mining_observer_data table
     * which ONLY contains mining data from YOUR corporation's observers/refineries.
     * If the mining event exists in this table, it means it was mined from your corp's moon.
     *
     * @param int $characterId
     * @param int $typeId
     * @param int $solarSystemId
     * @param string $date Date in 'Y-m-d' format
     * @return bool True if mined from corp moon, false otherwise
     */
    private function checkIfCorpMoon(int $characterId, int $typeId, int $solarSystemId, string $date): bool
    {
        try {
            // Query the corporation mining observer data
            // This table is populated by SeAT from ESI API and ONLY contains
            // mining data from YOUR corporation's moon mining structures
            // Use whereDate to match regardless of time portion in last_updated
            $result = DB::table('corporation_industry_mining_observer_data as d')
                ->select('d.*')
                ->join('universe_structures as u', 'd.observer_id', '=', 'u.structure_id')
                ->whereDate('d.last_updated', '=', $date)
                ->where('d.character_id', '=', $characterId)
                ->where('d.type_id', '=', $typeId)
                ->where('u.solar_system_id', '=', $solarSystemId)
                ->first();
            
            // If found, this mining event is from YOUR corp's moon
            if (!is_null($result)) {
                Log::debug("Mining Manager: Moon ore type {$typeId} confirmed as CORP MOON for character {$characterId}");
                return true;
            } else {
                Log::debug("Mining Manager: Moon ore type {$typeId} is from OTHER corp's moon for character {$characterId}");
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error checking corp moon: " . $e->getMessage());
            // On error, default to false (don't tax)
            return false;
        }
    }
    
    /**
     * Update overdue tax statuses — flip 'unpaid' → 'overdue' when the
     * due date has passed, but respect the ESI Wallet Lag Buffer setting
     * (payment.grace_period_hours, default 24h) to avoid false-positive
     * overdue flags when a payment might still be in-flight from ESI.
     *
     * Rationale for just the ESI buffer (not the longer grace_period_days):
     *  - ESI wallet journal entries can lag CCP's servers by several hours.
     *    A player who paid on their due date may not have their payment
     *    visible in ESI for up to 24h. Flipping status before the buffer
     *    elapses would cause false "overdue" notifications to legitimate
     *    payers.
     *  - `grace_period_days` (default 7) is a DIFFERENT concern used by
     *    TheftDetectionService — it controls when an unpaid/overdue tax
     *    escalates to a theft incident. Applying it here made the UI
     *    show "UNPAID" for a week past the due date, contradicting the
     *    red "N days ago" display next to the due date on the same page.
     *  - Historical behaviour subtracted BOTH buffers, so a tax due
     *    April 21 stayed 'unpaid' until April 28. That's fixed as of
     *    2026-04-23: only the ESI buffer applies.
     *
     * @return int Number of taxes marked as overdue
     */
    public function updateOverdueTaxes(): int
    {
        $paymentSettings = $this->settingsService->getPaymentSettings();
        $esiGraceHours = (int) ($paymentSettings['grace_period_hours'] ?? 24);

        $overdueThreshold = Carbon::now()->subHours($esiGraceHours);

        $updated = MiningTax::where('status', 'unpaid')
            ->where(function ($q) use ($overdueThreshold) {
                // Prefer due_date. Fall back to period_end or month for
                // historical records that don't have it populated.
                $q->where(function ($inner) use ($overdueThreshold) {
                    $inner->whereNotNull('due_date')
                          ->where('due_date', '<', $overdueThreshold);
                })->orWhere(function ($inner) use ($overdueThreshold) {
                    $inner->whereNull('due_date')
                          ->whereNotNull('period_end')
                          ->where('period_end', '<', $overdueThreshold);
                })->orWhere(function ($inner) use ($overdueThreshold) {
                    $inner->whereNull('due_date')
                          ->whereNull('period_end')
                          ->where('month', '<', $overdueThreshold->copy()->startOfMonth());
                });
            })
            ->update(['status' => 'overdue']);

        if ($updated > 0) {
            Log::info("Mining Manager: Marked {$updated} taxes as overdue (ESI lag buffer: {$esiGraceHours}h)");
        }

        return $updated;
    }

    /**
     * Recalculate tax for a specific character and month.
     *
     * @param int $characterId
     * @param Carbon $month
     * @return float
     */
    /**
     * Why a tax record must not have its amount rewritten, or null when it may.
     *
     * Two things pin an invoice: a payment code, because that code is already in
     * a member's hands and is what the wallet matcher looks for; and any money
     * received against it, because a changed total would silently re-open
     * something that was settled.
     *
     * @return string|null
     */
    private function invoiceFreezeReason(MiningTax $tax): ?string
    {
        if (in_array($tax->status, ['paid', 'partial'], true)) {
            return 'status:' . $tax->status;
        }

        if ((float) $tax->amount_paid > 0) {
            return 'payment received';
        }

        // Any code, whatever its status. 'used' means it was redeemed, which is
        // an even stronger reason to leave the invoice alone than 'active'.
        $hasCode = DB::table('mining_tax_codes')
            ->where('mining_tax_id', $tax->id)
            ->exists();

        return $hasCode ? 'tax code issued' : null;
    }

    public function recalculateTax(int $characterId, Carbon $month): float
    {
        $startDate = $month->copy()->startOfMonth();
        $endDate = $month->copy()->endOfMonth();

        $taxAmount = $this->calculateCharacterTax($characterId, $startDate, $endDate);

        // Try period_start first, fall back to month for pre-migration records
        $tax = MiningTax::where('character_id', $characterId)
            ->where('period_start', $startDate->format('Y-m-d'))
            ->first();

        if (!$tax) {
            $tax = MiningTax::where('character_id', $characterId)
                ->where('month', $startDate->format('Y-m-01'))
                ->first();
        }

        if ($tax) {
            // An invoice that has been issued is a record of what somebody was
            // asked to pay. Once a payment code exists, or money has landed
            // against it, the amount stops being a derived figure and becomes a
            // fact, so recalculation reports the new number without writing it.
            $frozenReason = $this->invoiceFreezeReason($tax);

            if ($frozenReason !== null) {
                Log::info('Mining Manager: skipped recalculating an issued tax record', [
                    'mining_tax_id' => $tax->id,
                    'character_id'  => $characterId,
                    'reason'        => $frozenReason,
                    'stored'        => (float) $tax->amount_owed,
                    'recalculated'  => round($taxAmount),
                ]);

                return (float) $tax->amount_owed;
            }

            $tax->update([
                'amount_owed' => round($taxAmount),
                'calculated_at' => Carbon::now(),
            ]);
        }

        return $taxAmount;
    }

    /**
     * Get tax breakdown for a character.
     *
     * @param int $characterId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getTaxBreakdown(int $characterId, Carbon $startDate, Carbon $endDate): array
    {
        // Read from daily summaries — the single source of truth for tax
        $summaries = MiningLedgerDailySummary::where('character_id', $characterId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $breakdown = [];
        $totalValue = 0;
        $totalTax = 0;
        $totalEventDiscount = 0;

        foreach ($summaries as $summary) {
            foreach ($summary->ore_types ?? [] as $ore) {
                $key = $ore['ore_name'] ?? "Type {$ore['type_id']}";

                if (!isset($breakdown[$key])) {
                    $breakdown[$key] = [
                        'type_id' => $ore['type_id'],
                        'name' => $key,
                        'category' => $ore['category'] ?? 'ore',
                        'rarity' => $ore['moon_rarity'] ?? null,
                        'quantity' => 0,
                        'value' => 0,
                        'tax_rate' => $ore['tax_rate'] ?? 0,
                        'event_modifier' => $ore['event_modifier'] ?? 0,
                        'event_qualified_value' => 0,
                        'event_discount_amount' => 0,
                        'effective_rate' => $ore['effective_rate'] ?? $ore['tax_rate'] ?? 0,
                        'tax' => 0,
                    ];
                }

                $quantity = (float) ($ore['quantity'] ?? 0);
                $value = (float) ($ore['total_value'] ?? 0);
                $tax = (float) ($ore['estimated_tax'] ?? 0);
                $qualifiedValue = (float) ($ore['event_qualified_value'] ?? 0);
                $discount = (float) ($ore['event_discount_amount'] ?? 0);

                $breakdown[$key]['quantity'] += $quantity;
                $breakdown[$key]['value'] += $value;
                $breakdown[$key]['tax'] += $tax;
                $breakdown[$key]['event_qualified_value'] += $qualifiedValue;
                $breakdown[$key]['event_discount_amount'] += $discount;

                // Keep the strongest event_modifier observed across the period
                // so the UI's "(-50% event)" tag reflects the applicable rate.
                $thisModifier = (int) ($ore['event_modifier'] ?? 0);
                $currentModifier = (int) $breakdown[$key]['event_modifier'];
                if ($thisModifier < $currentModifier) {
                    $breakdown[$key]['event_modifier'] = $thisModifier;
                }

                $totalValue += $value;
                $totalTax += $tax;
                $totalEventDiscount += $discount;
            }
        }

        return [
            'breakdown' => array_values($breakdown),
            'total_value' => $totalValue,
            'total_tax' => $totalTax,
            'event_discount_total' => $totalEventDiscount,
            'calculation_method' => 'daily_summaries',
        ];
    }

    /**
     * Get ore name from type_id.
     * 
     * @param int $typeId
     * @return string
     */
    private function getOreName(int $typeId): string
    {
        // Try to get from invTypes table (SeAT has this)
        try {
            $type = DB::table('invTypes')
                ->where('typeID', $typeId)
                ->first();

            return $type ? $type->typeName : "Unknown Ore ({$typeId})";
        } catch (\Exception $e) {
            return "Type {$typeId}";
        }
    }

    /**
     * Get tax statistics for a period.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getTaxStatistics(Carbon $startDate, Carbon $endDate): array
    {
        $taxes = MiningTax::whereBetween('month', [$startDate->format('Y-m-01'), $endDate->format('Y-m-01')])
            ->get();

        $statistics = [
            'total_owed' => $taxes->sum('amount_owed'),
            'total_paid' => $taxes->sum('amount_paid'),
            'outstanding' => $taxes->sum(function($tax) {
                return $tax->amount_owed - $tax->amount_paid;
            }),
            'count_unpaid' => $taxes->where('status', 'unpaid')->count(),
            'count_partial' => $taxes->where('status', 'partial')->count(),
            'count_paid' => $taxes->where('status', 'paid')->count(),
            'count_overdue' => $taxes->where('status', 'overdue')->count(),
            'count_waived' => $taxes->where('status', 'waived')->count(),
        ];

        return $statistics;
    }

    /**
     * Apply manual tax adjustment.
     *
     * @param int $taxId
     * @param float $adjustmentAmount
     * @param string $reason
     * @return bool
     */
    public function applyTaxAdjustment(int $taxId, float $adjustmentAmount, string $reason): bool
    {
        try {
            $tax = MiningTax::findOrFail($taxId);

            $newAmount = round(max(0, $tax->amount_owed + $adjustmentAmount));

            $tax->update([
                'amount_owed' => $newAmount,
                'notes' => ($tax->notes ? $tax->notes . "\n" : '') . 
                          Carbon::now()->toDateTimeString() . " - Adjustment: " . 
                          number_format($adjustmentAmount, 2) . " ISK. Reason: {$reason}",
            ]);

            Log::info("Mining Manager: Applied tax adjustment for tax {$taxId}: " . number_format($adjustmentAmount, 2) . " ISK");

            return true;
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error applying tax adjustment: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Waive tax for a character.
     *
     * @param int $taxId
     * @param string $reason
     * @return bool
     */
    public function waiveTax(int $taxId, string $reason): bool
    {
        try {
            $tax = MiningTax::findOrFail($taxId);

            $tax->update([
                'status' => 'waived',
                'notes' => ($tax->notes ? $tax->notes . "\n" : '') . 
                          Carbon::now()->toDateTimeString() . " - Tax waived. Reason: {$reason}",
            ]);

            Log::info("Mining Manager: Waived tax {$taxId} for character {$tax->character_id}");

            return true;
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error waiving tax: " . $e->getMessage());
            return false;
        }
    }
}
