// Renders the shop's FluentCart and FluentCRM emails on WordPress Playground after the shop's email settings
// (test-storefront/remote/design/emails.php), into shots/emails/*.html and shots/emails/emails.json.
//
//   npm run emails

import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const REMOTE = resolve(HERE, '../test-storefront/remote');

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${process.env.PLAYGROUND_WP || '7.1.3'}.zip`,
    php: '8.3',
    port: Number(process.env.PLAYGROUND_PORT || 9421),
    blueprint: {
        steps: [
            { step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'fluent-cart' }, options: { activate: true } },
            { step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'fluent-crm' }, options: { activate: true } },
        ],
    },
    mount: [
        { hostPath: resolve(REMOTE, 'design'), vfsPath: '/wordpress/wp-content/anypress-design' },
        { hostPath: REMOTE, vfsPath: '/wordpress/wp-content/anypress-remote' },
        { hostPath: resolve(HERE, 'email-preview'), vfsPath: '/wordpress/wp-content/anypress-preview' },
    ],
    verbosity: 'quiet',
});
const res = await cli.playground.run({ code: "<?php require '/wordpress/wp-content/anypress-preview/render.php';" });
const out = resolve(HERE, 'shots/emails');
mkdirSync(out, { recursive: true });
let data;
try {
    data = JSON.parse(res.text.slice(res.text.indexOf('{"log"')));
} catch {
    console.log(res.text.slice(0, 4000), res.errors || '');
    process.exit(1);
}
writeFileSync(resolve(out, 'emails.json'), JSON.stringify(data));
console.log(data.log.trim());
for (const [kind, list] of [['cart', data.cart], ['crm', data.crm]]) {
    list.forEach((m, i) => {
        writeFileSync(resolve(out, `${kind}-${i + 1}.html`), m.html);
        console.log(`${kind}: ${m.subject}`);
    });
}
await cli[Symbol.asyncDispose]?.();
process.exit(0);
