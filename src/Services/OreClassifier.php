<?php

namespace MiningManager\Services;

/**
 * What kind of ore a type id is, for tax and for reporting.
 *
 * TypeIdRegistry holds the ids. It says which ids are Bitumens and which are
 * Hezorime, and that is all it should ever say. Which tax category an ore falls
 * under is policy, not data, and it lives here.
 *
 * This used to be six copies. ProcessMiningLedgerCommand, ImportCharacter-
 * MiningCommand, BackfillOreTypeFlagsCommand, EventMiningAggregator,
 * LedgerSummaryService and TaxCalculationService each carried their own version
 * of the same ordered checks. They were written from each other and had already
 * drifted: five answered "moon" for a moon ore with no rarity on file while the
 * sixth answered "moon_r4", which is a real tax rate.
 *
 * There are two vocabularies here and they are deliberately different. The
 * mining_ledger.ore_category column stores "abyssal" and "triglavian"; the tax
 * rate settings are keyed "abyssal_ore" and "triglavian_ore". Both are already
 * in stored data and operator settings, so neither can be quietly renamed to
 * match the other.
 */
final class OreClassifier
{
    /**
     * The ores that count as abyssal: the Bezdnacine, Rakovene and Talassonite
     * families.
     *
     * Easy to widen by mistake. The Deep Space Survey families (Mordunium,
     * Ytirium, Eifyrium, Ducinium) and the Ore Prospecting Array families
     * (Griemeer, Nocxite, Kylixium, Hezorime, Ueganite) are newer and sound as
     * though they belong here, but they are nullsec and wormhole asteroid ore
     * that refines into ordinary minerals. They are regular ore. Counting them
     * as abyssal would move them off an operator's ore rate and onto the
     * abyssal one, and on an install that taxes ore but not abyssal ore they
     * would quietly stop being charged.
     *
     * @return array<int>
     */
    public static function abyssalTypeIds(): array
    {
        return TypeIdRegistry::ABYSSAL_ORES;
    }

    public static function isAbyssal(int $typeId): bool
    {
        return in_array($typeId, self::abyssalTypeIds(), true);
    }

    /**
     * The value written to mining_ledger.ore_category.
     *
     * Order matters and is the order the plugin has always used. Moon ore is
     * decided first because a moon rock is a moon rock whatever else it might
     * also be, and regular ore is the fallback rather than a category anything
     * is positively identified as.
     */
    public static function category(int $typeId): string
    {
        if (TypeIdRegistry::isMoonOre($typeId)) {
            $rarity = TypeIdRegistry::getMoonOreRarity($typeId);

            // No rarity means a moon ore the rarity map has not been told
            // about. Saying so is better than picking a tier: the only tier
            // worth guessing would be the cheapest, and quietly charging r4 for
            // something that might be r64 is the kind of wrong nobody notices.
            return $rarity ? 'moon_' . $rarity : 'moon';
        }

        if (TypeIdRegistry::isIce($typeId)) {
            return 'ice';
        }

        if (TypeIdRegistry::isGas($typeId)) {
            return 'gas';
        }

        if (self::isAbyssal($typeId)) {
            return 'abyssal';
        }

        if (TypeIdRegistry::isTriglavianOre($typeId)) {
            return 'triglavian';
        }

        return 'ore';
    }

    /**
     * The key a tax rate is looked up under.
     *
     * Moon ore collapses to a single "moon" here because the rate for moon ore
     * is chosen by rarity through a separate lookup, not by this string.
     */
    public static function taxCategory(int $typeId): string
    {
        if (TypeIdRegistry::isMoonOre($typeId)) {
            return 'moon';
        }

        if (TypeIdRegistry::isIce($typeId)) {
            return 'ice';
        }

        if (TypeIdRegistry::isGas($typeId)) {
            return 'gas';
        }

        if (self::isAbyssal($typeId)) {
            return 'abyssal_ore';
        }

        if (TypeIdRegistry::isTriglavianOre($typeId)) {
            return 'triglavian_ore';
        }

        return 'ore';
    }
}
