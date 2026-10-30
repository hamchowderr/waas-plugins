<?php

namespace WaaSClient\Forms;

/**
 * Swaps Contact Form 7, WPForms and Ninja Forms shortcodes and blocks in a piece of content for the Fluent Forms
 * form each was converted to, and puts them back. Plain string work, so it can be tested without WordPress.
 *
 * The map lists the converted forms by source:
 *   ['contactform7' => [['id' => 8, 'hash' => 'a8a5f43…', 'title' => 'Contact', 'fluent' => 3]], 'wpforms' => [...], 'ninja_forms' => [...]]
 */
class PageSwitcher
{
    /** Shortcode tags each source plugin registers (longest first, so contact-form-7 wins over contact-form). */
    private const SHORTCODES = [
        'contact-form-7' => 'contactform7',
        'contact-form' => 'contactform7',
        'wpforms' => 'wpforms',
        'ninja_forms_display_form' => 'ninja_forms',
        'ninja_forms' => 'ninja_forms',
        'ninja_form' => 'ninja_forms',
    ];

    /** Blocks each source plugin registers, and the attribute that holds the form id. */
    private const BLOCKS = [
        'contact-form-7/contact-form-selector' => ['contactform7', 'id'],
        'wpforms/form-selector' => ['wpforms', 'formId'],
        'ninja-forms/form' => ['ninja_forms', 'formID'],
    ];

    /** Text that shows a source form may be in a piece of content (for a quick database search). */
    public const NEEDLES = ['[contact-form', '[wpforms', '[ninja_form', 'wp:contact-form-7/', 'wp:wpforms/', 'wp:ninja-forms/'];

    private array $map;

    public function __construct(array $map)
    {
        $this->map = $map;
    }

    /**
     * The content with every source form whose Fluent Forms copy is known swapped for it.
     *
     * @return array{content: string, replacements: array<int, array{from: string, to: string, source: string, fluent: int}>, missing: string[]}
     */
    public function rewrite(string $content): array
    {
        $replacements = [];
        $missing = [];

        // Blocks first: CF7's block keeps its shortcode inside it.
        $content = (string) preg_replace_callback(
            '/<!--\s+wp:(' . implode('|', array_map(fn ($b) => preg_quote($b, '/'), array_keys(self::BLOCKS))) . ')(\s+\{.*?\})?\s+(?:\/-->|-->.*?<!--\s+\/wp:\1\s+-->)/s',
            function ($m) use (&$replacements, &$missing) {
                [$source, $key] = self::BLOCKS[$m[1]];
                $attrs = json_decode(trim($m[2] ?? ''), true);
                $attrs = is_array($attrs) ? $attrs : [];
                $fluent = $this->find($source, ['id' => (string) ($attrs[$key] ?? ''), 'hash' => (string) ($attrs['hash'] ?? ''), 'title' => (string) ($attrs['title'] ?? '')]);
                if (!$fluent) {
                    $missing[] = $m[0];
                    return $m[0];
                }
                $to = self::fluent_block($fluent);
                $replacements[] = ['from' => $m[0], 'to' => $to, 'source' => $source, 'fluent' => $fluent];
                return $to;
            },
            $content
        );

        $content = (string) preg_replace_callback(
            '/\[(\[?)(' . implode('|', array_map(fn ($t) => preg_quote($t, '/'), array_keys(self::SHORTCODES))) . ')(?=[\s\]\/])([^\]]*)\](\]?)/',
            function ($m) use (&$replacements, &$missing) {
                // [[wpforms id="1"]] is how a post shows a shortcode as text: leave it.
                if ($m[1] === '[' && $m[4] === ']') {
                    return $m[0];
                }
                $source = self::SHORTCODES[$m[2]];
                $attrs = self::attributes(rtrim($m[3], '/ '));
                $fluent = $this->find($source, ['id' => (string) ($attrs['id'] ?? ''), 'hash' => '', 'title' => (string) ($attrs['title'] ?? '')]);
                if (!$fluent) {
                    $missing[] = $m[0];
                    return $m[0];
                }
                $from = $m[0];
                $to = $m[1] . self::fluent_shortcode($fluent) . $m[4];
                $replacements[] = ['from' => $from, 'to' => $to, 'source' => $source, 'fluent' => $fluent];
                return $to;
            },
            $content
        );

        return ['content' => $content, 'replacements' => $replacements, 'missing' => $missing];
    }

