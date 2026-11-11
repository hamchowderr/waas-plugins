// End-to-end test of AnyPress Client's shop product routes (GET and POST /site/products) on WordPress Playground, as an
// online-store site: the AnyPress theme, FluentCart, the FluentCart Migrator add-on installed but switched off (as the
// store template ships it), and AnyPress Client from this checkout with ANYPRESS_TENANT_ROLES online-store. Requests
// are signed as the AnyPress API signs them. Checks: a simple and a three-variant product are made as drafts by
// FluentCart's importer with prices in cents, categories and photos; the same source address is not added twice; the
// add-on stays off; unsigned calls are refused; bad input is refused.
//
//   cd scripts/playground && npm ci
//   npm run products            # exit 1 on a failure
//   npm run products -- --serve # leave the site running at http://127.0.0.1:9403 (admin / password)
//
// Needs packages/anypress-client/vendor (composer install), a waas-provisioning-api checkout next to this repository
// (its store template's roles.json and the FluentCart Migrator zip it ships; or ANYPRESS_API_DIR), and network
// access to downloads.wordpress.org and the product photos (WordPress's own test images on raw.githubusercontent.com).

import { createHash, createHmac, randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const API = process.env.ANYPRESS_API_DIR || resolve(ROOT, '../waas-provisioning-api');
const MIGRATOR = resolve(API, 'templates/anypress-store/plugins/fluent-cart-migrator-1.1.0-beta.zip');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.4';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9403);
const SERVE = process.argv.includes('--serve');
const SITE_TYPE = 'online-store';
const SITE_KEY = randomBytes(24).toString('hex');
const PHOTOS = ['https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/tests/phpunit/data/images/canola.jpg', 'https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/src/wp-admin/images/wordpress-logo.png'];

for (const [path, fix] of [[resolve(CLIENT, 'vendor/autoload.php'), 'run composer install in packages/anypress-client'], [resolve(API, 'templates/anypress-store/roles.json'), 'set ANYPRESS_API_DIR to a waas-provisioning-api checkout'], [MIGRATOR, 'check the store template ships the FluentCart Migrator add-on']]) {
    if (!existsSync(path)) {
        console.error(`${path} is missing: ${fix} first.`);
        process.exit(1);
    }
}

const blueprint = {
    landingPage: '/wp-admin/edit.php?post_type=fluent-products',
    login: SERVE,
    features: { networking: true },
    steps: [
        { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: 'https://downloads.wordpress.org/plugin/fluent-cart.2.3.1.zip' }, options: { activate: true } },
        // Installed switched off, as the store template ships it.
        { step: 'installPlugin', pluginData: { resource: 'literal', name: 'fluent-cart-migrator.zip', contents: readFileSync(MIGRATOR) }, options: { activate: false } },
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(resolve(API, 'templates/anypress-store/roles.json'), 'utf8') },
        // A store sold as an online store: its site key and site type come from the container's environment.
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true'); putenv('ANYPRESS_SITE_KEY=${SITE_KEY}'); define('ANYPRESS_TENANT_ROLES', '${SITE_TYPE}');\n` },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        ...extraCertificates(),
        { step: 'setSiteOptions', options: { blogname: 'Playground Shop', permalink_structure: '/%postname%/' } },
    ],
};

/**
 * Behind a TLS-inspecting proxy (NODE_EXTRA_CA_CERTS set), WordPress must trust the proxy's certificate too, or it
 * can't download the photos: its own CA bundle plus that file, for this test site only.
 */
