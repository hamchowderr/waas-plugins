/**
 * The card-update page (CardUpdate.php): the customer types the new card into Authorize.Net's
 * hosted form (AcceptUI), and only the single-use token it returns is posted to this site.
 */
(function () {
    const ACCEPT_UI = {
        sandbox: 'https://jstest.authorize.net/v3/AcceptUI.js',
        production: 'https://js.authorize.net/v3/AcceptUI.js'
    };

    const config = window.waas_authorizenet_card_update || {};

    function $t(text) {
        return (config.translations || {})[text] || text;
    }

    function box() {
        return document.getElementById('waas-card-update-box');
    }

    function show(className, message) {
        const container = box();
        const existing = container.querySelector('.waas-anet-error, .waas-anet-success');
        if (existing) {
            existing.remove();
        }
        if (!message) {
            return;
        }
        const note = document.createElement('div');
        note.className = className;
        note.setAttribute('role', className === 'waas-anet-error' ? 'alert' : 'status');
        note.textContent = message;
        container.appendChild(note);
    }

    let button = null;
    let busy = false;

    function setBusy(value) {
        busy = value;
        button.disabled = value;
        button.textContent = value ? $t('Saving...') : $t('Update card');
    }

    async function save(opaqueData) {
        setBusy(true);
        show('', '');

        const body = new URLSearchParams({
            action: config.action,
            nonce: config.nonce,
            subscription: config.subscription,
            data_descriptor: opaqueData.dataDescriptor,
            data_value: opaqueData.dataValue
        });

        let result = null;
        try {
            const response = await fetch(config.ajax_url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                credentials: 'same-origin',
                body: body.toString()
            });
            result = await response.json();
        } catch (error) {
            result = null;
        }

        setBusy(false);
        if (result && result.status === 'success') {
            show('waas-anet-success', result.message);
            return;
        }
        show('waas-anet-error', (result && result.message) || $t('The card could not be saved. Please try another card.'));
    }

    window.waasAuthorizeNetCardUpdateResponse = function (response) {
        if (busy) {
            return;
        }
        if (!response || !response.messages || response.messages.resultCode === 'Error') {
            const messages = (response && response.messages && response.messages.message) || [];
            show('waas-anet-error', messages.map((m) => m.text).join(' ') || $t('The card could not be saved. Please try another card.'));
            return;
        }
        save(response.opaqueData);
    };

    function init() {
        const container = box();
        if (!container) {
            return;
        }

        button = document.createElement('button');
        button.type = 'button';
        button.className = 'waas-anet-pay-button';
        button.textContent = $t('Update card');
        button.addEventListener('click', () => {
            if (!busy) {
                show('', '');
                document.getElementById('waas-anet-acceptui').click();
            }
        });
        container.appendChild(button);

        // AcceptUI binds to `.AcceptUI` buttons present when its script loads.
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.id = 'waas-anet-acceptui';
        trigger.className = 'AcceptUI';
        trigger.style.display = 'none';
        trigger.setAttribute('data-billingAddressOptions', JSON.stringify({show: true, required: true}));
        trigger.setAttribute('data-paymentOptions', JSON.stringify({showCreditCard: true, showBankAccount: false}));
        trigger.setAttribute('data-apiLoginID', config.api_login_id);
        trigger.setAttribute('data-clientKey', config.public_client_key);
        trigger.setAttribute('data-acceptUIFormBtnTxt', $t('Save card'));
        trigger.setAttribute('data-acceptUIFormHeaderTxt', $t('Card details'));
        trigger.setAttribute('data-responseHandler', 'waasAuthorizeNetCardUpdateResponse');
        document.body.appendChild(trigger);

        const script = document.createElement('script');
        script.src = config.sandbox ? ACCEPT_UI.sandbox : ACCEPT_UI.production;
        script.charset = 'utf-8';
        script.onerror = () => show('waas-anet-error', $t('Could not load the card form.'));
        document.body.appendChild(script);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
