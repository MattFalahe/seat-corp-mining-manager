<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Tax\PaymentAllocationService;
use MiningManager\Services\Tax\TaxCalculationService;
use MiningManager\Services\Tax\TaxPeriodHelper;
use MiningManager\Services\Tax\WalletTransferService;
use MiningManager\Services\Tax\TaxCodeGeneratorService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Character\CharacterInfoService;
use MiningManager\Services\Pricing\OreValuationService;
use MiningManager\Services\Notification\NotificationService;
use MiningManager\Http\Controllers\Traits\EnrichesCharacterData;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningLedgerDailySummary;
use MiningManager\Models\EventMiningRecord;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\MiningPriceCache;
use MiningManager\Models\TaxInvoice;
use MiningManager\Models\TaxCode;
use MiningManager\Models\PaymentAllocation;
use MiningManager\Models\PaymentCredit;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MiningManager\Http\Controllers\Concerns\GuardsDataExport;

class TaxController extends Controller
{
    use GuardsDataExport;

    use EnrichesCharacterData;

    protected $taxService;
    protected $walletService;
    protected $allocationService;
    protected $codeService;
    protected $settingsService;
    protected $characterInfoService;
    protected $oreValuationService;
    protected $notificationService;

    public function __construct(
        TaxCalculationService $taxService,
        WalletTransferService $walletService,
        PaymentAllocationService $allocationService,
        TaxCodeGeneratorService $codeService,
        SettingsManagerService $settingsService,
        CharacterInfoService $characterInfoService,
        OreValuationService $oreValuationService,
        NotificationService $notificationService
    ) {
        $this->taxService = $taxService;
        $this->walletService = $walletService;
        $this->allocationService = $allocationService;
        $this->codeService = $codeService;
        $this->settingsService = $settingsService;
        $this->characterInfoService = $characterInfoService;
        $this->oreValuationService = $oreValuationService;
        $this->notificationService = $notificationService;
    }

    /**
     * Set corporation context for all services.
     * This ensures settings are retrieved for the correct corporation.
     *
     * @param int|null $corporationId
     * @return void
     */
    protected function setCorporationContext(?int $corporationId): void
    {
        $this->allocationService->setCorporationContext($corporationId);

        if ($corporationId) {
            $this->settingsService->setActiveCorporation($corporationId);
            $this->taxService->setCorporationContext($corporationId);
            $this->walletService->setCorporationContext($corporationId);
            $this->codeService->setCorporationContext($corporationId);
        }
    }

    /**
     * Check if the current user has admin permissions.
     */
    private function isAdmin(): bool
    {
        return auth()->user()?->can('mining-manager.admin') ?? false;
    }

    /**
     * Check if the current user has director permissions (includes admin).
     */
    private function isDirector(): bool
    {
        return $this->isAdmin() || (auth()->user()?->can('mining-manager.director') ?? false);
    }

    /**
     * Check if the current user has member permissions (includes director/admin).
     */
    private function isMember(): bool
    {
        return $this->isDirector() || (auth()->user()?->can('mining-manager.member') ?? false);
    }

    /**
     * Check if the current user should see all corporation data.
     * Admins always see all. Directors see all when toggled on.
     */
    private function isViewingAll(): bool
    {
        return $this->isAdmin() || ($this->isDirector() && session('mining-manager.view-all', false));
    }

    /**
     * Toggle between viewing own data and all corporation data (directors only).
     */
    public function toggleView(Request $request)
    {
        $current = session('mining-manager.view-all', false);
        session(['mining-manager.view-all' => !$current]);

        $message = !$current
            ? trans('mining-manager::taxes.switched_to_all_data')
            : trans('mining-manager::taxes.switched_to_my_data');

        return redirect()->back()->with('success', $message);
    }

    /**
     * Get feature flags from settings for view visibility.
     */
    /**
     * Held for the life of the request. The service's flag set is two dozen
     * reads and some pages ask for it twice, so without this the delegation
     * would cost real round trips for a value that cannot change mid render.
     *
     * @var array<string,mixed>|null
     */
    private ?array $featureFlagCache = null;

    private function getFeatureFlags(): array
    {
        if ($this->featureFlagCache !== null) {
            return $this->featureFlagCache;
        }

        // Values come out of the service's own flag set rather than being read
        // again here. This method used to do its own reads and list four keys,
        // while the views it feeds asked for more than four; a key it did not
        // define came back through `?? false` as a feature that was switched
        // on reading as switched off, with nothing to show anything was wrong.
        //
        // The names below are the view-facing ones and deliberately do not all
        // match the service's. What must not diverge again is the values, so
        // anything added here reads from $flags, never from a fresh getSetting.
        $flags = $this->settingsService->getFeatureFlags();

        return $this->featureFlagCache = [
            'tax_tracking' => (bool) ($flags['enable_tax_tracking'] ?? true),
            'wallet_verification' => (bool) ($flags['verify_wallet_transactions'] ?? true),
            'enable_upfront_payments' => (bool) ($flags['enable_upfront_payments'] ?? false),
            'allow_export_data' => (bool) ($flags['allow_export_data'] ?? true),

            // Not feature flags in the service's sense, they live under
            // tax_rates, so these two stay direct reads.
            'tax_codes' => (bool) $this->settingsService->getSetting('tax_rates.auto_generate_tax_codes', true),
            'reminders' => (bool) $this->settingsService->getSetting('tax_rates.send_tax_reminders', false),
        ];
    }

    /**
     * Get all character IDs linked to the authenticated user.
     */
    private function getUserCharacterIds(): array
    {
        $user = auth()->user();
        if (!$user) {
            return [];
        }

        $ids = $user->characters->pluck('character_id')->toArray();
        if (empty($ids) && $user->main_character_id) {
            $ids = [$user->main_character_id];
        }

        return $ids;
    }

    /**
     * Get character IDs relevant for tax queries.
     * Always uses account-level tax — returns only the main character ID
     * since taxes are consolidated under the main character.
     */
    private function getUserTaxCharacterIds(): array
    {
        $characterIds = $this->getUserCharacterIds();
        $mainId = auth()->user()->main_character_id;
        return $mainId ? [$mainId] : $characterIds;
    }

