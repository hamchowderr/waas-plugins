// Runs assets/authorizenet-checkout.js against a minimal fake DOM: `node --test 'tests/js/*.test.mjs'`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const SCRIPT = readFileSync(new URL('../../assets/authorizenet-checkout.js', import.meta.url), 'utf8');

function fakeElement(tagName, log) {
    const el = {
        tagName: tagName.toUpperCase(),
        children: [],
        attributes: {},
        style: {},
        listeners: {},
        className: '',
        textContent: '',
        set innerHTML(value) { el.children = []; },
        appendChild(child) { el.children.push(child); return child; },
        setAttribute(name, value) { el.attributes[name] = value; },
        addEventListener(type, fn) { el.listeners[type] = fn; },
        querySelector() { return null; },
        remove() {},
        click() { log.push('click ' + (el.id || el.className)); (el.listeners.click || (() => {}))(); },
    };
    return el;
}

function load() {
    const log = [];
    const container = fakeElement('div', log);
    const body = fakeElement('body', log);
    const windowListeners = {};
    const document = {
        body,
        createElement: (tag) => fakeElement(tag, log),
        querySelector: (selector) => (selector.startsWith('.fluent-cart-checkout_embed_payment_container_') ? container : null),
        getElementById: (id) => body.children.find((child) => child.id === id) || null,
    };
    const window = {
        waas_authorizenet_data: {},
        addEventListener: (type, fn) => { windowListeners[type] = fn; },
        dispatchEvent: () => true,
    };
    class CustomEvent { constructor(type, init) { this.type = type; this.detail = init && init.detail; } }
    const fetch = async () => ({
        json: async () => ({ status: 'success', payment_args: { api_login_id: 'login', public_client_key: 'key', sandbox: true } }),
    });

    vm.runInNewContext(SCRIPT, { window, document, CustomEvent, fetch, URLSearchParams });

    return { log, container, window, windowListeners };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

test('hides FluentCart\'s order-processing overlay before opening the card form', async () => {
    const { log, container, window, windowListeners } = load();
    const paymentLoader = {
        hideLoader: () => log.push('hideLoader'),
        changeLoaderStatus: () => {},
    };
    // FluentCart shows its overlay while placing the order and leaves it up afterwards.
    const orderHandler = async () => {
        log.push('orderHandler');
        return { data: { transaction_hash: 'abc' } };
    };

    windowListeners.fluent_cart_load_payments_waas_authorizenet({ detail: { paymentInfoUrl: '/info', nonce: 'n', orderHandler, paymentLoader } });
    await flush();
    await flush();

    const payButton = container.children.find((child) => child.className === 'waas-anet-pay-button');
    assert.ok(payButton, 'Pay with card button rendered');
    assert.ok(window.waasAuthorizeNetCheckout, 'checkout initialised');

    payButton.click();
    await flush();

    const opened = log.indexOf('click waas-anet-acceptui');
    assert.notEqual(opened, -1, 'card form opened');
    assert.ok(log.indexOf('hideLoader') !== -1 && log.indexOf('hideLoader') < opened, 'overlay hidden before the card form opens: ' + log.join(', '));
});
