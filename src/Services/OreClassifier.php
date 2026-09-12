<?php

namespace MiningManager\Services;

/**
 * What kind of ore a type id is, for tax and for reporting.
 *
 * TypeIdRegistry holds the ids. It says which ids are Bitumens and which are
 * Hezorime, and that is all it should ever say. Deciding that Hezorime counts
 * as abyssal for tax purposes is policy, not data, and it lives here.
 *
 * This used to be six copies. ProcessMiningLedgerCommand, ImportCharacter-
 * MiningCommand, BackfillOreTypeFlagsCommand, EventMiningAggregator,
 * LedgerSummaryService and TaxCalculationService each carried their own version
 * of the same ordered checks. They were written from each other and drifted
 * anyway: five of them answered "moon" where the sixth answered "moon_r4", and
 * all six shared a hardcoded abyssal test that predated nine ore families CCP
 * has since shipped, so every one of them called Hezorime plain belt ore.
 *
 * There are two vocabularies here and they are deliberately different. The
 * mining_ledger.ore_category column stores "abyssal" and "triglavian"; the tax
 * rate settings are keyed "abyssal_ore" and "triglavian_ore". Both are public
 * in the sense that data and operator settings already use them, so neither can
 * be quietly renamed to match the other.
 */
final class OreClassifier
{
    /**
     * Ore families that count as abyssal.
     *
     * ABYSSAL_ORES on its own is the original Bezdnacine, Rakovene and
     * Talassonite set. The nine families below arrived later, were added to the
     * registry, and were never added to the classification check, which is why
     * an install taxing abyssal ore collected nothing on them.
     *
     * Compressed variants are included for completeness even though they cannot
     * reach a mining ledger. You compress ore, you do not mine it compressed.
     *
     * @return array<int>
     */
    public static function abyssalTypeIds(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        return $cache = array_merge(
            TypeIdRegistry::ABYSSAL_ORES,
            TypeIdRegistry::MORDUNIUM_ORES,
            TypeIdRegistry::COMPRESSED_MORDUNIUM_ORES,
            TypeIdRegistry::YTIRIUM_ORES,
            TypeIdRegistry::COMPRESSED_YTIRIUM_ORES,
            TypeIdRegistry::EIFYRIUM_ORES,
            TypeIdRegistry::COMPRESSED_EIFYRIUM_ORES,
            TypeIdRegistry::DUCINIUM_ORES,
            TypeIdRegistry::COMPRESSED_DUCINIUM_ORES,
            TypeIdRegistry::GRIEMEER_ORES,
            TypeIdRegistry::COMPRESSED_GRIEMEER_ORES,
            TypeIdRegistry::NOCXITE_ORES,
            TypeIdRegistry::COMPRESSED_NOCXITE_ORES,
            TypeIdRegistry::KYLIXIUM_ORES,
            TypeIdRegistry::COMPRESSED_KYLIXIUM_ORES,
            TypeIdRegistry::HEZORIME_ORES,
            TypeIdRegistry::COMPRESSED_HEZORIME_ORES,
            TypeIdRegistry::UEGANITE_ORES,
            TypeIdRegistry::COMPRESSED_UEGANITE_ORES
        );
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
     * also be, and plain ore is the fallback rather than a category anything is
     * positively identified as.
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

    /**
     * The five booleans every import path stamps on a ledger row.
     *
     * Returned together so an importer cannot set four of them from here and
     * work the fifth out for itself, which is how the abyssal flag came to
     * disagree with the category sitting next to it.
     *
     * @return array{is_moon_ore:bool,is_ice:bool,is_gas:bool,is_abyssal:bool,is_triglavian:bool}
     */
    public static function flags(int $typeId): array
    {
        return [
            'is_moon_ore' => TypeIdRegistry::isMoonOre($typeId),
            'is_ice' => TypeIdRegistry::isIce($typeId),
            'is_gas' => TypeIdRegistry::isGas($typeId),
            'is_abyssal' => self::isAbyssal($typeId),
            'is_triglavian' => TypeIdRegistry::isTriglavianOre($typeId),
        ];
    }
}
