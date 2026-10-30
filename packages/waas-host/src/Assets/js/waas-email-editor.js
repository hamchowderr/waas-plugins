/* AnyPress → Settings → Emails: live preview with sample values, placeholder buttons and a test send. */
(function () {
    var form = document.querySelector('.ap-email-editor');
    if (!form || !window.waasEmailEditor) {
        return;
    }

    var subject = document.getElementById('ap-email-subject');
    var body = document.getElementById('ap-email-body');
    var frame = document.getElementById('ap-preview');
    var previewSubject = document.getElementById('ap-preview-subject');
    var testButton = document.getElementById('ap-send-test');
    var result = document.getElementById('ap-test-result');
    var lastField = body;
    var timer = null;

    function request(send) {
        var data = new FormData();
        data.append('action', 'waas_email_preview');
        data.append('_ajax_nonce', waasEmailEditor.nonce);
        data.append('email', form.getAttribute('data-email'));
        data.append('subject', subject.value);
        data.append('body', body.value);
        if (send) {
            data.append('send', '1');
        }

        return fetch(waasEmailEditor.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (response) { return response.json(); });
    }

    function refresh() {
        request(false).then(function (json) {
            if (!json || !json.success) {
                return;
            }
            previewSubject.textContent = json.data.subject;
            // The iframe is sandboxed with no permissions, so the admin's HTML can't run scripts here.
            frame.srcdoc = json.data.body;
        });
    }

    function queueRefresh() {
        clearTimeout(timer);
        timer = setTimeout(refresh, 300);
    }

    [subject, body].forEach(function (field) {
        field.addEventListener('input', queueRefresh);
        field.addEventListener('focus', function () { lastField = field; });
    });

    form.querySelectorAll('.ap-token').forEach(function (button) {
        button.addEventListener('click', function () {
            var token = button.getAttribute('data-token');
            var start = lastField.selectionStart || 0;
            var end = lastField.selectionEnd || 0;
            lastField.value = lastField.value.slice(0, start) + token + lastField.value.slice(end);
            lastField.focus();
            lastField.selectionStart = lastField.selectionEnd = start + token.length;
            queueRefresh();
        });
    });

    testButton.addEventListener('click', function () {
        testButton.disabled = true;
        result.textContent = waasEmailEditor.sending;
        request(true).then(function (json) {
            result.textContent = json && json.data && json.data.message ? json.data.message : '';
        }).catch(function () {
            result.textContent = '';
        }).then(function () {
            testButton.disabled = false;
        });
    });

    refresh();
})();
