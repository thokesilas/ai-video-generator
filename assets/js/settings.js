/* Local AI Video Studio - settings page logic */
(function () {
    'use strict';

    var $ = function (sel) { return document.querySelector(sel); };

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    function toast(message, kind) {
        var host = $('#toasts');
        var t = el('div', 'toast' + (kind ? ' ' + kind : ''), message);
        host.appendChild(t);
        setTimeout(function () {
            t.style.opacity = '0';
            t.style.transition = 'opacity .3s';
            setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 320);
        }, kind === 'err' ? 8000 : 4500);
    }

    async function api(url, opts) {
        opts = opts || {};
        var headers = Object.assign({}, opts.headers || {});
        var fetchOpts = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
        if (opts.method && opts.method !== 'GET') headers['X-CSRF-Token'] = LVS.csrf;
        if (opts.json !== undefined) {
            headers['Content-Type'] = 'application/json';
            fetchOpts.body = JSON.stringify(opts.json);
        }
        var res = await fetch(url, fetchOpts);
        var data = null;
        try { data = await res.json(); } catch (e) { data = null; }
        if (!res.ok || (data && data.ok === false)) {
            var msg = data && data.error && data.error.message ? data.error.message : ('Request failed (HTTP ' + res.status + ')');
            var err = new Error(msg);
            err.code = data && data.error ? data.error.code : 'http_' + res.status;
            err.data = data;
            throw err;
        }
        return data;
    }

    function renderDiagnostics(d) {
        var host = $('#diagList');
        host.innerHTML = '';
        var rows = [
            ['PHP version', d.php_version],
            ['cURL extension', d.curl ? 'enabled' : 'MISSING'],
            ['allow_url_fopen', d.allow_url_fopen ? 'on' : 'off'],
            ['fileinfo (MIME checks)', d.fileinfo ? 'enabled' : 'MISSING'],
            ['GD (images)', d.gd ? 'enabled' : 'not loaded'],
            ['storage/ writable', d.storage_writable ? 'yes' : 'NO'],
            ['config/ writable', d.config_writable ? 'yes' : 'NO'],
            ['upload_max_filesize', d.max_upload],
            ['post_max_size', d.post_max],
            ['open_basedir', d.open_basedir || '(not set)'],
            ['OS', d.os]
        ];
        rows.forEach(function (r) {
            var row = el('div', 'setting-row');
            row.appendChild(el('div', 'lbl', r[0]));
            var val = el('b', null, String(r[1]));
            if (String(r[1]).indexOf('MISSING') !== -1 || String(r[1]) === 'NO') val.className = 'err';
            row.appendChild(val);
            host.appendChild(row);
        });
    }

    function getSettings() {
        return {
            http_referer: $('#httpReferer').value.trim(),
            poll_interval_seconds: parseInt($('#pollInterval').value, 10),
            request_timeout_seconds: parseInt($('#requestTimeout').value, 10),
            max_local_storage_mb: parseInt($('#storageMb').value, 10),
            max_history_entries: parseInt($('#historyEntries').value, 10),
            max_upload_mb: parseInt($('#uploadMb').value, 10),
            generations_per_minute: parseInt($('#genPerMin').value, 10),
            allow_local_save: $('#allowSave').checked,
            enable_rate_limit: $('#rateLimit').checked
        };
    }

    async function saveSettings() {
        var payload = { action: 'save', settings: getSettings() };
        var key = $('#apiKey').value.trim();
        if (key) payload.api_key = key;
        try {
            var data = await api('api/settings.php', { method: 'POST', json: payload });
            $('#apiKey').value = '';
            if (data.masked_key) $('#maskedKey').value = data.masked_key;
            $('#settingsResult').textContent = 'Settings saved.';
            toast('Settings saved.', 'ok');
            if (data.has_key) { $('#testBtn').disabled = false; $('#clearKeyBtn').disabled = false; }
        } catch (e) {
            $('#settingsResult').textContent = e.message;
            $('#settingsResult').className = 'hint err mt';
            toast(e.message, 'err');
        }
    }

    function renderTestResult(result) {
        var box = $('#keyResult');
        box.innerHTML = '';
        box.className = 'mt';
        var ok = el('div', 'badge completed');
        ok.appendChild(el('span', 'dot'));
        ok.appendChild(el('span', null, 'Connected'));
        box.appendChild(ok);

        var lines = ['Video models available: ' + result.models_count];
        if (result.key_info) {
            if (result.key_info.label) lines.push('Key label: ' + result.key_info.label);
            if (result.key_info.usage !== null && result.key_info.usage !== undefined) lines.push('Usage: $' + result.key_info.usage);
            if (result.key_info.limit !== null && result.key_info.limit !== undefined) lines.push('Limit: $' + result.key_info.limit);
            if (result.key_info.is_free_tier !== null && result.key_info.is_free_tier !== undefined) lines.push('Free tier: ' + (result.key_info.is_free_tier ? 'yes' : 'no'));
        }
        if (result.credits) {
            if (result.credits.total_credits !== null && result.credits.total_credits !== undefined) lines.push('Total credits: $' + result.credits.total_credits);
            if (result.credits.total_usage !== null && result.credits.total_usage !== undefined) lines.push('Total usage: $' + result.credits.total_usage);
        }
        lines.forEach(function (l) { box.appendChild(el('div', 'hint', l)); });
    }

    async function testConnection() {
        $('#keyResult').className = 'hint mt';
        $('#keyResult').textContent = 'Testing...';
        try {
            var data = await api('api/test.php', { method: 'POST' });
            renderTestResult(data.result);
            toast('Connection OK.', 'ok');
        } catch (e) {
            $('#keyResult').className = 'hint err mt';
            $('#keyResult').textContent = e.message;
            toast(e.message, 'err');
        }
    }

    function wire() {
        $('#saveKeyBtn').addEventListener('click', saveSettings);
        $('#saveSettingsBtn').addEventListener('click', saveSettings);
        $('#testBtn').addEventListener('click', testConnection);
        $('#clearKeyBtn').addEventListener('click', async function () {
            if (!confirm('Remove the stored API key?')) return;
            try {
                await api('api/settings.php', { method: 'POST', json: { action: 'clear_key' } });
                $('#maskedKey').value = 'No key configured';
                $('#testBtn').disabled = true;
                $('#clearKeyBtn').disabled = true;
                toast('API key cleared.', 'ok');
            } catch (e) { toast(e.message, 'err'); }
        });
        $('#refreshModelsBtn').addEventListener('click', async function () {
            try {
                await api('api/models.php?refresh=1');
                $('#refreshResult').textContent = 'Model cache refreshed.';
                toast('Model cache refreshed.', 'ok');
            } catch (e) {
                $('#refreshResult').textContent = e.message;
                $('#refreshResult').className = 'hint err mt';
                toast(e.message, 'err');
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        renderDiagnostics(LVS.diagnostics);
        wire();
    });
})();
