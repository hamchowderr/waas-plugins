<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

/**
 * Checks a site before it moves: what would stop the move, what stays behind, what the owner has to
 * set up again, and what to check on AnyPress afterwards. The facts go into waas.json under
 * `preflight` (for AnyPress and its support), and notes() turns them into the short list the Tools
 * page shows. Never reads a secret's value: of wp-config.php it keeps only the constants' names.
 *
 * The pieces here are plain functions of what they're given; Plugin::preflight() gathers them from
 * WordPress.
 */
final class Preflight
{
    /** Above this the page suggests WP-CLI, which has no time limits. */
    const LARGE_BYTES = 1073741824;

    /** wp-config.php constants WordPress itself reads, or that AnyPress sets on the store. */
    const CORE_CONSTANTS = [
        'ABSPATH', 'ADMIN_COOKIE_PATH', 'ALLOW_UNFILTERED_UPLOADS', 'ALTERNATE_WP_CRON', 'AUTH_COOKIE', 'AUTH_KEY', 'AUTH_SALT',
        'AUTOMATIC_UPDATER_DISABLED', 'AUTOSAVE_INTERVAL', 'BLOG_ID_CURRENT_SITE', 'COMPRESS_CSS', 'COMPRESS_SCRIPTS',
        'CONCATENATE_SCRIPTS', 'COOKIEHASH', 'COOKIEPATH', 'COOKIE_DOMAIN', 'CORE_UPGRADE_SKIP_NEW_BUNDLED', 'CUSTOM_USER_META_TABLE',
        'CUSTOM_USER_TABLE', 'DB_CHARSET', 'DB_COLLATE', 'DB_HOST', 'DB_NAME', 'DB_PASSWORD', 'DB_USER', 'DISABLE_WP_CRON',
        'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'DISALLOW_UNFILTERED_HTML', 'DOMAIN_CURRENT_SITE', 'DO_NOT_UPGRADE_GLOBAL_TABLES',
        'EMPTY_TRASH_DAYS', 'ENFORCE_GZIP', 'FORCE_SSL_ADMIN', 'FORCE_SSL_LOGIN', 'FS_CHMOD_DIR', 'FS_CHMOD_FILE', 'FS_METHOD',
        'FS_TIMEOUT', 'FTP_BASE', 'FTP_CONTENT_DIR', 'FTP_HOST', 'FTP_PASS', 'FTP_PLUGIN_DIR', 'FTP_PRIKEY', 'FTP_PUBKEY', 'FTP_SSL',
        'FTP_USER', 'IMAGE_EDIT_OVERWRITE', 'LOGGED_IN_COOKIE', 'LOGGED_IN_KEY', 'LOGGED_IN_SALT', 'MEDIA_TRASH', 'MULTISITE',
        'NOBLOGREDIRECT', 'NONCE_KEY', 'NONCE_SALT', 'PASS_COOKIE', 'PATH_CURRENT_SITE', 'PLUGINDIR', 'PLUGINS_COOKIE_PATH',
        'RECOVERY_MODE_EMAIL', 'SAVEQUERIES', 'SCRIPT_DEBUG', 'SECURE_AUTH_COOKIE', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT',
        'SITECOOKIEPATH', 'SITE_ID_CURRENT_SITE', 'SUBDOMAIN_INSTALL', 'SUNRISE', 'TEST_COOKIE', 'UPLOADS', 'USER_COOKIE',
        'WPLANG', 'WPMU_PLUGIN_DIR', 'WPMU_PLUGIN_URL', 'WP_ACCESSIBLE_HOSTS', 'WP_ALLOW_MULTISITE', 'WP_ALLOW_REPAIR',
        'WP_AUTO_UPDATE_CORE', 'WP_CACHE', 'WP_CONTENT_DIR', 'WP_CONTENT_URL', 'WP_CRON_LOCK_TIMEOUT', 'WP_DEBUG', 'WP_DEBUG_DISPLAY',
        'WP_DEBUG_LOG', 'WP_DEFAULT_THEME', 'WP_DEVELOPMENT_MODE', 'WP_DISABLE_FATAL_ERROR_HANDLER', 'WP_ENVIRONMENT_TYPE',
        'WP_HOME', 'WP_HTTP_BLOCK_EXTERNAL', 'WP_LANG_DIR', 'WP_MAX_MEMORY_LIMIT', 'WP_MEMORY_LIMIT', 'WP_PLUGIN_DIR',
        'WP_PLUGIN_URL', 'WP_POST_REVISIONS', 'WP_PROXY_BYPASS_HOSTS', 'WP_PROXY_HOST', 'WP_PROXY_PASSWORD', 'WP_PROXY_PORT',
        'WP_PROXY_USERNAME', 'WP_SITEURL', 'WP_TEMP_DIR',
    ];

