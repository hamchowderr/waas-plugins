// The Tools > Move to AnyPress page: sends the site to AnyPress step by step through admin-ajax, and shows progress.
// window.anypressExport (from Plugin::enqueue) holds the AJAX URL, the nonce and the export's progress so far.
(function () {
    var __ = wp.i18n.__, sprintf = wp.i18n.sprintf;
    var config = window.anypressExport;
    var root = document.getElementById('anypress-export');
    var $ = function (sel) { return root.querySelector(sel); };
    var view = function (name) {
        $('[data-view="start"]').hidden = name !== 'start';
        $('[data-view="progress"]').hidden = name !== 'progress';
    };
    var error = function (text) {
        var box = $('[data-field="error"]');
        box.hidden = !text;
        box.querySelector('p').textContent = text || '';
    };
    var request = function (action, data) {
        var body = new URLSearchParams(Object.assign({ action: 'anypress_export_' + action, _ajax_nonce: config.nonce }, data || {}));
        return fetch(config.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (res) {
            return res.json().catch(function () { return { success: false, data: { error: sprintf(/* translators: %s: HTTP status code */ __('The server stopped answering (HTTP %s). Reload this page to carry on.', 'anypress-export'), res.status) } }; });
        }).then(function (json) {
            if (!json.success) throw new Error((json.data && json.data.error) || __('Something went wrong.', 'anypress-export'));
            return json.data;
        });
    };
    // The site check's notes: short groups of plain sentences, built with textContent.
    var notes = function (groups, empty) {
        var box = $('[data-field="notes"]');
        box.textContent = '';
        var add = function (tag, text, parent) {
            var el = document.createElement(tag);
            if (text) el.textContent = text;
            (parent || box).appendChild(el);
            return el;
        };
        (groups || []).forEach(function (group) {
            add('h2', group.title).style.fontSize = '1.1em';
            if (group.text) add('p', group.text);
            var list = add('ul', '');
            list.style.listStyle = 'disc';
            list.style.paddingLeft = '20px';
            group.items.forEach(function (item) { add('li', item, list); });
        });
        if (!(groups && groups.length) && empty) add('p', empty);
        box.hidden = !box.firstChild;
    };
    var preflight = function () {
        notes([], __('Checking your site…', 'anypress-export'));
        request('preflight').then(function (r) {
            notes(r.notes, __('We checked your site: nothing to flag.', 'anypress-export'));
        }).catch(function () { notes([]); });
    };
    var show = function (p) {
        view('progress');
        var store = p.store || {};
        $('[data-field="store"]').textContent = store.domain ? (store.size ? sprintf(/* translators: 1: the AnyPress address, 2: the store's size */ __('Moving to %1$s (%2$s)', 'anypress-export'), store.domain, store.size) : sprintf(/* translators: %s: the AnyPress address */ __('Moving to %s', 'anypress-export'), store.domain)) : '';
        $('[data-field="bar"]').style.width = p.percent + '%';
        $('[data-field="message"]').textContent = p.message;
        if (p.notes && p.notes.length) notes(p.notes);
    };
    var poll = function () {
        request('status').then(function (s) {
            if (s.status === 'done') {
                show({ percent: 100, message: sprintf(/* translators: %s: the site's address on AnyPress */ __('Done. Your site is on AnyPress: %s', 'anypress-export'), s.url), store: s.store });
                $('[data-field="hint"]').textContent = __('Check it there, then connect your domain on AnyPress. You can deactivate and delete this plugin now.', 'anypress-export');
                $('[data-action="cancel"]').parentNode.hidden = true; // the button's own hidden loses to .button's display
                return;
            }
            if (s.status === 'failed') {
                error(__('AnyPress could not set up your site. Contact AnyPress support; your site here is unchanged.', 'anypress-export'));
                return;
            }
            setTimeout(poll, 10000);
        }).catch(function (e) { error(e.message); setTimeout(poll, 30000); });
    };
    var step = function () {
        request('step').then(function (p) {
            show(p);
            if (p.step === 'moving') {
                $('[data-field="hint"]').textContent = __('The upload is done. You can close this page; AnyPress carries on without it.', 'anypress-export');
                poll();
            } else {
                setTimeout(step, 300);
            }
        }).catch(function (e) { error(e.message + ' ' + __('Reload this page to try again from where it stopped.', 'anypress-export')); });
    };
    $('[data-action="begin"]').addEventListener('click', function () {
        error('');
        request('begin', { code: $('#anypress-export-code').value }).then(function (p) { show(p); step(); }).catch(function (e) { error(e.message); });
    });
    $('[data-action="cancel"]').addEventListener('click', function () {
        if (!confirm(__('Stop moving this site? What was packed so far is deleted.', 'anypress-export'))) return;
        request('cancel').then(function () { error(''); view('start'); preflight(); }).catch(function (e) { error(e.message); });
    });
    if (config.progress) {
        show(config.progress);
        if (config.progress.step === 'moving') poll(); else step();
    } else {
        view('start');
        preflight();
    }
})();
