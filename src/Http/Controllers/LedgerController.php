<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningPriceCache;
use MiningManager\Models\MiningTax;
use MiningManager\Models\Setting;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Industry\CharacterMining;
use MiningManager\Services\Character\CharacterInfoService;
use MiningManager\Services\Ledger\LedgerSummaryService;
use MiningManager\Services\Pricing\OreValuationService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Services\ReprocessingRegistry;
use MiningManager\Models\MiningLedgerDailySummary;
use MiningManager\Http\Controllers\Traits\EnrichesCharacterData;
use Carbon\Carbon;
use MiningManager\Http\Controllers\Concerns\GuardsDataExport;

class LedgerController extends Controller
{
    use GuardsDataExport;

    use EnrichesCharacterData;

    protected $characterInfoService;
    protected $summaryService;
    protected OreValuationService $oreValuationService;
    protected SettingsManagerService $settingsService;

    public function __construct(
        CharacterInfoService $characterInfoService,
        LedgerSummaryService $summaryService,
        OreValuationService $oreValuationService,
        SettingsManagerService $settingsService
    ) {
        $this->characterInfoService = $characterInfoService;
        $this->summaryService = $summaryService;
        $this->oreValuationService = $oreValuationService;
        $this->settingsService = $settingsService;
    }
    /**
     * Display the mining ledger index with all mining activity
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        // Validate filter parameters
        $request->validate([
            'date_from' => 'nullable|date|date_format:Y-m-d',
            'date_to' => 'nullable|date|date_format:Y-m-d',
            'character_id' => 'nullable|integer',
            'corporation_id' => 'nullable|integer',
            'ore_type' => 'nullable|string|in:ore,moon,ice,gas',
            'system' => 'nullable|string|max:100',
            'sort_by' => 'nullable|string|in:date_desc,date_asc,value_desc,value_asc,quantity_desc',
            'per_page' => 'nullable|integer|in:25,50,100,200',
        ]);

        // Get validated filter parameters
        $dateFrom = $request->get('date_from', now()->startOfMonth()->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));
        $characterId = $request->get('character_id');
        $corporationId = $request->get('corporation_id');
        $oreType = $request->get('ore_type');
        $system = $request->get('system');
        $sortBy = $request->get('sort_by', 'date_desc');
        $perPage = $request->get('per_page', 50);

        // Build query with eager loading
        $query = MiningLedger::with(['character', 'affiliation', 'solarSystem', 'type'])
            ->whereBetween('date', [$dateFrom, $dateTo]);

        // Apply filters
        if ($characterId) {
            $query->where('character_id', $characterId);
        }

        // Corporation filter
        if ($corporationId) {
            $query->whereHas('character', function($q) use ($corporationId) {
                $q->whereIn('character_id', function($subQuery) use ($corporationId) {
                    $subQuery->select('character_id')
                        ->from('character_affiliations')
                        ->where('corporation_id', $corporationId);
                });
            });
        }

        if ($oreType) {
            switch ($oreType) {
                case 'ore':
                    $query->where('is_moon_ore', false)
                          ->where('is_ice', false)
                          ->where('is_gas', false);
                    break;
                case 'moon':
                    $query->where('is_moon_ore', true);
                    break;
                case 'ice':
                    $query->where('is_ice', true);
                    break;
                case 'gas':
                    $query->where('is_gas', true);
                    break;
            }
        }
        
        if ($system) {
            $query->where('solar_system_name', 'like', '%' . $system . '%');
        }

        // Apply sorting
        switch ($sortBy) {
            case 'date_desc':
                $query->orderBy('date', 'desc');
                break;
            case 'date_asc':
                $query->orderBy('date', 'asc');
                break;
            case 'value_desc':
                $query->orderBy('total_value', 'desc');
                break;
            case 'value_asc':
                $query->orderBy('total_value', 'asc');
                break;
            case 'quantity_desc':
                $query->orderBy('quantity', 'desc');
                break;
            default:
                $query->orderBy('date', 'desc');
        }

        // Paginate results
        $ledgerEntries = $query->paginate($perPage);
        
        // Enrich with character information (names, corporations for external characters)
        $ledgerEntries = $this->enrichPaginatorWithCharacterInfo(
            $ledgerEntries,
            $this->characterInfoService
        );

        // Calculate summary statistics for current month
        $topOre = MiningLedger::whereBetween('date', [$dateFrom, $dateTo])
            ->select('type_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('type_id')
            ->orderBy('total', 'desc')
            ->with('type')
            ->first();
        
        // Aggregate total_value and active_miners from pre-computed daily summaries
        $summaryAgg = MiningLedgerDailySummary::whereBetween('date', [$dateFrom, $dateTo]);

        // Apply same filters as main query
        if ($request->filled('character_id')) {
            $summaryAgg->where('character_id', $request->input('character_id'));
        }
        if ($request->filled('corporation_id')) {
            $corpCharIds = DB::table('character_affiliations')
                ->where('corporation_id', $request->input('corporation_id'))
                ->pluck('character_id');
            $summaryAgg->whereIn('character_id', $corpCharIds);
        }

        $summaryStats = $summaryAgg->selectRaw('
            SUM(total_quantity) as total_quantity,
            SUM(total_value) as total_value,
            COUNT(DISTINCT character_id) as active_miners
        ')->first();

        $summary = [
            'total_entries' => MiningLedger::whereBetween('date', [$dateFrom, $dateTo])->count(),
            'total_value' => (float) ($summaryStats->total_value ?? 0),
            'active_miners' => (int) ($summaryStats->active_miners ?? 0),
            'top_ore_type' => $topOre ? ($topOre->type->typeName ?? 'N/A') : 'N/A',
        ];

        // Get available characters for filter dropdown - include ALL characters (registered + unregistered)
        $characterIds = MiningLedger::select('character_id')
            ->distinct()
            ->pluck('character_id')
            ->toArray();
            
        $charactersInfo = $this->characterInfoService->getBatchCharacterInfo($characterIds);
        $characters = collect($charactersInfo)->sortBy('name');

        // Get corporations with mining activity
        $corporationIds = DB::table('character_affiliations')
            ->whereIn('character_id', function($query) {
                $query->select('character_id')
                    ->from('mining_ledger')
                    ->distinct();
            })
            ->distinct()
            ->pluck('corporation_id');

        $corporations = DB::table('corporation_infos')
            ->whereIn('corporation_id', $corporationIds)
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();

        // The ledger views read $features['allow_export_data'] to decide whether
        // to offer an export at all, and were never given it, so their own
        // fallback kept the button on however the setting was set.
        $features = $this->settingsService->getFeatureFlags();

        return view('mining-manager::ledger.index', compact(
            'features',
            'ledgerEntries',
            'summary',
            'characters',
            'corporations',
            'dateFrom',
            'dateTo',
            'characterId',
            'corporationId',
            'oreType',
            'system',
            'sortBy',
            'perPage'
        ));
    }

    /**
     * Display personal mining activity for the logged-in user
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function myMining(Request $request)
    {
        // Get user's characters
        $userCharacters = auth()->user()->characters->pluck('character_id');

        if ($userCharacters->isEmpty()) {
            $emptyChartData = $this->getEmptyChartData();
            return view('mining-manager::ledger.my-mining', [
                'ledgerEntries' => collect(),
                'stats' => $this->getEmptyStats(),
                'trendData' => ['labels' => [], 'values' => []],
                'oreDistribution' => ['labels' => [], 'values' => []],
                'monthlyComparison' => ['labels' => [], 'values' => []],
                'recentActivity' => collect(),
                'characterStats' => [],
                'characters' => collect(),
                'dateFrom' => now()->subMonth()->format('Y-m-d'),
                'dateTo' => now()->format('Y-m-d'),
                'characterId' => null,
                'features' => $this->settingsService->getFeatureFlags(),
                'message' => trans('mining-manager::ledger.no_characters'),
            ]);
        }

        // Get date range — support period shortcuts (week, month, year, all)
        $period = $request->get('period', 'month');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));
        $characterId = $request->get('character_id');

        // If no explicit date_from, calculate from period
        if (!$dateFrom) {
            switch ($period) {
                case 'week':
                    $dateFrom = now()->subWeek()->format('Y-m-d');
                    break;
                case 'year':
                    $dateFrom = now()->subYear()->format('Y-m-d');
                    break;
                case 'all':
                    $dateFrom = '2003-01-01'; // EVE Online launch date
                    break;
                case 'month':
                default:
                    $dateFrom = now()->subMonth()->format('Y-m-d');
                    break;
            }
        }

        // Build query for user's characters with eager loading
        $query = MiningLedger::with(['character', 'solarSystem', 'type'])
            ->whereIn('character_id', $userCharacters)
            ->whereBetween('date', [$dateFrom, $dateTo]);

        // Filter by specific character if selected
        if ($characterId && $userCharacters->contains($characterId)) {
            $query->where('character_id', $characterId);
        }

        $query->orderBy('date', 'desc');

        $ledgerEntries = $query->paginate(50);
        
        // Enrich with character information
        $ledgerEntries = $this->enrichPaginatorWithCharacterInfo(
            $ledgerEntries,
            $this->characterInfoService
        );

        // Calculate personal statistics
        $stats = $this->calculatePersonalStats($userCharacters, $dateFrom, $dateTo, $characterId);

        // Get chart data — map to variable names the view expects
        $rawChartData = $this->getPersonalChartData($userCharacters, $dateFrom, $dateTo, $characterId);
        $trendData = [
            'labels' => $rawChartData['daily']['labels'],
            'values' => $rawChartData['daily']['data'],
        ];
        $oreDistribution = [
            'labels' => $rawChartData['topOres']['labels'],
            'values' => $rawChartData['topOres']['data'],
        ];
        $monthlyComparison = $this->getMonthlyComparisonData($userCharacters, $characterId);

        // Get recent activity for the table
        $recentActivity = MiningLedger::with(['character', 'solarSystem', 'type'])
            ->whereIn('character_id', $userCharacters)
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->when($characterId && $userCharacters->contains($characterId), fn($q) => $q->where('character_id', $characterId))
            ->orderBy('date', 'desc')
            ->limit(20)
            ->get();

        // Per-character breakdown
        $characterStats = $this->getCharacterBreakdown($userCharacters, $dateFrom, $dateTo);

        // Get user's characters for filter - enrich with info
        $charactersInfo = $this->characterInfoService->getBatchCharacterInfo($userCharacters->toArray());
        $characters = collect($charactersInfo)->sortBy('name');

        $features = $this->settingsService->getFeatureFlags();

        return view('mining-manager::ledger.my-mining', compact(
            'features',
            'ledgerEntries',
            'stats',
            'trendData',
            'oreDistribution',
            'monthlyComparison',
            'recentActivity',
            'characterStats',
            'characters',
            'dateFrom',
            'dateTo',
            'characterId'
        ));
    }


    /**
     * Calculate ledger statistics for a given date range
     *
     * @param string $startDate
     * @param string $endDate
     * @param int|null $characterId
     * @return array
     */
    /**
     * Check if the current user can view a specific character's data.
     * Directors can view all; members can only view their own linked characters.
     */
    protected function canViewCharacter(int $characterId): bool
    {
        $user = auth()->user();

        // Directors and admins can view all characters
        if ($user->can('mining-manager.director') || $user->can('mining-manager.admin')) {
            return true;
        }

        // Members can only view their own linked characters
        $userCharacterIds = $user->characters->pluck('character_id')->toArray();
        return in_array($characterId, $userCharacterIds);
    }