    /** Plugin folders that send mail through their own settings (often an API key), by name. */
    const MAILERS = [
        'fluent-smtp' => 'FluentSMTP', 'wp-mail-smtp' => 'WP Mail SMTP', 'wp-mail-smtp-pro' => 'WP Mail SMTP Pro',
        'post-smtp' => 'Post SMTP', 'easy-wp-smtp' => 'Easy WP SMTP', 'gosmtp' => 'GoSMTP', 'smtp-mailer' => 'SMTP Mailer',
        'wp-ses' => 'WP Offload SES', 'offload-ses-lite' => 'WP Offload SES', 'mailgun' => 'Mailgun', 'suremails' => 'SureMail',
        'sendgrid-email-delivery-simplified' => 'SendGrid', 'mailin' => 'Brevo', 'site-mailer' => 'Site Mailer',
    ];

    /** Plugin folders for page and object caches, by name. AnyPress has its own page cache. */
    const CACHES = [
        'wp-rocket' => 'WP Rocket', 'w3-total-cache' => 'W3 Total Cache', 'litespeed-cache' => 'LiteSpeed Cache',
        'wp-super-cache' => 'WP Super Cache', 'redis-cache' => 'Redis Object Cache', 'object-cache-pro' => 'Object Cache Pro',
        'wp-fastest-cache' => 'WP Fastest Cache', 'sg-cachepress' => 'SiteGround Speed Optimizer', 'breeze' => 'Breeze',
        'hummingbird-performance' => 'Hummingbird', 'cache-enabler' => 'Cache Enabler', 'comet-cache' => 'Comet Cache',
        'nitropack' => 'NitroPack', 'wp-optimize' => 'WP-Optimize', 'swift-performance-lite' => 'Swift Performance',
        'docket-cache' => 'Docket Cache', 'powered-cache' => 'Powered Cache',
    ];

    /** PHP extensions that run encoded plugins; a store without them can't run those plugins. */
    const LOADERS = ['ionCube Loader', 'SourceGuardian', 'Zend Guard Loader'];

    /**
     * What stops the move before anything is packed.
     *
     * @return string[]
     */
    public static function requirements(bool $multisite, bool $zlib, bool $curl): array
    {
        $blockers = [];
        if ($multisite) $blockers[] = __('Multisite networks cannot be moved to AnyPress yet.', 'anypress-export');
        if (!$zlib) $blockers[] = __('This server cannot compress files (the zlib extension for PHP is missing). Ask your host to turn it on, then start again.', 'anypress-export');
        if (!$curl) $blockers[] = Api::noCurl();
        return $blockers;
    }

