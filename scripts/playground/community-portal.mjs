// Test of AnyPress Client's community set-ups on WordPress Playground, as a community store: the AnyPress theme,
// FluentCommunity and FluentAuth from wordpress.org at the store template's versions, and AnyPress Client from this
// checkout with ANYPRESS_TENANT_ROLES=community. Checks:
// - FluentAuth's Login Redirects decide where a member lands after FluentCommunity's own sign-in, when no page was
//   asked for; a page the member asked for is kept.
// - Settings > Reading's "Show the community on the home page": the community at / and its spaces without /portal,
//   and back at /portal when it is off again.
// - FluentAuth's "keep these roles out of wp-admin", as the store template sets it: a member opening wp-admin is
//   sent to the home page; the owner is not.
//
//   cd scripts/playground && npm ci
//   npm run community-portal          # exit 1 on a failure
//
// Needs packages/anypress-client/vendor (composer install), a waas-provisioning-api checkout next to this repository
// (its store template's roles.json; or ANYPRESS_API_DIR), and network access to downloads.wordpress.org.

import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const API = process.env.ANYPRESS_API_DIR || resolve(ROOT, '../waas-provisioning-api');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.4';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9412);
const SITE = `http://127.0.0.1:${PORT}`;
// The store template's versions (waas-provisioning-api templates/anypress-store/build.sh).
const PLUGINS = ['fluent-community.2.13.0.zip', 'fluent-security.3.1.1.zip'];

for (const [path, fix] of [[resolve(CLIENT, 'vendor/autoload.php'), 'run composer install in packages/anypress-client'], [resolve(API, 'templates/anypress-store/roles.json'), 'set ANYPRESS_API_DIR to a waas-provisioning-api checkout']]) {
    if (!existsSync(path)) {
        console.error(`${path} is missing: ${fix} first.`);
        process.exit(1);
    }
}

const blueprint = {
    features: { networking: true },
    steps: [
        { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } },
        { step: 'setSiteOptions', options: { permalink_structure: '/%postname%/' } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        ...PLUGINS.map((zip) => ({ step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/${zip}` }, options: { activate: true } })),
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(resolve(API, 'templates/anypress-store/roles.json'), 'utf8') },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: "<?php putenv('ANYPRESS_IS_TENANT=true'); define('ANYPRESS_TENANT_ROLES', 'community');\n" },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
    ],
};

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${WP}.zip`,
    php: PHP,
    port: PORT,
    blueprint,
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/anypress-client' }],
    verbosity: 'quiet',
});
console.log(`WordPress ${WP} on PHP ${PHP}, site type community`);

async function php(code) {
    const out = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` });
    const text = out.text.trim();
    return JSON.parse(text.slice(text.search(/[[{]/)));
}

// A page as a visitor (or a signed-in user, with their cookie) sees it; redirects are not followed.
async function get(path, cookie = '') {
    const res = await fetch(SITE + path, { redirect: 'manual', headers: cookie ? { cookie } : {} });
    return { status: res.status, location: res.headers.get('location') || '', body: res.status === 200 ? await res.text() : '' };
}
const isPortal = (page) => page.status === 200 && page.body.includes('fluent_com_portal');

let failed = 0;
const check = (ok, what) => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${what}`);
    if (!ok) failed++;
};

try {
    const setup = await php(`
        \\FluentCommunity\\App\\Models\\Space::create(['title' => 'Members', 'slug' => 'members', 'privacy' => 'public', 'status' => 'published', 'type' => 'community']);
        $member = wp_insert_user(['user_login' => 'member', 'user_pass' => 'member-pass-1', 'user_email' => 'member@example.com', 'role' => 'subscriber']);
        // FluentAuth's Login Redirects, as an owner sets them on its settings screen: members to a welcome page.
        update_option('__fls_auth_forms_settings', array_merge(\\FluentAuth\\App\\Helpers\\Helper::getAuthFormsSettings(), ['login_redirects' => 'yes', 'default_login_redirect' => home_url('/welcome/')]));
        // FluentAuth's "keep these roles out of wp-admin", as the store template's presets.php sets it.
        update_option('__fls_auth_settings', array_merge(\\FluentAuth\\App\\Helpers\\Helper::getAuthSettings(), ['disable_admin_bar' => 'yes', 'disable_bar_roles' => ['subscriber']]));
        $user = get_user_by('id', $member);
        $feed = \\FluentCommunity\\App\\Services\\Helper::baseUrl();
        $expire = time() + 3600;
        echo wp_json_encode([
            'client' => class_exists('AnyPressClient\\\\Features\\\\CommunityPortal'),
            'feed' => $feed,
            'landing' => apply_filters('fluent_community/auth/after_login_redirect_url', $feed, $user),
            'asked' => apply_filters('fluent_community/auth/after_login_redirect_url', $feed . 'space/members/home', $user),
            'welcome' => home_url('/welcome/'),
            'member_cookie' => LOGGED_IN_COOKIE . '=' . rawurlencode(wp_generate_auth_cookie($member, $expire, 'logged_in')) . '; ' . AUTH_COOKIE . '=' . rawurlencode(wp_generate_auth_cookie($member, $expire, 'auth')),
            'admin_cookie' => LOGGED_IN_COOKIE . '=' . rawurlencode(wp_generate_auth_cookie(1, $expire, 'logged_in')) . '; ' . AUTH_COOKIE . '=' . rawurlencode(wp_generate_auth_cookie(1, $expire, 'auth')),
        ]);
    `);
    check(setup.client, 'AnyPress Client is loaded');
    check(setup.landing === setup.welcome, `a member signing in to the community lands where FluentAuth's rule says (${setup.landing})`);
    check(setup.asked === `${setup.feed}space/members/home`, 'a page the member asked for is kept');

    const memberAdmin = await get('/wp-admin/', setup.member_cookie);
    check(memberAdmin.status === 302 && !memberAdmin.location.includes('/wp-admin'), `a member opening wp-admin is sent away (${memberAdmin.status} ${memberAdmin.location})`);
    const ownerAdmin = await get('/wp-admin/', setup.admin_cookie);
    check(ownerAdmin.status === 200, `the owner still opens wp-admin (${ownerAdmin.status})`);

    check(isPortal(await get('/portal/')), 'with the choice off, the community is at /portal');
    const reading = await get('/wp-admin/options-reading.php', setup.admin_cookie);
    check(reading.body.includes('anypress_community_home'), 'Settings > Reading shows the Community choice');

    await php(`update_option('anypress_community_home', 'yes'); echo '{}';`);
    check(isPortal(await get('/')), 'with the choice on, the community is at the home page');
    check(isPortal(await get('/space/members/home')), 'a space opens without /portal');
    check(isPortal(await get('/courses')), 'the courses page opens without /portal');

    await php(`update_option('anypress_community_home', 'no'); echo '{}';`);
    check(isPortal(await get('/portal/')), 'turned off again, the community is back at /portal');
    check(!isPortal(await get('/')), 'and the home page is the site\'s own again');
} catch (error) {
    check(false, String(error));
}

await cli[Symbol.asyncDispose]?.();
process.exit(failed ? 1 : 0);
