<?php

namespace MiningManager\Services\Events;

use MiningManager\Models\MiningEvent;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\EventMiningRecord;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Pricing\OreValuationService;
use Seat\Eveapi\Models\Industry\CharacterMining;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use MiningManager\Services\OreClassifier;

/**
 * EventMiningAggregator
 *
 * Materialises the set of mining records that qualify for a given event
 * into the event_mining_records table. Acts as the single place where
 * all four scope filters (corporation, location, time, ore category) are
 * applied; every downstream consumer can then read from the materialised
 * table without re-implementing the filter logic.
 *
 * Source routing by event type
 * ============================
 *   moon_extraction  → mining_ledger (observer rows, day granularity)
 *   mining_op        → character_minings (belt ore, SeAT-fetch-time precision)
 *   ice_mining       → character_minings (ice only)
 *   gas_huffing      → character_minings (gas only)
 *   special          → BOTH: moon-category rows from observer, everything
 *                     else from character_minings. Split avoids double-
 *                     counting a moon-ore entry that might appear on both
 *                     feeds (observer is authoritative for moon output).
 *
 * Corp filter
 * ===========
 * Matches the semantic used by LedgerSummaryService::getEventAttributionForLedgerRow():
 * an event with a specific corporation_id applies to miners whose *current*
 * corporation_id (from character_infos) matches; null corp means global.
 *
 * Called from:
 *   - UpdateMiningEventsCommand cron (for every active event, every tick)
 *   - MiningEvent::saved observer (re-aggregate when scope is edited)
 *   - artisan mining-manager:backfill-event-records (one-off backfill)
 *
 * Idempotent by design: upserts on the composite natural key
 * (event_id, character_id, mining_date, mining_time, type_id, solar_system_id, observer_id).
 * Running every minute doesn't duplicate rows.
 */
class EventMiningAggregator
{
    /**
     * @var OreValuationService
     */
    protected $valuationService;

    /**
     * @var SettingsManagerService
     */
    protected $settingsService;

    public function __construct(
        OreValuationService $valuationService,
        SettingsManagerService $settingsService
    ) {
        $this->valuationService = $valuationService;
        $this->settingsService = $settingsService;
    }

    /**
     * Materialise qualifying mining records for an event.
     *
     * @param MiningEvent $event
     * @param bool $fullRefresh If true, delete existing records for this event
     *                          before re-populating. Use when event scope has
     *                          been edited (location/corp/type/time changes).
     * @return array ['created' => int, 'updated' => int, 'skipped' => int, 'deleted' => int]
     */
    public function aggregate(MiningEvent $event, bool $fullRefresh = false): array
    {
        $deleted = 0;

        if ($fullRefresh) {
            $deleted = EventMiningRecord::where('event_id', $event->id)->delete();
            Log::info("Mining Manager: EventMiningAggregator full-refresh of event {$event->id} — deleted {$deleted} prior records");
        }

        $allowedCategories = MiningEvent::EVENT_TYPE_ORE_CATEGORIES[$event->type] ?? [];

        if (empty($allowedCategories)) {
            Log::warning("Mining Manager: EventMiningAggregator called for event {$event->id} with unknown type '{$event->type}' — no aggregation performed");
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => $deleted];
        }

        // Narrow the event's ore-category set to what the plugin is
        // actually taxing. Tracking participation for categories that
        // have tax_selector = false would produce misleading "won the
        // event, got 0 tax discount" outcomes. Moon categories pass
        // through unchanged here — aggregateFromObserver applies its
        // own moon-specific gate (no_moon_ore / only_corp_moon_ore /
        // all_moon_ore) which is more nuanced than a boolean toggle.
        $allowedCategories = $this->filterCategoriesByTaxSettings($event, $allowedCategories);