    /**
     * Where the site keeps its files and answers, compared with what AnyPress expects (everything under
     * wp-content, served from the top of the domain).
     *
     * @param array{home: string, siteurl: string, contentDir: string, contentUrl: string, uploadsDir: string, uploadsConstant?: string|null, uploadPath?: string} $site
     * @return array{blockers: string[], warnings: string[], contentUrl?: string, siteUrl?: string}
     */
    public static function layout(array $site): array
    {
        $result = ['blockers' => [], 'warnings' => []];
        $content = self::path($site['contentDir']);
        $uploads = self::path($site['uploadsDir']);
        if ($uploads !== $content && strpos($uploads . '/', $content . '/') !== 0) {
            $result['blockers'][] = sprintf(
                /* translators: %s: the media folder's path */
                __('Your media folder is outside wp-content (%s), so the move would leave your images and files behind. Ask your developer or host to move it back to wp-content/uploads, then start again.', 'anypress-export'),
                $site['uploadsDir']
            );
        } elseif (!empty($site['uploadsConstant']) || trim((string) ($site['uploadPath'] ?? '')) !== '') {
            $result['warnings'][] = __('Your media folder is set by hand (UPLOADS in wp-config.php or the upload_path setting). Your media still moves; if new uploads fail on AnyPress, ask AnyPress support.', 'anypress-export');
        }

        $home = rtrim($site['home'], '/');
        if (rtrim($site['contentUrl'], '/') !== $home . '/wp-content') {
            $result['contentUrl'] = $site['contentUrl'];
        }
        $path = trim((string) wp_parse_url($home, PHP_URL_PATH), '/');
        $siteurl = rtrim($site['siteurl'], '/');
        if ($path !== '' || $siteurl !== $home) {
            $result['siteUrl'] = $site['siteurl'];
        }
        if ($path !== '') {
            $result['warnings'][] = sprintf(
                /* translators: %s: the folder in the site's address, such as blog */
                __('Your site\'s address ends in a folder (/%s). AnyPress serves your site from the top of its domain, so check your links after the move.', 'anypress-export'),
                $path
            );
        } elseif ($siteurl !== $home) {
            $result['warnings'][] = sprintf(
                /* translators: 1: WordPress's address (siteurl), 2: the site's address (home) */
                __('WordPress\'s own files are at a different address (%1$s) from your site (%2$s). Check your links and images after the move.', 'anypress-export'),
                $site['siteurl'],
                $site['home']
            );
        }
        return $result;
    }

