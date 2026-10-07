/**
 * Authorize.Net card payments on FluentCart's checkout.
 *
 * The card is typed into Authorize.Net's hosted form (AcceptUI, a lightbox served by
 * Authorize.Net), which returns a single-use token. Only that token is posted to this site.
 *
 * FluentCart contract (same as its Paystack add-on): FluentCart fires
 * `fluent_cart_load_payments_waas_authorizenet` with {paymentInfoUrl, nonce, orderHandler,
 * paymentLoader}; orderHandler() places the order and resolves with the response of
 * AuthorizeNetGateway::makePaymentFromPaymentInstance().
 */
(function () {
    const ROUTE = 'waas_authorizenet';
    const CONTAINER = '.fluent-cart-checkout_embed_payment_container_' + ROUTE;
    const ACCEPT_UI = {
        sandbox: 'https://jstest.authorize.net/v3/AcceptUI.js',
        production: 'https://js.authorize.net/v3/AcceptUI.js'
    };

    function settings() {
        return window.waas_authorizenet_data || {};
    }

    function $t(text) {
        return (settings().translations || {})[text] || text;
    }

    function container() {
        return document.querySelector(CONTAINER);
    }

    class WaasAuthorizeNetCheckout {
        constructor(detail, config) {
            this.orderHandler = detail.orderHandler;
            this.paymentLoader = detail.paymentLoader;
            this.config = config;
            this.transactionHash = null;
            this.busy = false;
        }

        init() {
            const box = container();
            if (!box) {
                return;
            }

            box.innerHTML = '';

            const info = document.createElement('p');
            info.className = 'waas-anet-info';
            info.textContent = $t('Your card is entered on a secure Authorize.Net form. This site never sees your card number.');
            box.appendChild(info);

            this.button = document.createElement('button');
            this.button.type = 'button';
            this.button.className = 'waas-anet-pay-button';
            this.button.textContent = $t('Pay with card');
            this.button.addEventListener('click', () => this.pay());
            box.appendChild(this.button);

            this.mountAcceptUI();

            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                detail: {payment_method: ROUTE}
            }));
        }

        /**
         * AcceptUI binds to `.AcceptUI` buttons that exist when its script loads, so the
         * trigger button lives outside FluentCart's container (which is re-rendered when the
         * customer switches payment methods) and the script is loaded once, after it.
         */
        mountAcceptUI() {
            window.waasAuthorizeNetResponse = (response) => this.onToken(response);

            if (document.getElementById('waas-anet-acceptui')) {
                return;
            }

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.id = 'waas-anet-acceptui';
            trigger.className = 'AcceptUI';
            trigger.style.display = 'none';
            trigger.setAttribute('data-billingAddressOptions', JSON.stringify({show: true, required: true}));
            trigger.setAttribute('data-paymentOptions', JSON.stringify({showCreditCard: true, showBankAccount: false}));
            trigger.setAttribute('data-apiLoginID', this.config.api_login_id);
            trigger.setAttribute('data-clientKey', this.config.public_client_key);
            trigger.setAttribute('data-acceptUIFormBtnTxt', $t('Pay'));
            trigger.setAttribute('data-acceptUIFormHeaderTxt', $t('Card details'));
            trigger.setAttribute('data-responseHandler', 'waasAuthorizeNetResponse');
            document.body.appendChild(trigger);

            const script = document.createElement('script');
            script.src = this.config.sandbox ? ACCEPT_UI.sandbox : ACCEPT_UI.production;
            script.charset = 'utf-8';
            script.onerror = () => this.showError($t('Could not load the card form.'));
            document.body.appendChild(script);
        }

        async pay() {
            if (this.busy) {
                return;
            }

            // A declined card keeps the same order: the customer just tries another card.
            if (!this.transactionHash) {
                this.setBusy(true, $t('Processing...'));
                let response;
                try {
                    response = await this.orderHandler();
                } catch (error) {
                    this.setBusy(false);
                    this.showError(error && error.message ? error.message : $t('Payment failed. Please try again.'));
                    return;
                }
                this.setBusy(false);

                // FluentCart already showed why the order could not be placed.
                if (!response) {
                    return;
                }
                this.transactionHash = response.data && response.data.transaction_hash;
            }

            this.openCardForm();
        }

        /** The order was placed by FluentCart's own submit (e.g. Enter in a field). */
        handleNextAction(response) {
            const hash = response && response.data && response.data.transaction_hash;
            if (hash) {
                this.transactionHash = hash;
                this.openCardForm();
            }
        }

        openCardForm() {
            if (!this.transactionHash) {
                this.showError($t('Payment failed. Please try again.'));
                return;
            }
            this.clearError();
            const trigger = document.getElementById('waas-anet-acceptui');
            if (trigger) {
                trigger.click();
            }
        }

        onToken(response) {
            if (!response || !response.messages || response.messages.resultCode === 'Error') {
                const messages = (response && response.messages && response.messages.message) || [];
                this.showError(messages.map((m) => m.text).join(' ') || $t('Payment failed. Please try again.'));
                return;
            }

            this.confirm(response.opaqueData);
        }

        async confirm(opaqueData) {
            this.setBusy(true, $t('Confirming payment...'));
            this.paymentLoader && this.paymentLoader.changeLoaderStatus('confirming');

            const body = new URLSearchParams({
                action: settings().confirm_action,
                nonce: settings().nonce,
                transaction_hash: this.transactionHash,
                data_descriptor: opaqueData.dataDescriptor,
                data_value: opaqueData.dataValue
            });

            let result = null;
            try {
                const response = await fetch(window.fluentcart_checkout_vars.ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    credentials: 'same-origin',
                    body: body.toString()
                });
                result = await response.json();
            } catch (error) {
                result = null;
            }

            if (result && result.status === 'success' && result.redirect_url) {
                if (this.paymentLoader) {
                    this.paymentLoader.triggerPaymentCompleteEvent(result);
                    this.paymentLoader.changeLoaderStatus('redirecting');
                }
                window.location.href = result.redirect_url;
                return;
            }

            this.setBusy(false);
            this.paymentLoader && this.paymentLoader.hideLoader();
            this.showError((result && result.message) || $t('Payment failed. Please try again.'));
        }

        setBusy(busy, label) {
            this.busy = busy;
            if (this.button) {
                this.button.disabled = busy;
                this.button.textContent = busy ? label : $t('Pay with card');
            }
        }

        showError(message) {
            const box = container();
            if (!box) {
                return;
            }
            this.clearError();
            const error = document.createElement('div');
            error.className = 'waas-anet-error';
            error.setAttribute('role', 'alert');
            error.textContent = message;
            box.appendChild(error);
        }

        clearError() {
            const box = container();
            const existing = box && box.querySelector('.waas-anet-error');
            if (existing) {
                existing.remove();
            }
        }
    }

    window.addEventListener('fluent_cart_load_payments_' + ROUTE, function (event) {
        window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading', {
            detail: {payment_method: ROUTE}
        }));

        const box = container();
        if (box) {
            box.textContent = $t('Loading Payment Processor...');
        }

        fetch(event.detail.paymentInfoUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-WP-Nonce': event.detail.nonce},
            credentials: 'include'
        }).then((response) => response.json()).then((config) => {
            if (!config || config.status !== 'success') {
                throw new Error((config && config.message) || $t('Could not load the card form.'));
            }
            const checkout = new WaasAuthorizeNetCheckout(event.detail, config.payment_args);
            window.waasAuthorizeNetCheckout = checkout;
            checkout.init();
        }).catch((error) => {
            if (box) {
                box.textContent = '';
                const message = document.createElement('div');
                message.className = 'waas-anet-error';
                message.textContent = error.message;
                box.appendChild(message);
            }
            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_failed', {
                detail: {payment_method: ROUTE}
            }));
        });
    });

    window.addEventListener('fluent_cart_payment_next_action_' + ROUTE, function (event) {
        if (window.waasAuthorizeNetCheckout) {
            window.waasAuthorizeNetCheckout.handleNextAction(event.detail && event.detail.response);
        }
    });
})();