    protected function calculateLedgerStats($startDate, $endDate, $characterId = null)
    {
        $agg = MiningLedgerDailySummary::whereBetween('date', [$startDate, $endDate])
            ->when($characterId, fn($q) => $q->where('character_id', $characterId))
            ->selectRaw('
                SUM(total_quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(total_tax) as total_tax,
                COUNT(DISTINCT character_id) as unique_miners,
                COUNT(DISTINCT date) as mining_days
            ')
            ->first();

        return [
            'total_quantity' => (float) ($agg->total_quantity ?? 0),
            'total_value' => (float) ($agg->total_value ?? 0),
            'total_tax' => (float) ($agg->total_tax ?? 0),
            'unique_miners' => (int) ($agg->unique_miners ?? 0),
            'mining_days' => (int) ($agg->mining_days ?? 0),
        ];
    }

    /**
     * Calculate personal statistics for given character IDs
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @param int|null $specificCharacterId
     * @return array
     */
    protected function calculatePersonalStats($characterIds, $startDate, $endDate, $specificCharacterId = null)
    {
        // Closure to build a fresh base query on raw table (for queries needing per-row data)
        $baseQuery = fn() => MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId));

        // Single aggregation from pre-computed daily summaries
        $agg = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId))
            ->selectRaw('
                SUM(total_quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(total_tax) as total_tax,
                SUM(event_discount_total) as event_discount_total,
                COUNT(DISTINCT date) as active_days,
                MAX(total_value) as best_day_value
            ')
            ->first();

        $totalQuantity = (float) ($agg->total_quantity ?? 0);
        $totalValue = (float) ($agg->total_value ?? 0);
        $activeDays = (int) ($agg->active_days ?? 0);

        // Total volume in m³ from raw ledger × invTypes.volume
        $totalVolume = (float) $baseQuery()
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->selectRaw('COALESCE(SUM(mining_ledger.quantity * invTypes.volume), 0) as total_volume')
            ->value('total_volume');

        // Best single day by value (from daily summaries)
        $bestDayValue = (float) ($agg->best_day_value ?? 0);

        // Tax paid from mining_taxes (for selected date range)
        $paidTaxAmount = MiningTax::whereIn('character_id', $characterIds)
            ->whereBetween('month', [$startDate, $endDate])
            ->where('status', 'paid')
            ->sum('amount_paid');

        // Tax owed from mining_taxes (authoritative, for selected date range)
        $taxOwed = (float) MiningTax::whereIn('character_id', $characterIds)
            ->whereBetween('month', [$startDate, $endDate])
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId))
            ->sum('amount_owed');

        // Estimated tax: try daily summaries first, then raw ledger, then estimate from value
        $estimatedTax = (float) ($agg->total_tax ?? 0);

        if ($estimatedTax <= 0) {
            // Fallback: calculate from raw mining_ledger tax_amount
            $estimatedTax = (float) MiningLedger::whereIn('character_id', $characterIds)
                ->whereBetween('date', [$startDate, $endDate])
                ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId))
                ->sum('tax_amount');
        }

        if ($estimatedTax <= 0 && $totalValue > 0) {
            // Last resort: estimate from total_value × configured default tax rate
            $taxRates = $this->settingsService->getTaxRates();
            $defaultRate = (float) ($taxRates['ore'] ?? 10.0);
            $estimatedTax = $totalValue * ($defaultRate / 100);
        }

        // Use authoritative tax_owed if available, otherwise fall back to estimated
        $effectiveTaxOwed = $taxOwed > 0 ? $taxOwed : $estimatedTax;

        // All-time event tax savings — every discount the user has accrued
        // from event participation, across all events the daily summaries
        // have seen. Cheap one-column sum from mining_ledger_daily_summaries.
        $eventSavingsAllTime = $this->summaryService->getTotalEventSavings(
            is_array($characterIds) ? $characterIds : $characterIds->toArray()
        );

        return [
            'total_quantity' => $totalQuantity,
            'total_value' => $totalValue,
            'total_volume' => $totalVolume,
            'total_sessions' => $activeDays,
            'active_days' => $activeDays,
            'best_day_value' => $bestDayValue,
            'daily_average' => $activeDays > 0 ? round($totalValue / $activeDays, 0) : 0,
            'estimated_tax_this_month' => $estimatedTax,
            'total_tax_owed' => $effectiveTaxOwed,
            'total_tax_paid' => $paidTaxAmount,
            'tax_outstanding' => max(0, $effectiveTaxOwed - $paidTaxAmount),
            'event_discount_total' => (float) ($agg->event_discount_total ?? 0),
            'event_savings_all_time' => $eventSavingsAllTime,
            'mining_days' => $activeDays,
            'favorite_ore' => $this->getFavoriteOre($characterIds, $startDate, $endDate, $specificCharacterId),
            'corp_rank' => $this->calculateCorpRank($characterIds, $startDate, $endDate),
        ];
    }

    /**
     * Get chart data for personal mining visualization
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @param int|null $specificCharacterId
     * @return array
     */
    protected function getPersonalChartData($characterIds, $startDate, $endDate, $specificCharacterId = null)
    {
        // Use a closure to build a fresh query each time (avoids consumed builder bug)
        $baseQuery = fn() => MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId));

        // Daily mining value from pre-computed daily summaries
        $dailyData = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId))
            ->selectRaw('date, SUM(total_value) as value')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top ores by value (uses a fresh query builder)
        $topOres = ($baseQuery())
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->select('invTypes.typeName as name', DB::raw('SUM(total_value) as value'))
            ->groupBy('invTypes.typeName')
            ->orderByDesc('value')
            ->limit(10)
            ->get();

        return [
            'daily' => [
                'labels' => $dailyData->pluck('date')->map(fn($d) => Carbon::parse($d)->format('M d'))->toArray(),
                'data' => $dailyData->pluck('value')->toArray(),
            ],
            'topOres' => [
                'labels' => $topOres->pluck('name')->toArray(),
                'data' => $topOres->pluck('value')->toArray(),
            ],
        ];
    }

    /**
     * Get the favorite ore (most mined by value) for given parameters
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @param int|null $specificCharacterId
     * @return array
     */
    protected function getFavoriteOre($characterIds, $startDate, $endDate, $specificCharacterId = null)
    {
        $query = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate]);

        if ($specificCharacterId) {
            $query->where('character_id', $specificCharacterId);
        }

        $favorite = $query
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->select('invTypes.typeName as name', DB::raw('SUM(total_value) as total'))
            ->groupBy('invTypes.typeName', 'mining_ledger.type_id')
            ->orderByDesc('total')
            ->first();

        return $favorite ? [
            'name' => $favorite->name,
            'value' => $favorite->total,
        ] : [
            'name' => trans('mining-manager::ledger.no_data'),
            'value' => 0,
        ];
    }

    /**
     * Get tax rate for an ore type using settings service.
     * Respects per-rarity moon ore rates (R4-R64), ore, ice, gas rates.
     *
     * @param int $typeId
     * @return float Tax rate as percentage (0-100)
     */
    protected function getOreTaxRate(int $typeId): float
    {
        $taxRates = $this->settingsService->getTaxRates();

        // Check if it's moon ore
        if (TypeIdRegistry::isMoonOre($typeId)) {
            $rarity = TypeIdRegistry::getMoonOreRarity($typeId);
            return (float) ($taxRates['moon_ore'][$rarity] ?? $taxRates['moon_ore']['r4'] ?? 5.0);
        }

        // Check other categories
        if (TypeIdRegistry::isIce($typeId)) {
            return (float) ($taxRates['ice'] ?? 10.0);
        }

        if (TypeIdRegistry::isGas($typeId)) {
            return (float) ($taxRates['gas'] ?? 10.0);
        }

        // Regular ore
        return (float) ($taxRates['ore'] ?? 10.0);
    }

    /**
     * Get empty statistics array
     *
     * @return array
     */
    protected function getEmptyStats()
    {
        return [
            'total_quantity' => 0,
            'total_value' => 0,
            'total_sessions' => 0,
            'active_days' => 0,
            'best_day_value' => 0,
            'daily_average' => 0,
            'estimated_tax_this_month' => 0,
            'total_tax_owed' => 0,
            'total_tax_paid' => 0,
            'tax_outstanding' => 0,
            'event_discount_total' => 0,
            'event_savings_all_time' => 0,
            'mining_days' => 0,
            'favorite_ore' => [
                'name' => trans('mining-manager::ledger.no_data'),
                'value' => 0,
            ],
            'corp_rank' => null,
        ];
    }

    /**
     * Get estimated tax from daily summaries for a month.
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $month YYYY-MM format
     * @param int|null $specificCharacterId
     * @return float
     */
    protected function getEstimatedTaxFromDailySummaries($characterIds, string $month, ?int $specificCharacterId = null): float
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        return (float) MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->forMonth($monthDate)
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId))
            ->sum('total_tax');
    }

    /**
     * Calculate the player's rank among all miners for the period.
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @return int|null
     */
    protected function calculateCorpRank($characterIds, string $startDate, string $endDate): ?int
    {
        $myTotal = (float) MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('total_value');

        if ($myTotal <= 0) {
            return null;
        }

        // Count distinct characters with a higher total value using daily summaries
        $higherCount = MiningLedgerDailySummary::whereBetween('date', [$startDate, $endDate])
            ->select('character_id', DB::raw('SUM(total_value) as char_total'))
            ->groupBy('character_id')
            ->havingRaw('SUM(total_value) > ?', [$myTotal])
            ->get()
            ->count();

        return $higherCount + 1;
    }

    /**
     * Get monthly comparison data (last 6 months of totals).
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param int|null $specificCharacterId
     * @return array
     */
    protected function getMonthlyComparisonData($characterIds, ?int $specificCharacterId = null): array
    {
        $labels = [];
        $values = [];

        // Single batch query from daily summaries for all 6 months
        $sixMonthsAgo = now()->subMonths(5)->startOfMonth();
        $monthlyData = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->where('date', '>=', $sixMonthsAgo)
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId))
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as month_key, SUM(total_value) as total")
            ->groupBy('month_key')
            ->get()
            ->keyBy('month_key');

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthKey = $month->format('Y-m');
            $value = $monthlyData->get($monthKey)->total ?? 0;

            $labels[] = $month->format('M Y');
            $values[] = (float) $value;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Get per-character breakdown for the stats table.
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    protected function getCharacterBreakdown($characterIds, string $startDate, string $endDate): array
    {
        $entries = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw('
                character_id,
                SUM(total_value) as total_value,
                SUM(total_quantity) as quantity,
                COUNT(DISTINCT date) as sessions
            ')
            ->groupBy('character_id')
            ->get();

        // Get volume (m³) from raw ledger joined with invTypes
        $volumes = MiningLedger::whereIn('mining_ledger.character_id', $characterIds)
            ->whereBetween('mining_ledger.date', [$startDate, $endDate])
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->selectRaw('mining_ledger.character_id, SUM(mining_ledger.quantity * invTypes.volume) as total_volume_m3')
            ->groupBy('mining_ledger.character_id')
            ->pluck('total_volume_m3', 'character_id');

        $grandTotal = $entries->sum('total_value');

        return $entries->map(function ($entry) use ($grandTotal, $volumes) {
            $charInfo = CharacterInfo::find($entry->character_id);
            return [
                'character_id' => $entry->character_id,
                'name' => $charInfo ? $charInfo->name : "Character {$entry->character_id}",
                'total_value' => (float) $entry->total_value,
                'quantity' => (float) $entry->quantity,
                'total_volume_m3' => (float) ($volumes->get($entry->character_id) ?? 0),
                'sessions' => (int) $entry->sessions,
                'percentage' => $grandTotal > 0 ? ($entry->total_value / $grandTotal) * 100 : 0,
            ];
        })->sortByDesc('total_value')->values()->toArray();
    }

    /**
     * Get empty chart data array
     *
     * @return array
     */
    protected function getEmptyChartData()
    {
        return [
            'daily' => [
                'labels' => [],
                'data' => [],
            ],
            'topOres' => [
                'labels' => [],
                'data' => [],
            ],
        ];
    }

    /**
     * Get details for a specific mining ledger entry
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function details($id)
    {
        $entry = MiningLedger::with(['character.user', 'solarSystem', 'type'])
            ->findOrFail($id);

        // Authorization: route is gated by mining-manager.member, but the
        // permission only checks the user is *some* member — not that this
        // particular ledger row belongs to a character they're allowed to
        // see. Without this check, a member can iterate $id values and view
        // any character's per-row mining (system, ore, value, tax). Other
        // character-scoped endpoints in this controller (showCharacterDetails,
        // getCharacterDailySummary, getDetailedEntries, getCharacterSystemDetails)
        // all call canViewCharacter() — this one was the lone outlier.
        // canViewCharacter() returns true for directors/admins (they can
        // view everyone) and for members iff the character is one of their
        // own SeAT-linked characters.
        if (!$this->canViewCharacter($entry->character_id)) {
            abort(403, 'You do not have permission to view this character.');
        }

        // Get related tax record if exists
        $taxRecord = MiningTax::where('character_id', $entry->character_id)
            ->whereYear('month', Carbon::parse($entry->date)->year)
            ->whereMonth('month', Carbon::parse($entry->date)->month)
            ->first();

        return view('mining-manager::ledger.partials.details', compact('entry', 'taxRecord'));
    }

    /**
     * Delete a specific mining ledger entry
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete($id)
    {
        try {
            $entry = MiningLedger::findOrFail($id);
            $entry->delete();

            return response()->json([
                'success' => true,
                'message' => trans('mining-manager::ledger.entry_deleted'),
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to delete mining ledger entry', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => trans('mining-manager::ledger.delete_failed'),
            ], 500);
        }
    }

    /**
     * Bulk delete mining ledger entries
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'entry_ids' => 'required|array',
            'entry_ids.*' => 'integer|exists:mining_ledger,id',
        ]);

        try {
            $deleted = MiningLedger::whereIn('id', $request->input('entry_ids'))->delete();

            return response()->json([
                'success' => true,
                'message' => trans('mining-manager::ledger.entries_deleted', ['count' => $deleted]),
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to bulk delete mining ledger entries', [
                'ids' => $request->input('entry_ids'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => trans('mining-manager::ledger.delete_failed'),
            ], 500);
        }
    }

    /**
     * Export mining ledger data
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(Request $request)
    {
        if (!$this->dataExportIsAllowed()) {
            return $this->refuseDataExport($request);
        }

        $query = MiningLedger::with(['character', 'type', 'solarSystem']);

        // Apply filters if provided
        if ($request->has('character_id') && $request->input('character_id')) {
            $query->where('character_id', $request->input('character_id'));
        }

        if ($request->has('type_id') && $request->input('type_id')) {
            $query->where('type_id', $request->input('type_id'));
        }

        if ($request->has('start_date') && $request->input('start_date')) {
            $query->where('date', '>=', $request->input('start_date'));
        }

        if ($request->has('end_date') && $request->input('end_date')) {
            $query->where('date', '<=', $request->input('end_date'));
        }

        // If specific entry IDs provided (for selected export)
        if ($request->has('entry_ids')) {
            $entryIds = explode(',', $request->input('entry_ids'));
            $query->whereIn('id', $entryIds);
        }

        $filename = 'mining_ledger_' . now()->format('Y-m-d_His') . '.csv';

        // Stream the export in chunks so memory stays bounded regardless of
        // dataset size. Previously this loaded up to 10,000 rows into PHP
        // memory at once via ->limit(10000)->get() — fine for small corps,
        // but a 100-character active corp could OOM the request.
        //
        // chunkByIdDesc uses cursor pagination (WHERE id < last_id LIMIT 500)
        // which is stable across the iteration even if rows are inserted
        // mid-stream. Relations specified by with() are eager-loaded per
        // chunk, so no N+1.
        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // CSV Headers
            fputcsv($handle, [
                'Date',
                'Character',
                'Ore Type',
                'Solar System',
                'Quantity',
                'Price',
                'Total Value',
                'Tax Rate',
                'Tax Amount',
            ]);

            $query->chunkByIdDesc(500, function ($entries) use ($handle) {
                foreach ($entries as $entry) {
                    fputcsv($handle, [
                        Carbon::parse($entry->date)->format('Y-m-d H:i:s'),
                        $entry->character->name ?? 'Unknown',
                        $entry->type->name ?? 'Unknown',
                        $entry->solarSystem->name ?? 'Unknown',
                        number_format($entry->quantity, 2),
                        number_format($entry->price, 2),
                        number_format($entry->total_value, 2),
                        number_format($entry->tax_rate, 2) . '%',
                        number_format($entry->tax_amount, 2),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Export personal mining ledger data (only current user's characters)
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportPersonal(Request $request)
    {
        if (!$this->dataExportIsAllowed()) {
            return $this->refuseDataExport($request);
        }

        // Get current user's character IDs
        $characterIds = auth()->user()->characters->pluck('character_id');

        // Query only for user's characters
        $query = MiningLedger::with(['character', 'type', 'solarSystem'])
            ->whereIn('character_id', $characterIds);

        // Apply date filters if provided
        if ($request->has('start_date') && $request->input('start_date')) {
            $query->where('date', '>=', $request->input('start_date'));
        }

        if ($request->has('end_date') && $request->input('end_date')) {
            $query->where('date', '<=', $request->input('end_date'));
        }

        $filename = 'my_mining_' . now()->format('Y-m-d_His') . '.csv';

        // Stream in chunks — same rationale as exportLedger above.
        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // CSV Headers
            fputcsv($handle, [
                'Date',
                'Character',
                'Ore Type',
                'Solar System',
                'Quantity',
                'Price',
                'Total Value',
                'Tax Rate',
                'Tax Amount',
            ]);

            $query->chunkByIdDesc(500, function ($entries) use ($handle) {
                foreach ($entries as $entry) {
                    fputcsv($handle, [
                        Carbon::parse($entry->date)->format('Y-m-d H:i:s'),
                        $entry->character->name ?? 'Unknown',
                        $entry->type->name ?? 'Unknown',
                        $entry->solarSystem->name ?? 'Unknown',
                        number_format($entry->quantity, 2),
                        number_format($entry->price, 2),
                        number_format($entry->total_value, 2),
                        number_format($entry->tax_rate, 2) . '%',
                        number_format($entry->tax_amount, 2),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }


    /**
     * Display hierarchical mining summary with character monthly totals
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function summaryIndex(Request $request)
    {
        // Get month parameter or default to current month
        $month = $request->get('month', now()->format('Y-m'));
        $corporationId = $request->get('corporation_id');
        $groupByMain = $request->get('group_by_main', true); // Default to grouped view
        $sortBy = $request->get('sort_by', 'total_value'); // Default sort column
        $sortDir = $request->get('sort_dir', 'desc'); // Default sort direction

        // Validate month format
        try {
            $monthDate = Carbon::parse($month)->startOfMonth();
        } catch (\Exception $e) {
            $monthDate = now()->startOfMonth();
            $month = $monthDate->format('Y-m');
        }

        // Get enhanced monthly summaries (includes ore types and systems)
        $summaries = $this->summaryService->getEnhancedMonthlySummaries($month, $corporationId);

        // Group by main character if requested
        if ($groupByMain) {
            $summaries = $this->summaryService->groupByMainCharacter($summaries, $corporationId);
        }

        // Filter to user's own characters for non-directors (members see only their own)
        $isDirector = auth()->user()->can('mining-manager.director');
        if (!$isDirector) {
            $userCharacterIds = auth()->user()->characters->pluck('character_id')->toArray();
            $summaries = $summaries->filter(function ($summary) use ($userCharacterIds) {
                // Check main character
                if (in_array($summary->character_id, $userCharacterIds)) {
                    return true;
                }
                // Check alt characters (grouped view)
                if (isset($summary->alt_characters) && $summary->alt_characters->isNotEmpty()) {
                    return $summary->alt_characters->pluck('character_id')->intersect($userCharacterIds)->isNotEmpty();
                }
                return false;
            })->values();
        }

        // Enrich with character information (names, corporations for unregistered characters)
        $summaries = $this->enrichWithCharacterInfo($summaries, $this->characterInfoService);

        // Apply sorting
        $summaries = $this->sortSummaries($summaries, $sortBy, $sortDir);

        // Calculate totals
        $totals = [
            'total_value' => $summaries->sum('total_value'),
            'total_tax' => $summaries->sum('total_tax'),
            'event_discount_total' => $summaries->sum('event_discount_total'),
            'total_quantity' => $summaries->sum('total_quantity'),
            'total_volume_m3' => $summaries->sum('total_volume_m3'),
            'moon_ore_value' => $summaries->sum('moon_ore_value'),
            'regular_ore_value' => $summaries->sum('regular_ore_value'),
            'ice_value' => $summaries->sum('ice_value'),
            'gas_value' => $summaries->sum('gas_value'),
        ];

        // Get corporations for filter dropdown
        $corporations = DB::table('corporation_infos')
            ->whereIn('corporation_id', function($query) use ($monthDate) {
                $query->select('corporation_id')
                    ->from('mining_ledger')
                    ->whereYear('date', $monthDate->year)
                    ->whereMonth('date', $monthDate->month)
                    ->whereNotNull('corporation_id')
                    ->distinct();
            })
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();

        return view('mining-manager::ledger.summary', [
            'summaries' => $summaries,
            'totals' => $totals,
            'month' => $month,
            'monthDate' => $monthDate,
            'corporations' => $corporations,
            'selectedCorporationId' => $corporationId,
            'isCurrentMonth' => $monthDate->isSameMonth(now()),
            'groupByMain' => $groupByMain,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            'isDirector' => $isDirector,
        ]);
    }

    /**
     * Sort summaries by specified column and direction
     *
     * @param \Illuminate\Support\Collection $summaries
     * @param string $sortBy
     * @param string $sortDir
     * @return \Illuminate\Support\Collection
     */
    protected function sortSummaries($summaries, $sortBy, $sortDir)
    {
        $validColumns = ['character_name', 'total_quantity', 'total_value', 'corporation_name'];

        if (!in_array($sortBy, $validColumns)) {
            $sortBy = 'total_value';
        }

        if (!in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        switch ($sortBy) {
            case 'character_name':
                $summaries = $sortDir === 'asc'
                    ? $summaries->sortBy(function($s) {
                        return $s->character_info['name'] ?? $s->character->name ?? 'Unknown';
                    })
                    : $summaries->sortByDesc(function($s) {
                        return $s->character_info['name'] ?? $s->character->name ?? 'Unknown';
                    });
                break;

            case 'corporation_name':
                $summaries = $sortDir === 'asc'
                    ? $summaries->sortBy(function($s) {
                        return $s->character_info['corporation_name'] ?? 'Unknown';
                    })
                    : $summaries->sortByDesc(function($s) {
                        return $s->character_info['corporation_name'] ?? 'Unknown';
                    });
                break;

            case 'total_quantity':
            case 'total_value':
            default:
                $summaries = $sortDir === 'asc'
                    ? $summaries->sortBy($sortBy)
                    : $summaries->sortByDesc($sortBy);
                break;
        }

        return $summaries->values(); // Re-index the collection
    }

    /**
     * Show detailed mining ledger for a specific character
     *
     * @param Request $request
     * @param int $characterId
     * @return \Illuminate\View\View
     */
    public function showCharacterDetails(Request $request, $characterId)
    {
        // Authorization: members can only view their own characters, directors can view all
        if (!$this->canViewCharacter($characterId)) {
            abort(403, 'You do not have permission to view this character.');
        }

        $month = $request->get('month', now()->format('Y-m'));
        $includeAlts = $request->get('include_alts', false); // Option to include alt characters
        $sortBy = $request->get('sort_by', 'date'); // Default sort column
        $sortDir = $request->get('sort_dir', 'desc'); // Default sort direction

        try {
            $monthDate = Carbon::parse($month)->startOfMonth();
        } catch (\Exception $e) {
            $monthDate = now()->startOfMonth();
            $month = $monthDate->format('Y-m');
        }

        // Get character information
        $characterInfo = $this->characterInfoService->getCharacterInfo($characterId);

        // Determine which character IDs to include
        $characterIds = [$characterId];
        $altCharacters = collect();

        // If this is a main character, check if we should include alts
        if ($includeAlts || $characterInfo['is_registered']) {
            // Get user ID for this character
            $userId = DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->value('user_id');

            if ($userId) {
                // Check if this character is the main for this user
                $mainCharacterId = DB::table('users')
                    ->where('id', $userId)
                    ->value('main_character_id');

                // If this IS the main character, get all alts
                if ($mainCharacterId == $characterId) {
                    $altCharacterIds = DB::table('refresh_tokens')
                        ->where('user_id', $userId)
                        ->where('character_id', '!=', $characterId)
                        ->pluck('character_id')
                        ->toArray();

                    if (!empty($altCharacterIds)) {
                        $characterIds = array_merge($characterIds, $altCharacterIds);

                        // Get alt character info for display
                        $altCharacters = collect($this->characterInfoService->getBatchCharacterInfo($altCharacterIds))
                            ->values();
                    }
                }
            }
        }

        // Validate sort parameters
        $validColumns = ['date', 'quantity', 'total_value', 'character_id'];
        if (!in_array($sortBy, $validColumns)) {
            $sortBy = 'date';
        }
        if (!in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        // Get all mining entries for these characters with pagination
        $entries = MiningLedger::with(['solarSystem', 'type', 'character'])
            ->whereIn('character_id', $characterIds)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->orderBy($sortBy, $sortDir)
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->appends([
                'month' => $month,
                'include_alts' => $includeAlts ? '1' : '0',
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
            ]);

        // Calculate totals for all included characters
        $totals = MiningLedger::whereIn('mining_ledger.character_id', $characterIds)
            ->whereYear('mining_ledger.date', $monthDate->year)
            ->whereMonth('mining_ledger.date', $monthDate->month)
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->selectRaw('
                SUM(mining_ledger.quantity) as total_quantity,
                SUM(mining_ledger.quantity * invTypes.volume) as total_volume_m3,
                SUM(mining_ledger.total_value) as total_value,
                COUNT(DISTINCT mining_ledger.solar_system_id) as unique_systems,
                COUNT(DISTINCT mining_ledger.type_id) as unique_ores
            ')
            ->first();

        // Get total_tax from daily summaries (single source of truth)
        $totals->total_tax = (float) MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->sum('total_tax');

        return view('mining-manager::ledger.character-details', [
            'characterId' => $characterId,
            'characterInfo' => $characterInfo,
            'entries' => $entries,
            'totals' => $totals,
            'month' => $month,
            'monthDate' => $monthDate,
            'includeAlts' => count($characterIds) > 1,
            'altCharacters' => $altCharacters,
            'showingMultiple' => count($characterIds) > 1,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }

    /**
     * Get daily breakdown for a specific character and month (AJAX)
     *
     * @param Request $request
     * @param int $characterId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCharacterDailySummary(Request $request, $characterId)
    {
        // Authorization: members can only view their own characters, directors can view all
        if (!$this->canViewCharacter($characterId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $month = $request->get('month', now()->format('Y-m'));

        try {
            // Validate month format
            $monthDate = Carbon::parse($month)->startOfMonth();

            // Get daily summaries using the service
            $dailySummaries = $this->summaryService->getDailySummaries($characterId, $month);

            // Format the data for the response with system breakdown
            $formattedData = $dailySummaries->map(function ($summary) use ($characterId, $monthDate) {
                $date = $summary->date instanceof Carbon ? $summary->date : Carbon::parse($summary->date);

                // Get system breakdown for this date
                $systems = MiningLedger::with('solarSystem')
                    ->where('character_id', $characterId)
                    ->whereDate('date', $date)
                    ->select('solar_system_id', DB::raw('SUM(total_value) as system_value'), DB::raw('SUM(quantity) as system_quantity'))
                    ->groupBy('solar_system_id')
                    ->orderByDesc('system_value')
                    ->get()
                    ->map(function($sys) {
                        return [
                            'system_id' => $sys->solar_system_id,
                            'system_name' => $sys->solarSystem->name ?? 'Unknown System',
                            'value' => number_format($sys->system_value, 2),
                            'quantity' => number_format($sys->system_quantity, 2),
                        ];
                    });

                return [
                    'date' => $date->format('Y-m-d'),
                    'total_quantity' => number_format($summary->total_quantity, 2),
                    'total_value' => number_format($summary->total_value, 2),
                    'total_tax' => number_format($summary->total_tax, 2),
                    'moon_ore_value' => number_format($summary->moon_ore_value, 2),
                    'regular_ore_value' => number_format($summary->regular_ore_value, 2),
                    'ice_value' => number_format($summary->ice_value, 2),
                    'gas_value' => number_format($summary->gas_value, 2),
                    'is_finalized' => $summary->is_finalized ?? false,
                    'ore_breakdown' => $summary->ore_types ?? [],
                    'systems' => $systems,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load daily summaries: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed mining entries for a specific character and date (AJAX)
     *
     * @param Request $request
     * @param int $characterId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDetailedEntries(Request $request, $characterId)
    {
        // Authorization: members can only view their own characters, directors can view all
        if (!$this->canViewCharacter($characterId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $date = $request->get('date');

        try {
            // Validate date format
            $dateCarbon = Carbon::parse($date);

            // Get detailed entries for the day
            $entries = MiningLedger::with(['solarSystem', 'type'])
                ->where('character_id', $characterId)
                ->whereDate('date', $dateCarbon)
                ->orderBy('date', 'desc')
                ->get();

            // Format the data for the response
            $formattedData = $entries->map(function ($entry) {
                return [
                    'date' => $entry->date->format('Y-m-d H:i:s'),
                    'quantity' => number_format($entry->quantity, 2),
                    'total_value' => number_format($entry->total_value, 2),
                    'tax_amount' => number_format($entry->tax_amount, 2),
                    'ore_type' => $entry->ore_type,
                    'type_name' => $entry->type->typeName ?? 'Unknown',
                    'system_name' => $entry->solarSystem->name ?? 'Unknown',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load detailed entries: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get character mining details in a specific system (AJAX)
     *
     * @param Request $request
     * @param int $characterId
     * @param int $systemId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCharacterSystemDetails(Request $request, $characterId, $systemId)
    {
        // Authorization: members can only view their own characters, directors can view all
        if (!$this->canViewCharacter($characterId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $month = $request->get('month', now()->format('Y-m'));

        try {
            // Get system details using the service
            $entries = $this->summaryService->getCharacterSystemDetails($characterId, $month, $systemId);

            // Format the data for the response
            $formattedData = $entries->map(function ($entry) {
                return [
                    'date' => $entry->date->format('Y-m-d H:i:s'),
                    'quantity' => number_format($entry->quantity, 2),
                    'total_value' => number_format($entry->total_value, 2),
                    'tax_amount' => number_format($entry->tax_amount, 2),
                    'ore_type' => $entry->ore_type,
                    'type_name' => $entry->type->typeName ?? 'Unknown',
                    'type_id' => $entry->type_id,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData,
                'system_name' => $entries->first()->solarSystem->name ?? 'Unknown System',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load system details: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the reprocessing calculator page.
     *
     * @return \Illuminate\View\View
     */
    public function reprocessingCalculator()
    {
        // Get cached mineral prices (all refined materials)
        $allMaterialIds = TypeIdRegistry::getAllRefinedMaterials();
        $mineralPrices = MiningPriceCache::whereIn('type_id', $allMaterialIds)
            ->get()
            ->keyBy('type_id')
            ->map(function ($cache) {
                return [
                    'type_id' => $cache->type_id,
                    'price' => (float) $cache->getConfiguredPrice(),
                ];
            })
            ->toArray();

        // Get price cache age info
        $oldestCache = MiningPriceCache::whereIn('type_id', $allMaterialIds)
            ->orderBy('cached_at', 'asc')
            ->first();
        $newestCache = MiningPriceCache::whereIn('type_id', $allMaterialIds)
            ->orderBy('cached_at', 'desc')
            ->first();

        $cacheInfo = [
            'oldest_cached_at' => $oldestCache ? $oldestCache->cached_at->toIso8601String() : null,
            'newest_cached_at' => $newestCache ? $newestCache->cached_at->toIso8601String() : null,
            'oldest_age_minutes' => $oldestCache ? (int) $oldestCache->cached_at->diffInMinutes(now()) : null,
            'is_stale' => $oldestCache ? !$oldestCache->isFresh() : true,
        ];

        return view('mining-manager::ledger.reprocessing', compact('mineralPrices', 'cacheInfo'));
    }

    /**
     * Calculate reprocessing output for given ores.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function calculateReprocessing(Request $request)
    {
        $request->validate([
            'ores' => 'required|array|min:1',
            'ores.*.type_name' => 'required|string',
            'ores.*.quantity' => 'required|integer|min:1',
            'yield_percent' => 'required|numeric|min:50|max:100',
        ]);

        $ores = $request->input('ores');
        $yieldPercent = $request->input('yield_percent');
        $yieldFraction = $yieldPercent / 100;

        // Get all refined material prices
        $allMaterialIds = TypeIdRegistry::getAllRefinedMaterials();
        $mineralPrices = MiningPriceCache::whereIn('type_id', $allMaterialIds)
            ->get()
            ->mapWithKeys(function ($cache) {
                return [$cache->type_id => (float) $cache->getConfiguredPrice()];
            })
            ->toArray();

        // Resolve ore names to type IDs and portionSize via invTypes table
        $oreNames = collect($ores)->pluck('type_name')->unique()->toArray();
        $oreTypeData = DB::table('invTypes')
            ->whereIn('typeName', $oreNames)
            ->select('typeID', 'typeName', 'portionSize')
            ->get()
            ->keyBy('typeName');
        $oreNameToId = $oreTypeData->pluck('typeID', 'typeName')->toArray();

        $mineralTotals = [];   // mineralTypeId => total quantity
        $oreResults = [];
        $unresolved = [];      // names invTypes did not recognise
        $totalOreValue = 0;

        foreach ($ores as $ore) {
            $typeName = $ore['type_name'];
            $quantity = (int) $ore['quantity'];

            $typeId = $oreNameToId[$typeName] ?? null;
            if ($typeId === null) {
                // Dropping this quietly is how a stale SDE looked like an ore
                // being worthless: the row vanished from the result and nothing
                // said why. Hand the name back so the page can say it did not
                // recognise it.
                if (! in_array($typeName, $unresolved, true)) {
                    $unresolved[] = $typeName;
                }

                continue;
            }

            // Determine if compressed
            $isCompressed = TypeIdRegistry::isCompressedOre($typeId);

            // Determine category
            $category = 'regular';
            if (TypeIdRegistry::isMoonOre($typeId)) {
                $category = 'moon';
            } elseif (TypeIdRegistry::isIce($typeId)) {
                $category = 'ice';
            } elseif (TypeIdRegistry::isGas($typeId)) {
                $category = 'gas';
            }

            if ($isCompressed) {
                $category = 'compressed ' . $category;
            }

            // Get ore price from cache for ore value
            $orePrice = MiningPriceCache::where('type_id', $typeId)->first();
            $orePricePerUnit = $orePrice ? (float) $orePrice->getConfiguredPrice() : 0;
            $oreValue = $orePricePerUnit * $quantity;
            $totalOreValue += $oreValue;

            // Get reprocessing minerals
            $minerals = ReprocessingRegistry::getMineralsWithDetails($typeId);
            $oreMineralOutput = [];
            $hasReprocessingData = !empty($minerals);

            if ($minerals) {
                // Use portionSize from SDE invTypes table (batch size for reprocessing)
                $portionSize = isset($oreTypeData[$typeName]) ? (int) $oreTypeData[$typeName]->portionSize : 100;
                $batchCount = floor($quantity / $portionSize);
                foreach ($minerals as $mineral) {
                    $mineralQty = floor($mineral['quantity'] * $yieldFraction * $batchCount);
                    if ($mineralQty > 0) {
                        $mineralTypeId = $mineral['type_id'];
                        $mineralTotals[$mineralTypeId] = ($mineralTotals[$mineralTypeId] ?? 0) + $mineralQty;
                        $oreMineralOutput[] = [
                            'name' => $mineral['name'],
                            'quantity' => $mineralQty,
                        ];
                    }
                }
            }

            $oreResults[] = [
                'type_name' => $typeName,
                'type_id' => $typeId,
                'quantity' => $quantity,
                'category' => $category,
                'ore_value' => $oreValue,
                'minerals' => $oreMineralOutput,
                'has_reprocessing_data' => $hasReprocessingData,
            ];
        }

        // Build minerals output with prices
        $mineralsOutput = [];
        $totalMineralValue = 0;
        $totalItems = 0;

        // Get mineral names from DB for any we have
        $mineralNames = DB::table('invTypes')
            ->whereIn('typeID', array_keys($mineralTotals))
            ->pluck('typeName', 'typeID')
            ->toArray();

        foreach ($mineralTotals as $mineralTypeId => $qty) {
            $price = $mineralPrices[$mineralTypeId] ?? 0;
            $value = $price * $qty;
            $totalMineralValue += $value;
            $totalItems += $qty;

            $mineralsOutput[] = [
                'type_id' => $mineralTypeId,
                'name' => $mineralNames[$mineralTypeId] ?? "Unknown ({$mineralTypeId})",
                'quantity' => $qty,
                'price_per_unit' => $price,
                'total_value' => $value,
            ];
        }

        // Sort minerals by value descending
        usort($mineralsOutput, function ($a, $b) {
            return $b['total_value'] <=> $a['total_value'];
        });

        // Get cache age for the response
        $cacheAges = MiningPriceCache::whereIn('type_id', $allMaterialIds)
            ->select('type_id', 'cached_at')
            ->get()
            ->mapWithKeys(fn($c) => [$c->type_id => $c->cached_at->diffInMinutes(now())]);

        $oldestAge = $cacheAges->max();
        $pricingSettings = $this->settingsService->getPricingSettings();

        return response()->json([
            'success' => true,
            'summary' => [
                'total_ore_value' => $totalOreValue,
                'total_mineral_value' => $totalMineralValue,
                'total_items' => $totalItems,
                'yield_used' => $yieldPercent,
                'price_cache_age_minutes' => $oldestAge ?? null,
                'price_cache_stale' => ($oldestAge ?? 999) > ($pricingSettings['cache_duration'] ?? 240),
            ],
            'minerals' => $mineralsOutput,
            'ores' => $oreResults,
            'unresolved' => $unresolved,
        ]);
    }
}