    /**
     * Puts the source shortcodes and blocks back: each Fluent Forms shortcode or block this switch wrote is swapped
     * back for the original it replaced, in the order they were written. Fluent Forms shortcodes the owner added
     * themselves after the switch stay as they are, unless they are the same text as one this switch wrote.
     */
    public static function revert(string $content, array $replacements): string
    {
        $offset = 0;
        foreach ($replacements as $replacement) {
            $to = (string) ($replacement['to'] ?? '');
            $from = (string) ($replacement['from'] ?? '');
            if ($to === '') {
                continue;
            }
            $at = strpos($content, $to, $offset);
            if ($at === false) {
                // Moved or edited since: look from the start before giving up on it.
                $at = strpos($content, $to);
            }
            if ($at === false) {
                continue;
            }
            $content = substr_replace($content, $from, $at, strlen($to));
            $offset = $at + strlen($from);
        }

        return $content;
    }

    public static function fluent_shortcode(int $id): string
    {
        return '[fluentform id="' . $id . '"]';
    }

    /** Fluent Forms' block (its name really is "fluentfom/guten-block"). */
    public static function fluent_block(int $id): string
    {
        return '<!-- wp:fluentfom/guten-block {"formId":"' . $id . '"} /-->';
    }

    /** Shortcode attributes, as WordPress's shortcode_parse_atts reads them (name="value", name='value', name=value). */
    public static function attributes(string $text): array
    {
        $attrs = [];
        if (preg_match_all('/([\w-]+)\s*=\s*"([^"]*)"|([\w-]+)\s*=\s*\'([^\']*)\'|([\w-]+)\s*=\s*([^\s\'"]+)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if (($m[1] ?? '') !== '') {
                    $attrs[strtolower($m[1])] = $m[2];
                } elseif (($m[3] ?? '') !== '') {
                    $attrs[strtolower($m[3])] = $m[4];
                } elseif (($m[5] ?? '') !== '') {
                    $attrs[strtolower($m[5])] = $m[6];
                }
            }
        }

        return $attrs;
    }

    /** The Fluent Forms id for a source form named by id, CF7 hash or (CF7 only) title. */
    private function find(string $source, array $ref): ?int
    {
        $id = trim($ref['id']);
        $hash = trim($ref['hash']);
        $forms = array_filter($this->map[$source] ?? [], fn ($form) => (int) ($form['fluent'] ?? 0) > 0);
        // CF7 5.8+ writes the first 7 characters of the form's _hash and looks it up as a prefix before trying the
        // number as a post id (a hash can be all digits), so do the same.
        foreach ([$hash, $id] as $prefix) {
            if (strlen($prefix) < 7) {
                continue;
            }
            foreach ($forms as $form) {
                $full = (string) ($form['hash'] ?? '');
                if ($full !== '' && strpos($full, $prefix) === 0) {
                    return (int) $form['fluent'];
                }
            }
        }
        if ($id !== '' && ctype_digit($id)) {
            foreach ($forms as $form) {
                if ((int) $id === (int) $form['id']) {
                    return (int) $form['fluent'];
                }
            }
        }
        if ($source === 'contactform7' && $id === '' && $hash === '' && $ref['title'] !== '') {
            foreach ($forms as $form) {
                if ((string) ($form['title'] ?? '') === $ref['title']) {
                    return (int) $form['fluent'];
                }
            }
        }

        return null;
    }
}
