/* Local AI Video Studio - frontend logic (vanilla JS, no build step) */
(function () {
    'use strict';

    var $ = function (sel, root) { return (root || document).querySelector(sel); };

    // ---------------------------------------------------------------- helpers
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

    function randId() {
        return Math.random().toString(36).slice(2, 10) + Date.now().toString(36).slice(-4);
    }

    function parsePassthrough() {
        var raw = $('#passthrough').value.trim();
        if (raw === '') return { value: null, warn: null };
        try {
            var obj = JSON.parse(raw);
            if (obj === null || typeof obj !== 'object' || Array.isArray(obj)) {
                return { value: null, warn: 'Passthrough must be a JSON object; it was ignored.' };
            }
            return { value: obj, warn: null };
        } catch (e) {
            return { value: null, warn: 'Passthrough JSON is invalid; it was ignored.' };
        }
    }

    async function api(url, opts) {
        opts = opts || {};
        var headers = Object.assign({}, opts.headers || {});
        var fetchOpts = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
        if (opts.method && opts.method !== 'GET') headers['X-CSRF-Token'] = LVS.csrf;
        if (opts.json !== undefined) {
            headers['Content-Type'] = 'application/json';
            fetchOpts.body = JSON.stringify(opts.json);
        } else if (opts.form !== undefined) {
            fetchOpts.body = opts.form;
        }

        var res = await fetch(url, fetchOpts);
        var data = null;
        try { data = await res.json(); } catch (e) { data = null; }

        if (!res.ok || (data && data.ok === false)) {
            var msg = data && data.error && data.error.message ? data.error.message : ('Request failed (HTTP ' + res.status + ')');
            var err = new Error(msg);
            err.code = data && data.error ? data.error.code : ('http_' + res.status);
            err.status = res.status;
            err.data = data;
            throw err;
        }
        return data;
    }

    function debounce(fn, ms) {
        var t = null;
        return function () {
            var args = arguments, self = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    }

    // ------------------------------------------------------------------ state
    var state = {
        models: [],
        model: null,
        ratio: '16:9',
        imageTokenFirst: null,
        imageTokenLast: null,
        continueFrom: null,   // job id to extend (previous_job_id)
        continueModel: null,
        plan: null,
        batch: null,
        pollTimers: {},
        selected: null,   // { id, index }
        historyOffset: 0,
        historyLimit: 8,
        storageLimitMb: 0,
        generating: false
    };

    // ------------------------------------------------------------- models load
    async function loadModels(refresh) {
        if (!LVS.hasKey) return;
        var sel = $('#model');
        try {
            var data = await api('api/models.php' + (refresh ? '?refresh=1' : ''));
            state.models = data.models || [];
            sel.innerHTML = '';
            if (!state.models.length) {
                sel.appendChild(new Option('No video models available', ''));
                $('#modelHint').textContent = 'OpenRouter returned no video generation models for this key.';
                return;
            }
            state.models.forEach(function (m) {
                var label = m.name + '  ·  ' + m.id;
                if (m.supported_durations && m.supported_durations.length) {
                    label += '  ·  ' + m.supported_durations.join('/') + 's';
                }
                sel.appendChild(new Option(label, m.id));
            });
            if (state.model) {
                sel.value = state.model.id;
            }
            onModelChange();
        } catch (e) {
            sel.innerHTML = '';
            sel.appendChild(new Option('Could not load models', ''));
            $('#modelHint').innerHTML = '';
            $('#modelHint').appendChild(el('span', 'err', e.message));
            toast(e.message, 'err');
        }
    }

    function effectiveModelId() {
        var custom = $('#customModel') ? $('#customModel').value.trim() : '';
        return custom !== '' ? custom : $('#model').value;
    }

    function syntheticModel(id) {
        return {
            id: id,
            name: id,
            supported_durations: [],
            supported_resolutions: [],
            supported_aspect_ratios: [],
            supported_frame_images: [],
            generate_audio: null,
            seed: null,
            pricing_skus: {}
        };
    }

    function onModelChange() {
        var id = effectiveModelId();
        state.model = null;
        state.models.forEach(function (m) { if (m.id.toLowerCase() === id.toLowerCase()) state.model = m; });
        if (!state.model) {
            if (id === '') {
                $('#modelCaps').innerHTML = '';
                $('#modelHint').textContent = '';
                $('#durationChips').innerHTML = '';
                return;
            }
            state.model = syntheticModel(id);
        }
        renderModel(state.model);
        refreshPlan();
    }

    function renderModel(m) {
        // capability chips
        var caps = $('#modelCaps');
        caps.innerHTML = '';
        function cap(label, value) {
            var c = el('span', 'cap');
            c.appendChild(el('b', null, label + ': '));
            c.appendChild(document.createTextNode(value));
            caps.appendChild(c);
        }
        var durations = m.supported_durations || [];
        cap('durations', durations.length ? durations.join(', ') + 's' : 'unknown');
        if (m.supported_resolutions && m.supported_resolutions.length) cap('resolutions', m.supported_resolutions.join(', '));
        if (m.supported_aspect_ratios && m.supported_aspect_ratios.length) cap('ratios', m.supported_aspect_ratios.join(', '));
        cap('audio', m.generate_audio ? 'yes' : (m.generate_audio === false ? 'no' : 'unknown'));
        cap('img2vid', (m.supported_frame_images && m.supported_frame_images.length) ? m.supported_frame_images.join(', ') : 'unknown');

        var maxDur = durations.length ? Math.max.apply(null, durations) : 0;
        var minDur = durations.length ? Math.min.apply(null, durations) : 0;

        $('#modelHint').innerHTML = '';
        if (!durations.length) {
            $('#modelHint').appendChild(el('span', 'warn', 'Capabilities unknown for this custom model — set the target duration manually. The provider may reject unsupported values.'));
        } else if (maxDur < 20) {
            $('#modelHint').textContent = 'Longest single clip for this model is ' + maxDur + 's. A 20s target is produced as multiple clips (see the plan below).';
        }

        // resolutions
        var rsel = $('#resolution');
        rsel.innerHTML = '';
        rsel.appendChild(new Option('Model default', ''));
        (m.supported_resolutions || []).forEach(function (r) { rsel.appendChild(new Option(r, r)); });

        // ratios
        document.querySelectorAll('#ratioChips .chip').forEach(function (chip) {
            var ratio = chip.getAttribute('data-ratio');
            var ok = !m.supported_aspect_ratios || m.supported_aspect_ratios.length === 0 || m.supported_aspect_ratios.indexOf(ratio) !== -1;
            chip.classList.toggle('disabled', !ok);
        });
        // keep current ratio if valid, else pick first available
        var currentOk = !m.supported_aspect_ratios || m.supported_aspect_ratios.length === 0 || m.supported_aspect_ratios.indexOf(state.ratio) !== -1;
        if (!currentOk) {
            state.ratio = (m.supported_aspect_ratios && m.supported_aspect_ratios[0]) || '16:9';
            document.querySelectorAll('#ratioChips .chip').forEach(function (chip) {
                chip.classList.toggle('active', chip.getAttribute('data-ratio') === state.ratio);
            });
        }

        // audio availability
        var audio = $('#generateAudio');
        if (m.generate_audio === false) { audio.checked = false; audio.disabled = true; }
        else { audio.disabled = false; }

        // duration chips
        var host = $('#durationChips');
        host.innerHTML = '';
        durations.forEach(function (d) {
            var chip = el('span', 'chip', d + 's');
            chip.addEventListener('click', function () { $('#targetDuration').value = d; refreshPlan(); });
            host.appendChild(chip);
        });
        if (maxDur && maxDur < 20) {
            var c20 = el('span', 'chip', '20s target');
            c20.addEventListener('click', function () { $('#targetDuration').value = 20; refreshPlan(); });
            host.appendChild(c20);
        }

        var cur = parseInt($('#targetDuration').value, 10) || maxDur || 5;
        if (minDur && cur < minDur) cur = minDur;
        if (cur > 60) cur = 60;
        if (cur < 1) cur = 1;
        $('#targetDuration').value = cur;
    }

    async function refreshPlan() {
        if (!state.model || !LVS.hasKey) return;
        var target = parseInt($('#targetDuration').value, 10) || 0;
        if (target <= 0) return;
        try {
            var data = await api('api/generate.php', {
                method: 'POST',
                json: { action: 'plan', model: state.model.id, target_duration: target, aspect_ratio: state.ratio, resolution: $('#resolution').value, generate_audio: $('#generateAudio').checked }
            });
            state.plan = data.plan;
            renderPlan(data.plan, data.warnings || []);
        } catch (e) {
            state.plan = null;
            $('#planBox').classList.remove('hidden');
            $('#planBox').innerHTML = '';
            $('#planBox').appendChild(el('div', 'hint err', e.message));
        }
    }

    function renderPlan(plan, warnings) {
        var box = $('#planBox');
        box.classList.remove('hidden');
        box.innerHTML = '';

        (warnings || []).forEach(function (w) {
            box.appendChild(el('div', 'hint warn', w));
        });

        var est = el('div', 'est');
        est.appendChild(el('span', null, 'Clips'));
        est.appendChild(el('b', null, plan.clip_count + ' × ' + plan.clip_durations.join('s, ') + 's = ' + plan.total_planned_seconds + 's'));
        box.appendChild(est);

        var cost = el('div', 'est');
        cost.appendChild(el('span', null, 'Estimated cost'));
        if (plan.estimated_total_cost === null || plan.estimated_total_cost === undefined) {
            cost.appendChild(el('b', null, 'unknown (no pricing SKU)'));
        } else {
            cost.appendChild(el('b', null, '$' + Number(plan.estimated_total_cost).toFixed(3)));
        }
        box.appendChild(cost);

        if (plan.clip_count > 1) {
            var note = el('div', 'hint warn');
            note.textContent = 'This model cannot produce ' + plan.target_duration + 's in a single request (max ' + plan.max_single_duration +
                's). The target is split into ' + plan.clip_count + ' separate clips. They are independent generations and do NOT automatically form one seamless video. Stitching requires a tool such as FFmpeg.';
            box.appendChild(note);
        }
    }

    // --------------------------------------------------------- continuation
    function showContinueBanner() {
        var b = $('#continueBanner');
        if (!state.continueFrom) { b.classList.add('hidden'); return; }
        b.classList.remove('hidden');
        $('#continueText').textContent = 'Continuing video (' + (state.continueModel || 'model') + ', job ' +
            String(state.continueFrom).slice(0, 10) + '…). The next clip will be sent as previous_job_id to extend it.';
    }

    function clearContinue() {
        state.continueFrom = null;
        state.continueModel = null;
        $('#continueBanner').classList.add('hidden');
    }

    function setModelId(id) {
        var found = false;
        state.models.forEach(function (m) { if (m.id.toLowerCase() === id.toLowerCase()) found = true; });
        if (found) {
            if ($('#customModel')) $('#customModel').value = '';
            $('#model').value = id;
        } else if ($('#customModel')) {
            $('#customModel').value = id;
        }
        onModelChange();
    }

    function startContinue(job) {
        if (!job || !job.id) return;
        state.continueFrom = job.id;
        state.continueModel = job.model || null;
        if (job.model) setModelId(job.model);
        showContinueBanner();
        if (!$('#continueBanner').classList.contains('hidden')) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        toast('Ready to continue this video. Press Generate to add the next clip.', 'ok');
    }

    // ------------------------------------------------------------- generation
    async function startGeneration() {
        if (state.generating) return;
        if (!LVS.hasKey) { toast('Add an OpenRouter API key in Settings first.', 'err'); return; }

        var prompt = $('#prompt').value.trim();
        if (!prompt) { toast('Enter a prompt first.', 'err'); return; }
        if (!state.model) { toast('Select a model.', 'err'); return; }

        var pt = parsePassthrough();
        if (pt.warn) toast(pt.warn, 'err');

        var plan;
        try {
            var planData = await api('api/generate.php', {
                method: 'POST',
                json: { action: 'plan', model: state.model.id, target_duration: parseInt($('#targetDuration').value, 10) || 0, aspect_ratio: state.ratio, resolution: $('#resolution').value, generate_audio: $('#generateAudio').checked }
            });
            plan = planData.plan;
            (planData.warnings || []).forEach(function (w) { toast(w, 'err'); });
        } catch (e) { toast(e.message, 'err'); return; }

        state.batch = {
            id: randId(),
            clipJobs: new Array(plan.clip_count).fill(null),
            clips: plan.clip_durations,
            plan: plan,
            next: 0,
            prompt: prompt,
            settings: {
                aspect_ratio: state.ratio,
                resolution: $('#resolution').value,
                generate_audio: $('#generateAudio').checked,
                seed: $('#seed').value,
                passthrough: pt.value,
                continuation: $('#continuation').checked,
                autoAll: $('#autoAll').checked,
                negative_prompt: $('#negativePrompt').value.trim()
            }
        };

        $('#clipList').innerHTML = '';
        $('#progressEmpty').classList.add('hidden');
        state.generating = true;
        setGenerateBusy(true);

        await submitClip(0);
    }

    async function submitClip(index) {
        var b = state.batch;
        if (!b) return;
        var s = b.settings;
        var clipDuration = b.clips[index];
        if (clipDuration === undefined) return;

        var prevJob = (index > 0 && s.continuation && b.clipJobs[index - 1]) ? b.clipJobs[index - 1] : null;

        var clipRow = renderClipRow(index, clipDuration, { status: 'pending' });
        b.clipJobs[index] = 'submitting';

        try {
            var payload = {
                action: 'submit',
                model: state.model.id,
                prompt: b.prompt,
                negative_prompt: s.negative_prompt,
                target_duration: b.plan.target_duration,
                aspect_ratio: s.aspect_ratio,
                resolution: s.resolution,
                generate_audio: s.generate_audio,
                clip_index: index,
                clip_duration: clipDuration,
                batch_id: b.id
            };
            if (s.seed !== '' && s.seed !== null && s.seed !== undefined) payload.seed = parseInt(s.seed, 10);
            if (s.passthrough) payload.provider_passthrough = s.passthrough;
            if (prevJob) payload.previous_job_id = prevJob;
            if (index === 0) {
                if (state.continueFrom) payload.previous_job_id = state.continueFrom;
                if (state.imageTokenFirst) payload.image_token_first = state.imageTokenFirst;
                if (state.imageTokenLast) payload.image_token_last = state.imageTokenLast;
            }

            var data = await api('api/generate.php', { method: 'POST', json: payload });
            if (index === 0 && state.continueFrom) { clearContinue(); }
            b.clipJobs[index] = data.job.id;
            clipRow.dataset.jobId = data.job.id;
            clipRow.dataset.model = state.model.id;
            clipRow.dataset.status = 'pending';
            updateClipRow(data.job.id, data.job);
            (data.warnings || []).forEach(function (w) { toast(w, 'err'); });
            pollJob(data.job.id, { index: index, clipRow: clipRow });
            updateOverallProgress();
            refreshHistory();
        } catch (e) {
            b.clipJobs[index] = null;
            clipRow.dataset.status = 'failed';
            setClipText(clipRow, e.message, 'err');
            setGenerateBusy(false);
            state.generating = false;
            toast(e.message, 'err');
        }
    }

    function renderClipRow(index, duration, job) {
        var row = el('div', 'clip-item');
        row.dataset.index = index;
        var badge = el('span', 'badge ' + (job.status || 'pending'));
        badge.appendChild(el('span', 'dot'));
        badge.appendChild(el('span', 'txt', job.status || 'pending'));
        row.appendChild(badge);

        var grow = el('div', 'grow');
        grow.appendChild(el('div', 'title', 'Clip ' + (index + 1) + ' · ' + duration + 's'));
        var sub = el('div', 'sub', 'submitting...');
        grow.appendChild(sub);
        row.appendChild(grow);

        var actions = el('div', 'actions');
        var play = el('button', 'btn small hidden', 'Play');
        play.addEventListener('click', function () {
            selectOutput(row.dataset.jobId, index);
        });
        var dl = el('a', 'btn small hidden', 'Download');
        dl.href = '#';
        dl.addEventListener('click', function (ev) {
            ev.preventDefault();
            window.location = 'api/download.php?id=' + encodeURIComponent(row.dataset.jobId) + '&index=0';
        });
        var cont = el('button', 'btn small hidden', 'Continue');
        cont.addEventListener('click', function () {
            startContinue({ id: row.dataset.jobId, model: row.dataset.model });
        });
        actions.appendChild(play);
        actions.appendChild(dl);
        actions.appendChild(cont);
        row.appendChild(actions);
        row._play = play;
        row._download = dl;
        row._continue = cont;

        $('#clipList').appendChild(row);
        return row;
    }

    function updateClipRow(jobId, job) {
        var row = null;
        document.querySelectorAll('#clipList .clip-item').forEach(function (r) {
            if (r.dataset.jobId === jobId) row = r;
        });
        if (!row) {
            row = document.querySelector('#clipList .clip-item[data-index="' + (job.clip_index || 0) + '"]');
        }
        if (!row) return;
        row.dataset.status = job.status;
        var badge = row.querySelector('.badge');
        badge.className = 'badge ' + job.status;
        badge.querySelector('.txt').textContent = job.status;
        var sub = row.querySelector('.sub');
        if (job.status === 'completed') {
            var cost = (job.cost !== null && job.cost !== undefined) ? (' · $' + Number(job.cost).toFixed(3)) : '';
            sub.textContent = 'completed' + cost + (job.local ? ' · saved locally' : ' · remote');
            row._play.classList.remove('hidden');
            row._download.classList.remove('hidden');
            if (row._continue) row._continue.classList.remove('hidden');
        } else if (job.status === 'failed' || job.status === 'cancelled' || job.status === 'expired') {
            sub.textContent = (job.error ? job.error : job.status);
            sub.className = 'sub err';
        } else {
            sub.textContent = 'status: ' + job.status + ' (job ' + jobId + ')';
        }
    }

    function setClipText(row, text, cls) {
        var sub = row.querySelector('.sub');
        if (sub) { sub.textContent = text; sub.className = cls ? ('sub ' + cls) : 'sub'; }
    }

    function pollJob(jobId, meta) {
        if (state.pollTimers[jobId]) return;
        var interval = (LVS.pollInterval || 10) * 1000;
        var startedAt = Date.now();
        var timeoutMs = (LVS.generationTimeout || 900) * 1000;

        var tick = async function () {
            if (Date.now() - startedAt > timeoutMs) {
                stopPoll(jobId);
                var trow = document.querySelector('#clipList .clip-item[data-job-id="' + jobId + '"]');
                setClipText(trow, 'timed out while polling (the job may still be running at the provider)', 'err');
                toast('Polling timed out. The job may still be running at OpenRouter; use "Refresh status" in history later.', 'err');
                updateOverallProgress();
                return;
            }
            try {
                var d = await api('api/status.php?id=' + encodeURIComponent(jobId));
                var job = d.job;
                updateClipRow(jobId, job);
                updateOverallProgress();
                if (['completed', 'failed', 'cancelled', 'expired'].indexOf(job.status) !== -1) {
                    stopPoll(jobId);
                    onJobTerminal(jobId, job, meta);
                }
            } catch (e) {
                // Provider/network hiccup: surface it but keep the history truthful.
                var row = document.querySelector('#clipList .clip-item[data-job-id="' + jobId + '"]');
                setClipText(row, e.message, 'err');
            }
        };

        state.pollTimers[jobId] = setInterval(tick, interval);
        setTimeout(tick, 2500);
    }

    function stopPoll(jobId) {
        if (state.pollTimers[jobId]) {
            clearInterval(state.pollTimers[jobId]);
            delete state.pollTimers[jobId];
        }
    }

    function onJobTerminal(jobId, job, meta) {
        refreshHistory();
        if (job.status === 'completed') {
            selectOutput(jobId, job.clip_index || 0);
        }
        var b = state.batch;
        if (b && job.status === 'completed' && b.settings.autoAll) {
            var idx = meta && meta.index !== undefined ? meta.index : (job.clip_index || 0);
            var next = idx + 1;
            if (next < b.clips.length && b.clipJobs[next] === null) {
                b.next = next;
                submitClip(next);
                return;
            }
        }
        // Done with this batch if every planned clip has been submitted & is terminal
        if (b && b.clipJobs.length && b.clipJobs.every(function (x) { return x && x !== 'submitting'; })) {
            var anyPending = Object.keys(state.pollTimers).length > 0;
            if (!anyPending) {
                state.generating = false;
                setGenerateBusy(false);
            }
        }
    }

    function updateOverallProgress() {
        var b = state.batch;
        if (!b) return;
        var rows = document.querySelectorAll('#clipList .clip-item');
        var total = b.clips.length;
        var done = 0, active = 0;
        rows.forEach(function (r) {
            var st = r.dataset.status;
            if (st === 'completed' || st === 'failed' || st === 'cancelled' || st === 'expired') done++;
            else active++;
        });
        state.generating = active > 0 || done < total;
        var bar = $('#progressBar');
        $('#progressStatus').textContent = done + ' / ' + total + ' clips finished';
        if (active > 0) {
            bar.classList.add('indeterminate');
        } else {
            bar.classList.remove('indeterminate');
            bar.style.width = Math.round((done / total) * 100) + '%';
        }
    }

    function setGenerateBusy(busy) {
        var btn = $('#generateBtn');
        btn.disabled = busy;
        $('#generateBtnText').textContent = busy ? 'Generating...' : 'Generate video';
    }

    // ------------------------------------------------------------- preview
    function selectOutput(jobId, index) {
        state.selected = { id: jobId, index: index || 0 };
        var v = $('#preview');
        v.src = 'api/stream.php?id=' + encodeURIComponent(jobId) + '&index=' + (index || 0);
        v.load();
        $('#previewEmpty').classList.add('hidden');
        $('#previewLabel').textContent = 'job ' + jobId.slice(0, 10) + '…';
        $('#downloadBtn').disabled = false;
        $('#saveLocalBtn').disabled = false;
        loadJobIntoButtons(jobId);
    }

    async function loadJobIntoButtons(jobId) {
        try {
            var d = await api('api/history.php?id=' + encodeURIComponent(jobId));
            var job = d.job;
            $('#saveLocalBtn').disabled = !(job.status === 'completed');
            $('#deleteLocalBtn').disabled = !job.local;
            $('#remoteNote').textContent = job.local
                ? 'Saved locally on this computer.'
                : 'This video is stored remotely at OpenRouter. Use "Save locally" to keep a copy; remote links may expire.';
        } catch (e) { /* ignore */ }
    }

    // ------------------------------------------------------------- history
    async function refreshHistory(reset) {
        if (reset) { state.historyOffset = 0; $('#historyList').innerHTML = ''; }
        try {
            var data = await api('api/history.php?limit=' + state.historyLimit + '&offset=' + state.historyOffset);
            state.storageLimitMb = data.storage_limit_mb;
            $('#storageInfo').textContent = '· ' + fmtBytes(data.storage_bytes) + ' local / ' + data.storage_limit_mb + ' MB';
            if (reset) $('#historyList').innerHTML = '';

            if (!data.jobs.length && state.historyOffset === 0) {
                $('#historyList').appendChild(el('div', 'hint', 'Nothing yet.'));
            }
            data.jobs.forEach(renderHistoryItem);

            state.historyOffset += data.jobs.length;
            var more = state.historyOffset < data.total;
            $('#loadMoreBtn').classList.toggle('hidden', !more);
        } catch (e) {
            toast(e.message, 'err');
        }
    }

    function fmtBytes(b) {
        b = b || 0;
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1073741824).toFixed(2) + ' GB';
    }

    function renderHistoryItem(job) {
        var item = el('div', 'history-item');

        var thumb = el('div', 'thumb');
        if (job.status === 'completed') {
            thumb.textContent = 'ready';
        } else {
            thumb.textContent = job.status;
        }
        item.appendChild(thumb);

        var meta = el('div', 'meta');
        var top = el('div');
        var badge = el('span', 'badge ' + job.status);
        badge.appendChild(el('span', 'dot'));
        badge.appendChild(el('span', null, job.status));
        top.appendChild(badge);
        top.appendChild(document.createTextNode(' '));
        top.appendChild(el('span', 's', (job.model_name || job.model) + ' · ' + job.duration + 's · ' + (job.aspect_ratio || '') + ' ' + (job.resolution || '')));
        meta.appendChild(top);
        var p = el('div', 'p', job.prompt || '(no prompt)');
        meta.appendChild(p);
        var s = el('div', 's');
        var when = job.created_at ? new Date(job.created_at * 1000).toLocaleString() : '';
        s.textContent = when + (job.cost !== null && job.cost !== undefined ? ' · $' + Number(job.cost).toFixed(3) : '') + (job.local ? ' · local copy' : '');
        meta.appendChild(s);
        if (job.error) {
            var err = el('div', 's err', job.error);
            meta.appendChild(err);
        }
        item.appendChild(meta);

        var actions = el('div', 'actions');
        if (job.status === 'completed') {
            var play = el('button', 'btn small', 'Play');
            play.addEventListener('click', function () { selectOutput(job.id, 0); window.scrollTo({ top: 0, behavior: 'smooth' }); });
            actions.appendChild(play);

            var dl = el('a', 'btn small', 'Download');
            dl.href = 'api/download.php?id=' + encodeURIComponent(job.id) + '&index=0';
            actions.appendChild(dl);

            var cont = el('button', 'btn small', 'Continue');
            cont.addEventListener('click', function () { startContinue(job); });
            actions.appendChild(cont);
        } else if (job.status !== 'failed') {
            var poll = el('button', 'btn small', 'Refresh status');
            poll.addEventListener('click', async function () {
                try {
                    var d = await api('api/status.php?id=' + encodeURIComponent(job.id));
                    toast('Status: ' + d.job.status, 'ok');
                    refreshHistory(true);
                    if (d.job.status === 'completed') selectOutput(job.id, 0);
                } catch (e) { toast(e.message, 'err'); }
            });
            actions.appendChild(poll);
        }
        var del = el('button', 'btn small danger', 'Delete');
        del.addEventListener('click', async function () {
            if (!confirm('Delete this entry and any local copy?')) return;
            try { await api('api/history.php', { method: 'POST', json: { action: 'delete', id: job.id } }); refreshHistory(true); }
            catch (e) { toast(e.message, 'err'); }
        });
        actions.appendChild(del);
        item.appendChild(actions);

        $('#historyList').appendChild(item);
    }

    // ------------------------------------------------------------- upload
    function wireUpload() {
        wireDrop('dropFirst', 'imageInputFirst', 'first', 'frame');
        wireDrop('dropLast', 'imageInputLast', 'last', 'frame');
    }

    function wireDrop(dropId, inputId, slot, kind) {
        var drop = document.getElementById(dropId);
        var input = document.getElementById(inputId);
        if (!drop || !input) return;
        drop.addEventListener('click', function () { input.click(); });
        drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('drag'); });
        drop.addEventListener('dragleave', function () { drop.classList.remove('drag'); });
        drop.addEventListener('drop', function (e) {
            e.preventDefault(); drop.classList.remove('drag');
            if (e.dataTransfer.files && e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0], slot, kind, drop);
        });
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) handleFile(input.files[0], slot, kind, drop);
        });
    }

    async function handleFile(file, slot, kind, drop) {
        if (!LVS.hasKey) { toast('Add an API key before uploading images.', 'err'); return; }
        if (file.size > LVS.maxUploadMb * 1024 * 1024) {
            toast('Image is larger than ' + LVS.maxUploadMb + ' MB.', 'err');
            return;
        }
        var fd = new FormData();
        fd.append('image', file);
        try {
            var data = await api('api/upload.php', { method: 'POST', form: fd });
            if (slot === 'first') state.imageTokenFirst = data.token;
            else state.imageTokenLast = data.token;

            var prev = drop.querySelector('.frame-preview');
            if (prev) {
                prev.innerHTML = '';
                var img = el('img');
                img.src = URL.createObjectURL(file);
                prev.appendChild(img);
            }
            var label = slot === 'first' ? 'First frame' : 'Last frame';
            $('#uploadHint').textContent = label + ' ready (' + data.width + '×' + data.height + ', ' + fmtBytes(data.bytes) + ').';
            toast(label + ' uploaded.', 'ok');
        } catch (e) { toast(e.message, 'err'); }
    }

    // ------------------------------------------------------------- wiring
    function wire() {
        $('#model').addEventListener('change', function () {
            if ($('#customModel')) $('#customModel').value = '';
            clearContinue();
            onModelChange();
        });
        if ($('#customModel')) $('#customModel').addEventListener('input', debounce(function () {
            clearContinue();
            onModelChange();
        }, 500));
        if ($('#continueCancelBtn')) $('#continueCancelBtn').addEventListener('click', clearContinue);
        $('#targetDuration').addEventListener('input', debounce(refreshPlan, 400));
        $('#resolution').addEventListener('change', refreshPlan);
        $('#generateAudio').addEventListener('change', refreshPlan);

        document.querySelectorAll('#ratioChips .chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                if (chip.classList.contains('disabled')) return;
                document.querySelectorAll('#ratioChips .chip').forEach(function (c) { c.classList.remove('active'); });
                chip.classList.add('active');
                state.ratio = chip.getAttribute('data-ratio');
                refreshPlan();
            });
        });
        document.querySelector('#ratioChips .chip[data-ratio="16:9"]').classList.add('active');

        $('#generateBtn').addEventListener('click', startGeneration);
        $('#loadMoreBtn').addEventListener('click', function () { refreshHistory(false); });
        $('#clearHistoryBtn').addEventListener('click', async function () {
            if (!confirm('Clear the entire local history and all saved local videos?')) return;
            try { await api('api/history.php', { method: 'POST', json: { action: 'clear' } }); refreshHistory(true); toast('History cleared.', 'ok'); }
            catch (e) { toast(e.message, 'err'); }
        });

        $('#downloadBtn').addEventListener('click', function () {
            if (!state.selected) return;
            window.location = 'api/download.php?id=' + encodeURIComponent(state.selected.id) + '&index=' + state.selected.index;
        });
        $('#saveLocalBtn').addEventListener('click', async function () {
            if (!state.selected) return;
            try {
                await api('api/history.php', { method: 'POST', json: { action: 'save_local', id: state.selected.id, index: state.selected.index } });
                toast('Saved locally.', 'ok');
                loadJobIntoButtons(state.selected.id);
                refreshHistory(true);
            } catch (e) { toast(e.message, 'err'); }
        });
        $('#deleteLocalBtn').addEventListener('click', async function () {
            if (!state.selected) return;
            try {
                await api('api/history.php', { method: 'POST', json: { action: 'delete_local', id: state.selected.id } });
                toast('Local copy deleted.', 'ok');
                loadJobIntoButtons(state.selected.id);
                refreshHistory(true);
            } catch (e) { toast(e.message, 'err'); }
        });

        wireUpload();
    }

    // ------------------------------------------------------------- init
    document.addEventListener('DOMContentLoaded', function () {
        wire();
        refreshHistory(true);
        if (LVS.hasKey) loadModels(false);
    });
})();
