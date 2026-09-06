<?php

namespace MiningManager\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What slice of the mining ledger a chart is being asked about.
 *
 * Every analytics figure is cached, and every cache key is built by hand out of
 * the arguments its method received. Add a filter to the query without adding
 * it to the key and the page serves another filter's numbers for the next
 * fifteen minutes: no error, no empty result, just wrong totals that look
 * entirely plausible. That is the failure this class exists to make impossible.
 *
 * One object holds both halves. applyTo() narrows the query and cacheKey()
 * describes the same narrowing, so a filter that changes the data always
 * changes the key. Nothing else in the service has to remember to keep the two
 * in step, because there is only one place they are decided.
 *
 * An unfiltered instance returns an empty cache key, so existing entries stay
 * valid and every call site that never passes a filter behaves as it did.
 */
final class ChartFilter
{
    /** Everything mined, wherever it came from. */
    public const MOON_ALL = 'all';

    /** Only what came out of a structure this corporation owns. */
    public const MOON_MINE = 'my_moons';

    /** All moon ore, whoever's rock it was. */
    public const MOON_ANY = 'any_moons';

    /**
     * Moon ore that did not come from one of our observers.
     *
     * Worth being honest about: this is inferred, not recorded. The ledger says
     * "this is moon ore" and "no observer of ours saw it", and the conclusion
     * that it was somebody else's moon follows from those two facts rather than
     * from anything CCP tells us. Nearly always right, occasionally just an
     * observer we do not have. The page says so.
     */
    public const MOON_OTHER = 'other_moons';

    public const MOON_SOURCES = [
        self::MOON_ALL,
        self::MOON_MINE,
        self::MOON_ANY,
        self::MOON_OTHER,
    ];

    /**
     * Ore families a chart can be narrowed to.
     *
     * moon covers every R tier at once, since the moon-source filter is the
     * interesting axis there and six separate tiers in one dropdown is noise.
     */
    public const ORE_CATEGORIES = [
        'ore' => 'Regular Ore',
        'moon' => 'Moon Ore',
        'ice' => 'Ice',
        'gas' => 'Gas',
        'abyssal' => 'Abyssal',
        'triglavian' => 'Triglavian',
    ];

    private string $moonSource;

    private ?string $oreCategory;

    /** @var array<int> Character ids this is narrowed to; empty means everyone. */
    private array $characterIds;

    private ?int $moonOwnerCorporationId;

    /**
     * @param  array<int>  $characterIds
     */
    public function __construct(
        string $moonSource = self::MOON_ALL,
        ?string $oreCategory = null,
        array $characterIds = [],
        ?int $moonOwnerCorporationId = null
    ) {
        $this->moonSource = in_array($moonSource, self::MOON_SOURCES, true)
            ? $moonSource
            : self::MOON_ALL;

        $this->oreCategory = ($oreCategory !== null && array_key_exists($oreCategory, self::ORE_CATEGORIES))
            ? $oreCategory
            : null;

        $this->characterIds = array_values(array_unique(array_map('intval', $characterIds)));
        sort($this->characterIds);

        $this->moonOwnerCorporationId = $moonOwnerCorporationId;
    }

    /** Nothing narrowed. The state every existing call site is in. */
    public static function none(): self
    {
        return new self();
    }

    public function isActive(): bool
    {
        return $this->moonSource !== self::MOON_ALL
            || $this->oreCategory !== null
            || !empty($this->characterIds);
    }

    public function moonSource(): string
    {
        return $this->moonSource;
    }

    public function oreCategory(): ?string
    {
        return $this->oreCategory;
    }

    /** @return array<int> */
    public function characterIds(): array
    {
        return $this->characterIds;
    }

    /**
     * True when this slice leans on classification flags.
     *
     * Those flags are stamped at import and were only corrected from the
     * classification cutover forward, so a chart that reads them tells the
     * truth about recent mining and repeats an old mistake about the rest. The
     * page uses this to decide whether to say so.
     */
    public function dependsOnClassification(): bool
    {
        return $this->oreCategory !== null
            || in_array($this->moonSource, [self::MOON_ANY, self::MOON_OTHER], true);
    }