    /**
     * The names of the constants wp-config.php defines that WordPress doesn't (licence keys, plugin
     * settings). Reads only the names: values are never looked at.
     *
     * @return string[]
     */
    public static function configConstants(string $php): array
    {
        $tokens = @token_get_all($php);
        $names = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) continue;
            $text = strtolower(ltrim($token[1], '\\'));
            if ($text !== 'define' || !in_array($token[0], self::nameTokens(), true)) continue;
            $j = self::next($tokens, $i);
            if ($j === null || $tokens[$j] !== '(') continue;
            $j = self::next($tokens, $j);
            if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) continue;
            $name = substr($tokens[$j][1], 1, -1);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) && !in_array(strtoupper($name), self::CORE_CONSTANTS, true) && stripos($name, 'ANYPRESS_') !== 0) {
                $names[] = $name;
            }
        }
        $names = array_values(array_unique($names));
        sort($names);
        return $names;
    }

    /** @return int[] */
    private static function nameTokens(): array
    {
        $tokens = [T_STRING];
        if (defined('T_NAME_FULLY_QUALIFIED')) $tokens[] = T_NAME_FULLY_QUALIFIED;
        return $tokens;
    }

    private static function next(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            return $j;
        }
        return null;
    }

    /**
     * The active mailer and cache plugins among the active plugins (their files, `folder/plugin.php`).
     *
     * @param string[] $plugins
     * @return array{mailers: string[], caches: string[]}
     */
    public static function recognise(array $plugins): array
    {
        $found = ['mailers' => [], 'caches' => []];
        foreach ($plugins as $file) {
            $folder = strtok((string) $file, '/');
            if (isset(self::MAILERS[$folder])) $found['mailers'][] = self::MAILERS[$folder];
            if (isset(self::CACHES[$folder])) $found['caches'][] = self::CACHES[$folder];
        }
        return ['mailers' => array_values(array_unique($found['mailers'])), 'caches' => array_values(array_unique($found['caches']))];
    }

    /**
     * The database's part: what stays behind and how big it is.
     *
     * @return array{otherTables: string[], views: string[], triggers: string[], routines: string[], databaseBytes: int}
     */
    public static function database(Database $db): array
    {
        $bytes = 0;
        foreach ($db->tableStats() as $stats) $bytes += (int) $stats['bytes'];
        return $db->leftBehind() + ['databaseBytes' => $bytes];
    }

    /**
     * The file listing's part (a FileList cursor): sizes and what the listing leaves out.
     *
     * @return array<string, mixed>
     */
    public static function files(array $listing): array
    {
        return array_intersect_key($listing, array_flip(['files', 'bytes', 'uploadsBytes', 'symlinks', 'symlinkCount', 'unreadable', 'unreadableCount']))
            + ['complete' => !empty($listing['done'])];
    }

    /** About how much free space the export needs while it runs: the uploads, and the database twice (the dump, then in the archive). */
    public static function diskNeed(int $uploadsBytes, int $databaseBytes): int
    {
        return $uploadsBytes + 2 * $databaseBytes;
    }

    /**
     * The short list the page shows, in groups: what stops the move, what stays behind, what to add
     * again, what to check afterwards. Empty groups are left out.
     *
     * @param array<string, mixed> $report
     * @return array<int, array{id: string, title: string, items: string[], text?: string}>
     */
    public static function notes(array $report): array
    {
        $before = (array) ($report['blockers'] ?? []);
        $files = (array) ($report['files'] ?? []);
        $complete = !empty($files['complete']);
        $databaseBytes = (int) ($report['databaseBytes'] ?? 0);
        $uploadsBytes = (int) ($files['uploadsBytes'] ?? 0);
        $free = $report['disk']['free'] ?? null;
        $need = self::diskNeed($uploadsBytes, $databaseBytes);
        if ($free !== null && $need > 0 && $free < $need) {
            $before[] = sprintf(
                /* translators: 1: free space, such as 2 GB, 2: space the export needs */
                $complete ? __('This server has %1$s of free space, and the export needs about %2$s while it runs. Free up space or ask your host before you start.', 'anypress-export')
                    : __('This server has %1$s of free space, and the export needs at least %2$s while it runs. Free up space or ask your host before you start.', 'anypress-export'),
                Exporter::size((int) $free),
                Exporter::size($need)
            );
        }
        $total = (int) ($files['bytes'] ?? 0) + $databaseBytes;
        if ($total > self::LARGE_BYTES) {
            $before[] = sprintf(
                /* translators: %s: the site's size, such as 3 GB */
                $complete ? __('Your site is large (about %s). Moving it from the command line is more reliable: wp anypress-export send followed by your code. Ask your host if you can\'t run commands.', 'anypress-export')
                    : __('Your site is large (at least %s). Moving it from the command line is more reliable: wp anypress-export send followed by your code. Ask your host if you can\'t run commands.', 'anypress-export'),
                Exporter::size($total)
            );
        }

        $behind = [];
        if (!empty($report['otherTables'])) {
            $behind[] = sprintf(
                /* translators: 1: the site's table prefix, such as wp_, 2: table names */
                __('Database tables that don\'t start with %1$s: %2$s', 'anypress-export'),
                (string) ($report['tablePrefix'] ?? ''),
                self::names($report['otherTables'])
            );
        }
        /* translators: %s: names */
        if (!empty($report['views'])) $behind[] = sprintf(__('Database views: %s', 'anypress-export'), self::names($report['views']));
        /* translators: %s: names */
        if (!empty($report['triggers'])) $behind[] = sprintf(__('Database triggers: %s', 'anypress-export'), self::names($report['triggers']));
        /* translators: %s: names */
        if (!empty($report['routines'])) $behind[] = sprintf(__('Stored procedures and functions: %s', 'anypress-export'), self::names($report['routines']));
        if (!empty($files['symlinkCount'])) {
            /* translators: %s: paths, each with where it points */
            $behind[] = sprintf(__('Linked folders and files (symbolic links): %s', 'anypress-export'), self::names(array_map(function ($link) {
                return $link['path'] . ($link['target'] !== '' ? ' → ' . $link['target'] : '');
            }, (array) $files['symlinks']), (int) $files['symlinkCount']));
        }
        if (!empty($files['unreadableCount'])) {
            /* translators: %s: paths */
            $behind[] = sprintf(__('Files this server won\'t let us read: %s', 'anypress-export'), self::names((array) $files['unreadable'], (int) $files['unreadableCount']));
        }

        $after = (array) ($report['warnings'] ?? []);
        if (!empty($report['caches'])) {
            /* translators: %s: plugin names */
            $after[] = sprintf(__('AnyPress has its own page cache, so turn these off after the move: %s.', 'anypress-export'), implode(', ', $report['caches']));
        }
        if (!empty($report['mailers'])) {
            /* translators: %s: plugin names */
            $after[] = sprintf(__('These send email with this site\'s own mail settings: %s. Check them on AnyPress before you point your domain at it, so customers don\'t get emails twice.', 'anypress-export'), implode(', ', $report['mailers']));
        }
        $tasks = (array) ($report['actionScheduler'] ?? []);
        if (!empty($tasks['pastDue'])) {
            $after[] = sprintf(
                /* translators: 1: overdue tasks, 2: all waiting tasks */
                __('%1$d scheduled tasks are overdue here (%2$d waiting in all). They may run on AnyPress right after the move, which can send emails or sync orders again.', 'anypress-export'),
                (int) $tasks['pastDue'],
                (int) ($tasks['pending'] ?? $tasks['pastDue'])
            );
        }
        $loaders = array_values(array_intersect(self::LOADERS, (array) ($report['php']['extensions'] ?? [])));
        if ($loaders) {
            /* translators: %s: PHP extension names, such as ionCube Loader */
            $after[] = sprintf(__('This server has %s. Plugins that need it won\'t run on AnyPress.', 'anypress-export'), implode(', ', $loaders));
        }

        $groups = [];
        if ($before) $groups[] = ['id' => 'before', 'title' => __('Before you start', 'anypress-export'), 'items' => $before];
        if ($behind) $groups[] = ['id' => 'behind', 'title' => __('We\'ll leave these behind', 'anypress-export'), 'items' => $behind];
        if (!empty($report['configConstants'])) {
            $groups[] = [
                'id' => 'constants',
                'title' => __('Re-add these licence keys after the move', 'anypress-export'),
                'text' => __('Your wp-config.php sets these, and wp-config.php doesn\'t move. If any are licence keys or settings a plugin needs, add them again on AnyPress.', 'anypress-export'),
                'items' => array_values((array) $report['configConstants']),
            ];
        }
        if ($after) $groups[] = ['id' => 'after', 'title' => __('Check these after the move', 'anypress-export'), 'items' => $after];
        return $groups;
    }

    /** Up to 10 names, then how many more. */
    private static function names(array $names, ?int $count = null): string
    {
        $count = $count ?? count($names);
        $shown = array_slice(array_values($names), 0, 10);
        $more = $count - count($shown);
        /* translators: 1: names, 2: how many more */
        return $more > 0 ? sprintf(__('%1$s and %2$d more', 'anypress-export'), implode(', ', $shown), $more) : implode(', ', $shown);
    }

    private static function path(string $path): string
    {
        $real = @realpath($path);
        return rtrim(str_replace('\\', '/', $real !== false ? $real : $path), '/');
    }
}