function extraCertificates() {
    const extra = process.env.NODE_EXTRA_CA_CERTS;
    if (!extra || !existsSync(extra)) return [];
    return [
        { step: 'writeFile', path: '/wordpress/wp-content/extra-ca.crt', data: readFileSync(extra, 'utf8') },
        {
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/0-test-certificates.php',
            data: `<?php
add_filter('http_request_args', function ($args) {
    $bundle = WP_CONTENT_DIR . '/test-ca-bundle.crt';
    if (!file_exists($bundle)) {
        file_put_contents($bundle, file_get_contents(ABSPATH . WPINC . '/certificates/ca-bundle.crt') . "\n" . file_get_contents(WP_CONTENT_DIR . '/extra-ca.crt'));
    }
    $args['sslcertificates'] = $bundle;
    return $args;
});
`,
        },
    ];
}

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${WP}.zip`,
    php: PHP,
    port: PORT,
    blueprint,
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/anypress-client' }],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
console.log(`WordPress ${WP} on PHP ${PHP} at ${base}, site type ${SITE_TYPE}`);

function rawurlencode(value) {
    return encodeURIComponent(value).replace(/[!'()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`);
}

/** Calls a site route as the AnyPress API does: X-AnyPress-Signature over time, nonce, method, route and body hash. */
async function site(method, path, body, { sign = true } = {}) {
    const route = `/anypress-client/v1/site${path}`;
    const json = body === undefined ? '' : JSON.stringify(body);
    const headers = { 'content-type': 'application/json' };
    if (sign) {
        const t = String(Math.floor(Date.now() / 1000));
        const n = randomBytes(16).toString('hex');
        const [pathname, query = ''] = route.split('?');
        const sorted = [...new URLSearchParams(query)]
            .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
            .map(([k, v]) => `${rawurlencode(k)}=${rawurlencode(v)}`)
            .join('&');
        const payload = [t, n, method, sorted ? `${pathname}?${sorted}` : pathname, createHash('sha256').update(json).digest('hex')].join('\n');
        headers['x-anypress-signature'] = `t=${t},n=${n},s=${createHmac('sha256', SITE_KEY).update(payload).digest('hex')}`;
    }
    const res = await fetch(`${base}/wp-json${route}`, { method, headers, body: json || undefined });
    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        data = text;
    }
    return { status: res.status, data };
}