        if (empty($allowedCategories)) {
            Log::info("Mining Manager: EventMiningAggregator event {$event->id} — every category for event type '{$event->type}' is untaxed per current tax_selector; nothing to aggregate");
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => $deleted];
        }

        // Split categories by which source they come from.
        $moonCategories = array_values(array_filter(
            $allowedCategories,
            fn($c) => str_starts_with($c, 'moon_')
        ));
        $nonMoonCategories = array_values(array_filter(
            $allowedCategories,
            fn($c) => !str_starts_with($c, 'moon_')
        ));

        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => $deleted];

        DB::transaction(function () use ($event, $moonCategories, $nonMoonCategories, &$totals) {
            if (!empty($moonCategories)) {
                $obs = $this->aggregateFromObserver($event, $moonCategories);
                $totals['created'] += $obs['created'];
                $totals['updated'] += $obs['updated'];
                $totals['skipped'] += $obs['skipped'];
            }

            if (!empty($nonMoonCategories)) {
                $cm = $this->aggregateFromCharacterMining($event, $nonMoonCategories);
                $totals['created'] += $cm['created'];
                $totals['updated'] += $cm['updated'];
                $totals['skipped'] += $cm['skipped'];
            }
        });

        Log::info(sprintf(
            "Mining Manager: EventMiningAggregator event %d (%s) — created %d, updated %d, skipped %d%s",
            $event->id,
            $event->type,
            $totals['created'],
            $totals['updated'],
            $totals['skipped'],
            $deleted > 0 ? " (refreshed, deleted {$deleted} prior)" : ''
        ));

        return $totals;
    }

    /**
     * Pull qualifying rows from mining_ledger (corp observer data).
     *
     * Observer data is day-aggregated — we copy the date and use '00:00:00'
     * as the placeholder mining_time. The ore_category already lives on
     * mining_ledger (populated at ingestion).
     *
     * Source pool follows the plugin's tax_selector setting
     * ===================================================
     * Event moon data should reflect what the plugin is taxing:
     *
     *   no_moon_ore          → no moon rows at all (plugin doesn't tax moon ore)
     *   only_corp_moon_ore   → only observer rows where corporation_id =
     *                           general.moon_owner_corporation_id. This
     *                           matches what LedgerSummaryService::shouldTaxType
     *                           enforces during tax calculation.
     *   all_moon_ore         → all observer rows, regardless of which corp
     *                           owns the moon / installed the observer.
     *
     * Miner-corp filter
     * =================
     * Applied on top of the source pool:
     *   • Corp-scoped event (event.corporation_id set) →
     *       miner's current corp == event.corporation_id
     *   • Global event (event.corporation_id null) →
     *       no miner-corp filter (anyone's moon mining counts)
     *
     * Note: no "miner corp must equal moon owner corp" constraint. A
     * Corp-B miner mining at a Corp-A moon legitimately counts for a
     * Corp-B event (because the miner is a Corp B member) and for any
     * global event — as long as the moon row is in the source pool per
     * the tax-scope setting above.
     *
     * @param MiningEvent $event
     * @param array $allowedCategories Moon categories to include.
     * @return array ['created' => int, 'updated' => int, 'skipped' => int]
     */
    private function aggregateFromObserver(MiningEvent $event, array $allowedCategories): array
    {
        // Honour the plugin's moon-ore tax scope setting so event data
        // reflects the same pool the tax engine operates on.
        $taxSelector = $this->settingsService->getTaxSelector();

        if (!empty($taxSelector['no_moon_ore'])) {
            Log::debug("Mining Manager: EventMiningAggregator event {$event->id} — plugin has no_moon_ore set, skipping observer pass");
            return ['created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $startDate = $event->start_time->toDateString();
        $endDate = ($event->end_time ?? Carbon::now())->toDateString();

        $query = MiningLedger::whereNotNull('observer_id')
            ->whereBetween('date', [$startDate, $endDate])
            ->whereIn('ore_category', $allowedCategories);

        // only_corp_moon_ore narrows the source pool to the moon-owner
        // corp's observers — same restriction shouldTaxType() applies.
        if (!empty($taxSelector['only_corp_moon_ore'])) {
            $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');

            if (!$moonOwnerCorpId) {
                Log::warning("Mining Manager: EventMiningAggregator event {$event->id} — only_corp_moon_ore is set but moon_owner_corporation_id is unconfigured; observer pass skipped");
                return ['created' => 0, 'updated' => 0, 'skipped' => 0];
            }

            $query->where('corporation_id', (int) $moonOwnerCorpId);
        }
        // all_moon_ore (the default) needs no extra filter — any observer row qualifies.

        // Location scope — resolves constellation/region to system IDs.
        $event->applyLocationFilter($query, 'solar_system_id');

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        // Miner-corp lookup (in PHP) for corp-scoped events. Unused for
        // global events but cheap enough that we always resolve it.
        $characterCorps = $this->resolveCharacterCorps(
            $rows->pluck('character_id')->unique()->all()
        );

        $created = 0; $updated = 0; $skipped = 0;

        foreach ($rows as $row) {
            // Corp-scoped event: require miner's current corp = event corp.
            // Global event: no miner-corp filter.
            if ($event->corporation_id !== null) {
                $charCorp = $characterCorps[$row->character_id] ?? null;
                if ($charCorp !== (int) $event->corporation_id) {
                    $skipped++;
                    continue;
                }
            }

            $record = EventMiningRecord::updateOrCreate(
                [
                    'event_id' => $event->id,
                    'character_id' => $row->character_id,
                    'mining_date' => $row->date,
                    'mining_time' => '00:00:00',
                    'type_id' => $row->type_id,
                    'solar_system_id' => $row->solar_system_id,
                    'observer_id' => $row->observer_id,
                ],
                [
                    'ore_category' => $row->ore_category,
                    'quantity' => $row->quantity,
                    'unit_price' => $row->unit_price,
                    'value_isk' => $row->total_value,
                    'source' => EventMiningRecord::SOURCE_OBSERVER,
                    'recorded_at' => Carbon::now(),
                ]
            );

            $record->wasRecentlyCreated ? $created++ : $updated++;
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Pull qualifying rows from SeAT's character_minings table.
     *
     * Uses SeAT's TIME column to filter within the event's datetime window
     * (the time reflects when SeAT fetched the entry, not the literal EVE
     * mining moment — but it's the best sub-day signal we have for belt/
     * ice/gas events). Ore category must be classified per-row via
     * OreClassifier.
     *
     * @param MiningEvent $event
     * @param array $allowedCategories Non-moon categories to include.
     * @return array ['created' => int, 'updated' => int, 'skipped' => int]
     */
    private function aggregateFromCharacterMining(MiningEvent $event, array $allowedCategories): array
    {
        if (!class_exists(CharacterMining::class)) {
            Log::warning('Mining Manager: SeAT CharacterMining model not found — skipping character_mining aggregation');
            return ['created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $startDt = $event->start_time->toDateTimeString();
        $endDt = ($event->end_time ?? Carbon::now())->toDateTimeString();

        // CONCAT(date, ' ', time) gives us a full datetime to compare against
        // the event window — SeAT splits the components into two columns.
        $query = CharacterMining::query()
            ->whereRaw("CONCAT(`date`, ' ', `time`) >= ?", [$startDt])
            ->whereRaw("CONCAT(`date`, ' ', `time`) <= ?", [$endDt]);

        // Location scope on character_minings.solar_system_id.
        $event->applyLocationFilter($query, 'solar_system_id');

        // Corp filter via JOIN to character_affiliations on the miner's
        // current corp. corporation_id was dropped from character_infos in
        // SeAT 2019 and now lives only in character_affiliations.
        if ($event->corporation_id !== null) {
            $query->whereExists(function ($sub) use ($event) {
                $sub->select(DB::raw(1))
                    ->from('character_affiliations')
                    ->whereColumn('character_affiliations.character_id', 'character_minings.character_id')
                    ->where('character_affiliations.corporation_id', $event->corporation_id);
            });
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        // Build a (char, date, type, system) → historical aggregate map from
        // mining_ledger so we can carve the ledger's total_value proportionally
        // across the character_minings rows that make it up. Falls back to
        // OreValuationService (current prices) for rows not yet ingested into
        // mining_ledger or rows where processing left total_value at zero.
        $ledgerMap = $this->buildLedgerPriceMap($rows);

        $created = 0; $updated = 0; $skipped = 0;

        foreach ($rows as $row) {
            $category = $this->classifyOreCategory($row->type_id);

            if (!in_array($category, $allowedCategories, true)) {
                $skipped++;
                continue;
            }

            // Normalise the date to a string key so lookup matches how
            // buildLedgerPriceMap indexed the data.
            $dateStr = $row->date instanceof \DateTimeInterface
                ? $row->date->format('Y-m-d')
                : (string) $row->date;

            $ledgerKey = sprintf(
                '%d|%s|%d|%d',
                $row->character_id,
                $dateStr,
                $row->type_id,
                $row->solar_system_id
            );

            $ledger = $ledgerMap[$ledgerKey] ?? null;

            if ($ledger && $ledger['total_quantity'] > 0 && $ledger['total_value'] > 0) {
                // Historical-price path: proportionally allocate ledger value
                // across the character_minings rows for this slice.
                // (char_minings sums on the same slice should equal ledger.quantity,
                // but we don't rely on that — proportion against whatever each row
                // contributes to the ledger's captured total_quantity.)
                $proportion = $row->quantity / $ledger['total_quantity'];
                $unitPrice = $ledger['unit_price'];
                $totalValue = $ledger['total_value'] * $proportion;
            } else {
                // Fallback: no matching ledger row (or unpriced) — compute at
                // aggregation time using current market prices. Affects only
                // recent mining that hasn't been processed into mining_ledger
                // yet, OR mining_ledger rows that never got priced.
                $values = $this->valuationService->calculateOreValue($row->type_id, $row->quantity);
                $unitPrice = $values['unit_price'] ?? 0;
                $totalValue = $values['total_value'] ?? 0;
            }

            // mining_time comes from SeAT as a TIME value — normalise to string
            // so the unique key comparison behaves consistently across drivers.
            $miningTime = $row->time instanceof \DateTimeInterface
                ? $row->time->format('H:i:s')
                : (string) $row->time;

            $record = EventMiningRecord::updateOrCreate(
                [
                    'event_id' => $event->id,
                    'character_id' => $row->character_id,
                    'mining_date' => $row->date,
                    'mining_time' => $miningTime,
                    'type_id' => $row->type_id,
                    'solar_system_id' => $row->solar_system_id,
                    'observer_id' => null,
                ],
                [
                    'ore_category' => $category,
                    'quantity' => $row->quantity,
                    'unit_price' => $unitPrice,
                    'value_isk' => $totalValue,
                    'source' => EventMiningRecord::SOURCE_CHARACTER_MINING,
                    'recorded_at' => Carbon::now(),
                ]
            );

            $record->wasRecentlyCreated ? $created++ : $updated++;
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Build a historical-price lookup map keyed by (char, date, type, system).
     *
     * For non-moon events we want event_mining_records.value_isk anchored to
     * the historical prices that ProcessMiningLedgerCommand froze into
     * mining_ledger.total_value, not to the aggregator's current price cache.
     * Otherwise re-running a backfill months after an event would retroactively
     * rewrite event value with today's prices.
     *
     * The map captures mining_ledger's aggregate for each slice:
     *   - total_quantity: summed across observer_id variants (usually just one row)
     *   - total_value:    historical ISK value (frozen at ingestion)
     *   - unit_price:     historical unit price
     *
     * Each character_minings row in aggregateFromCharacterMining() then
     * contributes (row.quantity / ledger.total_quantity) × ledger.total_value
     * as its portion of the historical value. If ledger has no matching
     * entry (mining still pending ingestion), the caller falls back to
     * current prices.
     *
     * @param  \Illuminate\Support\Collection  $characterMiningRows
     * @return array<string, array{total_quantity:int, total_value:float, unit_price:float}>
     */
    private function buildLedgerPriceMap($characterMiningRows): array
    {
        if ($characterMiningRows->isEmpty()) {
            return [];
        }

        $characterIds = $characterMiningRows->pluck('character_id')->unique()->values()->all();

        $dates = $characterMiningRows->pluck('date')->map(function ($d) {
            return $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d;
        })->unique()->values()->all();

        // Single aggregate query across the (charIds × dates) span.
        // The existing (character_id, date) index makes this cheap even
        // when we happen to pull extra type/system combinations.
        $ledgerAggs = MiningLedger::whereIn('character_id', $characterIds)
            ->whereIn('date', $dates)
            ->selectRaw('
                character_id,
                `date`,
                type_id,
                solar_system_id,
                SUM(quantity) as total_quantity,
                SUM(total_value) as total_value,
                MAX(unit_price) as unit_price
            ')
            ->groupBy('character_id', 'date', 'type_id', 'solar_system_id')
            ->get();

        $map = [];
        foreach ($ledgerAggs as $agg) {
            $dateStr = $agg->date instanceof \DateTimeInterface
                ? $agg->date->format('Y-m-d')
                : (string) $agg->date;

            $key = sprintf(
                '%d|%s|%d|%d',
                $agg->character_id,
                $dateStr,
                $agg->type_id,
                $agg->solar_system_id
            );

            $map[$key] = [
                'total_quantity' => (int) $agg->total_quantity,
                'total_value' => (float) $agg->total_value,
                'unit_price' => (float) $agg->unit_price,
            ];
        }

        return $map;
    }

    /**
     * Narrow an event's allowed ore categories to those the plugin is
     * currently taxing, so event participation never tracks ore the
     * plugin ignores.
     *
     * Non-moon categories map 1:1 to boolean tax_selector keys:
     *   ore          → tax_selector.ore
     *   ice          → tax_selector.ice
     *   gas          → tax_selector.gas
     *   abyssal      → tax_selector.abyssal_ore
     *   triglavian   → tax_selector.triglavian_ore
     *
     * Moon categories (moon_r4 through moon_r64) are NOT filtered here —
     * the moon scope is more nuanced (no_moon_ore / only_corp_moon_ore /
     * all_moon_ore) and is enforced inside aggregateFromObserver().
     *
     * Any category that got dropped is logged so organisers can see in
     * the log why a "gas_huffing" event is empty on a gas-untaxed install.
     */
    private function filterCategoriesByTaxSettings(MiningEvent $event, array $categories): array
    {
        $taxSelector = $this->settingsService->getTaxSelector();

        $map = [
            'ore' => 'ore',
            'ice' => 'ice',
            'gas' => 'gas',
            'abyssal' => 'abyssal_ore',
            'triglavian' => 'triglavian_ore',
        ];

        $kept = [];
        $dropped = [];

        foreach ($categories as $category) {
            if (str_starts_with($category, 'moon_')) {
                // Moon categories handled by aggregateFromObserver's own tax gate.
                $kept[] = $category;
                continue;
            }

            $settingKey = $map[$category] ?? null;
            if ($settingKey === null) {
                $kept[] = $category; // Unknown category — let it through
                continue;
            }

            if (!empty($taxSelector[$settingKey])) {
                $kept[] = $category;
            } else {
                $dropped[] = $category;
            }
        }

        if (!empty($dropped)) {
            Log::info(sprintf(
                'Mining Manager: EventMiningAggregator event %d — dropped untaxed categories [%s] per tax_selector; configure tax settings or adjust event type to resolve',
                $event->id,
                implode(', ', $dropped)
            ));
        }

        return $kept;
    }

    /**
     * Look up current corporation_id for each character in bulk.
     *
     * Current affiliation (corp, alliance, faction) lives in the
     * character_affiliations table — NOT character_infos. The corporation_id
     * column was removed from character_infos in SeAT 2019 and migrated
     * to character_affiliations. Other parts of this plugin (AnalyticsController,
     * DashboardController, etc.) already use DB::table('character_affiliations')
     * for this — we match that pattern rather than introducing a new model.
     *
     * @param array $characterIds
     * @return array<int, int> Map of character_id => corporation_id
     */
    private function resolveCharacterCorps(array $characterIds): array
    {
        if (empty($characterIds)) {
            return [];
        }

        return DB::table('character_affiliations')
            ->whereIn('character_id', $characterIds)
            ->pluck('corporation_id', 'character_id')
            ->map(fn($v) => (int) $v)
            ->toArray();
    }

    /**
     * Classify an ore type_id into a category string.
     *
     * Mirrors the helper in ImportCharacterMiningCommand /
     * ProcessMiningLedgerCommand — kept local here for aggregation-time
     * classification of character_minings rows (which don't carry a
     * pre-computed category like mining_ledger does).
     */
    private function classifyOreCategory(int $typeId): string
    {
        return OreClassifier::category($typeId);
    }
}
