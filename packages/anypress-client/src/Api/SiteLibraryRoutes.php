<?php

namespace AnyPressClient\Api;

use WP_Error;
use WP_REST_Request;

/**
 * SiteController's library routes: what there is to build with, beyond the patterns themselves. The pattern
 * categories (the theme's kits among them), the interactive blocks (AnyPress Blocks), and the theme's style
 * variations: whole looks (the kits), colour palettes, font sets, and the section and block styles used as
 * is-style-<slug> classes. All of it comes from WordPress's own registries, as the Site Editor shows it.
 */
trait SiteLibraryRoutes
{
    /** The pattern categories, with how many patterns each has, so find-patterns can be narrowed to one. */
    public function list_pattern_categories(): array
    {
        $counts = [];
        foreach (\WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern) {
            if (($pattern['inserter'] ?? true) === false) {
                continue;
            }
            foreach ($pattern['categories'] ?? [] as $category) {
                $counts[$category] = ($counts[$category] ?? 0) + 1;
            }
        }
        $list = [];
        foreach (\WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered() as $category) {
            if (empty($counts[$category['name']])) {
                continue;
            }
            $list[] = array_filter([
                'name' => $category['name'],
                'label' => $category['label'] ?? $category['name'],
                'description' => $category['description'] ?? null,
                'patterns' => $counts[$category['name']],
            ]);
        }

        return $list;
    }

    /**
     * The interactive blocks this website has beyond WordPress's own (AnyPress Blocks: carousel, popup, counter...):
     * each one's settings, and a few patterns that already use it, to read with get-pattern as an example.
     */
    public function list_blocks(): array
    {
        $types = array_filter(
            \WP_Block_Type_Registry::get_instance()->get_all_registered(),
            fn ($type) => strpos($type->name, 'anypress/') === 0
        );
        $uses = array_fill_keys(array_keys($types), []);
        foreach (\WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern) {
            foreach (array_keys($uses) as $name) {
                if (count($uses[$name]) < 3 && strpos($pattern['content'], '<!-- wp:' . $name . ' ') !== false) {
                    $uses[$name][] = $pattern['name'];
                }
            }
        }
        $list = [];
        foreach ($types as $type) {
            $attributes = [];
            foreach ($type->attributes ?? [] as $key => $attribute) {
                // WordPress adds its own (lock, metadata, className...) to every block; only the block's own are listed.
                if (in_array($key, ['lock', 'metadata', 'className', 'style', 'anchor', 'align', 'textColor', 'backgroundColor', 'fontSize', 'fontFamily', 'borderColor', 'gradient'], true)) {
                    continue;
                }
                $attributes[$key] = array_intersect_key($attribute, array_flip(['type', 'default', 'enum']));
            }
            $list[] = array_filter([
                'name' => $type->name,
                'title' => $type->title,
                'description' => $type->description,
                'parent' => $type->parent,
                'settings' => $attributes ?: null,
                'usedIn' => $uses[$type->name] ?: null,
            ]);
        }

        return $list;
    }

    /**
     * The theme's style variations by kind: kit (a whole look made for one kind of business, with its own pattern
     * category), style (another whole look), colors (a palette), fonts (a font set), and the section and block
     * styles, which aren't switched on but added to a block as className is-style-<slug>.
     */
    public function list_style_variations(): array
    {
        $current = $this->user_global_styles();
        $looks = [];
        foreach (\WP_Theme_JSON_Resolver::get_style_variations() as $variation) {
            $kind = $this->variation_kind($variation);
            $looks[] = array_filter([
                'slug' => $variation['slug'] ?? sanitize_title($variation['title']),
                'title' => $variation['title'],
                'kind' => $kind,
                'description' => $variation['description'] ?? null,
                'colors' => array_column($this->presets($variation['settings']['color']['palette'] ?? []), 'color', 'slug') ?: null,
                'fonts' => array_column($this->presets($variation['settings']['typography']['fontFamilies'] ?? []), 'name', 'slug') ?: null,
                'active' => $this->variation_is_active($variation, $kind, $current) ?: null,
            ]);
        }
        $styles = [];
        foreach (\WP_Theme_JSON_Resolver::get_style_variations('block') as $variation) {
            $slug = $variation['slug'] ?? sanitize_title($variation['title']);
            $styles[] = [
                'className' => 'is-style-' . $slug,
                'title' => $variation['title'],
                'blocks' => $variation['blockTypes'],
            ];
        }

        return ['variations' => $looks, 'blockStyles' => $styles];
    }