async function php(code) {
    const out = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` });
    const text = out.text.trim();
    return JSON.parse(text.slice(text.search(/[[{]/)));
}

const results = [];
async function check(name, fn) {
    try {
        await fn();
        results.push(true);
        console.log(`  ok    ${name}`);
    } catch (error) {
        results.push(false);
        console.log(`  FAIL  ${name}\n        ${error.message}`);
    }
}
const expect = (condition, message) => {
    if (!condition) throw new Error(message);
};
const short = (value) => JSON.stringify(value).slice(0, 700);

const products = [
    { title: 'Sourdough Loaf', description: '<p>Baked every morning.</p>', url: 'https://example.com/products/sourdough', categories: ['Bread'], variants: [{ price: '8.50', sku: 'SD-1' }], images: [PHOTOS[0]] },
    {
        title: 'Celebration Cake',
        url: 'https://example.com/products/cake',
        categories: ['Cakes', 'Bread'],
        images: PHOTOS,
        variants: [
            { title: 'Small', price: '24', compareAtPrice: '30', sku: 'CAKE-S' },
            { title: 'Medium', price: 36.5, sku: 'CAKE-M' },
            { title: 'Large', price: '48.00', available: false },
        ],
    },
];

let first;
await check('adds a simple and a three-variant product as drafts', async () => {
    const res = await site('POST', '/products', { products });
    expect(res.status === 200, short(res));
    first = res.data.results;
    expect(first.length === 2 && first.every((r) => r.status === 'created' && r.productStatus === 'draft'), short(first));
});

await check('the products are FluentCart drafts with prices in cents, variations, categories, photos and their source', async () => {
    const ids = first.map((r) => r.id).join(',');
    const rows = await php(`global $wpdb; $out = [];
        foreach ([${ids}] as $id) {
            $post = get_post($id);
            $out[] = [
                'type' => $post->post_type, 'status' => $post->post_status, 'title' => $post->post_title,
                'variations' => $wpdb->get_results($wpdb->prepare("SELECT variation_title, sku, item_price, compare_price, stock_status FROM {$wpdb->prefix}fct_product_variations WHERE post_id = %d ORDER BY serial_index", $id), ARRAY_A),
                'detail' => $wpdb->get_row($wpdb->prepare("SELECT variation_type, min_price, max_price FROM {$wpdb->prefix}fct_product_details WHERE post_id = %d", $id), ARRAY_A),
                'categories' => wp_get_post_terms($id, 'product-categories', ['fields' => 'names']),
                'thumbnail' => (int) get_post_thumbnail_id($id),
                'gallery' => count((array) get_post_meta($id, 'fluent-products-gallery-image', true)),
                'source' => get_post_meta($id, '_anypress_source_url', true),
            ];
        }
        echo json_encode($out);`);
    const [loaf, cake] = rows;
    expect(rows.every((r) => r.type === 'fluent-products' && r.status === 'draft'), short(rows));
    expect(loaf.variations.length === 1 && Number(loaf.variations[0].item_price) === 850 && loaf.variations[0].sku === 'SD-1', short(loaf));
    expect(cake.variations.length === 3 && cake.variations.map((v) => Number(v.item_price)).join() === '2400,3650,4800', short(cake.variations));
    expect(Number(cake.variations[0].compare_price) === 3000 && cake.variations[2].stock_status === 'out-of-stock', short(cake.variations));
    expect(cake.detail && cake.detail.variation_type !== 'simple', short(cake.detail));
    expect(cake.categories.sort().join() === 'Bread,Cakes' && loaf.categories.join() === 'Bread', `${short(loaf.categories)} ${short(cake.categories)}`);
    expect(first[0].images === 1 && first[1].images === 2 && loaf.thumbnail > 0 && cake.gallery === 2, short({ first, loaf, cake }));
    expect(loaf.source === products[0].url, loaf.source);
});

await check('the same source address is not added twice', async () => {
    const res = await site('POST', '/products', { products: [products[0]] });
    expect(res.status === 200 && res.data.results[0].status === 'exists' && res.data.results[0].id === first[0].id, short(res));
});

await check('lists the products with where each came from', async () => {
    const res = await site('GET', '/products');
    expect(res.status === 200 && res.data.length === 2, short(res));
    expect(res.data.every((p) => p.status === 'draft' && p.sourceUrl && p.link), short(res.data));
});

await check('a product asked for as published is published', async () => {
    const res = await site('POST', '/products', { products: [{ title: 'Gift Card', status: 'publish', variants: [{ price: '25' }] }] });
    expect(res.status === 200 && res.data.results[0].status === 'created' && res.data.results[0].productStatus === 'publish' && res.data.results[0].images === 0, short(res));
    const status = await php(`echo json_encode([get_post_status(${res.data.results[0].id})]);`);
    expect(status[0] === 'publish', short(status));
});

await check('the FluentCart Migrator add-on stays switched off', async () => {
    const active = await php(`echo json_encode(get_option('active_plugins'));`);
    expect(!active.some((p) => p.startsWith('fluent-cart-migrator/')), short(active));
});

await check('bad input is refused, and a product without a title fails on its own', async () => {
    const none = await site('POST', '/products', { products: [] });
    const many = await site('POST', '/products', { products: Array.from({ length: 21 }, (_, i) => ({ title: `P${i}` })) });
    const untitled = await site('POST', '/products', { products: [{ title: '' }] });
    expect(none.status === 400 && many.status === 400, short({ none, many }));
    expect(untitled.status === 200 && untitled.data.results[0].status === 'failed', short(untitled));
});

await check('unsigned calls are refused', async () => {
    const read = await site('GET', '/products', undefined, { sign: false });
    const write = await site('POST', '/products', { products }, { sign: false });
    expect(read.status === 401 && write.status === 401, short({ read, write }));
});

const failed = results.filter((ok) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed (site type ${SITE_TYPE})`);
if (!SERVE) {
    process.exit(failed ? 1 : 0);
}
