/*
 * Manual cut & metadata stage: waveform editor that marks regions to remove.
 * The server removes the regions with ffmpeg (aselect) when the job resumes.
 */
(function () {
    'use strict';

    var AEP = window.AEP;
    var t = AEP.t;
    var dataEl = document.getElementById('aep-cutter-data');
    if (!dataEl) { return; }
    var data = JSON.parse(dataEl.textContent);

    var root = document.querySelector('[data-cutter]');
    var canvas = root.querySelector('[data-cutter-canvas]');
    var overview = root.querySelector('[data-cutter-overview]');
    var scroller = root.querySelector('[data-cutter-scroll]');
    var audio = root.querySelector('[data-cutter-audio]');
    var timeEl = root.querySelector('[data-cutter-time]');
    var durationEl = root.querySelector('[data-cutter-duration]');
    var skip = root.querySelector('[data-cutter-skip]');
    var list = document.querySelector('[data-cutter-list]');
    var summary = document.querySelector('[data-cutter-summary]');
    var errorBox = document.querySelector('[data-cutter-error]');
    var playButton = root.querySelector('[data-cutter-action="play"]');

    var peaks = [];
    var duration = 0;
    var zoom = 1;
    var cuts = [];          // [[start, end], ...] sorted, merged
    var history = [];
    var selection = null;   // [start, end]
    var markIn = null;
    var playhead = 0;
    var hoverCut = -1;

    var css = function (name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    };

    /* ---------------- Data loading ---------------- */
    AEP.request('GET', data.peaks).then(function (result) {
        peaks = result.peaks || [];
        duration = result.duration || audio.duration || 0;
        root.querySelector('[data-cutter-loading]').remove();
        durationEl.textContent = '/ ' + AEP.formatTime(duration, true);
        resize();
        renderList();
    }).catch(function (error) {
        root.querySelector('[data-cutter-loading]').textContent = error.message;
    });

    /* ---------------- Geometry ---------------- */
    var width = function () { return scroller.clientWidth * zoom; };
    var tToX = function (time) { return duration ? time / duration * width() : 0; };
    var xToT = function (x) { return Math.max(0, Math.min(duration, x / width() * duration)); };

    var resize = function () {
        var ratio = window.devicePixelRatio || 1;
        var w = width();
        canvas.style.width = w + 'px';
        canvas.width = Math.round(w * ratio);
        canvas.height = Math.round(180 * ratio);
        var ow = overview.clientWidth;
        overview.width = Math.round(ow * ratio);
        overview.height = Math.round(36 * ratio);
        draw();
    };
    window.addEventListener('resize', resize);

    /* ---------------- Drawing ---------------- */
    var drawWave = function (ctx, w, h, from, to, color) {
        if (!peaks.length) { return; }
        var mid = h / 2;
        ctx.fillStyle = color;
        var startX = Math.max(0, Math.floor(from));
        var endX = Math.min(w, Math.ceil(to));
        for (var x = startX; x < endX; x++) {
            var i0 = Math.floor(x / w * peaks.length);
            var i1 = Math.max(i0 + 1, Math.floor((x + 1) / w * peaks.length));
            var min = 0, max = 0;
            for (var i = i0; i < i1 && i < peaks.length; i++) {
                if (peaks[i][0] < min) { min = peaks[i][0]; }
                if (peaks[i][1] > max) { max = peaks[i][1]; }
            }
            var y1 = mid - max * (mid - 4);
            var y2 = mid - min * (mid - 4);
            ctx.fillRect(x, y1, 1, Math.max(1, y2 - y1));
        }
    };
    var draw = function () {
        var ratio = window.devicePixelRatio || 1;
        var ctx = canvas.getContext('2d');
        var w = width(), h = 180;
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.clearRect(0, 0, w, h);
        var viewFrom = scroller.scrollLeft - 50, viewTo = scroller.scrollLeft + scroller.clientWidth + 50;

        // ruler
        ctx.font = '10px ui-monospace, Menlo, monospace';
        var step = [0.5, 1, 2, 5, 10, 15, 30, 60, 120, 300, 600][0];
        [0.5, 1, 2, 5, 10, 15, 30, 60, 120, 300, 600].some(function (s) { step = s; return tToX(s) > 70; });
        for (var s = 0; s <= duration; s += step) {
            var x = tToX(s);
            if (x < viewFrom || x > viewTo) { continue; }
            ctx.fillStyle = 'rgba(255,255,255,.08)';
            ctx.fillRect(x, 0, 1, h);
            ctx.fillStyle = 'rgba(255,255,255,.4)';
            ctx.fillText(AEP.formatTime(s), x + 3, 11);
        }
        ctx.fillStyle = 'rgba(255,255,255,.12)';
        ctx.fillRect(0, h / 2, w, 1);

        drawWave(ctx, w, h, viewFrom, viewTo, css('--bs-primary', '#e8590c'));

        // cut regions
        cuts.forEach(function (cut, index) {
            var x1 = tToX(cut[0]), x2 = tToX(cut[1]);
            ctx.fillStyle = index === hoverCut ? 'rgba(255,77,79,.45)' : 'rgba(255,77,79,.3)';
            ctx.fillRect(x1, 0, x2 - x1, h);
            ctx.strokeStyle = 'rgba(255,77,79,.9)';
            ctx.lineWidth = 1;
            ctx.beginPath();
            for (var hx = x1 - h; hx < x2; hx += 10) {
                ctx.moveTo(Math.max(x1, hx), hx < x1 ? h - (x1 - hx) : h);
                ctx.lineTo(Math.min(x2, hx + h), hx + h > x2 ? h - (x2 - hx) : 0);
            }
            ctx.globalAlpha = 0.35; ctx.stroke(); ctx.globalAlpha = 1;
            ctx.fillStyle = '#ff6b6b';
            ctx.fillRect(x1, 0, 1.5, h);
            ctx.fillRect(x2 - 1.5, 0, 1.5, h);
        });
        // selection
        if (selection) {
            var sx1 = tToX(selection[0]), sx2 = tToX(selection[1]);
            ctx.fillStyle = 'rgba(77,171,247,.25)';
            ctx.fillRect(sx1, 0, sx2 - sx1, h);
            ctx.fillStyle = '#4dabf7';
            ctx.fillRect(sx1, 0, 1, h);
            ctx.fillRect(sx2 - 1, 0, 1, h);
        }
        if (markIn !== null) {
            ctx.fillStyle = '#4dabf7';
            ctx.beginPath(); ctx.moveTo(tToX(markIn), 14); ctx.lineTo(tToX(markIn) + 8, 14); ctx.lineTo(tToX(markIn), 24); ctx.fill();
        }
        // playhead
        var px = tToX(playhead);
        ctx.fillStyle = '#f1f3f5';
        ctx.fillRect(px - 0.5, 0, 1.5, h);
        ctx.beginPath(); ctx.moveTo(px - 5, 0); ctx.lineTo(px + 6, 0); ctx.lineTo(px + 0.5, 7); ctx.fill();

        drawOverview();
        timeEl.textContent = AEP.formatTime(playhead, true);
    };
    var drawOverview = function () {
        var ratio = window.devicePixelRatio || 1;
        var ctx = overview.getContext('2d');
        var w = overview.clientWidth, h = 36;
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.clearRect(0, 0, w, h);
        drawWave(ctx, w, h, 0, w, 'rgba(173,181,189,.7)');
        cuts.forEach(function (cut) {
            ctx.fillStyle = 'rgba(255,77,79,.45)';
            ctx.fillRect(cut[0] / duration * w, 0, (cut[1] - cut[0]) / duration * w, h);
        });
        if (zoom > 1) {
            ctx.strokeStyle = css('--bs-primary', '#e8590c');
            ctx.lineWidth = 1.5;
            ctx.strokeRect(scroller.scrollLeft / width() * w + 0.5, 0.5, scroller.clientWidth / width() * w - 1, h - 1);
        }
        ctx.fillStyle = '#f1f3f5';
        ctx.fillRect(playhead / (duration || 1) * w, 0, 1, h);
    };
    scroller.addEventListener('scroll', draw);

    /* ---------------- Cuts ---------------- */
    var normalize = function (list) {
        var sorted = list.filter(function (c) { return c[1] - c[0] > 0.005; })
            .map(function (c) { return [Math.max(0, c[0]), Math.min(duration, c[1])]; })
            .sort(function (a, b) { return a[0] - b[0]; });
        var merged = [];
        sorted.forEach(function (c) {
            var last = merged[merged.length - 1];
            if (last && c[0] <= last[1]) { last[1] = Math.max(last[1], c[1]); } else { merged.push([c[0], c[1]]); }
        });
        return merged;
    };
    var setCuts = function (next) {
        history.push(JSON.stringify(cuts));
        if (history.length > 100) { history.shift(); }
        cuts = normalize(next);
        selection = null;
        markIn = null;
        renderList();
        draw();
    };
    var renderList = function () {
        list.innerHTML = '';
        if (!cuts.length) {
            list.innerHTML = '<li class="list-group-item px-0 text-body-secondary">' + AEP.escape(t('js.cut.none')) + '</li>';
        }
        cuts.forEach(function (cut, index) {
            var li = document.createElement('li');
            li.className = 'list-group-item px-0 d-flex align-items-center gap-2';
            li.innerHTML = '<span class="aep-led aep-led-red on"></span><button type="button" class="btn btn-link btn-sm p-0 font-monospace text-body" data-goto></button><span class="text-body-secondary ms-auto"></span><button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove aria-label="' + AEP.escape(t('js.cut.remove')) + '"><i class="bi bi-x-lg"></i></button>';
            li.querySelector('[data-goto]').textContent = AEP.formatTime(cut[0], true) + ' – ' + AEP.formatTime(cut[1], true);
            li.querySelector('.ms-auto').textContent = (cut[1] - cut[0]).toFixed(2) + ' s';
            li.querySelector('[data-goto]').addEventListener('click', function () { seek(Math.max(0, cut[0] - 2)); centerOn(cut[0]); });
            li.querySelector('[data-remove]').addEventListener('click', function () {
                setCuts(cuts.filter(function (_, i) { return i !== index; }));
            });
            li.addEventListener('mouseenter', function () { hoverCut = index; draw(); });
            li.addEventListener('mouseleave', function () { hoverCut = -1; draw(); });
            list.appendChild(li);
        });
        var removed = cuts.reduce(function (sum, c) { return sum + c[1] - c[0]; }, 0);
        summary.textContent = t('js.cut.summary', {
            count: cuts.length,
            removed: AEP.formatTime(removed, true),
            result: AEP.formatTime(Math.max(0, duration - removed), true)
        });
    };

    /* ---------------- Playback ---------------- */
    var seek = function (time) {
        playhead = Math.max(0, Math.min(duration, time));
        if (isFinite(playhead)) { audio.currentTime = playhead; }
        draw();
    };
    var centerOn = function (time) {
        if (zoom > 1) { scroller.scrollLeft = tToX(time) - scroller.clientWidth / 2; }
    };
    var tick = function () {
        if (audio.paused) { return; }
        playhead = audio.currentTime;
        if (skip.checked) {
            for (var i = 0; i < cuts.length; i++) {
                if (playhead >= cuts[i][0] && playhead < cuts[i][1] - 0.01) {
                    audio.currentTime = cuts[i][1];
                    playhead = cuts[i][1];
                    break;
                }
            }
        }
        var x = tToX(playhead);
        if (zoom > 1 && (x < scroller.scrollLeft || x > scroller.scrollLeft + scroller.clientWidth - 20)) {
            scroller.scrollLeft = x - 40;
        }
        draw();
        requestAnimationFrame(tick);
    };
    audio.addEventListener('play', function () { playButton.innerHTML = '<i class="bi bi-pause-fill"></i>'; requestAnimationFrame(tick); });
    audio.addEventListener('pause', function () { playButton.innerHTML = '<i class="bi bi-play-fill"></i>'; draw(); });
    audio.addEventListener('loadedmetadata', function () {
        if (!duration) { duration = audio.duration; durationEl.textContent = '/ ' + AEP.formatTime(duration, true); resize(); }
    });
    var togglePlay = function () {
        if (audio.paused) {
            audio.currentTime = playhead;
            audio.play().catch(function (e) { AEP.toast(e.message, 'danger'); });
        } else {
            audio.pause();
        }
    };

    /* ---------------- Mouse interaction ---------------- */
    var dragStart = null;
    canvas.addEventListener('pointerdown', function (e) {
        var rect = canvas.getBoundingClientRect();
        dragStart = xToT(e.clientX - rect.left);
        selection = null;
        canvas.setPointerCapture(e.pointerId);
    });
    canvas.addEventListener('pointermove', function (e) {
        var rect = canvas.getBoundingClientRect();
        var time = xToT(e.clientX - rect.left);
        if (dragStart !== null && Math.abs(tToX(time) - tToX(dragStart)) > 3) {
            selection = [Math.min(dragStart, time), Math.max(dragStart, time)];
            draw();
        } else if (dragStart === null) {
            var found = -1;
            cuts.forEach(function (c, i) { if (time >= c[0] && time <= c[1]) { found = i; } });
            if (found !== hoverCut) { hoverCut = found; draw(); }
        }
    });
    canvas.addEventListener('pointerup', function (e) {
        var rect = canvas.getBoundingClientRect();
        var time = xToT(e.clientX - rect.left);
        if (!selection) { seek(time); }
        dragStart = null;
        draw();
    });
    overview.addEventListener('click', function (e) {
        var rect = overview.getBoundingClientRect();
        var time = (e.clientX - rect.left) / rect.width * duration;
        if (zoom > 1) { centerOn(time); } else { seek(time); }
    });
    scroller.addEventListener('wheel', function (e) {
        if (!e.ctrlKey) { return; }
        e.preventDefault();
        setZoom(zoom * (e.deltaY < 0 ? 1.25 : 0.8), e.clientX - scroller.getBoundingClientRect().left);
    }, { passive: false });
    var setZoom = function (next, anchorX) {
        var anchorTime = xToT(scroller.scrollLeft + (anchorX === undefined ? scroller.clientWidth / 2 : anchorX));
        zoom = Math.max(1, Math.min(200, next));
        resize();
        scroller.scrollLeft = tToX(anchorTime) - (anchorX === undefined ? scroller.clientWidth / 2 : anchorX);
        draw();
    };

    /* ---------------- Actions ---------------- */
    var actions = {
        play: togglePlay,
        stop: function () { audio.pause(); seek(0); },
        'mark-in': function () { markIn = playhead; selection = null; draw(); },
        'mark-out': function () {
            if (markIn !== null && playhead > markIn) { selection = [markIn, playhead]; markIn = null; draw(); }
        },
        cut: function () {
            if (selection) { setCuts(cuts.concat([selection])); } else { AEP.toast(t('js.cut.select_first')); }
        },
        'trim-start': function () { if (playhead > 0) { setCuts(cuts.concat([[0, playhead]])); } },
        'trim-end': function () { if (playhead < duration) { setCuts(cuts.concat([[playhead, duration]])); } },
        undo: function () {
            if (!history.length) { return; }
            cuts = JSON.parse(history.pop());
            renderList();
            draw();
        },
        'zoom-in': function () { setZoom(zoom * 2); },
        'zoom-out': function () { setZoom(zoom / 2); }
    };
    root.querySelectorAll('[data-cutter-action]').forEach(function (button) {
        button.addEventListener('click', function () { actions[button.getAttribute('data-cutter-action')](); });
    });
    document.addEventListener('keydown', function (e) {
        var tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select') { return; }
        if (e.key === ' ') { e.preventDefault(); togglePlay(); }
        else if (e.key === 'i' || e.key === 'I') { actions['mark-in'](); }
        else if (e.key === 'o' || e.key === 'O') { actions['mark-out'](); }
        else if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); actions.cut(); }
        else if ((e.ctrlKey || e.metaKey) && e.key === 'z') { e.preventDefault(); actions.undo(); }
        else if (e.key === 'ArrowLeft') { seek(playhead - (e.shiftKey ? 0.1 : 1)); }
        else if (e.key === 'ArrowRight') { seek(playhead + (e.shiftKey ? 0.1 : 1)); }
        else if (e.key === '+') { actions['zoom-in'](); }
        else if (e.key === '-') { actions['zoom-out'](); }
    });

    /* ---------------- Metadata & templates ---------------- */
    var metaForm = document.querySelector('[data-cutter-meta]');
    metaForm.addEventListener('submit', function (e) { e.preventDefault(); });
    var templateSelect = document.querySelector('[data-cutter-template]');
    if (templateSelect) {
        templateSelect.addEventListener('change', function () {
            var tpl = data.templates.filter(function (x) { return String(x.id) === templateSelect.value; })[0];
            if (!tpl) { return; }
            Object.keys(tpl.fields).forEach(function (field) {
                var el = metaForm.elements.namedItem(field);
                if (el && tpl.fields[field] !== '') { el.value = tpl.fields[field]; }
            });
            templateSelect.value = '';
            AEP.toast(t('js.template_applied', { name: tpl.name }), 'success');
        });
    }

    /* ---------------- Submit ---------------- */
    document.querySelector('[data-cutter-submit]').addEventListener('click', function (e) {
        var button = e.currentTarget;
        var metadata = {};
        Array.prototype.forEach.call(metaForm.elements, function (el) {
            if (el.name) { metadata[el.name] = el.value; }
        });
        button.disabled = true;
        errorBox.classList.add('d-none');
        AEP.request('POST', data.submit, { cuts: cuts, metadata: metadata }).then(function (result) {
            location.href = result.redirect;
        }).catch(function (error) {
            button.disabled = false;
            errorBox.textContent = error.message;
            errorBox.classList.remove('d-none');
        });
    });
})();