    /**
     * Display tax overview dashboard
     */
    public function index(Request $request)
    {
        $status = $request->input('status') ?: 'all';
        $month = $request->input('month') ?: null;
        $corporationId = $request->input('corporation_id') ?: null;
        $minerType = $request->input('miner_type') ?: 'all';

        // Get moon owner corporation ID from settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');

        // Set corporation context for settings retrieval
        $this->setCorporationContext($moonOwnerCorpId);

        // Build query - include affiliation for corporation_id lookup
        $query = MiningTax::with(['character', 'affiliation', 'taxCodes', 'taxInvoices']);

        if ($status !== 'all') {
            // "outstanding" is the view a director actually wants: everything
            // with money still on it, rather than checking unpaid, overdue and
            // partial in turn and holding the total in their head.
            if ($status === 'outstanding') {
                $query->whereIn('status', ['unpaid', 'overdue', 'partial']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($month) {
            $query->where('month', Carbon::parse($month)->format('Y-m-01'));
        }

        if ($corporationId) {
            // Use character_affiliations table for corporation_id lookup
            $query->whereIn('character_id', function($q) use ($corporationId) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->where('corporation_id', $corporationId);
            });
        }

        // Filter by miner type (corp member vs guest) - uses configured corporations list
        $homeCorporationIds = $this->settingsService->getHomeCorporationIds();
        if ($minerType === 'corp' && !empty($homeCorporationIds)) {
            // Only show corp members (characters in any configured corporation)
            $query->whereIn('character_id', function($q) use ($homeCorporationIds) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->whereIn('corporation_id', $homeCorporationIds);
            });
        } elseif ($minerType === 'guest' && !empty($homeCorporationIds)) {
            // Only show guest miners (characters NOT in any configured corporation)
            $query->whereIn('character_id', function($q) use ($homeCorporationIds) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->whereNotIn('corporation_id', $homeCorporationIds);
            });
        }

        // Scope data: members/directors (default) see own data; admin/directors (toggled) see all
        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();
        $taxCharacterIds = !$viewAll ? $this->getUserTaxCharacterIds() : [];
        if (!$viewAll) {
            $query->whereIn('character_id', $taxCharacterIds);
        }

        $taxes = $query->with('taxCodes')
            ->orderByRaw('COALESCE(period_start, month) DESC')
            ->orderBy('character_id')
            ->get();

        // Enrich with character names and corporation names
        $taxes = $this->enrichWithCharacterInfo(collect($taxes), $this->characterInfoService);

        // Summary statistics - split by corp/guest if moon owner is configured
        // Helper to apply user scoping to summary queries
        $scopeSummary = function($query) use ($viewAll, $taxCharacterIds) {
            if (!$viewAll && !empty($taxCharacterIds)) {
                $query->whereIn('character_id', $taxCharacterIds);
            }
            return $query;
        };

        $summaryQuery = MiningTax::query();
        $corpSummaryQuery = null;
        $guestSummaryQuery = null;

        if (!empty($homeCorporationIds) && $viewAll) {
            // Corp vs guest breakdown only shown to directors
            $corpSummaryQuery = MiningTax::whereIn('character_id', function($q) use ($homeCorporationIds) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->whereIn('corporation_id', $homeCorporationIds);
            });

            $guestSummaryQuery = MiningTax::whereIn('character_id', function($q) use ($homeCorporationIds) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->whereNotIn('corporation_id', $homeCorporationIds);
            });
        }

        $totalOwed = $scopeSummary(MiningTax::where('status', 'unpaid'))->sum('amount_owed');
        $totalOverdue = $scopeSummary(MiningTax::where('status', 'overdue'))->sum('amount_owed');
        $collectedThisMonth = $scopeSummary(MiningTax::where('status', 'paid')
            ->whereMonth('paid_at', Carbon::now()->month))->sum('amount_paid');
        $unpaidCount = $scopeSummary(MiningTax::where('status', 'unpaid'))->count();
        $overdueCount = $scopeSummary(MiningTax::where('status', 'overdue'))->count();
        $paidCount = $scopeSummary(MiningTax::where('status', 'paid')
            ->whereMonth('paid_at', Carbon::now()->month))->count();

        // Calculate collection rate
        $totalExpected = $totalOwed + $totalOverdue + $collectedThisMonth;
        $collectionRate = $totalExpected > 0 ? ($collectedThisMonth / $totalExpected) * 100 : 0;

        $summary = [
            'total_owed' => $totalOwed,
            'overdue_amount' => $totalOverdue,
            'collected' => $collectedThisMonth,
            'unpaid_count' => $unpaidCount,
            'overdue_count' => $overdueCount,
            'paid_count' => $paidCount,
            'collection_rate' => $collectionRate,
        ];

        // Add corp vs guest breakdown if moon owner is configured
        if ($moonOwnerCorpId && $corpSummaryQuery && $guestSummaryQuery) {
            $summary['corp_members'] = [
                'owed' => (clone $corpSummaryQuery)->where('status', 'unpaid')->sum('amount_owed'),
                'count' => (clone $corpSummaryQuery)->whereIn('status', ['unpaid', 'overdue'])->count(),
                'collected' => (clone $corpSummaryQuery)->where('status', 'paid')
                    ->whereMonth('paid_at', Carbon::now()->month)
                    ->sum('amount_paid'),
            ];
            $summary['guest_miners'] = [
                'owed' => (clone $guestSummaryQuery)->where('status', 'unpaid')->sum('amount_owed'),
                'count' => (clone $guestSummaryQuery)->whereIn('status', ['unpaid', 'overdue'])->count(),
                'collected' => (clone $guestSummaryQuery)->where('status', 'paid')
                    ->whereMonth('paid_at', Carbon::now()->month)
                    ->sum('amount_paid'),
            ];
        }

        // Get payment method from settings
        $paymentMethod = $this->settingsService->getPaymentSettings()['method'];

        // Get active corporations with taxes
        $corporationIds = DB::table('character_affiliations')
            ->whereIn('character_id', function($query) {
                $query->select('character_id')
                    ->from('mining_taxes')
                    ->distinct();
            })
            ->distinct()
            ->pluck('corporation_id');

        $corporations = DB::table('corporation_infos')
            ->whereIn('corporation_id', $corporationIds)
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();

        $features = $this->getFeatureFlags();

        // Period context for the view — non-monthly setups need a visible
        // indicator so directors know which period length the rows below
        // represent. 'F Y' alone on the header is misleading when the
        // plugin is running biweekly or weekly.
        $periodHelper = app(TaxPeriodHelper::class);
        $periodType = $periodHelper->getConfiguredPeriodType();
        [$currentPeriodStart, $currentPeriodEnd] = $periodHelper->getPeriodBounds(Carbon::now(), $periodType);
        $currentPeriodLabel = $periodHelper->formatPeriod($currentPeriodStart, $currentPeriodEnd, $periodType);

        // For non-monthly setups, also compute "collected this period"
        // (cash tied to the current active period specifically) so the
        // director has a metric that tracks the period, not just the
        // calendar month.
        $collectedThisPeriod = null;
        if ($periodType !== 'monthly') {
            $collectedThisPeriod = $scopeSummary(
                MiningTax::where('status', 'paid')
                    ->where('period_start', $currentPeriodStart->toDateString())
            )->sum('amount_paid');
        }

        return view('mining-manager::taxes.index', compact(
            'taxes',
            'summary',
            'paymentMethod',
            'corporations',
            'status',
            'month',
            'corporationId',
            'minerType',
            'moonOwnerCorpId',
            'isAdmin',
            'isDirector',
            'viewAll',
            'features',
            'periodType',
            'currentPeriodLabel',
            'currentPeriodStart',
            'currentPeriodEnd',
            'collectedThisPeriod'
        ));
    }

    /**
     * Show tax calculation form
     */
    public function showCalculateForm()
    {
        // Get corporations for dropdown
        $corporations = CorporationInfo::orderBy('name')->get();

        // Generate years array (current year and past 2 years)
        $currentYear = now()->year;
        $years = range($currentYear - 2, $currentYear);

        $paymentSettings = $this->settingsService->getPaymentSettings();

        // Get configured period type
        $periodHelper = app(TaxPeriodHelper::class);
        $periodType = $periodHelper->getConfiguredPeriodType();

        // Get tracking data from daily summaries
        $liveTracking = $this->getLiveTrackingData();

        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();
        $features = $this->getFeatureFlags();

        return view('mining-manager::taxes.calculate', compact(
            'corporations',
            'years',
            'paymentSettings',
            'periodType',
            'liveTracking',
            'isAdmin',
            'isDirector',
            'viewAll',
            'features'
        ));
    }

    /**
     * Get mining tracking data for current month.
     * Reads from daily summaries as the single source of truth.
     * Detail entries loaded from mining_ledger for display only.
     *
     * @return array
     */
    protected function getLiveTrackingData(): array
    {
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $currentMonth = now()->startOfMonth();

        // Get ALL character IDs who mined this month (includes guests)
        // Guests are taxed at guest rates via daily summaries, so they must be included
        $characterIds = MiningLedger::where('date', '>=', $currentMonth)
            ->distinct()
            ->pluck('character_id')
            ->toArray();

        if (empty($characterIds)) {
            return [
                'has_data' => false,
                'entries' => [],
                'total_value' => 0,
                'estimated_tax' => 0,
                'character_count' => 0,
                'month' => $currentMonth->format('F Y'),
            ];
        }

        // === SUMMARY TOTALS from daily summaries (single source of truth) ===
        $summaryData = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->where('date', '>=', $currentMonth->format('Y-m-d'))
            ->where('date', '<=', now()->format('Y-m-d'))
            ->selectRaw('SUM(total_value) as total_value, SUM(total_tax) as total_tax, COUNT(DISTINCT character_id) as character_count')
            ->first();

        $totalValue = (float) ($summaryData->total_value ?? 0);
        $estimatedTax = (float) ($summaryData->total_tax ?? 0);
        $characterCount = (int) ($summaryData->character_count ?? count($characterIds));

        // Supplement: add today's data from MiningLedger directly (today's daily summary
        // may not exist yet since the summary command hasn't run). This only queries
        // one day of data instead of the entire month, keeping the query lightweight.
        $today = now()->format('Y-m-d');
        $todayHasSummary = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->where('date', $today)
            ->exists();

        if (!$todayHasSummary && !empty($characterIds)) {
            $todayTotals = MiningLedger::whereIn('character_id', $characterIds)
                ->where('date', $today)
                ->whereNotNull('processed_at')
                ->selectRaw('SUM(total_value) as total_value, SUM(tax_amount) as total_tax, COUNT(DISTINCT character_id) as character_count')
                ->first();

            if (($todayTotals->total_value ?? 0) > 0) {
                $totalValue += (float) $todayTotals->total_value;
                $estimatedTax += (float) ($todayTotals->total_tax ?? 0);
                // Character count: use the higher of summary count vs total unique characters
                $characterCount = max($characterCount, (int) ($todayTotals->character_count ?? 0));
            }
        }

        // === PER-ACCOUNT TOTALS from daily summaries ===
        $perCharacterTotals = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->where('date', '>=', $currentMonth->format('Y-m-d'))
            ->where('date', '<=', now()->format('Y-m-d'))
            ->selectRaw('character_id, SUM(total_value) as total_value, SUM(total_tax) as total_tax, SUM(total_quantity) as total_quantity')
            ->groupBy('character_id')
            ->get()
            ->keyBy('character_id');

        // Supplement: merge today's per-character data from MiningLedger if no summary exists yet
        if (!$todayHasSummary && !empty($characterIds)) {
            $todayPerChar = MiningLedger::whereIn('character_id', $characterIds)
                ->where('date', $today)
                ->whereNotNull('processed_at')
                ->selectRaw('character_id, SUM(total_value) as total_value, SUM(tax_amount) as total_tax, SUM(quantity) as total_quantity')
                ->groupBy('character_id')
                ->get()
                ->keyBy('character_id');

            // Merge today's data into the summary collection
            foreach ($todayPerChar as $charId => $todayData) {
                if ($perCharacterTotals->has($charId)) {
                    // Add today's values to existing summary
                    $existing = $perCharacterTotals->get($charId);
                    $existing->total_value = ($existing->total_value ?? 0) + ($todayData->total_value ?? 0);
                    $existing->total_tax = ($existing->total_tax ?? 0) + ($todayData->total_tax ?? 0);
                    $existing->total_quantity = ($existing->total_quantity ?? 0) + ($todayData->total_quantity ?? 0);
                } else {
                    // New character only in today's data
                    $perCharacterTotals->put($charId, $todayData);
                }
            }
        }

        // === DETAIL ENTRIES (limited for display) ===
        $entryQuery = MiningLedger::where('date', '>=', $currentMonth)
            ->whereIn('character_id', $characterIds)
            ->orderBy('date', 'desc')
            ->limit(100);
        $entries = $entryQuery->get();

        // Prefetch event attribution for these ledger rows so the "Event Tax"
        // column can show the actual per-row discount. Previously that column
        // read $entry->event_tax_amount, which is a non-existent mining_ledger
        // attribute — so every row showed 0 ISK regardless of event activity.
        //
        // Build a map keyed by (character_id, mining_date, type_id, solar_system_id)
        // holding SUM(value_isk) of event-qualified mining and the best
        // (most negative) modifier across any covering event. We only
        // consider active/completed events with a NEGATIVE modifier —
        // positive modifiers (tax increases) wouldn't produce a "discount"
        // so leaving them out keeps the column's meaning consistent with
        // the per-ore "saved X ISK" indicators elsewhere.
        $eventAttribution = collect();
        if ($entries->isNotEmpty()) {
            $entryCharIds = $entries->pluck('character_id')->unique()->values()->all();
            $entryDates = $entries->pluck('date')->map(function ($d) {
                return Carbon::parse($d)->toDateString();
            })->unique()->values()->all();

            $eventAttribution = EventMiningRecord::query()
                ->whereIn('event_mining_records.character_id', $entryCharIds)
                ->whereIn('event_mining_records.mining_date', $entryDates)
                ->join('mining_events', 'event_mining_records.event_id', '=', 'mining_events.id')
                ->whereIn('mining_events.status', ['active', 'completed'])
                ->where('mining_events.tax_modifier', '<', 0)
                ->selectRaw('
                    event_mining_records.character_id,
                    event_mining_records.mining_date,
                    event_mining_records.type_id,
                    event_mining_records.solar_system_id,
                    SUM(event_mining_records.value_isk) as qualified_value,
                    MIN(mining_events.tax_modifier) as best_modifier
                ')
                ->groupBy(
                    'event_mining_records.character_id',
                    'event_mining_records.mining_date',
                    'event_mining_records.type_id',
                    'event_mining_records.solar_system_id'
                )
                ->get()
                ->keyBy(function ($row) {
                    // EventMiningRecord casts mining_date to 'date', so $row->mining_date
                    // is a Carbon instance. Carbon's __toString returns a full datetime
                    // "YYYY-MM-DD 00:00:00", but the entry-side lookup key uses
                    // toDateString() → "YYYY-MM-DD". Force the map-side format too so
                    // the keys actually match. (Without this cast, every entry's
                    // attribution lookup misses and Event Tax column reads 0 for every row.)
                    $miningDate = $row->mining_date instanceof \DateTimeInterface
                        ? $row->mining_date->format('Y-m-d')
                        : (string) $row->mining_date;

                    return sprintf('%d|%s|%d|%d',
                        $row->character_id,
                        $miningDate,
                        $row->type_id,
                        $row->solar_system_id ?? 0
                    );
                });
        }

        // Batch-resolve character names — use ALL character IDs (from full aggregation), not just from limited entries
        $allCharIds = $perCharacterTotals->keys()->toArray();
        $charactersInfo = $this->characterInfoService->getBatchCharacterInfo($allCharIds);

        $mainCharIds = collect($charactersInfo)
            ->pluck('main_character_id')
            ->filter()
            ->unique()
            ->diff(array_keys($charactersInfo))
            ->values()
            ->toArray();

        if (!empty($mainCharIds)) {
            $mainCharsInfo = $this->characterInfoService->getBatchCharacterInfo($mainCharIds);
            $charactersInfo = array_replace($charactersInfo, $mainCharsInfo);
        }

        // Batch-resolve ore type names and volumes from invTypes
        $typeIds = $entries->pluck('type_id')->unique()->toArray();
        $typeData = DB::table('invTypes')
            ->whereIn('typeID', $typeIds)
            ->select('typeID', 'typeName', 'volume')
            ->get()
            ->keyBy('typeID');
        $typeNames = $typeData->pluck('typeName', 'typeID')->toArray();
        $typeVolumes = $typeData->pluck('volume', 'typeID')->toArray();

        // Build account grouping map
        $accountGroups = [];
        foreach ($charactersInfo as $charId => $charInfo) {
            $mainId = $charInfo['main_character_id'] ?? $charId;
            if (!isset($accountGroups[$mainId])) {
                $accountGroups[$mainId] = [
                    'main_character_id' => $mainId,
                    'main_character_name' => $charactersInfo[$mainId]['name'] ?? "Account {$mainId}",
                    'characters' => [],
                ];
            }
            if (!in_array($charId, $accountGroups[$mainId]['characters'])) {
                $accountGroups[$mainId]['characters'][] = $charId;
            }
        }

        // Map entries with enriched data
        $mappedEntries = $entries->map(function($entry) use ($charactersInfo, $accountGroups, $typeNames, $typeVolumes, $eventAttribution) {
            $charInfo = $charactersInfo[$entry->character_id] ?? null;
            $mainId = $charInfo['main_character_id'] ?? $entry->character_id;

            // Calculate volume from quantity * type volume (invTypes)
            $unitVolume = (float) ($typeVolumes[$entry->type_id] ?? 0);
            $totalVolume = $entry->quantity * $unitVolume;

            // Per-row event tax discount: look up the event attribution
            // for this ledger slice. qualified_value is capped at the
            // entry's own total_value (event_mining_records values are
            // frozen-in-time proportional allocations and can drift
            // slightly from the ledger aggregate under reconciliation).
            $attrKey = sprintf('%d|%s|%d|%d',
                $entry->character_id,
                Carbon::parse($entry->date)->toDateString(),
                $entry->type_id,
                $entry->solar_system_id ?? 0
            );
            $attribution = $eventAttribution->get($attrKey);

            $eventTax = 0.0;
            if ($attribution && $entry->tax_rate > 0) {
                $qualifiedValue = min((float) $attribution->qualified_value, (float) $entry->total_value);
                if ($qualifiedValue > 0) {
                    $baseRate = (float) $entry->tax_rate;
                    $modifiedRate = max(0, $baseRate * (1 + ((int) $attribution->best_modifier / 100)));
                    $eventTax = $qualifiedValue * (($baseRate - $modifiedRate) / 100);
                }
            }

            return [
                'date' => $entry->date,
                'character_id' => $entry->character_id,
                'character' => [
                    'name' => $charInfo['name'] ?? "Character {$entry->character_id}",
                    'is_registered' => $charInfo['is_registered'] ?? false,
                ],
                'main_character_id' => $mainId,
                'main_character_name' => $accountGroups[$mainId]['main_character_name'] ?? "Account {$mainId}",
                'type_id' => $entry->type_id,
                'ore_name' => $typeNames[$entry->type_id] ?? "Type {$entry->type_id}",
                'quantity' => $entry->quantity,
                'volume' => $totalVolume,
                'total_value' => (float) ($entry->total_value ?? 0),
                'tax_amount' => (float) ($entry->tax_amount ?? 0),
                'event_tax' => round($eventTax, 2),
            ];
        })->toArray();

        // Build per-account totals from full aggregation (not limited entries)
        $accountTotals = [];
        foreach ($perCharacterTotals as $charId => $charTotal) {
            $charInfo = $charactersInfo[$charId] ?? null;
            $mainId = $charInfo['main_character_id'] ?? $charId;

            if (!isset($accountTotals[$mainId])) {
                $accountTotals[$mainId] = [
                    'total_value' => 0,
                    'total_tax' => 0,
                    'total_quantity' => 0,
                    'character_count' => 0,
                ];
            }
            $accountTotals[$mainId]['total_value'] += (float) $charTotal->total_value;
            $accountTotals[$mainId]['total_tax'] += (float) $charTotal->total_tax;
            $accountTotals[$mainId]['total_quantity'] += (int) $charTotal->total_quantity;
            $accountTotals[$mainId]['character_count']++;
        }

        return [
            'has_data' => true,
            'entries' => $mappedEntries,
            'account_groups' => $accountGroups,
            'account_totals' => $accountTotals,
            'total_value' => $totalValue,
            'estimated_tax' => $estimatedTax,
            'character_count' => $characterCount,
            'account_count' => count($accountGroups),
            'month' => $currentMonth->format('F Y'),
        ];
    }

    /**
     * Process tax calculation.
     * Calculate: sums existing daily summaries into MiningTax records.
     * Recalculate: regenerates all daily summaries with current prices/rates first.
     */
    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'period_start' => 'nullable|date_format:Y-m-d',
            'recalculate' => 'boolean',
            'character_id' => 'nullable|integer',
            'corporation_id' => 'nullable|integer',
            'confirm_incomplete' => 'boolean',
        ]);

        try {
            $periodHelper = app(TaxPeriodHelper::class);
            $periodType = $periodHelper->getConfiguredPeriodType();
            $recalculate = $validated['recalculate'] ?? false;
            $characterId = $validated['character_id'] ?? null;
            $confirmIncomplete = $validated['confirm_incomplete'] ?? false;

            $month = Carbon::parse($validated['month'])->startOfMonth();

            // Get all periods within the selected month for the configured period type
            // Monthly: 1 period, Biweekly: 2 periods, Weekly: 4-5 periods
            if (!empty($validated['period_start'])) {
                // Specific period requested
                $periodDate = Carbon::parse($validated['period_start']);
                $periods = [$periodHelper->getPeriodBounds($periodDate, $periodType)];
            } else {
                $periods = $periodHelper->getPeriodsInMonth($month, $periodType);
            }

            // Check for incomplete/future periods
            $lastPeriodEnd = end($periods)[1];
            $firstPeriodStart = $periods[0][0];
            $monthLabel = $month->format('F Y');

            if (!$periodHelper->isPeriodComplete($lastPeriodEnd) && !$confirmIncomplete) {
                $isFuture = $firstPeriodStart->gt(Carbon::now());
                $periodCount = count($periods);
                $periodDesc = $periodCount > 1 ? "{$periodCount} {$periodType} periods in {$monthLabel}" : $monthLabel;
                return response()->json([
                    'status' => 'incomplete_month',
                    'message' => $isFuture
                        ? "{$periodDesc} hasn't started yet. There is no mining data to calculate taxes from."
                        : "Not all periods in {$monthLabel} have ended yet. Tax calculations will be based on incomplete data and may need to be recalculated after the period ends.",
                    'is_future' => $isFuture,
                ], 200);
            }

            // Build trigger source for audit trail
            $user = auth()->user();
            $triggeredBy = $user
                ? 'Manual: ' . ($user->main_character->name ?? 'User #' . $user->id)
                : 'Manual: Unknown';

            // Set corporation context for settings (use provided or fall back to moon owner)
            $corporationId = $validated['corporation_id'] ?? null;
            if (!$corporationId) {
                $corporationId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
            }
            $this->setCorporationContext($corporationId);

            Log::info("Mining Manager: Tax calculation triggered by {$triggeredBy} for {$monthLabel} ({$periodType}, " . count($periods) . " periods)");

            // Recalculate mode: regenerate all daily summaries with current prices and tax rates
            if ($recalculate) {
                $regenerated = $this->taxService->regenerateMonthSummaries($month, $corporationId);
                Log::info("Recalculate: regenerated {$regenerated} daily summaries for {$monthLabel}");
            }

            // Calculate taxes for each period in the month
            $totalCount = 0;
            $totalAmount = 0;
            $allErrors = [];

            foreach ($periods as [$startDate, $endDate]) {
                $periodLabel = $periodHelper->formatPeriod($startDate, $endDate, $periodType);

                // Check if taxes already exist for this period
                $existingQuery = MiningTax::where('period_start', $startDate->format('Y-m-d'));
                if ($characterId) {
                    $existingQuery->where('character_id', $characterId);
                }
                $existingCount = $existingQuery->count();

                if ($existingCount > 0 && !$recalculate) {
                    // Skip periods that already have taxes (unless recalculating)
                    continue;
                }

                if ($characterId) {
                    $taxAmount = $this->taxService->recalculateTax((int) $characterId, $startDate);
                    $totalCount += $taxAmount > 0 ? 1 : 0;
                    $totalAmount += $taxAmount;
                } else {
                    $results = $this->taxService->calculateTaxes($startDate, $endDate, $periodType, $recalculate, $triggeredBy);
                    $totalCount += $results['count'];
                    $totalAmount += $results['total'];
                    $allErrors = array_merge($allErrors, $results['errors'] ?? []);
                }
            }

            $results = [
                'method' => $characterId ? 'individual' : ($periodType === 'monthly' ? 'accumulated' : "accumulated ({$periodType})"),
                'count' => $totalCount,
                'total' => $totalAmount,
                'errors' => $allErrors,
            ];

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.calculation_complete'),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Tax calculation error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.calculation_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display wallet verification page
     * TODO: Implement WalletTransaction model and wallet verification feature
     */
    public function wallet(Request $request)
    {
        // Check if wallet verification feature is enabled
        $featureFlags = $this->getFeatureFlags();
        if (!$featureFlags['wallet_verification']) {
            return redirect()->route('mining-manager.taxes.index')
                ->with('warning', trans('mining-manager::taxes.feature_disabled'));
        }

        $status = $request->input('status', 'pending');
        $month = $request->input('month');
        $days = $request->input('days', 30);
        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();

        // Get corporation ID from settings (or use first corporation if not configured)
        $corporationId = $this->settingsService->getSetting('general.moon_owner_corporation_id');

        if (!$corporationId) {
            // Try to get from first configured corporation
            $corporations = $this->settingsService->getAllCorporations();
            $corporationId = $corporations->first()->corporation_id ?? null;
        }

        // Set corporation context for all services
        $this->setCorporationContext($corporationId);

        if (!$corporationId) {
            // No corporation configured, return empty view
            return view('mining-manager::taxes.wallet', [
                'transactions' => collect(),
                'stats' => [
                    'pending' => 0,
                    'verified' => 0,
                    'mismatched' => 0,
                    'total_amount' => 0,
                ],
                'status' => $status,
                'month' => $month,
                'corporationId' => null,
                'isAdmin' => $isAdmin,
                'isDirector' => $isDirector,
                'viewAll' => $viewAll,
                'features' => $this->getFeatureFlags(),
                'unpaidTaxes' => collect(),
                'corpCharacterIds' => collect(),
                'payerInvoiceMap' => [],
                'heldCredits' => collect(),
                'showLegacy' => false,
                'hiddenLegacy' => 0,
            ]);
        }

        // Admins and directors always see all payments on the wallet page
        $canSeeAll = $isAdmin || $isDirector;

        // Get user's character IDs for scoping (member-only users)
        $userCharacterIds = [];
        if (!$canSeeAll) {
            $userCharacterIds = auth()->user()->characters->pluck('character_id')->toArray();
        }

        // Get corporation donations (player_donation type transactions)
        $donations = $this->walletService->getCorporationDonations($corporationId, $days);

        // Get unmatched donations (donations without tax codes)
        // Payments from before the verification cutover that carry a valid tax
        // code are withheld unless asked for. See unmatchedDonationBreakdown()
        // for why: they were most likely already credited, and we cannot prove
        // it for anything settled in instalments.
        $showLegacy = $request->boolean('show_legacy');
        $breakdown = $this->walletService->unmatchedDonationBreakdown($corporationId, $days, $showLegacy);
        $unmatchedDonations = $breakdown['donations'];
        $hiddenLegacy = $breakdown['hidden_legacy'];

        // Scope transactions for regular members: only show their own transfers
        if (!$canSeeAll && !empty($userCharacterIds)) {
            $donations = $donations->filter(function($transaction) use ($userCharacterIds) {
                return in_array($transaction->first_party_id ?? $transaction->character_id ?? 0, $userCharacterIds);
            });
            $unmatchedDonations = $unmatchedDonations->filter(function($transaction) use ($userCharacterIds) {
                return in_array($transaction->first_party_id ?? $transaction->character_id ?? 0, $userCharacterIds);
            });
        }

        // Calculate summary statistics (scoped for regular members)
        $statsBaseQuery = MiningTax::query();
        if (!$canSeeAll && !empty($userCharacterIds)) {
            $mainCharId = auth()->user()->main_character_id;
            $taxCharIds = $mainCharId ? [$mainCharId] : $userCharacterIds;
            $statsBaseQuery->whereIn('character_id', $taxCharIds);
        }

        $totalVerifiedIsk = (clone $statsBaseQuery)->where('status', 'paid')
            ->where('paid_at', '>=', Carbon::now()->subDays($days))
            ->sum('amount_paid');

        $pendingCount = $unmatchedDonations->count();
        $verifiedCount = (clone $statsBaseQuery)->where('status', 'paid')
            ->where('paid_at', '>=', Carbon::now()->subDays($days))
            ->count();

        // "Mismatched" used to repeat the pending count, which made the two
        // tiles say the same thing. It now means what it sounds like: a
        // payment carrying a code that matches no invoice we know about.
        $mismatchedCount = $canSeeAll
            ? $unmatchedDonations->where('blocker', 'tax_code_not_recognised')->count()
            : 0;

        $stats = [
            'pending' => $pendingCount,
            'verified' => $verifiedCount,
            'mismatched' => $mismatchedCount,
            'total_amount' => $totalVerifiedIsk,
        ];

        // Filter transactions based on status
        if ($status === 'pending') {
            $transactions = $unmatchedDonations;
        } else {
            $transactions = $donations;
        }

        // Apply month filter if specified
        if ($month) {
            $monthDate = Carbon::parse($month);
            $transactions = $transactions->filter(function($transaction) use ($monthDate) {
                $transactionDate = Carbon::parse($transaction->date);
                return $transactionDate->isSameMonth($monthDate);
            });
        }

        // Paginate results
        $perPage = 25;
        $page = $request->input('page', 1);
        $offset = ($page - 1) * $perPage;
        $paginatedTransactions = $transactions->slice($offset, $perPage);

        $features = $this->getFeatureFlags();

        // Get unpaid/overdue invoices for manual payment modal (director/admin only)
        $unpaidTaxes = collect();
        $corpCharacterIds = [];
        $payerInvoiceMap = [];
        $heldCredits = collect();
        if ($canSeeAll && $corporationId) {
            $unpaidTaxes = MiningTax::with('character')
                ->whereIn('status', ['unpaid', 'overdue', 'partial'])
                ->orderByRaw('COALESCE(period_start, month) asc')
                ->get();

            // Get corporation member IDs for manual entry character dropdown
            $corpCharacterIds = DB::table('character_affiliations')
                ->where('corporation_id', $corporationId)
                ->join('character_infos', 'character_affiliations.character_id', '=', 'character_infos.character_id')
                ->select('character_infos.character_id', 'character_infos.name')
                ->orderBy('character_infos.name')
                ->get();

            // Which open invoices each pending payer could legitimately settle.
            // Resolved here rather than in the browser because working out who
            // is an alt of whom needs the SeAT user link. Bounded by the number
            // of distinct payers on screen, so it stays cheap.
            $payerInvoiceMap = $this->buildPayerInvoiceMap($transactions, $unpaidTaxes);

            $heldCredits = PaymentCredit::with('character')
                ->where('remaining', '>', 0)
                ->orderBy('created_at')
                ->get();
        }

        return view('mining-manager::taxes.wallet', compact(
            'transactions',
            'stats',
            'status',
            'month',
            'corporationId',
            'paginatedTransactions',
            'isAdmin',
            'isDirector',
            'viewAll',
            'features',
            'unpaidTaxes',
            'corpCharacterIds',
            'payerInvoiceMap',
            'heldCredits',
            'showLegacy',
            'hiddenLegacy'
        ));
    }

    /**
     * Map each paying character on the pending list to the invoice ids they
     * are allowed to settle, honouring the accept-alts setting.
     *
     * @param  \Illuminate\Support\Collection  $transactions
     * @param  \Illuminate\Support\Collection  $unpaidTaxes
     */
    private function buildPayerInvoiceMap($transactions, $unpaidTaxes): array
    {
        $paymentSettings = $this->settingsService->getPaymentSettings();
        $acceptAlts = (bool) ($paymentSettings['accept_alt_characters'] ?? true);

        $payerIds = collect($transactions)
            ->map(fn ($t) => (int) ($t->first_party_id ?? 0))
            ->filter()
            ->unique()
            ->values();

        $map = [];

        foreach ($payerIds as $payerId) {
            $eligible = [$payerId];

            if ($acceptAlts) {
                $userId = DB::table('refresh_tokens')->where('character_id', $payerId)->value('user_id');

                if ($userId !== null) {
                    $eligible = DB::table('refresh_tokens')
                        ->where('user_id', $userId)
                        ->pluck('character_id')
                        ->map(fn ($id) => (int) $id)
                        ->all();

                    if (!in_array($payerId, $eligible, true)) {
                        $eligible[] = $payerId;
                    }
                }
            }

            $map[$payerId] = $unpaidTaxes
                ->filter(fn ($tax) => in_array((int) $tax->character_id, $eligible, true))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        return $map;
    }

    /**
     * Verify wallet payment
     */
    public function verifyPayment(Request $request, $transactionId)
    {
        // Set corporation context for settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $days = (int) $request->input('days', 30);

            // This used to call a verifyPayment() that does not exist on the
            // service, so the Sync button returned a 500 every time it was
            // pressed. It is a rescan of the configured wallet divisions.
            $result = $this->walletService->verifyPayments($days, true);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.wallet_synced', [
                    'matched' => $result['matched'],
                    'unmatched' => $result['unmatched'],
                ]),
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Payment verification error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.verification_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Auto-match wallet transactions to tax codes
     * Uses corporation_wallet_journals to verify player donations
     */
    public function autoMatchPayments(Request $request)
    {
        try {
            $days = $request->input('days', 30);

            // Get corporation ID from settings
            $corporationId = $this->settingsService->getSetting('general.moon_owner_corporation_id');

            if (!$corporationId) {
                $corporations = $this->settingsService->getAllCorporations();
                $corporationId = $corporations->first()->corporation_id ?? null;
            }

            // Set corporation context for services
            $this->setCorporationContext($corporationId);

            // Run auto-verification using corporation wallet journals
            $results = $this->walletService->autoVerifyFromCorporationWallet($corporationId, $days);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.auto_match_complete', [
                    'verified' => $results['verified']
                ]),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Auto-match error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.auto_match_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark tax as paid
     */
    public function markPaid(Request $request)
    {
        try {
            // Validate up front. Reading raw input via $request->input()
            // and leaning on findOrFail($taxId) for existence lets
            // non-numeric tax_id values fall through to MySQL with soft type
            // coercion, and garbage payment_date strings throw deep inside
            // Carbon::parse and bubble up as a 500. Validation surfaces
            // these as a 422 with field-level errors instead.
            $validated = $request->validate([
                'tax_id' => 'required|integer|exists:mining_taxes,id',
                'amount_paid' => 'required|numeric|min:0',
                'payment_date' => 'nullable|date',
                'notes' => 'nullable|string|max:1000',
            ]);

            $taxId = (int) $validated['tax_id'];
            $amountPaid = (float) $validated['amount_paid'];
            $paymentDate = $validated['payment_date'] ?? Carbon::now();
            $notes = $validated['notes'] ?? null;

            $user = auth()->user();
            $userName = $user->main_character->name ?? $user->name ?? 'Unknown';

            // Wrap mining_taxes update + tax_codes update atomically so a partial
            // failure can't leave active tax codes after a tax row is marked paid
            // (which would cause the wallet listener to double-process).
            $result = DB::transaction(function () use ($taxId, $amountPaid, $paymentDate, $notes, $userName) {
                $tax = MiningTax::findOrFail($taxId);

                // Accumulate partial payments
                $previouslyPaid = (float) ($tax->amount_paid ?? 0);
                $totalPaid = $previouslyPaid + $amountPaid;

                if ($totalPaid >= (float) $tax->amount_owed) {
                    $tax->status = 'paid';
                    $tax->amount_paid = $totalPaid;
                } else {
                    $tax->status = 'partial';
                    $tax->amount_paid = $totalPaid;
                }
                $tax->paid_at = Carbon::parse($paymentDate);

                // Append payment note to existing notes
                $paymentNote = "Marked as paid by {$userName} on " . Carbon::now()->format('Y-m-d H:i');
                $paymentNote .= " — " . number_format($amountPaid, 0) . " ISK";
                if ($previouslyPaid > 0) {
                    $paymentNote .= " (cumulative: " . number_format($totalPaid, 0) . " ISK)";
                }
                if ($notes) {
                    $paymentNote .= "\nReason: {$notes}";
                }
                $tax->notes = $tax->notes ? $tax->notes . "\n\n" . $paymentNote : $paymentNote;
                $tax->save();

                // Record the slice even though there is no wallet transaction
                // behind it. Without this the invoice's amount_paid would not
                // match the payments recorded against it and the reconciliation
                // check would flag every hand-marked invoice as a fault.
                PaymentAllocation::create([
                    'transaction_id' => null,
                    'credit_id' => null,
                    'mining_tax_id' => $tax->id,
                    'character_id' => (int) $tax->character_id,
                    'amount' => $amountPaid,
                    'source' => PaymentAllocation::SOURCE_MANUAL,
                    'allocated_by' => auth()->user()->main_character_id ?? auth()->id(),
                    'notes' => $paymentNote,
                    'allocated_at' => Carbon::now(),
                ]);

                // Mark any active tax codes as used to prevent wallet listener from double-processing
                if ($tax->status === 'paid') {
                    $tax->taxCodes()->where('status', 'active')->update([
                        'status' => 'used',
                        'used_at' => Carbon::now(),
                        'notes' => "Manually marked paid by {$userName}",
                    ]);
                }

                return ['tax' => $tax, 'total_paid' => $totalPaid];
            });

            Log::info('Tax manually marked as paid', [
                'tax_id' => $taxId,
                'amount' => $amountPaid,
                'total_paid' => $result['total_paid'],
                'status' => $result['tax']->status,
                'marked_by' => $userName,
                'notes' => $notes,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.marked_as_paid_success'),
            ]);

        } catch (\Exception $e) {
            Log::error('Error marking tax as paid: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.error_marking_paid'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a manual tax entry (ad-hoc payment record).
     * Used for mid-period payments, characters leaving corp, etc.
     * No tax code is generated — wallet listener will not interfere.
     */
    public function createManualEntry(Request $request)
    {
        try {
            $request->validate([
                'character_id' => 'required|integer',
                'amount' => 'required|numeric|min:1',
                'period_start' => 'required|date',
                'period_end' => 'required|date|after_or_equal:period_start',
                'notes' => 'nullable|string|max:1000',
            ]);

            $characterId = (int) $request->input('character_id');
            $amount = (float) $request->input('amount');
            $periodStart = Carbon::parse($request->input('period_start'));
            $periodEnd = Carbon::parse($request->input('period_end'));
            $notes = $request->input('notes');

            // Check for duplicate manual entry for same character and overlapping period
            $existing = MiningTax::where('character_id', $characterId)
                ->where('triggered_by', 'like', 'Manual Entry:%')
                ->where(function ($q) use ($periodStart, $periodEnd) {
                    $q->where(function ($inner) use ($periodStart, $periodEnd) {
                        $inner->where('period_start', '<=', $periodEnd)
                              ->where('period_end', '>=', $periodStart);
                    });
                })
                ->first();

            if ($existing) {
                return response()->json([
                    'status' => 'error',
                    'message' => trans('mining-manager::taxes.duplicate_manual_entry'),
                ], 422);
            }

            $user = auth()->user();
            $userName = $user->main_character->name ?? $user->name ?? 'Unknown';

            $paymentNote = "Manual entry by {$userName} on " . Carbon::now()->format('Y-m-d H:i');
            if ($notes) {
                $paymentNote .= "\n{$notes}";
            }

            $tax = MiningTax::create([
                'character_id' => $characterId,
                'month' => $periodStart->copy()->startOfMonth(),
                'period_type' => 'manual',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'amount_owed' => $amount,
                'amount_paid' => $amount,
                'status' => 'paid',
                'calculated_at' => Carbon::now(),
                'paid_at' => Carbon::now(),
                'due_date' => null,
                'triggered_by' => "Manual Entry: {$userName}",
                'notes' => $paymentNote,
            ]);

            // Born fully paid, so record the payment behind it for the same
            // reconciliation reason as markPaid().
            PaymentAllocation::create([
                'transaction_id' => null,
                'credit_id' => null,
                'mining_tax_id' => $tax->id,
                'character_id' => $characterId,
                'amount' => $amount,
                'source' => PaymentAllocation::SOURCE_MANUAL,
                'allocated_by' => $user->main_character_id ?? $user->id,
                'notes' => $paymentNote,
                'allocated_at' => Carbon::now(),
            ]);

            Log::info('Manual tax entry created', [
                'tax_id' => $tax->id,
                'character_id' => $characterId,
                'amount' => $amount,
                'period' => $periodStart->format('Y-m-d') . ' to ' . $periodEnd->format('Y-m-d'),
                'created_by' => $userName,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.manual_entry_success'),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->validator->errors()->first(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating manual tax entry: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.manual_entry_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send payment reminder
     */
    public function sendReminder(Request $request)
    {
        try {
            // Validate up front for parity with markPaid — otherwise a
            // non-integer tax_id falls through to MySQL with a soft cast.
            $validated = $request->validate([
                'tax_id' => 'required|integer|exists:mining_taxes,id',
            ]);

            $taxId = (int) $validated['tax_id'];
            $tax = MiningTax::findOrFail($taxId);

            $dueDate = $tax->due_date ? Carbon::parse($tax->due_date) : Carbon::now();
            $daysRemaining = (int) max(0, Carbon::now()->startOfDay()->diffInDays($dueDate->startOfDay(), false));

            // Ask for what is left, not what was originally charged, and say
            // how much of it their balance already met.
            $outstanding = round((float) $tax->amount_owed - (float) ($tax->amount_paid ?? 0), 2);
            $creditApplied = round((float) PaymentAllocation::where('mining_tax_id', $tax->id)
                ->where('source', PaymentAllocation::SOURCE_CREDIT)
                ->sum('amount'), 2);

            $result = $this->notificationService->sendTaxReminder(
                (int) $tax->character_id,
                $outstanding,
                $dueDate,
                $daysRemaining,
                ['credit_applied' => $creditApplied]
            );

            // Update reminder tracking on the tax record
            $tax->update([
                'last_reminder_sent' => Carbon::now(),
                'reminder_count' => ($tax->reminder_count ?? 0) + 1,
            ]);

            Log::info('Payment reminder sent', [
                'tax_id' => $taxId,
                'character_id' => $tax->character_id,
                'amount' => $tax->amount_owed,
                'requested_by' => auth()->user()->name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.reminder_sent'),
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending reminder: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.error_sending_reminder'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk mark taxes as paid
     */
    public function bulkMarkPaid(Request $request)
    {
        $request->validate([
            'tax_ids' => 'required|array|min:1',
            'tax_ids.*' => 'integer|min:1',
        ]);

        try {
            $taxIds = $request->input('tax_ids', []);
            $paymentDate = Carbon::parse($request->input('payment_date', Carbon::now()));

            $user = auth()->user();
            $actorId = $user->main_character_id ?? $user->id;
            $actorName = $user->main_character->name ?? $user->name ?? 'Unknown';

            // Was a single mass UPDATE setting amount_paid = amount_owed. That
            // left no record of what was paid, skipped the tax codes so they
            // stayed active, and could not be reconciled afterwards. Slower per
            // row, but each invoice now leaves the same trail as any other
            // payment.
            $updated = 0;

            DB::transaction(function () use ($taxIds, $paymentDate, $actorId, $actorName, &$updated) {
                $taxes = MiningTax::whereIn('id', $taxIds)->lockForUpdate()->get();

                foreach ($taxes as $tax) {
                    $owed = (float) $tax->amount_owed;
                    $alreadyPaid = (float) ($tax->amount_paid ?? 0);
                    $delta = round($owed - $alreadyPaid, 2);

                    if ($delta <= 0) {
                        continue;
                    }

                    $note = "Bulk marked as paid by {$actorName} on " . Carbon::now()->format('Y-m-d H:i');

                    $tax->update([
                        'status' => 'paid',
                        'paid_at' => $paymentDate,
                        'amount_paid' => $owed,
                        'notes' => $tax->notes ? $tax->notes . "\n\n" . $note : $note,
                    ]);

                    PaymentAllocation::create([
                        'transaction_id' => null,
                        'credit_id' => null,
                        'mining_tax_id' => $tax->id,
                        'character_id' => (int) $tax->character_id,
                        'amount' => $delta,
                        'source' => PaymentAllocation::SOURCE_MANUAL,
                        'allocated_by' => $actorId,
                        'notes' => $note,
                        'allocated_at' => Carbon::now(),
                    ]);

                    TaxCode::where('mining_tax_id', $tax->id)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'used',
                            'used_at' => Carbon::now(),
                            'notes' => "Bulk marked paid by {$actorName}",
                        ]);

                    $updated++;
                }
            });

            Log::info('Taxes bulk marked as paid', [
                'count' => $updated,
                'marked_by' => $actorName,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.bulk_marked_success', ['count' => $updated]),
            ]);

        } catch (\Exception $e) {
            Log::error('Error bulk marking taxes: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.error_bulk_marking'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk send reminders
     */
    public function bulkSendReminders(Request $request)
    {
        $request->validate([
            'tax_ids' => 'required|array|min:1',
            'tax_ids.*' => 'integer|min:1',
        ]);

        try {
            $taxIds = $request->input('tax_ids', []);

            $taxes = MiningTax::whereIn('id', $taxIds)->get();
            $sent = 0;
            $errors = 0;

            // Group by character to send one reminder per character
            $taxesByCharacter = $taxes->groupBy('character_id');

            foreach ($taxesByCharacter as $characterId => $characterTaxes) {
                try {
                    $totalOwed = round($characterTaxes->sum(
                        fn ($t) => max(0, (float) $t->amount_owed - (float) ($t->amount_paid ?? 0))
                    ), 2);

                    if ($totalOwed <= 0) {
                        continue;
                    }

                    $creditApplied = round((float) PaymentAllocation::whereIn('mining_tax_id', $characterTaxes->pluck('id'))
                        ->where('source', PaymentAllocation::SOURCE_CREDIT)
                        ->sum('amount'), 2);

                    // Find the earliest due date among these taxes
                    $earliestDueDate = $characterTaxes->min('due_date');
                    $dueDate = $earliestDueDate ? Carbon::parse($earliestDueDate) : Carbon::now();
                    $daysRemaining = (int) max(0, Carbon::now()->startOfDay()->diffInDays($dueDate->startOfDay(), false));

                    $this->notificationService->sendTaxReminder(
                        (int) $characterId,
                        (float) $totalOwed,
                        $dueDate,
                        $daysRemaining,
                        ['credit_applied' => $creditApplied]
                    );

                    // Update reminder tracking on each tax record
                    foreach ($characterTaxes as $tax) {
                        $tax->update([
                            'last_reminder_sent' => Carbon::now(),
                            'reminder_count' => ($tax->reminder_count ?? 0) + 1,
                        ]);
                    }

                    $sent++;
                } catch (\Exception $e) {
                    Log::warning("Failed to send reminder to character {$characterId}: " . $e->getMessage());
                    $errors++;
                }
            }

            Log::info('Bulk reminders sent', [
                'requested' => count($taxIds),
                'characters_notified' => $sent,
                'errors' => $errors,
                'requested_by' => auth()->user()->name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.bulk_reminders_sent', ['count' => $sent]),
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending bulk reminders: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.error_bulk_reminders'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display user's personal tax overview
     * Supports both accumulated (account) and individual (per-character) tax modes
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function myTaxes(Request $request)
    {
        $user = auth()->user();

        // Get all character IDs for this user
        $characterIds = $user->characters->pluck('character_id')->toArray();

        // Always use account-level tax
        $taxMethod = 'account';
        $mainCharacterId = $user->main_character_id ?? ($characterIds[0] ?? null);

        // Get account characters info
        $accountCharacters = collect();
        if (!empty($characterIds)) {
            $charInfos = $this->characterInfoService->getBatchCharacterInfo($characterIds);
            $accountCharacters = collect($charInfos);
        }
        $mainCharacterName = $accountCharacters[$mainCharacterId]['name'] ?? 'Unknown';

        // Get payment method from settings
        $paymentMethod = $this->settingsService->getPaymentSettings()['method'];

        if (empty($characterIds)) {
            // Even on the empty-state branch, resolve period metadata so
            // the view's period-aware labels render sensibly instead of
            // defaulting to "F Y".
            $periodHelperEmpty = app(TaxPeriodHelper::class);
            $periodTypeEmpty = $periodHelperEmpty->getConfiguredPeriodType();
            [$periodStartEmpty, $periodEndEmpty] = $periodHelperEmpty->getPeriodBounds(Carbon::now(), $periodTypeEmpty);

            return view('mining-manager::taxes.my-taxes', [
                'creditBalance' => 0.0,
                'creditRecords' => collect(),
                'taxHistory' => collect(),
                'summary' => [
                    'total_owed' => 0,
                    'overdue_amount' => 0,
                    'paid_this_month' => 0,
                    'unpaid_count' => 0,
                    'overdue_count' => 0,
                ],
                'currentTax' => null,
                'unpaidTaxes' => collect(),
                'periodType' => $periodTypeEmpty,
                'currentPeriodStart' => $periodStartEmpty,
                'currentPeriodEnd' => $periodEndEmpty,
                'currentPeriodLabel' => $periodHelperEmpty->formatPeriod($periodStartEmpty, $periodEndEmpty, $periodTypeEmpty),
                'totalTaxPaid' => 0,
                'onTimePayments' => 0,
                'latePayments' => 0,
                'status' => $request->input('status', 'all'),
                'month' => $request->input('month'),
                'paymentMethod' => $paymentMethod,
                'taxMethod' => $taxMethod,
                'accountCharacters' => $accountCharacters,
                'mainCharacterName' => $mainCharacterName,
                'currentMonthBreakdown' => [],
                'isAdmin' => $this->isAdmin(),
                'isDirector' => $this->isDirector(),
                'viewAll' => $this->isViewingAll(),
                'features' => $this->getFeatureFlags(),
                'walletDivisionName' => $this->settingsService->getWalletDivisionName(),
                'walletDivision' => $this->settingsService->getPaymentSettings()['wallet_division'] ?? 1,
                'corpName' => null,
            ]);
        }

        // Determine which character IDs to query for taxes
        if ($taxMethod === 'account') {
            // In accumulated mode, taxes are stored under the main character
            $taxCharacterIds = [$mainCharacterId];
        } else {
            // In individual mode, each character has own tax records
            $taxCharacterIds = $characterIds;
        }

        // Get taxes for user's characters
        $status = $request->input('status', 'all');
        $month = $request->input('month');

        $query = MiningTax::with(['character', 'taxCodes', 'taxInvoices'])
            ->whereIn('character_id', $taxCharacterIds);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($month) {
            $query->where('month', Carbon::parse($month)->format('Y-m-01'));
        }

        // Ordered on the period's own start date, not on the month it falls in.
        // Both halves of a fortnightly month carry the same `month` value, so
        // ordering by that alone left them tied and the tie broken by whatever
        // came out of the table first: "Jul 1-14" above "Jul 15-31" even though
        // the second half is the later period. COALESCE covers the monthly
        // records made before fortnightly periods existed, which have no
        // period_start.
        $taxHistory = $query->orderByRaw('COALESCE(period_start, month) DESC')
            ->orderBy('character_id')
            ->paginate(25);

        // Personal summary statistics (query all user characters for mining data)
        $totalOwed = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'unpaid')
            ->sum('amount_owed');

        $totalOverdue = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'overdue')
            ->sum('amount_owed');

        $paidThisMonth = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'paid')
            ->whereMonth('paid_at', Carbon::now()->month)
            ->sum('amount_paid');

        $unpaidCount = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'unpaid')
            ->count();

        $overdueCount = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'overdue')
            ->count();

        $summary = [
            'total_owed' => $totalOwed,
            'overdue_amount' => $totalOverdue,
            'paid_this_month' => $paidThisMonth,
            'unpaid_count' => $unpaidCount,
            'overdue_count' => $overdueCount,
        ];

        // Resolve the configured tax period (monthly / biweekly / weekly)
        // and compute bounds for the CURRENT active period. The prior code
        // assumed monthly and queried ->where('month', $firstOfMonth)->first(),
        // which returned a non-deterministic row whenever two bi-weekly or
        // four weekly tax rows existed for the same calendar month. Now we
        // target the precise period the user is currently accumulating tax
        // in via period_start / period_end.
        $periodHelper = app(TaxPeriodHelper::class);
        $periodType = $periodHelper->getConfiguredPeriodType();
        [$currentPeriodStart, $currentPeriodEnd] = $periodHelper->getPeriodBounds(Carbon::now(), $periodType);
        $currentPeriodLabel = $periodHelper->formatPeriod($currentPeriodStart, $currentPeriodEnd, $periodType);

        // Pull the tax row that matches the current active period (may not
        // exist yet — taxes are calculated after the period ends). Fall back
        // to the most-recent UNPAID tax so the user sees a meaningful balance
        // card when the current period hasn't been invoiced yet.
        $currentTax = MiningTax::with(['character', 'taxCodes'])
            ->whereIn('character_id', $taxCharacterIds)
            ->where('period_start', $currentPeriodStart->toDateString())
            ->first();

        if (!$currentTax) {
            $currentTax = MiningTax::with(['character', 'taxCodes'])
                ->whereIn('character_id', $taxCharacterIds)
                ->whereIn('status', ['unpaid', 'overdue'])
                ->orderBy('due_date', 'asc')
                ->first();
        }

        // All unpaid/overdue taxes — used by the view to stack multiple
        // period rows (bi-weekly with both halves unpaid, weekly with
        // multiple open weeks, etc.) rather than silently showing only one.
        $unpaidTaxes = MiningTax::with(['character', 'taxCodes'])
            ->whereIn('character_id', $taxCharacterIds)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->orderBy('due_date', 'asc')
            ->get();

        // Mining breakdown scoped to the current active period, so the
        // "Mining Breakdown - {period}" section matches the tax row it's
        // sitting next to. On bi-weekly this is half a calendar month;
        // on weekly it's a single ISO week.
        $currentMonthBreakdown = $this->getMyTaxBreakdownData(
            $characterIds,
            $currentPeriodStart,
            $currentPeriodEnd
        );

        // Get payment statistics (all time)
        $totalTaxPaid = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'paid')
            ->sum('amount_paid');

        $onTimePayments = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereNotNull('due_date')
            ->whereColumn('paid_at', '<=', 'due_date')
            ->count();

        $latePayments = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereNotNull('due_date')
            ->whereColumn('paid_at', '>', 'due_date')
            ->count();

        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();
        $features = $this->getFeatureFlags();

        // Wallet division info for payment instructions
        $walletDivisionName = $this->settingsService->getWalletDivisionName();
        $paymentSettings = $this->settingsService->getPaymentSettings();
        $walletDivision = $paymentSettings['wallet_division'] ?? 1;

        // Get corporation name for payment instructions
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $corpName = null;
        if ($moonOwnerCorpId) {
            $corpName = \Seat\Eveapi\Models\Corporation\CorporationInfo::where('corporation_id', $moonOwnerCorpId)->value('name');
        }

        // Money we are holding for this player from an earlier overpayment.
        // Alt-aware, because the surplus can sit on whichever character sent
        // the ISK while the invoices belong to their main.
        $creditBalance = PaymentCredit::balanceFor($characterIds);
        $creditRecords = $creditBalance > 0
            ? PaymentCredit::whereIn('character_id', $characterIds)
                ->where('remaining', '>', 0)
                ->orderBy('created_at')
                ->get()
            : collect();

        return view('mining-manager::taxes.my-taxes', compact(
            'creditBalance',
            'creditRecords',
            'taxHistory',
            'summary',
            'currentTax',
            'unpaidTaxes',
            'periodType',
            'currentPeriodStart',
            'currentPeriodEnd',
            'currentPeriodLabel',
            'totalTaxPaid',
            'onTimePayments',
            'latePayments',
            'status',
            'month',
            'paymentMethod',
            'taxMethod',
            'accountCharacters',
            'mainCharacterName',
            'currentMonthBreakdown',
            'isAdmin',
            'isDirector',
            'viewAll',
            'features',
            'walletDivisionName',
            'walletDivision',
            'corpName'
        ));
    }

    /**
     * Get mining breakdown data for a user's characters across a date range.
     *
     * Accepts precise bounds so callers can pass any tax period — monthly,
     * bi-weekly, or weekly — without leaking calendar-month assumptions
     * into the aggregation. (Previously only took a month; bi-weekly
     * callers ended up aggregating the whole calendar month regardless
     * of the actual period being displayed.)
     *
     * @param array $characterIds
     * @param Carbon $startDate Inclusive start of the period
     * @param Carbon $endDate   Inclusive end of the period
     * @return array Grouped by character_id
     */
    protected function getMyTaxBreakdownData(array $characterIds, Carbon $startDate, Carbon $endDate): array
    {
        $breakdowns = [];

        foreach ($characterIds as $characterId) {
            try {
                $breakdown = $this->taxService->getTaxBreakdown($characterId, $startDate, $endDate);
                if (!empty($breakdown['breakdown'])) {
                    $charInfo = $this->characterInfoService->getCharacterInfo($characterId);
                    $breakdowns[$characterId] = [
                        'character_name' => $charInfo['name'] ?? "Character {$characterId}",
                        'character_id' => $characterId,
                        'breakdown' => $breakdown['breakdown'],
                        'total_value' => $breakdown['total_value'],
                        'total_tax' => $breakdown['total_tax'],
                        'event_discount_total' => $breakdown['event_discount_total'] ?? 0,
                    ];
                }
            } catch (\Exception $e) {
                Log::debug("Mining Manager: Error getting breakdown for character {$characterId}: " . $e->getMessage());
            }
        }

        return $breakdowns;
    }

    /**
     * AJAX endpoint: Get mining breakdown for a given month
     *
     * @param Request $request
     * @param string|null $month
     * @return \Illuminate\Http\JsonResponse
     */
    public function myTaxBreakdown(Request $request, $month = null)
    {
        $user = auth()->user();
        $characterIds = $user->characters->pluck('character_id')->toArray();

        if (empty($characterIds)) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        // The {$month} route parameter continues to accept a YYYY-MM string
        // for backward compatibility — we derive the configured period's
        // bounds for that month. If omitted, we return the current active
        // period (not the whole calendar month) so bi-weekly/weekly calls
        // get a meaningful slice.
        $periodHelper = app(TaxPeriodHelper::class);
        $periodType = $periodHelper->getConfiguredPeriodType();

        if ($month) {
            $anchor = Carbon::parse($month);
        } else {
            $anchor = Carbon::now();
        }

        [$periodStart, $periodEnd] = $periodHelper->getPeriodBounds($anchor, $periodType);
        $breakdowns = $this->getMyTaxBreakdownData($characterIds, $periodStart, $periodEnd);

        return response()->json([
            'status' => 'success',
            'data' => $breakdowns,
            'period_type' => $periodType,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'period_label' => $periodHelper->formatPeriod($periodStart, $periodEnd, $periodType),
            // Legacy field kept for any callers still reading this key.
            'month' => $anchor->format('F Y'),
        ]);
    }

    /**
     * Display tax codes management page
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function codes(Request $request)
    {
        // Check if tax codes feature is enabled
        $features = $this->getFeatureFlags();
        if (!$features['tax_codes']) {
            return redirect()->route('mining-manager.taxes.index')
                ->with('warning', trans('mining-manager::taxes.feature_disabled'));
        }

        $status = $request->input('status', 'active');
        $search = $request->input('search');
        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();

        // Build query
        $query = TaxCode::with(['character', 'miningTax']);

        // Scope: only show own codes unless viewing all
        $taxCharacterIds = [];
        if (!$viewAll) {
            $taxCharacterIds = $this->getUserTaxCharacterIds();
            $query->whereIn('character_id', $taxCharacterIds);
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhereHas('miningTax.character', function($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $taxCodes = $query->orderBy('created_at', 'desc')
            ->get();

        // Enrich with character names
        $taxCodes = $this->enrichWithCharacterInfo(collect($taxCodes), $this->characterInfoService);

        // Summary statistics (scoped unless viewing all)
        $summaryBase = TaxCode::query();
        if (!$viewAll && !empty($taxCharacterIds)) {
            $summaryBase = TaxCode::whereIn('character_id', $taxCharacterIds);
        }
        $activeCount = (clone $summaryBase)->where('status', 'active')->count();
        $usedCount = (clone $summaryBase)->where('status', 'used')->count();
        $expiredCount = (clone $summaryBase)->where('status', 'expired')->count();

        $summary = [
            'active_count' => $activeCount,
            'used_count' => $usedCount,
            'expired_count' => $expiredCount,
        ];

        $features = $this->getFeatureFlags();

        // Get tax code prefix from settings for display
        $taxCodePrefix = $this->settingsService->getSetting('tax_rates.tax_code_prefix', 'TAX-');

        return view('mining-manager::taxes.codes', compact(
            'taxCodes',
            'summary',
            'status',
            'search',
            'taxCodePrefix',
            'isAdmin',
            'isDirector',
            'viewAll',
            'features'
        ));
    }

    /**
     * Generate tax codes
     */
    public function generateCodes(Request $request)
    {
        // Set corporation context for settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $month = $request->input('month');
            $monthDate = $month ? Carbon::parse($month)->startOfMonth() : Carbon::now()->startOfMonth();

            // Get unpaid tax IDs for this month
            $taxIds = MiningTax::where('month', $monthDate->format('Y-m-01'))
                ->whereIn('status', ['unpaid', 'overdue'])
                ->whereDoesntHave('taxCodes', function($q) {
                    $q->where('status', 'active');
                })
                ->pluck('id')
                ->toArray();

            if (empty($taxIds)) {
                return response()->json([
                    'status' => 'warning',
                    'message' => trans('mining-manager::taxes.no_unpaid_taxes_for_codes'),
                    'results' => ['generated' => 0, 'errors' => []],
                ]);
            }

            $results = $this->codeService->generateBulkTaxCodes($taxIds);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.codes_generated'),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Code generation error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.code_generation_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process tax calculation (POST endpoint)
     * This is an alias for calculate() to handle POST requests
     */
    public function processCalculation(Request $request)
    {
        return $this->calculate($request);
    }

    /**
     * Live tracking endpoint - returns current mining tracking data as JSON
     * Accepts ?mode=archived (default) or ?mode=live
     * Live mode checks price cache freshness first
     */
    public function liveTracking(Request $request)
    {
        $data = $this->getLiveTrackingData();

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Regenerate payments for a specific month.
     * Always recalculates daily summaries with current prices/rates,
     * then calculates taxes and regenerates payment codes.
     */
    public function regeneratePayments(Request $request)
    {
        // Set corporation context for settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $corporationId = $request->input('corporation_id') ?: $moonOwnerCorpId;
        $this->setCorporationContext($corporationId);

        try {
            $month = $request->input('month');

            if (!$month) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Month parameter is required',
                ], 400);
            }

            $periodHelper = app(TaxPeriodHelper::class);
            $periodType = $periodHelper->getConfiguredPeriodType();
            $monthDate = Carbon::parse($month)->startOfMonth();
            $monthLabel = $monthDate->format('F Y');

            // Get all periods in the month
            $periods = $periodHelper->getPeriodsInMonth($monthDate, $periodType);
            $lastPeriodEnd = end($periods)[1];
            $firstPeriodStart = $periods[0][0];

            // Warn if regenerating for incomplete period
            if (!$periodHelper->isPeriodComplete($lastPeriodEnd) && !$request->input('confirm_incomplete')) {
                $isFuture = $firstPeriodStart->gt(Carbon::now());
                return response()->json([
                    'status' => 'incomplete_month',
                    'message' => $isFuture
                        ? "{$monthLabel} hasn't started yet."
                        : "Not all periods in {$monthLabel} have ended yet. Regenerated codes will be based on incomplete data.",
                    'is_future' => $isFuture,
                ], 200);
            }

            $user = auth()->user();
            $triggeredBy = $user
                ? 'Regenerate: ' . ($user->main_character->name ?? 'User #' . $user->id)
                : 'Regenerate: Unknown';

            Log::info("Mining Manager: Regenerate codes triggered by {$triggeredBy} for {$monthLabel} ({$periodType}, " . count($periods) . " periods)");

            // Regenerate all daily summaries with current prices and tax rates
            $regenerated = $this->taxService->regenerateMonthSummaries($monthDate, $corporationId);
            Log::info("Regenerate codes: regenerated {$regenerated} daily summaries for {$monthLabel}");

            // Recalculate taxes for each period in the month
            $totalCount = 0;
            $totalAmount = 0;
            $allErrors = [];

            foreach ($periods as [$startDate, $endDate]) {
                $results = $this->taxService->calculateTaxes($startDate, $endDate, $periodType, true, $triggeredBy);
                $totalCount += $results['count'];
                $totalAmount += $results['total'];
                $allErrors = array_merge($allErrors, $results['errors'] ?? []);
            }

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.payments_regenerated'),
                'results' => [
                    'method' => "regenerate ({$periodType})",
                    'count' => $totalCount,
                    'total' => $totalAmount,
                    'errors' => $allErrors,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Payment regeneration error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.regeneration_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify payments — dispatches based on action parameter.
     * Handles: sync (re-fetch wallet data), auto_match (auto-match transactions), verify (verify specific transactions)
     */
    public function verifyPayments(Request $request)
    {
        $action = $request->input('action');

        // Dispatch sync and auto-match to their dedicated handlers
        if ($action === 'sync') {
            return $this->verifyPayment($request, null);
        }

        if ($action === 'auto_match') {
            return $this->autoMatchPayments($request);
        }

        // For transaction verification, validate the IDs
        $request->validate([
            'transaction_ids' => 'required|array|min:1',
            'transaction_ids.*' => 'integer|min:1',
        ]);

        // Set corporation context for settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $transactionIds = $request->input('transaction_ids', []);

            $results = [
                'verified' => 0,
                'failed' => 0,
                'errors' => [],
            ];

            foreach ($transactionIds as $transactionId) {
                try {
                    $outcome = $this->walletService->matchTransaction((int) $transactionId, ['apply' => true]);

                    if ($outcome['applied']) {
                        $results['verified']++;
                        continue;
                    }

                    $results['failed']++;
                    $results['errors'][] = [
                        'transaction_id' => $transactionId,
                        'error' => $this->describeMatchFailure($outcome['reason']),
                    ];
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'transaction_id' => $transactionId,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // Reporting a 200 with "0 verified" for a batch where nothing
            // worked is how this page came to look like the buttons did
            // nothing. Anything that failed outright answers as a failure.
            if ($results['verified'] === 0 && $results['failed'] > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => $results['errors'][0]['error'],
                    'results' => $results,
                ], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.payments_verified', ['count' => $results['verified']]),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Batch payment verification error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.verification_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Turn a match failure into something a director can act on.
     */
    private function describeMatchFailure(?string $reason): string
    {
        $key = 'mining-manager::taxes.match_failed_' . ($reason ?: 'unknown');
        $message = trans($key);

        // trans() hands back the key itself when there is no translation, which
        // would put a raw lang path in a toast.
        return $message === $key
            ? trans('mining-manager::taxes.match_failed_unknown')
            : $message;
    }

    /**
     * Assign a wallet payment to an invoice by hand.
     *
     * This is the answer to a member who transferred the ISK but left the tax
     * code out of the reason field. Nothing can match it automatically, so a
     * director points it at the right invoice. Anything left over after that
     * invoice is settled rolls onto their next-oldest unpaid one, and a final
     * surplus is held as credit.
     */
    public function assignPayment(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|integer|exists:corporation_wallet_journals,id',
            // Optional: without it the payment goes to the player's account
            // balance instead of a named invoice. That is the only thing a
            // director can do with a codeless transfer from somebody who has
            // nothing outstanding, and it used to be a dead end.
            'tax_id' => 'nullable|integer|exists:mining_taxes,id',
            'cascade' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $user = auth()->user();
            $actorId = $user->main_character_id ?? $user->id;
            $actorName = $user->main_character->name ?? $user->name ?? 'Unknown';

            $notes = trim("Assigned by {$actorName}" . (!empty($validated['notes']) ? ": {$validated['notes']}" : ''));

            if (empty($validated['tax_id'])) {
                $result = $this->walletService->manualCredit(
                    (int) $validated['transaction_id'],
                    [
                        'allocated_by' => $actorId,
                        'notes' => $notes,
                    ]
                );

                if (!$result['success']) {
                    return response()->json([
                        'status' => 'error',
                        'message' => $this->describeMatchFailure($result['reason']),
                    ], 422);
                }

                Log::info('Mining Manager: wallet payment banked to account balance by hand', [
                    'transaction_id' => (int) $validated['transaction_id'],
                    'invoices' => count($result['allocations']),
                    'banked' => $result['credited'],
                    'assigned_by' => $actorName,
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => $this->describeAllocation($result),
                    'result' => $result,
                ]);
            }

            $result = $this->walletService->manualMatch(
                (int) $validated['transaction_id'],
                (int) $validated['tax_id'],
                [
                    'allocated_by' => $actorId,
                    'notes' => $notes,
                    'cascade' => $request->has('cascade') ? (bool) $validated['cascade'] : null,
                ]
            );

            if (!$result['success']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $this->describeMatchFailure($result['reason']),
                ], 422);
            }

            Log::info('Mining Manager: wallet payment assigned by hand', [
                'transaction_id' => (int) $validated['transaction_id'],
                'tax_id' => (int) $validated['tax_id'],
                'invoices' => count($result['allocations']),
                'surplus_held' => $result['credited'],
                'assigned_by' => $actorName,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => $this->describeAllocation($result),
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Error assigning wallet payment: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.assign_payment_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Undo an assignment and put the payment back in the queue.
     */
    public function unassignPayment(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|integer|exists:corporation_wallet_journals,id',
        ]);

        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $user = auth()->user();
            $actorId = $user->main_character_id ?? $user->id;

            $result = $this->walletService->unassign((int) $validated['transaction_id'], $actorId);

            if (!$result['reversed']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $result['reason'] === 'credit_partially_spent'
                        ? trans('mining-manager::taxes.unassign_credit_spent')
                        : trans('mining-manager::taxes.unassign_failed'),
                ], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.unassign_success', [
                    'count' => count($result['invoices']),
                ]),
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Error unassigning wallet payment: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.unassign_failed'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Plain-language summary of where an assigned payment ended up.
     */
    private function describeAllocation(array $result): string
    {
        $count = count($result['allocations']);

        // Nothing settled means the whole payment went to the balance. Saying
        // "assigned to invoice" here would name something that did not happen.
        if ($count === 0) {
            return trans('mining-manager::taxes.assign_payment_balance_only', [
                'amount' => number_format($result['credited'], 0),
            ]);
        }

        if ($count > 1) {
            $message = trans('mining-manager::taxes.assign_payment_spread', ['count' => $count]);
        } else {
            $message = trans('mining-manager::taxes.assign_payment_success');
        }

        if ($result['credited'] > 0) {
            $message .= ' ' . trans('mining-manager::taxes.assign_payment_credit', [
                'amount' => number_format($result['credited'], 0),
            ]);
        }

        return $message;
    }

    /**
     * Dismiss/ignore a wallet transaction so it no longer appears in pending
     */
    public function dismissTransaction(Request $request)
    {
        try {
            // Validate up front. Accepting any integer — including ones
            // that don't correspond to a real wallet journal entry — lets an
            // admin (or a compromised admin token) stuff the dismissal table
            // with bogus IDs. The existence check confirms the row is real
            // before we permanently dismiss it.
            $validated = $request->validate([
                'transaction_id' => 'required|integer|exists:corporation_wallet_journals,id',
            ]);

            $transactionId = (int) $validated['transaction_id'];

            $user = auth()->user();
            $dismissedBy = $user->main_character_id ?? $user->id;

            DB::table('mining_manager_dismissed_transactions')->updateOrInsert(
                ['transaction_id' => $transactionId],
                [
                    'dismissed_by' => $dismissedBy,
                    'dismissed_at' => Carbon::now(),
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Transaction dismissed',
            ]);

        } catch (\Exception $e) {
            Log::error('Dismiss transaction error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to dismiss transaction: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show tax details for a specific tax record
     */
    public function details(Request $request, $taxId)
    {
        $tax = MiningTax::with(['character', 'affiliation', 'taxCodes', 'taxInvoices'])->findOrFail($taxId);

        // Enrich with character/corporation names
        $this->enrichModelWithCharacterInfo($tax, $this->characterInfoService);

        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();

        // Ownership check: members and directors (not viewing all) can only see their own
        if (!$viewAll) {
            $taxCharacterIds = $this->getUserTaxCharacterIds();
            if (!in_array($tax->character_id, $taxCharacterIds)) {
                abort(403, trans('mining-manager::taxes.no_permission_view_tax'));
            }
        }

        // Always use accumulated (per-account) mode
        $taxCalculationMethod = 'accumulated';

        $paymentHistory = $this->getPaymentHistory((int) $tax->id);

        // How much of this invoice was settled from money we were already
        // holding, rather than from a payment the member made for it. Without
        // saying so, an invoice that arrives part-paid looks like a mistake.
        $creditApplied = (float) $paymentHistory
            ->where('source', PaymentAllocation::SOURCE_CREDIT)
            ->sum('amount');

        // Get mining breakdown for the tax's period (or fall back to month)
        $startDate = $tax->period_start ? Carbon::parse($tax->period_start) : Carbon::parse($tax->month)->startOfMonth();
        $endDate = $tax->period_end ? Carbon::parse($tax->period_end) : Carbon::parse($tax->month)->endOfMonth();

        $miningBreakdown = [];
        $miningTotal = 0;

        if ($taxCalculationMethod === 'accumulated') {
            // In accumulated mode, get breakdown for all alts in the account
            // Resolve the tax OWNER's characters (not the viewer's) for correct breakdown
            $characterIds = [$tax->character_id];
            try {
                $ownerUserId = DB::table('refresh_tokens')
                    ->where('character_id', $tax->character_id)
                    ->value('user_id');

                if ($ownerUserId) {
                    $ownerUser = \Seat\Web\Models\User::find($ownerUserId);
                    if ($ownerUser) {
                        $characterIds = $ownerUser->characters->pluck('character_id')->toArray();
                    }
                }
            } catch (\Exception $e) {
                Log::debug("Mining Manager: Could not resolve tax owner's characters: " . $e->getMessage());
            }

            if (empty($characterIds)) {
                $characterIds = [$tax->character_id];
            }

            foreach ($characterIds as $charId) {
                try {
                    $breakdown = $this->taxService->getTaxBreakdown($charId, $startDate, $endDate);
                    if (!empty($breakdown['breakdown'])) {
                        $charInfo = $this->characterInfoService->getCharacterInfo($charId);
                        foreach ($breakdown['breakdown'] as $ore) {
                            $miningBreakdown[] = [
                                'character_name' => $charInfo['name'] ?? "Character {$charId}",
                                'type_name' => $ore['name'],
                                'category' => $ore['category'],
                                'rarity' => $ore['rarity'],
                                'quantity' => $ore['quantity'],
                                'total_value' => $ore['value'],
                                'tax_rate' => $ore['effective_rate'],
                                'tax_amount' => $ore['tax'],
                                'event_modifier' => $ore['event_modifier'],
                            ];
                        }
                        $miningTotal += $breakdown['total_value'];
                    }
                } catch (\Exception $e) {
                    Log::debug("Mining Manager: Error getting breakdown for char {$charId}: " . $e->getMessage());
                }
            }
        }

        $features = $this->getFeatureFlags();

        return view('mining-manager::taxes.details', compact(
            'tax',
            'miningBreakdown',
            'miningTotal',
            'taxCalculationMethod',
            'isAdmin',
            'isDirector',
            'viewAll',
            'features',
            'paymentHistory',
            'creditApplied'
        ));
    }

    /**
     * Account balances: money held from overpayments and paying ahead.
     *
     * One page, two audiences, the way the rest of the tax section already
     * works. A director sees everyone who is holding a balance and where it
     * came from; a member sees their own, alt-aware, because the surplus sits
     * on whichever character sent the ISK while the invoices belong to their
     * main.
     */
    /**
     * Give held balance back to a member.
     *
     * Reduces the balance now so what they are owed is right immediately, and
     * leaves the row pending until the ISK is seen leaving the corporation
     * wallet. Nothing here sends money: EVE has no API for that, so a director
     * makes the transfer in game and this records it was agreed.
     */
    public function refundBalance(Request $request)
    {
        $validated = $request->validate([
            'credit_id' => 'required|integer|exists:mining_manager_payment_credits,id',
            // Absent means all of it, which is what somebody leaving the corp
            // wants and saves retyping a figure they would only get wrong.
            'amount' => 'nullable|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);

        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $user = auth()->user();
            $actorId = $user->main_character_id ?? $user->id;
            $actorName = $user->main_character->name ?? $user->name ?? 'Unknown';

            $result = app(\MiningManager\Services\Tax\RefundService::class)->record(
                (int) $validated['credit_id'],
                isset($validated['amount']) ? (float) $validated['amount'] : null,
                $validated['reason'],
                $actorId
            );

            if (!$result['success']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $this->describeRefundFailure($result['reason']),
                ], 422);
            }

            Log::info('Mining Manager: balance refunded by hand', [
                'credit_id' => (int) $validated['credit_id'],
                'amount' => $result['refunded'],
                'refunded_by' => $actorName,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.refund_recorded', [
                    'amount' => number_format($result['refunded'], 0),
                ]),
            ]);

        } catch (\Exception $e) {
            Log::error('Error refunding balance: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.refund_error'),
            ], 500);
        }
    }

    /**
     * Record that a refund was paid, when no transfer will ever match it.
     *
     * The reconciler only recognises money leaving the corporation wallet with
     * the agreed keyword in the reason. That is the right default and it will
     * miss things: a keyword left off, a contract, a payment from somewhere the
     * plugin cannot see. Without a way to close those by hand they stay pending
     * forever and the outstanding figure stops being worth reading.
     *
     * The note is required. A confirmed refund with no transaction behind it is
     * only as good as the explanation attached to it.
     */
    public function markRefundSent(Request $request, $refundId)
    {
        $validated = $request->validate([
            'note' => 'required|string|max:255',
        ]);

        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $user = auth()->user();
            $actorId = $user->main_character_id ?? $user->id;
            $actorName = $user->main_character->name ?? $user->name ?? 'Unknown';

            $result = app(\MiningManager\Services\Tax\RefundService::class)->markSent(
                (int) $refundId,
                $validated['note'],
                $actorId
            );

            if (!$result['success']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $this->describeRefundFailure($result['reason']),
                ], 422);
            }

            Log::info('Mining Manager: refund marked as sent by hand', [
                'refund_id' => (int) $refundId,
                'confirmed_by' => $actorName,
                'note' => $validated['note'],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.refund_marked_sent'),
            ]);

        } catch (\Exception $e) {
            Log::error('Error marking a refund as sent: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.refund_error'),
            ], 500);
        }
    }

    /**
     * Withdraw a hand confirmation and put the refund back on the pending list.
     *
     * Refund rows for one person look alike, so confirming the wrong one is an
     * ordinary slip. Only hand confirmations can be withdrawn; one matched to a
     * real transaction is left alone.
     */
    public function reopenRefund(Request $request, $refundId)
    {
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $user = auth()->user();
            $actorId = $user->main_character_id ?? $user->id;
            $actorName = $user->main_character->name ?? $user->name ?? 'Unknown';

            $result = app(\MiningManager\Services\Tax\RefundService::class)->revertToPending(
                (int) $refundId,
                $actorId
            );

            if (!$result['success']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $this->describeRefundFailure($result['reason']),
                ], 422);
            }

            Log::info('Mining Manager: refund reopened', [
                'refund_id' => (int) $refundId,
                'reopened_by' => $actorName,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.refund_reopened'),
            ]);

        } catch (\Exception $e) {
            Log::error('Error reopening a refund: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.refund_error'),
            ], 500);
        }
    }

    /**
     * Turn a refund failure reason into something a director can act on.
     */
    private function describeRefundFailure(?string $reason): string
    {
        $key = 'mining-manager::taxes.refund_failed_' . ($reason ?: 'unknown');
        $message = trans($key);

        return $message === $key
            ? trans('mining-manager::taxes.refund_failed_unknown')
            : $message;
    }

    public function balances(Request $request)
    {
        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();
        $canSeeAll = $isAdmin || $isDirector;

        $corporationId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($corporationId ? (int) $corporationId : null);

        // A member only ever sees money held against their own characters.
        // Scoping on the way in rather than filtering a full list afterwards,
        // so a bug here cannot leak someone else's balance.
        $scopeCharacterIds = $canSeeAll ? null : $this->getUserCharacterIds();

        $creditsQuery = PaymentCredit::with('character')->orderByDesc('created_at');

        if ($scopeCharacterIds !== null) {
            if (empty($scopeCharacterIds)) {
                $creditsQuery->whereRaw('1 = 0');
            } else {
                $creditsQuery->whereIn('character_id', $scopeCharacterIds);
            }
        }

        $credits = $creditsQuery->get();
        $openCredits = $credits->where('remaining', '>', 0);

        // Refunds, scoped exactly as the balances above are. Keyed by credit so
        // a row can show what has been handed back off it alongside what it was
        // spent on.
        $refundsQuery = \MiningManager\Models\PaymentRefund::orderByDesc('created_at');

        if ($scopeCharacterIds !== null) {
            if (empty($scopeCharacterIds)) {
                $refundsQuery->whereRaw('1 = 0');
            } else {
                $refundsQuery->whereIn('character_id', $scopeCharacterIds);
            }
        }

        $refunds = $refundsQuery->get()->groupBy('credit_id');

        // What each balance has already paid for. Keyed by credit so a row can
        // show its own drawdowns rather than one undifferentiated list.
        $drawdowns = collect();

        if ($credits->isNotEmpty()) {
            $drawdowns = PaymentAllocation::with('tax.character')
                ->whereIn('credit_id', $credits->pluck('id'))
                ->orderByDesc('allocated_at')
                ->get()
                ->groupBy('credit_id');
        }

        // A credit records only the surplus, so on its own it cannot answer the
        // question a member actually asks: "I sent 1.2b, where did it go?".
        // Pair each one with what the SAME payment settled outright, and the
        // two add back up to the transfer. Read from the allocation rows rather
        // than the wallet journal so the figures reconcile against each other
        // by construction; a journal lookup could disagree with our own ledger
        // and there would be no way to tell which was right.
        $settledByPayment = collect();
        $transactionIds = $credits->pluck('transaction_id')->filter()->unique();

        if ($transactionIds->isNotEmpty()) {
            $settledByPayment = PaymentAllocation::whereIn('transaction_id', $transactionIds)
                ->whereNull('credit_id')
                ->selectRaw('transaction_id, SUM(amount) as total')
                ->groupBy('transaction_id')
                ->pluck('total', 'transaction_id');
        }

        foreach ($credits as $credit) {
            $settled = (float) ($settledByPayment[$credit->transaction_id] ?? 0);

            $credit->settled_on_arrival = $settled;
            $credit->payment_total = round($settled + (float) $credit->amount, 2);
        }

        $stats = [
            'total_held' => (float) $openCredits->sum('remaining'),
            'holders' => $openCredits->pluck('character_id')->unique()->count(),
            // Balance spent on later invoices. Deliberately NOT the same as the
            // money a payment settled the moment it arrived, which never became
            // balance at all.
            'total_drawn' => (float) $drawdowns->flatten()->sum('amount'),
        ];

        return view('mining-manager::taxes.balances', [
            'credits' => $credits,
            'openCredits' => $openCredits,
            'drawdowns' => $drawdowns,
            'stats' => $stats,
            'isAdmin' => $isAdmin,
            'isDirector' => $isDirector,
            'viewAll' => $viewAll,
            'canSeeAll' => $canSeeAll,
            'features' => $this->getFeatureFlags(),
            'upfrontKeyword' => $this->walletService->getUpfrontKeyword(),
            // Money the corporation has agreed to hand back and, as far as the
            // wallet shows, has not. Worth surfacing: it is the one number here
            // that represents a promise rather than a fact.
            'refunds' => $refunds,
            // Shown in the refund dialog so a director knows what to type into
            // the transfer. Without it in the reason the refund cannot tell
            // itself apart from an SRP payout and will not confirm.
            'refundKeyword' => app(\MiningManager\Services\Tax\RefundService::class)->keyword(),
            // The refund dialog says where the ISK may be sent, and that
            // depends on the same setting tax and upfront payments read.
            'refundAcceptsAlts' => (bool) ($this->settingsService->getPaymentSettings()['accept_alt_characters'] ?? true),
            'pendingRefundTotal' => (float) $refunds->flatten()
                ->where('status', \MiningManager\Models\PaymentRefund::STATUS_PENDING)
                ->sum('amount'),
        ]);
    }

    /**
     * Every payment recorded against an invoice, newest first.
     *
     * mining_taxes.transaction_id only ever holds the most recent payment, so
     * an invoice settled in instalments used to show just the last one. The
     * allocation rows carry the full picture.
     *
     * @return \Illuminate\Support\Collection
     */
    private function getPaymentHistory(int $taxId)
    {
        return PaymentAllocation::where('mining_tax_id', $taxId)
            ->orderByDesc('allocated_at')
            ->orderByDesc('id')
            ->get()
            ->map(function ($allocation) {
                $label = match ($allocation->source) {
                    PaymentAllocation::SOURCE_AUTO => trans('mining-manager::taxes.payment_source_auto'),
                    PaymentAllocation::SOURCE_MANUAL => trans('mining-manager::taxes.payment_source_manual'),
                    PaymentAllocation::SOURCE_CASCADE => trans('mining-manager::taxes.payment_source_cascade'),
                    PaymentAllocation::SOURCE_CREDIT => trans('mining-manager::taxes.payment_source_credit'),
                    default => $allocation->source,
                };

                $allocation->source_label = $label;

                return $allocation;
            });
    }

    /**
     * Store a new tax code
     */
    public function storeCode(Request $request)
    {
        // Set corporation context for settings (for code length, prefix, etc.)
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $validated = $request->validate([
                'mining_tax_id' => 'required|exists:mining_taxes,id',
                'code' => 'nullable|string|unique:mining_tax_codes,code',
                'expires_at' => 'nullable|date',
            ]);

            // Get tax to determine character_id
            $tax = MiningTax::findOrFail($validated['mining_tax_id']);

            $taxCode = new TaxCode();
            $taxCode->mining_tax_id = $validated['mining_tax_id'];
            $taxCode->character_id = $tax->character_id;
            $taxCode->code = $validated['code'] ?? $this->codeService->generateUniqueCode();
            $taxCode->status = 'active';
            $taxCode->generated_at = Carbon::now();
            $taxCode->expires_at = isset($validated['expires_at'])
                ? Carbon::parse($validated['expires_at'])
                : Carbon::now()->addDays(
                    $this->settingsService->getSetting('exemptions.grace_period_days', 7) +
                    $this->settingsService->getSetting('tax_rates.tax_code_expiration_buffer', 30)
                );
            $taxCode->save();

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.code_created'),
                'code' => $taxCode,
            ]);

        } catch (\Exception $e) {
            Log::error('Tax code creation error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.code_creation_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a tax code
     */
    /**
     * Close off a code whose invoice was settled some other way.
     *
     * A code is normally marked used by the payment that quotes it. A payment
     * assigned by hand does not quote it, so the invoice goes paid while its
     * code sits there active and eventually expires. The page then shows an
     * expired code against a settled invoice, with no way to say what actually
     * happened, and no way to remove it either.
     *
     * Only for codes whose invoice is genuinely settled. Marking a code used
     * while money is still owed would take away the reference the member is
     * meant to quote, which is the one thing that must not happen by accident.
     */
    public function markCodeUsed(Request $request, $id)
    {
        try {
            $taxCode = TaxCode::with('miningTax')->findOrFail($id);

            if ($taxCode->status === 'used') {
                return response()->json([
                    'status' => 'error',
                    'message' => trans('mining-manager::taxes.code_already_used'),
                ], 422);
            }

            $tax = $taxCode->miningTax;

            if (!$tax) {
                return response()->json([
                    'status' => 'error',
                    'message' => trans('mining-manager::taxes.code_no_invoice'),
                ], 422);
            }

            // Settled means paid, or paid enough that the remainder is rounding.
            // Reading the figures rather than trusting the status alone, because
            // a hand-assigned payment is exactly the case where the two can
            // disagree.
            $outstanding = round((float) $tax->amount_owed - (float) ($tax->amount_paid ?? 0), 2);

            if ($tax->status !== 'paid' && $outstanding > 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => trans('mining-manager::taxes.code_invoice_not_settled', [
                        'amount' => number_format($outstanding, 0),
                    ]),
                ], 422);
            }

            $taxCode->update([
                'status' => 'used',
                'used_at' => $tax->paid_at ?? Carbon::now(),
                // Whatever settled the invoice, if the invoice recorded it.
                'transaction_id' => $taxCode->transaction_id ?? $tax->transaction_id,
            ]);

            $user = auth()->user();
            $actorName = $user->main_character->name ?? $user->name ?? 'Unknown';

            Log::info('Mining Manager: tax code marked used by hand', [
                'tax_code_id' => (int) $taxCode->id,
                'code' => $taxCode->code,
                'mining_tax_id' => (int) $tax->id,
                'marked_by' => $actorName,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.code_marked_used'),
            ]);

        } catch (\Exception $e) {
            Log::error('Tax code mark-used error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.code_mark_used_error'),
            ], 500);
        }
    }

    public function destroyCode(Request $request, $id)
    {
        try {
            $taxCode = TaxCode::findOrFail($id);

            // Don't delete used codes, just mark as expired
            if ($taxCode->status === 'used') {
                return response()->json([
                    'status' => 'error',
                    'message' => trans('mining-manager::taxes.cannot_delete_used_code'),
                ], 400);
            }

            $taxCode->delete();

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.code_deleted'),
            ]);

        } catch (\Exception $e) {
            Log::error('Tax code deletion error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.code_deletion_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update tax status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:unpaid,paid,overdue,exempted',
            ]);

            $userName = auth()->user()->main_character->name ?? auth()->user()->name ?? 'Unknown';

            // Wrap mining_taxes update + tax_codes update atomically so a partial
            // failure can't leave active tax codes after a tax row is marked paid
            // (which would cause the wallet listener to double-process).
            $oldStatus = DB::transaction(function () use ($id, $validated, $userName) {
                $tax = MiningTax::findOrFail($id);
                $oldStatus = $tax->status;
                $tax->status = $validated['status'];

                // If marking as paid, set payment date and deactivate tax codes
                if ($validated['status'] === 'paid' && $oldStatus !== 'paid') {
                    $delta = round((float) $tax->amount_owed - (float) ($tax->amount_paid ?? 0), 2);

                    $tax->paid_at = Carbon::now();
                    $tax->amount_paid = $tax->amount_owed;

                    // Same reason as markPaid(): keep amount_paid and the
                    // payments recorded against it in step.
                    if ($delta > 0) {
                        PaymentAllocation::create([
                            'transaction_id' => null,
                            'credit_id' => null,
                            'mining_tax_id' => $tax->id,
                            'character_id' => (int) $tax->character_id,
                            'amount' => $delta,
                            'source' => PaymentAllocation::SOURCE_MANUAL,
                            'allocated_by' => auth()->user()->main_character_id ?? auth()->id(),
                            'notes' => "Status changed to paid by {$userName}",
                            'allocated_at' => Carbon::now(),
                        ]);
                    }
                }

                $tax->save();

                // When changing to paid, mark active tax codes as used
                if ($validated['status'] === 'paid' && $oldStatus !== 'paid') {
                    $tax->taxCodes()->where('status', 'active')->update([
                        'status' => 'used',
                        'used_at' => Carbon::now(),
                        'notes' => "Status changed to paid by {$userName}",
                    ]);
                }

                return $oldStatus;
            });

            Log::info('Tax status updated', [
                'tax_id' => $id,
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
                'updated_by' => auth()->user()->name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.status_updated'),
            ]);

        } catch (\Exception $e) {
            Log::error('Tax status update error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.status_update_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a tax record
     */
    public function destroy(Request $request, $id)
    {
        try {
            $tax = MiningTax::findOrFail($id);

            // Prevent deletion of paid taxes
            if ($tax->status === 'paid') {
                return response()->json([
                    'status' => 'error',
                    'message' => trans('mining-manager::taxes.cannot_delete_paid'),
                ], 400);
            }

            $characterName = $tax->character->name ?? 'Unknown';
            $tax->delete();

            Log::info('Tax record deleted', [
                'tax_id' => $id,
                'character' => $characterName,
                'deleted_by' => auth()->user()->name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.tax_deleted'),
            ]);

        } catch (\Exception $e) {
            Log::error('Tax deletion error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.deletion_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export taxes to CSV/Excel
     */
    public function export(Request $request)
    {
        if (!$this->dataExportIsAllowed()) {
            return $this->refuseDataExport($request);
        }

        try {
            $status = $request->input('status', 'all');
            $month = $request->input('month');
            $format = $request->input('format', 'csv');

            // Normalize format: treat excel/xlsx as csv
            if (in_array($format, ['excel', 'xlsx', 'xls'])) {
                $format = 'csv';
            }

            // Build query
            $query = MiningTax::with(['character']);

            // Scope: only export own taxes unless viewing all
            if (!$this->isViewingAll()) {
                $query->whereIn('character_id', $this->getUserTaxCharacterIds());
            }

            if ($status !== 'all') {
                $query->where('status', $status);
            }

            if ($month) {
                $query->where('month', Carbon::parse($month)->format('Y-m-01'));
            }

            $taxes = $query->orderByRaw('COALESCE(period_start, month) DESC')
                ->orderBy('character_id')
                ->get();

            if ($format === 'json') {
                $data = $taxes->map(function ($tax) {
                    return [
                        'character' => $tax->character->name ?? 'Unknown',
                        'period' => $tax->formatted_period ?? Carbon::parse($tax->month)->format('F Y'),
                        'period_type' => $tax->period_type ?? 'monthly',
                        'month' => $tax->month ? Carbon::parse($tax->month)->format('Y-m') : null,
                        'amount_owed' => (float) $tax->amount_owed,
                        'amount_paid' => (float) ($tax->amount_paid ?? 0),
                        'status' => $tax->status,
                        'due_date' => $tax->due_date ? Carbon::parse($tax->due_date)->format('Y-m-d') : null,
                        'paid_at' => $tax->paid_at ? Carbon::parse($tax->paid_at)->format('Y-m-d') : null,
                    ];
                });

                $filename = 'taxes_export_' . Carbon::now()->format('Y-m-d_His') . '.json';

                return response()->json($data)
                    ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            }

            // Default: CSV export
            $filename = 'taxes_export_' . Carbon::now()->format('Y-m-d_His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ];

            $callback = function() use ($taxes) {
                $file = fopen('php://output', 'w');

                // Headers
                fputcsv($file, ['Character', 'Period', 'Period Type', 'Month', 'Amount Owed', 'Amount Paid', 'Status', 'Due Date', 'Paid At']);

                // Data rows
                foreach ($taxes as $tax) {
                    fputcsv($file, [
                        $tax->character->name ?? 'Unknown',
                        $tax->formatted_period ?? Carbon::parse($tax->month)->format('F Y'),
                        $tax->period_type ?? 'monthly',
                        $tax->month,
                        $tax->amount_owed,
                        $tax->amount_paid ?? 0,
                        $tax->status,
                        $tax->due_date,
                        $tax->paid_at,
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Tax export error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.export_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export personal taxes for logged-in user
     */
    public function exportPersonal(Request $request)
    {
        if (!$this->dataExportIsAllowed()) {
            return $this->refuseDataExport($request);
        }

        $user = auth()->user();
        $characterIds = $user->characters->pluck('character_id')->toArray();

        try {
            $format = $request->input('format', 'csv');

            // Normalize format: treat excel/xlsx as csv
            if (in_array($format, ['excel', 'xlsx', 'xls'])) {
                $format = 'csv';
            }

            $taxes = MiningTax::with(['character'])
                ->whereIn('character_id', $characterIds)
                ->orderByRaw('COALESCE(period_start, month) DESC')
                ->orderBy('character_id')
                ->get();

            if ($format === 'json') {
                $data = $taxes->map(function ($tax) {
                    return [
                        'character' => $tax->character->name ?? 'Unknown',
                        'period' => $tax->formatted_period ?? Carbon::parse($tax->month)->format('F Y'),
                        'period_type' => $tax->period_type ?? 'monthly',
                        'month' => $tax->month ? Carbon::parse($tax->month)->format('Y-m') : null,
                        'amount_owed' => (float) $tax->amount_owed,
                        'amount_paid' => (float) ($tax->amount_paid ?? 0),
                        'status' => $tax->status,
                        'due_date' => $tax->due_date ? Carbon::parse($tax->due_date)->format('Y-m-d') : null,
                        'paid_at' => $tax->paid_at ? Carbon::parse($tax->paid_at)->format('Y-m-d') : null,
                    ];
                });

                $filename = 'my_taxes_' . Carbon::now()->format('Y-m-d_His') . '.json';

                return response()->json($data)
                    ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            }

            // Default: CSV export
            $filename = 'my_taxes_' . Carbon::now()->format('Y-m-d_His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ];

            $callback = function() use ($taxes) {
                $file = fopen('php://output', 'w');

                fputcsv($file, ['Character', 'Period', 'Period Type', 'Month', 'Amount Owed', 'Amount Paid', 'Status', 'Due Date', 'Paid At']);

                foreach ($taxes as $tax) {
                    fputcsv($file, [
                        $tax->character->name ?? 'Unknown',
                        $tax->formatted_period ?? Carbon::parse($tax->month)->format('F Y'),
                        $tax->period_type ?? 'monthly',
                        $tax->month,
                        $tax->amount_owed,
                        $tax->amount_paid ?? 0,
                        $tax->status,
                        $tax->due_date,
                        $tax->paid_at,
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Personal tax export error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.export_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download tax receipt/invoice PDF
     */
    public function downloadReceipt(Request $request, $id)
    {
        try {
            $tax = MiningTax::with(['character', 'taxCode'])->findOrFail($id);

            // Check if user has permission to view this tax
            $user = auth()->user();
            $userCharacterIds = $user->characters->pluck('character_id')->toArray();

            if (!in_array($tax->character_id, $userCharacterIds) && !$this->isViewingAll()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized',
                ], 403);
            }

            // Generate simple text receipt
            $receipt = "TAX RECEIPT\n";
            $receipt .= "===========\n\n";
            $receipt .= "Character: " . ($tax->character->name ?? 'Unknown') . "\n";
            $receipt .= "Period: " . ($tax->formatted_period ?? Carbon::parse($tax->month)->format('F Y')) . "\n";
            $receipt .= "Amount Owed: " . number_format($tax->amount_owed, 2) . " ISK\n";
            $receipt .= "Amount Paid: " . number_format($tax->amount_paid ?? 0, 2) . " ISK\n";
            $receipt .= "Status: " . strtoupper($tax->status) . "\n";
            $receipt .= "Due Date: " . ($tax->due_date ?? 'N/A') . "\n";
            $receipt .= "Paid At: " . ($tax->paid_at ?? 'N/A') . "\n";

            if ($tax->taxCode) {
                $receipt .= "\nPayment Code: " . $tax->taxCode->code . "\n";
            }

            $receipt .= "\nGenerated: " . Carbon::now()->toDateTimeString() . "\n";

            $filename = 'receipt_' . $tax->character_id . '_' . ($tax->period_start ? Carbon::parse($tax->period_start)->format('Y-m-d') : Carbon::parse($tax->month)->format('Y-m')) . '.txt';

            return response($receipt, 200)
                ->header('Content-Type', 'text/plain')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Exception $e) {
            Log::error('Receipt download error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.receipt_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
