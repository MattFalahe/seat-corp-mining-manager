<?php

namespace MiningManager\Services\Analytics;

use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningPriceCache;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Character\CharacterInfoService;
use MiningManager\Services\TypeIdRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Mining Analytics Service for SeAT v5.x
 *
 * This service provides analytics and statistics for mining operations.
 * Uses pre-computed total_value from mining_ledger for consistent ISK values.
 * All query methods accept an optional $corporationId to filter by miner's corporation.
 */
class MiningAnalyticsService
{
    /**
     * Settings manager service
     */
    protected SettingsManagerService $settingsService;

    /**
     * Character info service
     */
    protected CharacterInfoService $characterInfoService;

    public function __construct(SettingsManagerService $settingsService, CharacterInfoService $characterInfoService)
    {
        $this->settingsService = $settingsService;
        $this->characterInfoService = $characterInfoService;
    }

    /**
     * Get character IDs belonging to a corporation.
     * Uses character_affiliations table for current corp membership.
     *
     * @param int $corporationId
     * @return array
     */
    protected function getCorporationCharacterIds(int $corporationId): array
    {
        $cacheKey = "mining-analytics:corp-chars:{$corporationId}";
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($corporationId) {
            return DB::table('character_affiliations')
                ->where('corporation_id', $corporationId)
                ->pluck('character_id')
                ->toArray();
        });
    }

    /**
     * Apply corporation filter to a query by restricting to characters in that corporation.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $corporationId
     * @return void
     */
    protected function applyCorporationFilter($query, ?int $corporationId): void
    {
        if ($corporationId) {
            $characterIds = $this->getCorporationCharacterIds($corporationId);
            if (!empty($characterIds)) {
                $query->whereIn('mining_ledger.character_id', $characterIds);
            } else {
                // No characters in this corp — force empty result
                $query->whereRaw('1 = 0');
            }
        }
    }

    /**
     * Build a corporation-aware cache key suffix.
     *
     * @param int|null $corporationId
     * @return string
     */
    protected function corpCacheKey(?int $corporationId): string
    {
        return $corporationId ? ":corp{$corporationId}" : ':all';
    }

    /**
     * Get corporations that have mining data in the system.
     * Returns corporation_id => corporation_name pairs.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getCorporationsWithData()
    {
        $cacheKey = "mining-analytics:corps-with-data";
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () {
            return DB::table('mining_ledger')
                ->join('character_affiliations', 'mining_ledger.character_id', '=', 'character_affiliations.character_id')
                ->join('corporation_infos', 'character_affiliations.corporation_id', '=', 'corporation_infos.corporation_id')
                ->select('character_affiliations.corporation_id', 'corporation_infos.name')
                ->distinct()
                ->orderBy('corporation_infos.name')
                ->get()
                ->pluck('name', 'corporation_id');
        });
    }

    /**
     * Get all known corporations from SeAT (for comparative analysis).
     * Returns corporation_id => corporation_name pairs.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getAllCorporations()
    {
        $cacheKey = "mining-analytics:all-corps";
        $cacheDuration = 60; // Cache for 1 hour

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () {
            return DB::table('corporation_infos')
                ->select('corporation_id', 'name')
                ->orderBy('name')
                ->get()
                ->pluck('name', 'corporation_id');
        });
    }

    /**
     * Get total quantity mined in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return int
     */
    public function getTotalVolume(Carbon $startDate, Carbon $endDate, ?int $corporationId = null): int
    {
        $cacheKey = "mining-analytics:total-volume:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}" . $this->corpCacheKey($corporationId);
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $corporationId) {
            $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate]);
            $this->applyCorporationFilter($query, $corporationId);
            return $query->sum('mining_ledger.quantity');
        });
    }

    /**
     * Get total volume in m³ mined in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return float
     */
    public function getTotalVolumeM3(Carbon $startDate, Carbon $endDate, ?int $corporationId = null): float
    {
        $cacheKey = "mining-analytics:total-volume-m3:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}" . $this->corpCacheKey($corporationId);
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $corporationId) {
            $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
                ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID');
            $this->applyCorporationFilter($query, $corporationId);
            return (float) $query->selectRaw('COALESCE(SUM(mining_ledger.quantity * invTypes.volume), 0) as total_m3')
                ->value('total_m3');
        });
    }

    /**
     * Get total ISK value of ore mined in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return float
     */
    public function getTotalValue(Carbon $startDate, Carbon $endDate, ?int $corporationId = null): float
    {
        $cacheKey = "mining-analytics:total-value:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}" . $this->corpCacheKey($corporationId);
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $corporationId) {
            $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate]);
            $this->applyCorporationFilter($query, $corporationId);
            return (float) $query->sum('mining_ledger.total_value');
        });
    }

    /**
     * Get count of unique miners in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return int
     */
    public function getUniqueMinerCount(Carbon $startDate, Carbon $endDate, ?int $corporationId = null): int
    {
        $cacheKey = "mining-analytics:unique-miners:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}" . $this->corpCacheKey($corporationId);
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $corporationId) {
            $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate]);
            $this->applyCorporationFilter($query, $corporationId);
            return $query->distinct('mining_ledger.character_id')
                ->count('mining_ledger.character_id');
        });
    }

    /**
     * Get top miners by quantity in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int $limit
     * @param int|null $corporationId Filter by miner's corporation
     * @return \Illuminate\Support\Collection
     */
    public function getTopMiners(Carbon $startDate, Carbon $endDate, int $limit = 10, ?int $corporationId = null)
    {
        $cacheKey = "mining-analytics:top-miners:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}:{$limit}" . $this->corpCacheKey($corporationId);
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $limit, $corporationId) {
            $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
                ->join('character_infos', 'mining_ledger.character_id', '=', 'character_infos.character_id');
            $this->applyCorporationFilter($query, $corporationId);
            return $query->select(
                    'mining_ledger.character_id',
                    'character_infos.name',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw('SUM(mining_ledger.total_value) as total_value')
                )
                ->groupBy('mining_ledger.character_id', 'character_infos.name')
                ->orderByDesc('total_quantity')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Get top miners grouped by account (main character), summing across alts.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int $limit
     * @param int|null $corporationId Filter by miner's corporation
     * @return \Illuminate\Support\Collection
     */
    public function getTopMinersByAccount(Carbon $startDate, Carbon $endDate, int $limit = 20, ?int $corporationId = null, ?ChartFilter $filter = null)
    {
        $filter = $filter ?? ChartFilter::none();
        $cacheKey = "mining-analytics:top-miners-account:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}:{$limit}" . $this->corpCacheKey($corporationId) . $filter->cacheKey();
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $limit, $corporationId, $filter) {
            // Get per-character mining data (left join so non-SeAT characters are included)
            $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
                ->leftJoin('character_infos', 'mining_ledger.character_id', '=', 'character_infos.character_id');
            $this->applyCorporationFilter($query, $corporationId);
            $filter->applyTo($query);
            $perCharacter = $query->select(
                    'mining_ledger.character_id',
                    'character_infos.name',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw('SUM(mining_ledger.total_value) as total_value')
                )
                ->groupBy('mining_ledger.character_id', 'character_infos.name')
                ->get();

            if ($perCharacter->isEmpty()) {
                return collect();
            }

            // Map character_id -> user_id via refresh_tokens
            $characterIds = $perCharacter->pluck('character_id')->toArray();
            $userMap = DB::table('refresh_tokens')
                ->whereIn('character_id', $characterIds)
                ->pluck('user_id', 'character_id')
                ->toArray();

            // Get SeAT user main character: users.main_character_id
            $userMainChars = DB::table('users')
                ->whereIn('id', array_unique(array_values($userMap)))
                ->pluck('main_character_id', 'id')
                ->toArray();

            // Get all character names we might need
            $allCharIds = array_unique(array_merge($characterIds, array_values($userMainChars)));
            $charNames = DB::table('character_infos')
                ->whereIn('character_id', $allCharIds)
                ->pluck('name', 'character_id')
                ->toArray();

            // Resolve missing guest names via CharacterInfoService
            $guestCharIds = array_diff($characterIds, array_keys($userMap));
            $missingGuestIds = array_filter($guestCharIds, fn($id) => !isset($charNames[$id]));
            if (!empty($missingGuestIds)) {
                $batchInfo = $this->characterInfoService->getBatchCharacterInfo(array_values($missingGuestIds));
                foreach ($batchInfo as $cId => $info) {
                    if (!empty($info['name']) && $info['name'] !== "Character {$cId}") {
                        $charNames[$cId] = $info['name'];
                    }
                }
            }

            // Group by SeAT account (user_id)
            $grouped = [];
            foreach ($perCharacter as $miner) {
                $userId = $userMap[$miner->character_id] ?? null;

                if ($userId) {
                    $mainId = $userMainChars[$userId] ?? $miner->character_id;
                    $groupKey = $userId;
                } else {
                    // Guest miner — no SeAT account
                    $mainId = $miner->character_id;
                    $groupKey = 'guest_' . $miner->character_id;
                }

                if (!isset($grouped[$groupKey])) {
                    $grouped[$groupKey] = [
                        'main_character_id' => $mainId,
                        'name' => $charNames[$mainId] ?? ($miner->name ?? "Unknown #{$miner->character_id}"),
                        'total_quantity' => 0,
                        'total_value' => 0,
                        'character_count' => 0,
                    ];
                }

                $grouped[$groupKey]['total_quantity'] += $miner->total_quantity;
                $grouped[$groupKey]['total_value'] += $miner->total_value;
                $grouped[$groupKey]['character_count']++;
            }

            // Sort by total_quantity descending, limit, and return as collection of objects
            return collect($grouped)
                ->sortByDesc('total_quantity')
                ->take($limit)
                ->values()
                ->map(function ($item) {
                    return (object) $item;
                });
        });
    }

    /**
     * Get ore type breakdown in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param string|null $category Optional category filter
     * @param int|null $corporationId Filter by miner's corporation
     * @return \Illuminate\Support\Collection
     */
    public function getOreBreakdown(Carbon $startDate, Carbon $endDate, ?string $category = null, ?int $corporationId = null, ?ChartFilter $filter = null)
    {
        $filter = $filter ?? ChartFilter::none();
        $categoryKey = $category ?? 'all';
        $cacheKey = "mining-analytics:ore-breakdown:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}:{$categoryKey}" . $this->corpCacheKey($corporationId) . $filter->cacheKey();
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $category, $corporationId, $filter) {
            $query = MiningLedger::with('type')
                ->whereBetween('mining_ledger.date', [$startDate, $endDate]);

            $this->applyCorporationFilter($query, $corporationId);
            $filter->applyTo($query);

            // Filter by ore category if specified
            if ($category) {
                $typeIds = $this->getTypeIdsForCategory($category);
                if (!empty($typeIds)) {
                    $query->whereIn('mining_ledger.type_id', $typeIds);
                }
            }

            $results = $query->select(
                    'mining_ledger.type_id',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw('SUM(mining_ledger.total_value) as total_value')
                )
                ->groupBy('mining_ledger.type_id')
                ->orderByDesc('total_quantity')
                ->get();

            // Add ore names from the type relationship
            return $results->map(function($item) {
                $item->ore_name = $item->type_name ?? 'Unknown';
                return $item;
            });
        });
    }

    /**
     * Get solar system breakdown in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return \Illuminate\Support\Collection
     */
    public function getSystemBreakdown(Carbon $startDate, Carbon $endDate, ?int $corporationId = null, ?ChartFilter $filter = null)
    {
        $filter = $filter ?? ChartFilter::none();
        $cacheKey = "mining-analytics:system-breakdown:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}" . $this->corpCacheKey($corporationId) . $filter->cacheKey();
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $corporationId, $filter) {
            $query = MiningLedger::with('solarSystem')
                ->whereBetween('mining_ledger.date', [$startDate, $endDate]);
            $this->applyCorporationFilter($query, $corporationId);
            $filter->applyTo($query);
            $results = $query->select(
                    'mining_ledger.solar_system_id',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw('SUM(mining_ledger.total_value) as total_value'),
                    DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners')
                )
                ->groupBy('mining_ledger.solar_system_id')
                ->orderByDesc('total_value')
                ->get();

            // Add system names from the relationship
            return $results->map(function($item) {
                $item->system_name = $item->system_name ?? 'Unknown';
                return $item;
            });
        });
    }

    /**
     * Get daily mining trends in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return \Illuminate\Support\Collection
     */
    public function getDailyTrends(Carbon $startDate, Carbon $endDate, ?int $corporationId = null, ?ChartFilter $filter = null)
    {
        $filter = $filter ?? ChartFilter::none();
        $cacheKey = "mining-analytics:daily-trends:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}" . $this->corpCacheKey($corporationId) . $filter->cacheKey();
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $corporationId, $filter) {
            $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate]);
            $this->applyCorporationFilter($query, $corporationId);
            $filter->applyTo($query);
            return $query->select(
                    'mining_ledger.date',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw('SUM(mining_ledger.total_value) as total_value'),
                    DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners')
                )
                ->groupBy('mining_ledger.date')
                ->orderBy('mining_ledger.date')
                ->get();
        });
    }

    /**
     * Get character mining statistics.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return \Illuminate\Support\Collection
     */
    public function getCharacterStatistics(Carbon $startDate, Carbon $endDate, ?int $corporationId = null)
    {
        $cacheKey = "mining-analytics:char-stats:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}" . $this->corpCacheKey($corporationId);
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $corporationId) {
            $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
                ->join('character_infos', 'mining_ledger.character_id', '=', 'character_infos.character_id')
                ->leftJoin('character_affiliations', 'mining_ledger.character_id', '=', 'character_affiliations.character_id')
                ->leftJoin('corporation_infos', 'character_affiliations.corporation_id', '=', 'corporation_infos.corporation_id');
            $this->applyCorporationFilter($query, $corporationId);
            return $query->select(
                    'mining_ledger.character_id',
                    'character_infos.name',
                    'corporation_infos.name as corporation_name',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw('SUM(mining_ledger.total_value) as total_value'),
                    DB::raw('COUNT(DISTINCT mining_ledger.date) as days_active'),
                    DB::raw('COUNT(DISTINCT mining_ledger.type_id) as unique_ore_types'),
                    DB::raw('COUNT(DISTINCT mining_ledger.solar_system_id) as unique_systems'),
                    DB::raw('MAX(mining_ledger.date) as last_activity')
                )
                ->groupBy('mining_ledger.character_id', 'character_infos.name', 'corporation_infos.name')
                ->orderByDesc('total_quantity')
                ->get();
        });
    }

    /**
     * Get detailed ore statistics.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return \Illuminate\Support\Collection
     */
    public function getOreStatistics(Carbon $startDate, Carbon $endDate, ?int $corporationId = null)
    {
        $cacheKey = "mining-analytics:ore-stats:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}" . $this->corpCacheKey($corporationId);
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $corporationId) {
            $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
                ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID');
            $this->applyCorporationFilter($query, $corporationId);
            return $query->select(
                    'mining_ledger.type_id',
                    'invTypes.typeName as ore_name',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw('SUM(mining_ledger.total_value) as total_value'),
                    DB::raw('CASE WHEN SUM(mining_ledger.quantity) > 0 THEN SUM(mining_ledger.total_value) / SUM(mining_ledger.quantity) ELSE 0 END as average_price'),
                    DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners')
                )
                ->groupBy('mining_ledger.type_id', 'invTypes.typeName')
                ->orderByDesc('total_quantity')
                ->get();
        });
    }

    /**
     * Get detailed system statistics.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return \Illuminate\Support\Collection
     */
    public function getSystemStatistics(Carbon $startDate, Carbon $endDate, ?int $corporationId = null)
    {
        $cacheKey = "mining-analytics:system-stats:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}" . $this->corpCacheKey($corporationId);
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $corporationId) {
            $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
                ->join('solar_systems', 'mining_ledger.solar_system_id', '=', 'solar_systems.system_id')
                ->leftJoin('mapDenormalize as md_system', function ($join) {
                    $join->on('solar_systems.system_id', '=', 'md_system.itemID')
                         ->where('md_system.groupID', '=', 5);
                })
                ->leftJoin('mapDenormalize as md_region', function ($join) {
                    $join->on('md_system.regionID', '=', 'md_region.itemID')
                         ->where('md_region.groupID', '=', 3);
                });
            $this->applyCorporationFilter($query, $corporationId);
            return $query->select(
                    'mining_ledger.solar_system_id',
                    'solar_systems.name as system_name',
                    'solar_systems.security as security_status',
                    'md_region.itemName as region_name',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw('SUM(mining_ledger.total_value) as total_value'),
                    DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners'),
                    DB::raw('COUNT(DISTINCT mining_ledger.type_id) as unique_ore_types'),
                    DB::raw('COUNT(DISTINCT mining_ledger.date) as days_active')
                )
                ->groupBy('mining_ledger.solar_system_id', 'solar_systems.name', 'solar_systems.security', 'md_region.itemName')
                ->orderByDesc('total_quantity')
                ->get();
        });
    }

    /**
     * Get export data for analytics.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return array
     */
    public function getExportData(Carbon $startDate, Carbon $endDate, ?int $corporationId = null, ?ChartFilter $filter = null): array
    {
        $filter = $filter ?? ChartFilter::none();

        $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
            ->join('character_infos', 'mining_ledger.character_id', '=', 'character_infos.character_id')
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->join('solar_systems', 'mining_ledger.solar_system_id', '=', 'solar_systems.system_id');
        $this->applyCorporationFilter($query, $corporationId);

        // Same slice as the charts above the button. An export that quietly
        // ignored the filters would hand somebody a file that disagrees with
        // the page they took it from.
        $filter->applyTo($query);
        return $query->select(
                'character_infos.name as character',
                'invTypes.typeName as ore_type',
                'mining_ledger.quantity',
                'mining_ledger.total_value as value',
                'solar_systems.name as system',
                'mining_ledger.date'
            )
            ->orderBy('mining_ledger.date', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    $item->character,
                    $item->ore_type,
                    $item->quantity,
                    number_format($item->value, 2),
                    $item->system,
                    $item->date->format('Y-m-d'),
                ];
            })
            ->toArray();
    }

    /**
     * Get mining trend data for charts.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return \Illuminate\Support\Collection
     */
    public function getMiningTrendData(Carbon $startDate, Carbon $endDate, ?int $corporationId = null, ?ChartFilter $filter = null)
    {
        return $this->getDailyTrends($startDate, $endDate, $corporationId, $filter);
    }

    /**
     * Get miner statistics for tables view.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return \Illuminate\Support\Collection
     */
    public function getMinerStatistics(Carbon $startDate, Carbon $endDate, ?int $corporationId = null)
    {
        return $this->getCharacterStatistics($startDate, $endDate, $corporationId);
    }

    /**
     * Get ore distribution data for charts.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return array
     */
    public function getOreDistributionData(Carbon $startDate, Carbon $endDate, ?int $corporationId = null, ?ChartFilter $filter = null)
    {
        $filter = $filter ?? ChartFilter::none();
        $cacheKey = "mining-analytics:ore-distribution-cat:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}" . $this->corpCacheKey($corporationId) . $filter->cacheKey();
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        $byCategory = Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $corporationId, $filter) {
            $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate]);
            $this->applyCorporationFilter($query, $corporationId);
            $filter->applyTo($query);
            return $query->select(
                    DB::raw("COALESCE(mining_ledger.ore_category, 'unknown') as category"),
                    DB::raw('SUM(mining_ledger.total_value) as total_value'),
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity')
                )
                ->groupBy('category')
                ->orderByDesc('total_value')
                ->get();
        });

        $categoryLabels = [
            'ore' => 'Regular Ore',
            'moon_r4' => 'Moon R4',
            'moon_r8' => 'Moon R8',
            'moon_r16' => 'Moon R16',
            'moon_r32' => 'Moon R32',
            'moon_r64' => 'Moon R64',
            'ice' => 'Ice',
            'gas' => 'Gas',
            'abyssal' => 'Abyssal',
            'triglavian' => 'Triglavian',
            'unknown' => 'Other',
        ];

        return [
            'labels' => $byCategory->map(fn($r) => $categoryLabels[$r->category] ?? ucfirst($r->category))->toArray(),
            'data' => $byCategory->pluck('total_quantity')->toArray(),
            'values' => $byCategory->pluck('total_value')->toArray(),
        ];
    }

    /**
     * Get miner activity data for charts.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return array
     */
    public function getMinerActivityData(Carbon $startDate, Carbon $endDate, ?int $corporationId = null, ?ChartFilter $filter = null)
    {
        $topMiners = $this->getTopMinersByAccount($startDate, $endDate, 10, $corporationId, $filter);

        return [
            'labels' => $topMiners->pluck('name')->toArray(),
            'data' => $topMiners->pluck('total_quantity')->toArray(),
            'values' => $topMiners->pluck('total_value')->toArray(),
        ];
    }

    /**
     * Get system activity data for charts.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return array
     */
    public function getSystemActivityData(Carbon $startDate, Carbon $endDate, ?int $corporationId = null, ?ChartFilter $filter = null)
    {
        $systemBreakdown = $this->getSystemBreakdown($startDate, $endDate, $corporationId, $filter);

        return [
            'labels' => $systemBreakdown->pluck('system_name')->toArray(),
            'data' => $systemBreakdown->pluck('total_quantity')->toArray(),
            'values' => $systemBreakdown->pluck('total_value')->toArray(),
        ];
    }

    /**
     * Get heatmap data: daily activity by day of week, with per-character breakdown.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $corporationId Filter by miner's corporation
     * @return array
     */
    public function getHeatmapData(Carbon $startDate, Carbon $endDate, ?int $corporationId = null, ?ChartFilter $filter = null)
    {
        $filter = $filter ?? ChartFilter::none();
        $cacheKey = "mining-analytics:heatmap:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}" . $this->corpCacheKey($corporationId) . $filter->cacheKey();
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $corporationId, $filter) {
            // Get daily data per character
            $query = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate]);
            $this->applyCorporationFilter($query, $corporationId);
            $filter->applyTo($query);
            $raw = $query->select(
                    'mining_ledger.date',
                    'mining_ledger.character_id',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw('SUM(mining_ledger.total_value) as total_value')
                )
                ->groupBy('mining_ledger.date', 'mining_ledger.character_id')
                ->get();

            // Build day-of-week aggregation
            $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $byDow = array_fill(0, 7, ['value' => 0, 'quantity' => 0, 'miners' => [], 'days_count' => 0]);

            // Track unique dates per DOW for averaging
            $datesPerDow = array_fill(0, 7, []);

            foreach ($raw as $entry) {
                $date = Carbon::parse($entry->date);
                $dow = $date->dayOfWeekIso - 1; // 0=Monday ... 6=Sunday
                $byDow[$dow]['value'] += $entry->total_value;
                $byDow[$dow]['quantity'] += $entry->total_quantity;
                $byDow[$dow]['miners'][$entry->character_id] = ($byDow[$dow]['miners'][$entry->character_id] ?? 0) + $entry->total_value;
                $datesPerDow[$dow][$date->format('Y-m-d')] = true;
            }

            // Calculate averages and per-character data
            $result = [];
            foreach ($dayNames as $i => $name) {
                $numDays = max(1, count($datesPerDow[$i]));
                $result[] = [
                    'day' => $name,
                    'avg_value' => round($byDow[$i]['value'] / $numDays),
                    'avg_quantity' => round($byDow[$i]['quantity'] / $numDays),
                    'total_value' => $byDow[$i]['value'],
                    'total_quantity' => $byDow[$i]['quantity'],
                    'unique_miners' => count($byDow[$i]['miners']),
                    'days_in_range' => $numDays,
                ];
            }

            // Build per-character totals by day of week
            $characterTotals = [];
            foreach ($raw as $entry) {
                $charId = $entry->character_id;
                if (!isset($characterTotals[$charId])) {
                    $characterTotals[$charId] = array_fill(0, 7, 0);
                }
                $dow = Carbon::parse($entry->date)->dayOfWeekIso - 1;
                $characterTotals[$charId][$dow] += $entry->total_value;
            }

            $charIds = array_keys($characterTotals);

            // Build per-account (user) grouping
            $accountTotals = [];
            $accountNames = [];
            if (!empty($charIds)) {
                // Map character_id -> user_id via refresh_tokens
                $userMap = DB::table('refresh_tokens')
                    ->whereIn('character_id', $charIds)
                    ->pluck('user_id', 'character_id')
                    ->toArray();

                // Get SeAT user main character: users.main_character_id
                $userMainChars = DB::table('users')
                    ->whereIn('id', array_unique(array_values($userMap)))
                    ->pluck('main_character_id', 'id')
                    ->toArray();

                // Get all character names we might need
                $allCharIds = array_unique(array_merge($charIds, array_values($userMainChars)));
                $charNames = DB::table('character_infos')
                    ->whereIn('character_id', $allCharIds)
                    ->pluck('name', 'character_id')
                    ->toArray();

                // Identify guest character IDs (not in refresh_tokens) missing from character_infos
                $guestCharIds = array_diff($charIds, array_keys($userMap));
                $missingGuestIds = array_filter($guestCharIds, fn($id) => !isset($charNames[$id]));

                // Resolve missing guest names via CharacterInfoService (ESI/zKill fallback)
                if (!empty($missingGuestIds)) {
                    $batchInfo = $this->characterInfoService->getBatchCharacterInfo(array_values($missingGuestIds));
                    foreach ($batchInfo as $cId => $info) {
                        if (!empty($info['name']) && $info['name'] !== "Character {$cId}") {
                            $charNames[$cId] = $info['name'];
                        }
                    }
                }

                foreach ($characterTotals as $charId => $dowValues) {
                    $userId = $userMap[$charId] ?? null;

                    if ($userId) {
                        // Registered character — group under account
                        if (!isset($accountTotals[$userId])) {
                            $accountTotals[$userId] = array_fill(0, 7, 0);
                            // Use the SeAT main character name for this account
                            $mainCharId = $userMainChars[$userId] ?? $charId;
                            $accountNames[$userId] = $charNames[$mainCharId] ?? ($charNames[$charId] ?? "Account #{$userId}");
                        }
                        for ($d = 0; $d < 7; $d++) {
                            $accountTotals[$userId][$d] += $dowValues[$d];
                        }
                    } else {
                        // Guest miner — show as individual with resolved name
                        $guestKey = 'guest_' . $charId;
                        $accountTotals[$guestKey] = $dowValues;
                        $accountNames[$guestKey] = $charNames[$charId] ?? "Unknown #{$charId}";
                    }
                }
            }

            // Format account datasets
            $acctDatasets = [];
            foreach ($accountTotals as $key => $dowValues) {
                $acctDatasets[] = [
                    'label' => $accountNames[$key] ?? "Unknown",
                    'data' => $dowValues,
                ];
            }

            // Sort by total value descending so biggest contributors are at bottom of stack
            usort($acctDatasets, function ($a, $b) {
                return array_sum($b['data']) - array_sum($a['data']);
            });

            return [
                'summary' => $result,
                'day_labels' => $dayNames,
                'by_account' => $acctDatasets,
            ];
        });
    }

    /**
     * Get type IDs for an ore category.
     *
     * @param string $category
     * @return array
     */
    protected function getTypeIdsForCategory(string $category): array
    {
        return match ($category) {
            'moon_ore' => TypeIdRegistry::MOON_ORES,
            'regular_ore' => TypeIdRegistry::REGULAR_ORES,
            'ice' => array_merge(TypeIdRegistry::ICE, TypeIdRegistry::COMPRESSED_ICE),
            'gas' => array_merge(TypeIdRegistry::GAS_FULLERITES, TypeIdRegistry::GAS_BOOSTERS),
            'abyssal_ore' => TypeIdRegistry::ABYSSAL_ORES,
            'triglavian_ore' => TypeIdRegistry::TRIGLAVIAN_ORES,
            default => [],
        };
    }

    /**
     * Clear analytics cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        try {
            Cache::tags(['mining-analytics'])->flush();
        } catch (\Exception $e) {
            // File/database cache driver doesn't support tags
        }
    }
}