    /**
     * { slug } Switches on one of the theme's style variations, as Styles in the Site Editor does: a whole look (kit
     * or style) replaces this website's colours, fonts and styles; a palette or font set changes only those. The
     * previous styles are kept as a revision.
     */
    public function apply_style_variation(WP_REST_Request $request)
    {
        $slug = (string) $request->get_param('slug');
        $variation = null;
        foreach (\WP_Theme_JSON_Resolver::get_style_variations() as $candidate) {
            if (($candidate['slug'] ?? sanitize_title($candidate['title'])) === $slug) {
                $variation = $candidate;
                break;
            }
        }
        if (!$variation) {
            return new WP_Error('anypress_site_not_found', 'The theme has no style variation with that slug (see list-style-variations).', ['status' => 404]);
        }
        $kind = $this->variation_kind($variation);
        $current = $this->user_global_styles();
        if ($kind === 'colors' || $kind === 'fonts') {
            $key = $kind === 'colors' ? 'color' : 'typography';
            $next = $current;
            $next['settings'][$key] = array_replace($current['settings'][$key] ?? [], $variation['settings'][$key] ?? []);
            $next['styles'] = $this->merge_styles($current['styles'] ?? [], $this->only_styles($variation['styles'] ?? [], $key));
        } else {
            $next = ['settings' => $variation['settings'] ?? [], 'styles' => $variation['styles'] ?? []];
        }
        $id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
        $next['title'] = $variation['title'];
        $next['version'] = \WP_Theme_JSON::LATEST_SCHEMA;
        $next = \WP_Theme_JSON::remove_insecure_properties($next, 'custom');
        $next['version'] = \WP_Theme_JSON::LATEST_SCHEMA;
        $next['isGlobalStylesUserThemeJSON'] = true;
        $saved = $this->update_post(['ID' => $id, 'post_content' => wp_slash(wp_json_encode($next))]);
        if (is_wp_error($saved)) {
            return $saved;
        }
        wp_clean_theme_json_cache();

        return ['applied' => ['slug' => $slug, 'title' => $variation['title'], 'kind' => $kind]] + $this->get_styles();
    }

    /** A preset list, which theme.json data keeps by origin ({ theme: [...] }) once read. */
    private function presets(array $presets): array
    {
        return array_is_list($presets) ? $presets : array_merge(...array_values(array_filter($presets, 'is_array')));
    }

    private function user_global_styles(): array
    {
        $id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();

        return json_decode((string) get_post_field('post_content', $id), true) ?: [];
    }

    /** What a theme-scope variation changes, grouped as the Site Editor groups them: only colours, only type, or more. */
    private function variation_kind(array $variation): string
    {
        if (strpos((string) ($variation['slug'] ?? ''), 'kit-') === 0) {
            return 'kit';
        }
        $families = array_unique(array_merge(array_keys($variation['settings'] ?? []), $this->style_families($variation['styles'] ?? [])));
        if ($families === ['color']) {
            return 'colors';
        }
        if ($families === ['typography']) {
            return 'fonts';
        }

        return 'style';
    }

    /** The kinds of style (color, typography, spacing...) used anywhere in styles, its elements and blocks. */
    private function style_families(array $styles): array
    {
        $families = [];
        foreach ($styles as $key => $value) {
            if ($key === 'elements' || $key === 'blocks' || strpos((string) $key, ':') === 0) {
                foreach ((array) $value as $name => $style) {
                    $families = array_merge($families, $this->style_families($key === 'elements' || $key === 'blocks' ? (array) $style : [$name => $style]));
                }
                continue;
            }
            $families[] = (string) $key;
        }

        return array_values(array_unique($families));
    }

    private function variation_is_active(array $variation, string $kind, array $current): bool
    {
        if ($kind === 'colors' || $kind === 'fonts') {
            $key = $kind === 'colors' ? 'color' : 'typography';
            $mine = ['settings' => $current['settings'][$key] ?? [], 'styles' => $this->only_styles($current['styles'] ?? [], $key)];
            $theirs = ['settings' => $variation['settings'][$key] ?? [], 'styles' => $this->only_styles($variation['styles'] ?? [], $key)];

            return $this->contains($mine, $theirs);
        }

        return ($current['title'] ?? null) === $variation['title'];
    }

    /** Whether every value in part is also in whole (lists must match exactly). */
    private function contains($whole, $part): bool
    {
        if (!is_array($part) || array_is_list($part)) {
            return $whole === $part;
        }
        foreach ($part as $key => $value) {
            if (!is_array($whole) || !array_key_exists($key, $whole) || !$this->contains($whole[$key], $value)) {
                return false;
            }
        }

        return true;
    }

    /** The parts of styles that are about colour (or type): the top level and every element and block. */
    private function only_styles(array $styles, string $key): array
    {
        $out = [];
        if (isset($styles[$key])) {
            $out[$key] = $styles[$key];
        }
        foreach (['elements', 'blocks'] as $group) {
            foreach ($styles[$group] ?? [] as $name => $style) {
                $part = $this->only_styles((array) $style, $key);
                if ($part) {
                    $out[$group][$name] = $part;
                }
            }
        }

        return $out;
    }

    /** Merges styles, replacing lists whole (a palette is a list) rather than item by item. */
    private function merge_styles(array $base, array $changes): array
    {
        foreach ($changes as $key => $value) {
            $base[$key] = is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null)
                ? $this->merge_styles($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
