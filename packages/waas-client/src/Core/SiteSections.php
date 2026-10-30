<?php

namespace WaaSClient\Core;

/**
 * A page's sections: its top-level blocks as parse_blocks() returns them, leaving out the whitespace between
 * blocks (which parse_blocks returns as empty freeform blocks). Agents address sections by their position.
 */
class SiteSections
{
    /** @return array<int, int> section position => index in $blocks */
    public static function positions(array $blocks): array
    {
        $positions = [];
        foreach ($blocks as $i => $block) {
            if (($block['blockName'] ?? null) !== null || trim((string) ($block['innerHTML'] ?? '')) !== '') {
                $positions[] = $i;
            }
        }

        return $positions;
    }

    /** A short outline: each section's block name, its name in the List View if it has one, and its first words. */
    public static function outline(array $blocks): array
    {
        $outline = [];
        foreach (self::positions($blocks) as $position => $i) {
            $block = $blocks[$i];
            $outline[] = array_filter([
                'section' => $position,
                'block' => $block['blockName'] ?? 'core/freeform',
                'name' => $block['attrs']['metadata']['name'] ?? null,
                'text' => self::excerpt(self::text($block)),
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $outline;
    }

    /** Replaces section $position with $new (parsed blocks). Null when there is no such section. */
    public static function replace(array $blocks, int $position, array $new): ?array
    {
        $positions = self::positions($blocks);
        if (!isset($positions[$position])) {
            return null;
        }
        array_splice($blocks, $positions[$position], 1, $new);

        return $blocks;
    }

    /** Removes section $position. Null when there is no such section. */
    public static function remove(array $blocks, int $position): ?array
    {
        return self::replace($blocks, $position, []);
    }

    /**
     * Inserts $new before section $position; null or a position past the end appends. Negative positions are
     * refused (null).
     */
    public static function insert(array $blocks, ?int $position, array $new): ?array
    {
        if ($position !== null && $position < 0) {
            return null;
        }
        $positions = self::positions($blocks);
        $at = $position === null || !isset($positions[$position]) ? count($blocks) : $positions[$position];
        array_splice($blocks, $at, 0, $new);

        return $blocks;
    }

    private static function text(array $block): string
    {
        $text = wp_strip_all_tags((string) ($block['innerHTML'] ?? ''));
        foreach ($block['innerBlocks'] ?? [] as $inner) {
            $text .= ' ' . self::text($inner);
        }

        return $text;
    }

    private static function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($text) > 120 ? rtrim(mb_substr($text, 0, 119)) . '…' : $text;
    }
}
