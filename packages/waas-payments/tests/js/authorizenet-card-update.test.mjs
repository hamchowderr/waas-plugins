// Runs assets/authorizenet-card-update.js against a minimal fake DOM: `node --test 'tests/js/*.test.mjs'`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const SCRIPT = readFileSync(new URL('../../assets/authorizenet-card-update.js', import.meta.url), 'utf8');

function fakeElement(tagName, log) {
    const el = {
        tagName: tagName.toUpperCase(),
        children: [],
        attributes: {},
        style: {},
        listeners: {},
        className: '',
        textContent: '',
        disabled: false,
        appendChild(child) { el.children.push(child); child.parent = el; return child; },
        setAttribute(name, value) { el.attributes[name] = value; },
        addEventListener(type, fn) { el.listeners[type] = fn; },
        querySelector(selector) {
            const classes = selector.split(',').map((s) => s.trim().replace(/^\./, ''));
            return el.children.find((child) => classes.includes(child.className)) || null;
        },
        remove() { el.parent.children = el.parent.children.filter((child) => child !== el); },
        click() { log.push('click ' + (el.id || el.className)); (el.listeners.click || (() => {}))(); },
    };
    return el;
}

function load(serverReply) {
    const log = [];
    const requests = [];
    const box = fakeElement('div', log);
    const body = fakeElement('body', log);
    const document = {
        body,
        readyState: 'complete',
        createElement: (tag) => fakeElement(tag, log),
        getElementById: (id) => (id === 'waas-card-update-box' ? box : body.children.find((child) => child.id === id) || null),
    };
    const window = {
        waas_authorizenet_card_update: {
            ajax_url: '/wp-admin/admin-ajax.php',
            action: 'waas_payments_authorizenet_card_update',
            nonce: 'nonce-1',
            subscription: 'sub-uuid',
            api_login_id: 'login',
            public_client_key: 'client-key',
            sandbox: true,
            translations: {},
        },
    };
    const fetch = async (url, init) => {
        requests.push({ url, body: Object.fromEntries(new URLSearchParams(init.body)) });
        return { json: async () => serverReply };
    };

    vm.runInNewContext(SCRIPT, { window, document, fetch, URLSearchParams });

    return { log, box, body, window, requests };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

test('loads the sandbox hosted form and opens it from the Update card button', () => {
    const { log, box, body } = load({});

    const trigger = body.children.find((child) => child.id === 'waas-anet-acceptui');
    assert.equal(trigger.className, 'AcceptUI');
    assert.equal(trigger.attributes['data-clientKey'], 'client-key');
    assert.equal(trigger.attributes['data-responseHandler'], 'waasAuthorizeNetCardUpdateResponse');
    assert.equal(body.children.find((child) => child.tagName === 'SCRIPT').src, 'https://jstest.authorize.net/v3/AcceptUI.js');

    box.children.find((child) => child.className === 'waas-anet-pay-button').click();
    assert.ok(log.includes('click waas-anet-acceptui'));
});

test('posts only the token with the subscription and nonce, then shows the result', async () => {
    const { box, window, requests } = load({ status: 'success', message: 'Your card was updated.' });

    window.waasAuthorizeNetCardUpdateResponse({
        messages: { resultCode: 'Ok' },
        opaqueData: { dataDescriptor: 'COMMON.ACCEPT.INAPP.PAYMENT', dataValue: 'token-value' },
    });
    await flush();
    await flush();

    assert.deepEqual(requests, [{
        url: '/wp-admin/admin-ajax.php',
        body: {
            action: 'waas_payments_authorizenet_card_update',
            nonce: 'nonce-1',
            subscription: 'sub-uuid',
            data_descriptor: 'COMMON.ACCEPT.INAPP.PAYMENT',
            data_value: 'token-value',
        },
    }]);
    const note = box.children.find((child) => child.className === 'waas-anet-success');
    assert.equal(note.textContent, 'Your card was updated.');
});

test('shows the hosted form\'s error without posting anything', () => {
    const { box, window, requests } = load({});

    window.waasAuthorizeNetCardUpdateResponse({ messages: { resultCode: 'Error', message: [{ text: 'Card number is invalid.' }] } });

    assert.equal(requests.length, 0);
    assert.equal(box.children.find((child) => child.className === 'waas-anet-error').textContent, 'Card number is invalid.');
});