    /**
     * Narrow a mining_ledger query to this slice.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public function applyTo($query): void
    {
        $this->applyMoonSource($query);
        $this->applyOreCategory($query);

        if (!empty($this->characterIds)) {
            $query->whereIn('mining_ledger.character_id', $this->characterIds);
        }
    }

    /**
     * The same narrowing, as a string.
     *
     * Appended to the caller's existing key rather than replacing it, so an
     * unfiltered call keeps the key it has always had and its cached value
     * stays good. The character list is sorted in the constructor so one player
     * always produces one key regardless of how the ids arrived.
     */
    public function cacheKey(): string
    {
        if (!$this->isActive()) {
            return '';
        }

        $parts = [];

        if ($this->moonSource !== self::MOON_ALL) {
            $parts[] = 'src' . $this->moonSource;

            // Which observers count as ours is part of the answer, so a change
            // of moon owner has to invalidate rather than silently reuse.
            if (in_array($this->moonSource, [self::MOON_MINE, self::MOON_OTHER], true)) {
                $parts[] = 'own' . ($this->moonOwnerCorporationId ?? 0);
            }
        }

        if ($this->oreCategory !== null) {
            $parts[] = 'cat' . $this->oreCategory;
        }

        if (!empty($this->characterIds)) {
            // Hashed because a player with forty alts would otherwise produce a
            // key longer than some cache drivers will accept.
            $parts[] = 'chr' . substr(md5(implode(',', $this->characterIds)), 0, 12);
        }

        return ':' . implode(':', $parts);
    }

    /**
     * Observer ids belonging to the moon owner.
     *
     * Cached for the same reason everything else here is: this runs once per
     * chart and there are five charts on the page.
     *
     * @return array<int>
     */
    private function myObserverIds(): array
    {
        if (!$this->moonOwnerCorporationId) {
            return [];
        }

        $corpId = $this->moonOwnerCorporationId;

        try {
            return Cache::remember(
                "mining-analytics:my-observers:{$corpId}",
                now()->addMinutes((int) config('mining-manager.performance.query_cache_duration', 15)),
                function () use ($corpId) {
                    return DB::table('corporation_industry_mining_observers')
                        ->where('corporation_id', $corpId)
                        ->pluck('observer_id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                }
            );
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: could not read the corporation observer list for an analytics filter', [
                'corporation_id' => $corpId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function applyMoonSource($query): void
    {
        if ($this->moonSource === self::MOON_ALL) {
            return;
        }

        if ($this->moonSource === self::MOON_ANY) {
            $query->where('mining_ledger.is_moon_ore', true);

            return;
        }

        $observerIds = $this->myObserverIds();

        if ($this->moonSource === self::MOON_MINE) {
            // No observers means no moons of our own, so nothing qualifies.
            // Forcing empty is the honest answer; leaving it unfiltered would
            // quietly show everybody's mining under a heading that says ours.
            if (empty($observerIds)) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereIn('mining_ledger.observer_id', $observerIds);

            return;
        }

        // MOON_OTHER: moon ore we did not watch being mined. A null observer is
        // included on purpose, since personal character mining of moon ore came
        // from somewhere and it was not one of our structures.
        $query->where('mining_ledger.is_moon_ore', true);

        if (!empty($observerIds)) {
            $query->where(function ($q) use ($observerIds) {
                $q->whereNull('mining_ledger.observer_id')
                    ->orWhereNotIn('mining_ledger.observer_id', $observerIds);
            });
        }
    }

    private function applyOreCategory($query): void
    {
        if ($this->oreCategory === null) {
            return;
        }

        // Moon ore is spread across six R tiers in ore_category, and the flag
        // is what means "moon" regardless of tier.
        if ($this->oreCategory === 'moon') {
            $query->where('mining_ledger.is_moon_ore', true);

            return;
        }

        $query->where('mining_ledger.ore_category', $this->oreCategory);
    }
}
