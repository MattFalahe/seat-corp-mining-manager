<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Analytics\MiningAnalyticsService;
use MiningManager\Services\Moon\MoonAnalyticsService;
use MiningManager\Models\MiningLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use MiningManager\Http\Controllers\Concerns\GuardsDataExport;

class AnalyticsController extends Controller
{
    use GuardsDataExport;

    /**
     * Analytics service
     *
     * @var MiningAnalyticsService
     */
    protected $analyticsService;

    /**
     * Constructor
     *
     * @param MiningAnalyticsService $analyticsService
     */
    public function __construct(MiningAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Extract corporation filter from request.
     * Returns null for "all corporations", or the corporation_id integer.
     *
     * With no corporation_id in the query at all - a first visit, or a link
     * from elsewhere - this defaults to the viewer's own corporation rather
     * than every corporation on the install. Analytics opened on "All
     * Corporations" mixes other people's mining into every chart and total,
     * which is almost never the question being asked.
     *
     * has() rather than filled() is the important part: an empty
     * corporation_id means the viewer deliberately chose All Corporations, and
     * that has to survive. Treating empty as "no preference" would snap the
     * dropdown back on every submit and make All Corporations unselectable.
     *
     * The default only applies when the viewer's corporation is actually in
     * the list offered by the dropdown. Filtering to a corporation that is not
     * an option would leave the select showing "All Corporations" while the
     * page silently filtered to something else.
     *
     * @param  Request  $request
     * @param  \Illuminate\Support\Collection|array|null  $available  Corporations the page offers
     * @return int|null
     */
    protected function getCorporationFilter(Request $request, $available = null): ?int
    {
        if ($request->has('corporation_id')) {
            $corpId = $request->input('corporation_id');

            return $corpId ? (int) $corpId : null;
        }

        $userCorporationId = $this->getUserCorporationId();

        if (!$userCorporationId) {
            return null;
        }

        $available = $available ?? $this->analyticsService->getCorporationsWithData();
        $keys = $available instanceof \Illuminate\Support\Collection
            ? $available->keys()->all()
            : array_keys((array) $available);

        // Loose comparison on purpose: these ids arrive as ints from the
        // affiliation lookup and as string keys off a plucked collection.
        return in_array($userCorporationId, $keys) ? (int) $userCorporationId : null;
    }

    /**
     * Get the user's own corporation ID from their main character.
     *
     * @return int|null
     */
    protected function getUserCorporationId(): ?int
    {
        $user = auth()->user();
        if (!$user || !$user->main_character_id) {
            return null;
        }

        return DB::table('character_affiliations')
            ->where('character_id', $user->main_character_id)
            ->value('corporation_id');
    }

    /**
     * Display analytics overview
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'corporation_id' => 'nullable|integer',
            'group_by' => 'nullable|in:account,character',
            'ore_category' => 'nullable|string|max:50',
        ]);

        try {
            $features = app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getFeatureFlags();
            if (!($features['enable_analytics'] ?? true)) {
                return redirect()->route('mining-manager.dashboard.index')
                    ->with('warning', 'This feature is currently disabled. Enable it in Settings > Features.');
            }

            // Get date range from request or default to last 30 days
            $startDate = $request->input('start_date')
                ? Carbon::parse($request->input('start_date'))
                : Carbon::now()->subDays(30);

            $endDate = $request->input('end_date')
                ? Carbon::parse($request->input('end_date'))
                : Carbon::now();

            // Get group_by and ore_category params
            $groupBy = $request->input('group_by', 'account');
            $oreCategory = $request->input('ore_category');

            // Corporation filter
            $corporations = $this->analyticsService->getCorporationsWithData();
            $corporationId = $this->getCorporationFilter($request, $corporations);
            $userCorporationId = $this->getUserCorporationId();

            // Get top miners based on grouping
            $topMiners = $groupBy === 'character'
                ? $this->analyticsService->getTopMiners($startDate, $endDate, 20, $corporationId)
                : $this->analyticsService->getTopMinersByAccount($startDate, $endDate, 20, $corporationId);

            // Get analytics data
            $analytics = [
                'total_volume' => $this->analyticsService->getTotalVolume($startDate, $endDate, $corporationId),
                'total_value' => $this->analyticsService->getTotalValue($startDate, $endDate, $corporationId),
                'unique_miners' => $this->analyticsService->getUniqueMinerCount($startDate, $endDate, $corporationId),
                'top_miners' => $topMiners,
                'ore_breakdown' => $this->analyticsService->getOreBreakdown($startDate, $endDate, $oreCategory, $corporationId),
                'system_breakdown' => $this->analyticsService->getSystemBreakdown($startDate, $endDate, $corporationId),
                'daily_trends' => $this->analyticsService->getDailyTrends($startDate, $endDate, $corporationId),
            ];

            return view('mining-manager::analytics.index', compact(
                'analytics', 'startDate', 'endDate', 'groupBy', 'oreCategory',
                'corporationId', 'corporations', 'userCorporationId'
            ));
        } catch (\Exception $e) {
            Log::error('Mining Manager: Analytics error: ' . $e->getMessage());
            return back()->with('error', 'An error occurred loading analytics data.');
        }
    }

    /**
     * Display charts page
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function charts(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'corporation_id' => 'nullable|integer',
        ]);

        try {
            $startDate = $request->input('start_date')
                ? Carbon::parse($request->input('start_date'))
                : Carbon::now()->subDays(30);

            $endDate = $request->input('end_date')
                ? Carbon::parse($request->input('end_date'))
                : Carbon::now();

            // Corporation filter
            $corporations = $this->analyticsService->getCorporationsWithData();
            $corporationId = $this->getCorporationFilter($request, $corporations);
            $userCorporationId = $this->getUserCorporationId();

            // Get chart data
            $chartData = [
                'mining_trends' => $this->analyticsService->getMiningTrendData($startDate, $endDate, $corporationId),
                'ore_distribution' => $this->analyticsService->getOreDistributionData($startDate, $endDate, $corporationId),
                'miner_activity' => $this->analyticsService->getMinerActivityData($startDate, $endDate, $corporationId),
                'system_activity' => $this->analyticsService->getSystemActivityData($startDate, $endDate, $corporationId),
                'heatmap' => $this->analyticsService->getHeatmapData($startDate, $endDate, $corporationId),
            ];

            return view('mining-manager::analytics.charts', array_merge(compact(
                'chartData', 'startDate', 'endDate',
                'corporationId', 'corporations', 'userCorporationId'
            ), ['features' => app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getFeatureFlags()]));
        } catch (\Exception $e) {
            Log::error('Mining Manager: Analytics error: ' . $e->getMessage());
            return back()->with('error', 'An error occurred loading analytics data.');
        }
    }

    /**
     * Display detailed tables
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function tables(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'corporation_id' => 'nullable|integer',
        ]);

        try {
            $startDate = $request->input('start_date')
                ? Carbon::parse($request->input('start_date'))
                : Carbon::now()->subDays(30);

            $endDate = $request->input('end_date')
                ? Carbon::parse($request->input('end_date'))
                : Carbon::now();

            // Corporation filter
            $corporations = $this->analyticsService->getCorporationsWithData();
            $corporationId = $this->getCorporationFilter($request, $corporations);
            $userCorporationId = $this->getUserCorporationId();

            // Get detailed table data
            $tableData = [
                'miner_stats' => $this->analyticsService->getMinerStatistics($startDate, $endDate, $corporationId),
                'ore_stats' => $this->analyticsService->getOreStatistics($startDate, $endDate, $corporationId),
                'system_stats' => $this->analyticsService->getSystemStatistics($startDate, $endDate, $corporationId),
            ];

            return view('mining-manager::analytics.tables', compact(
                'tableData', 'startDate', 'endDate',
                'corporationId', 'corporations', 'userCorporationId'
            ));
        } catch (\Exception $e) {
            Log::error('Mining Manager: Analytics error: ' . $e->getMessage());
            return back()->with('error', 'An error occurred loading analytics data.');
        }
    }

    /**
     * Display comparative analysis
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function compare(Request $request)
    {
        $validated = $request->validate([
            'comparison_type' => 'nullable|in:periods,miners,systems,ores',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'corporation_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            // Corporation filter — for compare, show ALL corporations (even without data)
            $corporations = $this->analyticsService->getAllCorporations();
            $corporationId = $this->getCorporationFilter($request, $corporations);
            $userCorporationId = $this->getUserCorporationId();

            // Check if comparison data should be generated
            if (!$request->has('comparison_type')) {
                return view('mining-manager::analytics.compare', compact(
                    'corporationId', 'corporations', 'userCorporationId'
                ));
            }

            $comparisonType = $request->input('comparison_type');
            $comparisonData = [];

            switch ($comparisonType) {
                case 'periods':
                    $comparisonData = $this->comparePeriods($request, $corporationId);
                    break;
                case 'miners':
                    $comparisonData = $this->compareMiners($request, $corporationId);
                    break;
                case 'systems':
                    $comparisonData = $this->compareSystems($request, $corporationId);
                    break;
                case 'ores':
                    $comparisonData = $this->compareOres($request, $corporationId);
                    break;
            }

            return view('mining-manager::analytics.compare', array_merge(compact(
                'comparisonData', 'comparisonType',
                'corporationId', 'corporations', 'userCorporationId'
            ), ['features' => app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getFeatureFlags()]));
        } catch (\Exception $e) {
            Log::error('Mining Manager: Analytics error: ' . $e->getMessage());
            return back()->with('error', 'An error occurred loading analytics data.');
        }
    }

    /**
     * Compare two time periods
     *
     * @param Request $request
     * @param int|null $corporationId
     * @return array
     */
    private function comparePeriods(Request $request, ?int $corporationId = null)
    {
        $period1Start = Carbon::parse($request->input('period1_start'));
        $period1End = Carbon::parse($request->input('period1_end'));
        $period2Start = Carbon::parse($request->input('period2_start'));
        $period2End = Carbon::parse($request->input('period2_end'));

        // Get data for both periods
        $period1Data = $this->getPeriodData($period1Start, $period1End, $corporationId);
        $period2Data = $this->getPeriodData($period2Start, $period2End, $corporationId);

        // Calculate differences and percentages (change = P2 relative to P1)
        $metrics = $this->calculateMetricComparisons($period1Data, $period2Data);

        // Determine which period performed better
        $topPerformerPeriod = $period1Data['total_value'] > $period2Data['total_value'] ? 1 : 2;

        return [
            'labels' => [
                $period1Start->format('M d') . ' - ' . $period1End->format('M d, Y'),
                $period2Start->format('M d') . ' - ' . $period2End->format('M d, Y'),
            ],
            'metrics' => $metrics,
            'volume_data' => [$period1Data['total_volume'], $period2Data['total_volume']],
            'volume_m3_data' => [$period1Data['total_volume_m3'], $period2Data['total_volume_m3']],
            'value_data' => [$period1Data['total_value'], $period2Data['total_value']],
            'trend_labels' => $this->getTrendLabels($period1Start, $period1End),
            'trend_datasets' => $this->getTrendDatasets($period1Start, $period1End, $period2Start, $period2End),
            'detailed' => $this->getDetailedComparison($period1Data, $period2Data),
            'top_period_1' => $this->analyticsService->getTopMiners($period1Start, $period1End, 5, $corporationId),
            'top_period_2' => $this->analyticsService->getTopMiners($period2Start, $period2End, 5, $corporationId),
            'top_performer_period' => $topPerformerPeriod,
            'insights' => $this->generateInsights($period1Data, $period2Data),
        ];
    }

    /**
     * Get data for a specific period
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId
     * @return array
     */
    private function getPeriodData($startDate, $endDate, ?int $corporationId = null)
    {
        return [
            'total_volume' => $this->analyticsService->getTotalVolume($startDate, $endDate, $corporationId),
            'total_volume_m3' => $this->analyticsService->getTotalVolumeM3($startDate, $endDate, $corporationId),
            'total_value' => $this->analyticsService->getTotalValue($startDate, $endDate, $corporationId),
            'unique_miners' => $this->analyticsService->getUniqueMinerCount($startDate, $endDate, $corporationId),
            'ore_breakdown' => $this->analyticsService->getOreBreakdown($startDate, $endDate, null, $corporationId),
            'system_breakdown' => $this->analyticsService->getSystemBreakdown($startDate, $endDate, $corporationId),
        ];
    }

    /**
     * Calculate metric comparisons
     *
     * @param array $period1
     * @param array $period2
     * @return array
     */
    private function calculateMetricComparisons($period1, $period2)
    {
        $metrics = [];

        // Quantity comparison (units)
        $qtyDiff = $period2['total_volume'] - $period1['total_volume'];
        $qtyChange = $period1['total_volume'] > 0
            ? (($qtyDiff / $period1['total_volume']) * 100)
            : 0;

        $metrics[] = [
            'label' => trans('mining-manager::analytics.total_quantity_units'),
            'value_1' => number_format($period1['total_volume'], 0) . ' units',
            'value_2' => number_format($period2['total_volume'], 0) . ' units',
            'change' => number_format(abs($qtyChange), 1) . '%',
            'change_type' => $qtyChange > 0 ? 'positive' : ($qtyChange < 0 ? 'negative' : 'neutral'),
        ];

        // Volume comparison (m³)
        $m3Diff = $period2['total_volume_m3'] - $period1['total_volume_m3'];
        $m3Change = $period1['total_volume_m3'] > 0
            ? (($m3Diff / $period1['total_volume_m3']) * 100)
            : 0;

        $metrics[] = [
            'label' => trans('mining-manager::analytics.total_volume_m3'),
            'value_1' => number_format($period1['total_volume_m3'], 0) . ' m³',
            'value_2' => number_format($period2['total_volume_m3'], 0) . ' m³',
            'change' => number_format(abs($m3Change), 1) . '%',
            'change_type' => $m3Change > 0 ? 'positive' : ($m3Change < 0 ? 'negative' : 'neutral'),
        ];

        // Value comparison
        $valueDiff = $period2['total_value'] - $period1['total_value'];
        $valueChange = $period1['total_value'] > 0
            ? (($valueDiff / $period1['total_value']) * 100)
            : 0;

        $metrics[] = [
            'label' => trans('mining-manager::analytics.total_value'),
            'value_1' => number_format($period1['total_value'] / 1000000, 2) . 'M ISK',
            'value_2' => number_format($period2['total_value'] / 1000000, 2) . 'M ISK',
            'change' => number_format(abs($valueChange), 1) . '%',
            'change_type' => $valueChange > 0 ? 'positive' : ($valueChange < 0 ? 'negative' : 'neutral'),
        ];

        // Miners comparison
        $minersDiff = $period2['unique_miners'] - $period1['unique_miners'];
        $minersChange = $period1['unique_miners'] > 0
            ? (($minersDiff / $period1['unique_miners']) * 100)
            : 0;

        $metrics[] = [
            'label' => trans('mining-manager::analytics.unique_miners'),
            'value_1' => number_format($period1['unique_miners']),
            'value_2' => number_format($period2['unique_miners']),
            'change' => number_format(abs($minersChange), 1) . '%',
            'change_type' => $minersChange > 0 ? 'positive' : ($minersChange < 0 ? 'negative' : 'neutral'),
        ];

        return $metrics;
    }

    /**
     * Generate insights based on comparison
     *
     * @param array $period1
     * @param array $period2
     * @return array
     */
    private function generateInsights($period1, $period2)
    {
        $insights = [];

        // Value insight: change from P1 to P2
        $valueDiff = $period1['total_value'] > 0
            ? (($period2['total_value'] - $period1['total_value']) / $period1['total_value']) * 100
            : 0;
        if (abs($valueDiff) > 10) {
            $insights[] = [
                'type' => $valueDiff > 0 ? 'success' : 'warning',
                'icon' => $valueDiff > 0 ? 'arrow-up' : 'arrow-down',
                'title' => $valueDiff > 0
                    ? trans('mining-manager::analytics.significant_increase')
                    : trans('mining-manager::analytics.significant_decrease'),
                'message' => trans('mining-manager::analytics.value_changed_by', [
                    'percent' => number_format(abs($valueDiff), 1)
                ]),
            ];
        }

        // Miner activity insight: change from P1 to P2
        $minerDiff = $period1['unique_miners'] > 0
            ? (($period2['unique_miners'] - $period1['unique_miners']) / $period1['unique_miners']) * 100
            : 0;
        if (abs($minerDiff) > 15) {
            $insights[] = [
                'type' => 'info',
                'icon' => 'users',
                'title' => trans('mining-manager::analytics.miner_activity_change'),
                'message' => trans('mining-manager::analytics.miner_count_changed_by', [
                    'percent' => number_format(abs($minerDiff), 1)
                ]),
            ];
        }

        return $insights;
    }

    /**
     * Compare miners - show top miners by quantity and value
     *
     * @param Request $request
     * @param int|null $corporationId
     * @return array
     */
    private function compareMiners(Request $request, ?int $corporationId = null)
    {
        $startDate = $request->input('miner_start')
            ? Carbon::parse($request->input('miner_start'))
            : Carbon::now()->subDays(30);
        $endDate = $request->input('miner_end')
            ? Carbon::parse($request->input('miner_end'))
            : Carbon::now();
        $limit = $request->input('limit', 10);

        // Get character IDs for corporation filter
        $corpCharIds = $corporationId
            ? DB::table('character_affiliations')->where('corporation_id', $corporationId)->pluck('character_id')->toArray()
            : null;

        // Get top miners by quantity
        $queryByQty = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('character_id, SUM(quantity) as total_quantity, SUM(total_value) as total_value, COUNT(DISTINCT date) as days_active')
            ->groupBy('character_id')
            ->orderByDesc('total_quantity')
            ->limit($limit);
        if ($corpCharIds !== null) {
            $queryByQty->whereIn('character_id', $corpCharIds);
        }
        $topByQuantity = $queryByQty->with('character')->get()
            ->map(function($ledger) {
                return [
                    'character_id' => $ledger->character_id,
                    'character_name' => $ledger->character->name ?? 'Unknown',
                    'total_quantity' => $ledger->total_quantity,
                    'total_value' => $ledger->total_value,
                    'days_active' => $ledger->days_active,
                    'avg_per_day' => $ledger->days_active > 0 ? $ledger->total_quantity / $ledger->days_active : 0,
                ];
            });

        // Get top miners by value
        $queryByVal = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('character_id, SUM(total_value) as total_value, SUM(quantity) as total_quantity, COUNT(DISTINCT date) as days_active')
            ->groupBy('character_id')
            ->orderByDesc('total_value')
            ->limit($limit);
        if ($corpCharIds !== null) {
            $queryByVal->whereIn('character_id', $corpCharIds);
        }
        $topByValue = $queryByVal->with('character')->get()
            ->map(function($ledger) {
                return [
                    'character_id' => $ledger->character_id,
                    'character_name' => $ledger->character->name ?? 'Unknown',
                    'total_value' => $ledger->total_value,
                    'total_quantity' => $ledger->total_quantity,
                    'days_active' => $ledger->days_active,
                    'avg_value_per_day' => $ledger->days_active > 0 ? $ledger->total_value / $ledger->days_active : 0,
                ];
            });

        return [
            'by_quantity' => $topByQuantity,
            'by_value' => $topByValue,
            'date_range' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
        ];
    }

    /**
     * Compare systems - show most productive solar systems
     *
     * @param Request $request
     * @param int|null $corporationId
     * @return array
     */
    private function compareSystems(Request $request, ?int $corporationId = null)
    {
        $startDate = $request->input('system_start')
            ? Carbon::parse($request->input('system_start'))
            : Carbon::now()->subDays(30);
        $endDate = $request->input('system_end')
            ? Carbon::parse($request->input('system_end'))
            : Carbon::now();
        $limit = $request->input('limit', 10);

        $corpCharIds = $corporationId
            ? DB::table('character_affiliations')->where('corporation_id', $corporationId)->pluck('character_id')->toArray()
            : null;

        // Get mining activity by system
        $query = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('solar_system_id')
            ->selectRaw('solar_system_id, SUM(quantity) as total_quantity, SUM(total_value) as total_value, COUNT(DISTINCT character_id) as unique_miners, COUNT(DISTINCT date) as days_active')
            ->groupBy('solar_system_id')
            ->orderByDesc('total_value')
            ->limit($limit);
        if ($corpCharIds !== null) {
            $query->whereIn('character_id', $corpCharIds);
        }
        $systemStats = $query->get()
            ->map(function($stat) {
                // Get system name from SeAT's database
                $systemName = \DB::table('solar_systems')
                    ->where('system_id', $stat->solar_system_id)
                    ->value('name');

                return [
                    'solar_system_id' => $stat->solar_system_id,
                    'system_name' => $systemName ?? 'Unknown System',
                    'total_quantity' => $stat->total_quantity,
                    'total_value' => $stat->total_value,
                    'unique_miners' => $stat->unique_miners,
                    'days_active' => $stat->days_active,
                    'avg_value_per_day' => $stat->days_active > 0 ? $stat->total_value / $stat->days_active : 0,
                ];
            });

        return [
            'systems' => $systemStats,
            'date_range' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
        ];
    }

    /**
     * Compare ore types - show most valuable and most mined ores
     *
     * @param Request $request
     * @param int|null $corporationId
     * @return array
     */
    private function compareOres(Request $request, ?int $corporationId = null)
    {
        $startDate = $request->input('ore_start')
            ? Carbon::parse($request->input('ore_start'))
            : Carbon::now()->subDays(30);
        $endDate = $request->input('ore_end')
            ? Carbon::parse($request->input('ore_end'))
            : Carbon::now();
        $limit = $request->input('limit', 15);

        $corpCharIds = $corporationId
            ? DB::table('character_affiliations')->where('corporation_id', $corporationId)->pluck('character_id')->toArray()
            : null;

        // Get ore statistics by value
        $queryByVal = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('type_id, SUM(quantity) as total_quantity, SUM(total_value) as total_value, COUNT(DISTINCT character_id) as miners_count')
            ->groupBy('type_id')
            ->orderByDesc('total_value')
            ->limit($limit);
        if ($corpCharIds !== null) {
            $queryByVal->whereIn('character_id', $corpCharIds);
        }
        $oreStats = $queryByVal->get()
            ->map(function($stat) {
                $oreName = \DB::table('invTypes')
                    ->where('typeID', $stat->type_id)
                    ->value('typeName');

                return [
                    'type_id' => $stat->type_id,
                    'ore_name' => $oreName ?? 'Unknown Ore',
                    'total_quantity' => $stat->total_quantity,
                    'total_value' => $stat->total_value,
                    'miners_count' => $stat->miners_count,
                    'avg_value_per_unit' => $stat->total_quantity > 0 ? $stat->total_value / $stat->total_quantity : 0,
                ];
            });

        // Get most mined ores (by quantity)
        $queryByQty = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('type_id, SUM(quantity) as total_quantity')
            ->groupBy('type_id')
            ->orderByDesc('total_quantity')
            ->limit($limit);
        if ($corpCharIds !== null) {
            $queryByQty->whereIn('character_id', $corpCharIds);
        }
        $mostMined = $queryByQty->get()
            ->map(function($stat) {
                $oreName = \DB::table('invTypes')
                    ->where('typeID', $stat->type_id)
                    ->value('typeName');

                return [
                    'type_id' => $stat->type_id,
                    'ore_name' => $oreName ?? 'Unknown Ore',
                    'total_quantity' => $stat->total_quantity,
                ];
            });

        return [
            'by_value' => $oreStats,
            'by_quantity' => $mostMined,
            'date_range' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
        ];
    }

    /**
     * Display moon analytics (no corporation filter — structure-centric data)
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function moons(Request $request)
    {
        $validated = $request->validate([
            'view_mode' => 'nullable|in:monthly,extraction',
            'month' => 'nullable|date_format:Y-m',
            'extraction_id' => 'nullable|integer',
        ]);

        try {
            $moonService = app(MoonAnalyticsService::class);

            $viewMode = $request->input('view_mode', 'monthly');
            $month = $request->input('month')
                ? Carbon::parse($request->input('month') . '-01')
                : Carbon::now()->startOfMonth();

            $extractionId = $request->input('extraction_id');

            $data = [
                'viewMode' => $viewMode,
                'month' => $month,
                'availableExtractions' => $moonService->getAvailableExtractions($month),
            ];

            if ($viewMode === 'extraction' && $extractionId) {
                $data['extractionData'] = $moonService->getExtractionUtilization((int) $extractionId);
                $data['selectedExtraction'] = $extractionId;
            } else {
                $data['summary'] = $moonService->getSummaryStats($month);
                $data['utilization'] = $moonService->getMoonUtilization($month);
                $data['popularity'] = $moonService->getMoonPopularity($month);
                $data['orePopularity'] = $moonService->getOrePopularity($month);
                $data['poolOreDistribution'] = $moonService->getPoolOreDistribution($month);
            }

            return view('mining-manager::analytics.moons', $data);
        } catch (\Exception $e) {
            Log::error('Mining Manager: Analytics error: ' . $e->getMessage());
            return back()->with('error', 'An error occurred loading analytics data.');
        }
    }

    /**
     * Export analytics data
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function export(Request $request)
    {
        if (!$this->dataExportIsAllowed()) {
            return $this->refuseDataExport($request);
        }

        $validated = $request->validate([
            'format' => 'nullable|in:csv,json',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'corporation_id' => 'nullable|integer',
        ]);

        try {
            $format = $request->input('format', 'csv');
            $startDate = $request->input('start_date')
                ? Carbon::parse($request->input('start_date'))
                : Carbon::now()->subDays(30);

            $endDate = $request->input('end_date')
                ? Carbon::parse($request->input('end_date'))
                : Carbon::now();

            $corporationId = $this->getCorporationFilter($request);

            // Get export data
            $data = $this->analyticsService->getExportData($startDate, $endDate, $corporationId);

            if ($format === 'json') {
                return response()->json(['data' => $data]);
            }

            // CSV export — streamed via php://output rather than building
            // the full string in memory. Concatenating every row into a
            // single PHP string before returning can hit hundreds of MB for
            // active corps with months of mining data. The ledger export
            // path streams for the same reason.
            $headers = ['Character', 'Ore Type', 'Quantity', 'Value', 'System', 'Date'];
            $filename = 'mining_analytics_' . $startDate->format('Y-m-d') . '_' . $endDate->format('Y-m-d') . '.csv';

            return response()->streamDownload(function () use ($headers, $data) {
                $out = fopen('php://output', 'w');
                // fputcsv handles RFC 4180 quoting / escaping for us;
                // safer than the manual str_replace approach below.
                fputcsv($out, $headers);
                foreach ($data as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv',
            ]);
        } catch (\Exception $e) {
            Log::error('Mining Manager: Analytics error: ' . $e->getMessage());
            return back()->with('error', 'An error occurred loading analytics data.');
        }
    }

    /**
     * Get analytics data via AJAX
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function data(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'corporation_id' => 'nullable|integer',
            'type' => 'nullable|in:overview,trends,ore_distribution,miner_activity',
        ]);

        try {
            $startDate = $request->input('start_date')
                ? Carbon::parse($request->input('start_date'))
                : Carbon::now()->subDays(30);

            $endDate = $request->input('end_date')
                ? Carbon::parse($request->input('end_date'))
                : Carbon::now();

            $corporationId = $this->getCorporationFilter($request);

            $type = $request->input('type', 'overview');

            switch ($type) {
                case 'trends':
                    return response()->json($this->analyticsService->getDailyTrends($startDate, $endDate, $corporationId));
                case 'ore_distribution':
                    return response()->json($this->analyticsService->getOreDistributionData($startDate, $endDate, $corporationId));
                case 'miner_activity':
                    return response()->json($this->analyticsService->getMinerActivityData($startDate, $endDate, $corporationId));
                default:
                    return response()->json([
                        'total_volume' => $this->analyticsService->getTotalVolume($startDate, $endDate, $corporationId),
                        'total_value' => $this->analyticsService->getTotalValue($startDate, $endDate, $corporationId),
                        'unique_miners' => $this->analyticsService->getUniqueMinerCount($startDate, $endDate, $corporationId),
                    ]);
            }
        } catch (\Exception $e) {
            Log::error('Mining Manager: Analytics error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to load analytics data'], 500);
        }
    }

    /**
     * Get trend labels for period comparison chart
     */
    private function getTrendLabels($start, $end)
    {
        $labels = [];
        $current = $start->copy();
        while ($current->lte($end)) {
            $labels[] = $current->format('M d');
            $current->addDay();
        }
        return $labels;
    }

    /**
     * Get trend datasets for period comparison chart
     */
    private function getTrendDatasets($p1Start, $p1End, $p2Start, $p2End)
    {
        $p1Trends = $this->analyticsService->getDailyTrends($p1Start, $p1End);
        $p2Trends = $this->analyticsService->getDailyTrends($p2Start, $p2End);

        return [
            [
                'label' => $p1Start->format('M d') . ' - ' . $p1End->format('M d'),
                'data' => $p1Trends->pluck('total_value')->toArray(),
            ],
            [
                'label' => $p2Start->format('M d') . ' - ' . $p2End->format('M d'),
                'data' => $p2Trends->pluck('total_value')->toArray(),
            ],
        ];
    }

    /**
     * Get detailed comparison between two periods
     */
    private function getDetailedComparison($period1, $period2)
    {
        return [
            'ore_comparison' => [
                'period1' => $period1['ore_breakdown']->take(10)->toArray(),
                'period2' => $period2['ore_breakdown']->take(10)->toArray(),
            ],
            'system_comparison' => [
                'period1' => $period1['system_breakdown']->take(5)->toArray(),
                'period2' => $period2['system_breakdown']->take(5)->toArray(),
            ],
        ];
    }
}
