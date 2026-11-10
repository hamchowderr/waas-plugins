<?php
/**
 * Polylang's functions AnyPress Client's Languages switch calls, for PHPStan only (Polylang 3.9). Polylang is off on
 * most stores, so every caller checks Languages::is_ready() first. PLL() returns Polylang's main object, whose
 * members PHPStan treats as unknown.
 */

/** @return mixed */
function PLL() {}
/** @return string[]|object[] */
function pll_languages_list(array $args = []) {}
/** @return string|false */
function pll_default_language(string $field = 'slug') {}
/** @return string|false */
function pll_get_post_language(int $post_id, string $field = 'slug') {}
/** @return int|false|null */
function pll_get_post(int $post_id, string $lang = '') {}
function pll_set_post_language(int $id, string $lang): bool {}
/** @return array<string, int> */
function pll_get_post_translations(int $post_id): array {}
/** @param array<string, int> $arr */
function pll_save_post_translations(array $arr): array {}
function pll_count_posts(string $lang, array $args = []): int {}
