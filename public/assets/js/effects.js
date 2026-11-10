/*
 * Effect GUIs ("rack units") for all audio effects. Each GUI edits a params
 * object that matches the server side schema (see src/Domain/Audio/Effect).
 *
 *   var unit = AEP.Effects.render(container, 'compressor', params, schema, function (params) { ... });
 *
 * Curve displays use the same filter math as ffmpeg (RBJ biquads) so the
 * picture matches what the server renders.
 */
(function () {
    'use strict';

    var AEP = window.AEP;
    var t = function (key, params) { return AEP.t(key, params); };
    var FS = 48000;
    var BAND_COLORS = ['#ff6b6b', '#ffa94d', '#ffd43b', '#69db7c', '#4dabf7', '#b197fc'];
    var CATEGORY = {
        noise_reduction: 'restore', low_cut: 'restore', parametric_eq: 'eq', dynamic_eq: 'eq',
        gate: 'dynamics', expander: 'dynamics', compressor: 'dynamics', master_leveler: 'master'
    };
    var MODEL = {
        noise_reduction: 'NR-1 SPECTRAL', low_cut: 'HP-24 FILTER', parametric_eq: 'PQ-6 PARAMETRIC', dynamic_eq: 'DQ-4 DYNAMIC',
        gate: 'GT-1 GATE', expander: 'EX-2 EXPANDER', compressor: 'CL-1 COMPRESSOR', master_leveler: 'LV-R128 LEVELER'
    };

    /* ------------------------------------------------------------------ */
    /* Filter math                                                         */
    /* ------------------------------------------------------------------ */
    var biquad = function (type, f, gain, q) {
        var A = Math.pow(10, gain / 40);
        var w0 = 2 * Math.PI * Math.min(f, FS / 2 - 1) / FS;
        var cos = Math.cos(w0);
        var alpha = Math.sin(w0) / (2 * Math.max(q, 0.01));
        var sA = 2 * Math.sqrt(A) * alpha;
        var b0, b1, b2, a0, a1, a2;
        switch (type) {
            case 'low_shelf':
                b0 = A * ((A + 1) - (A - 1) * cos + sA); b1 = 2 * A * ((A - 1) - (A + 1) * cos); b2 = A * ((A + 1) - (A - 1) * cos - sA);
                a0 = (A + 1) + (A - 1) * cos + sA; a1 = -2 * ((A - 1) + (A + 1) * cos); a2 = (A + 1) + (A - 1) * cos - sA;
                break;
            case 'high_shelf':
                b0 = A * ((A + 1) + (A - 1) * cos + sA); b1 = -2 * A * ((A - 1) + (A + 1) * cos); b2 = A * ((A + 1) + (A - 1) * cos - sA);
                a0 = (A + 1) - (A - 1) * cos + sA; a1 = 2 * ((A - 1) - (A + 1) * cos); a2 = (A + 1) - (A - 1) * cos - sA;
                break;
            case 'low_pass':
                b0 = (1 - cos) / 2; b1 = 1 - cos; b2 = (1 - cos) / 2; a0 = 1 + alpha; a1 = -2 * cos; a2 = 1 - alpha;
                break;
            case 'high_pass':
                b0 = (1 + cos) / 2; b1 = -(1 + cos); b2 = (1 + cos) / 2; a0 = 1 + alpha; a1 = -2 * cos; a2 = 1 - alpha;
                break;
            case 'notch':
                b0 = 1; b1 = -2 * cos; b2 = 1; a0 = 1 + alpha; a1 = -2 * cos; a2 = 1 - alpha;
                break;
            case 'band_pass':
                b0 = alpha; b1 = 0; b2 = -alpha; a0 = 1 + alpha; a1 = -2 * cos; a2 = 1 - alpha;
                break;
            default: // bell / peaking
                b0 = 1 + alpha * A; b1 = -2 * cos; b2 = 1 - alpha * A; a0 = 1 + alpha / A; a1 = -2 * cos; a2 = 1 - alpha / A;
        }
        return [b0 / a0, b1 / a0, b2 / a0, a1 / a0, a2 / a0];
    };
    var magnitudeDb = function (c, f) {
        var w = 2 * Math.PI * f / FS;
        var cos1 = Math.cos(w), sin1 = Math.sin(w), cos2 = Math.cos(2 * w), sin2 = Math.sin(2 * w);
        var nr = c[0] + c[1] * cos1 + c[2] * cos2, ni = -(c[1] * sin1 + c[2] * sin2);
        var dr = 1 + c[3] * cos1 + c[4] * cos2, di = -(c[3] * sin1 + c[4] * sin2);
        return 10 * Math.log10((nr * nr + ni * ni) / Math.max(1e-20, dr * dr + di * di));
    };

    /* ------------------------------------------------------------------ */
    /* Canvas display helper                                               */
    /* ------------------------------------------------------------------ */
    function Screen(parent, options) {
        this.o = Object.assign({ small: false, readout: true }, options);
        this.wrap = document.createElement('div');
        this.wrap.className = 'aep-screen' + (this.o.small ? ' small' : '');
        this.canvas = document.createElement('canvas');
        this.wrap.appendChild(this.canvas);
        if (this.o.readout) {
            this.readout = document.createElement('div');
            this.readout.className = 'aep-screen-readout';
            this.wrap.appendChild(this.readout);
        }
        parent.appendChild(this.wrap);
        this.draw = function () {};
        var self = this;
        if (window.ResizeObserver) {
            new ResizeObserver(function () { self.redraw(); }).observe(this.canvas);
        }
    }
    Screen.prototype.redraw = function () {
        var rect = this.canvas.getBoundingClientRect();
        if (rect.width < 10) { return; }
        var ratio = window.devicePixelRatio || 1;
        this.canvas.width = Math.round(rect.width * ratio);
        this.canvas.height = Math.round(rect.height * ratio);
        var ctx = this.canvas.getContext('2d');
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        this.w = rect.width;
        this.h = rect.height;
        ctx.clearRect(0, 0, this.w, this.h);
        this.draw(ctx, this.w, this.h);
    };
    Screen.prototype.grid = function (ctx, w, h, xs, ys, xLabel, yLabel) {
        ctx.font = '10px ui-monospace, Menlo, monospace';
        ctx.lineWidth = 1;
        xs.forEach(function (x) {
            ctx.strokeStyle = 'rgba(157,255,138,.08)';
            ctx.beginPath(); ctx.moveTo(x.px + 0.5, 0); ctx.lineTo(x.px + 0.5, h); ctx.stroke();
            if (x.label) { ctx.fillStyle = 'rgba(157,255,138,.45)'; ctx.fillText(x.label, x.px + 3, h - 4); }
        });
        ys.forEach(function (y) {
            ctx.strokeStyle = y.strong ? 'rgba(157,255,138,.22)' : 'rgba(157,255,138,.08)';
            ctx.beginPath(); ctx.moveTo(0, y.px + 0.5); ctx.lineTo(w, y.px + 0.5); ctx.stroke();
            if (y.label) { ctx.fillStyle = 'rgba(157,255,138,.45)'; ctx.fillText(y.label, 3, y.px < 14 ? y.px + 11 : y.px - 3); }
        });
    };

    // log frequency axis helpers
    var F_MIN = 20, F_MAX = 20000;
    var fToX = function (f, w) { return Math.log(f / F_MIN) / Math.log(F_MAX / F_MIN) * w; };
    var xToF = function (x, w) { return F_MIN * Math.pow(F_MAX / F_MIN, Math.min(1, Math.max(0, x / w))); };
    var freqGrid = function (w) {
        return [50, 100, 200, 500, 1000, 2000, 5000, 10000].map(function (f) {
            return { px: fToX(f, w), label: f >= 1000 ? (f / 1000) + 'k' : String(f) };
        });
    };
    var fmtHz = function (f) { return f >= 1000 ? (f / 1000).toFixed(f >= 10000 ? 1 : 2) + ' kHz' : Math.round(f) + ' Hz'; };

    /* ------------------------------------------------------------------ */
    /* Rack unit builder                                                   */
    /* ------------------------------------------------------------------ */
    function Unit(container, type, params, schema, onChange) {
        this.type = type;
        this.params = JSON.parse(JSON.stringify(params || {}));
        this.schema = schema || {};
        this.onChange = onChange || function () {};
        this.controls = {};
        this.redraws = [];

        container.innerHTML = '';
        var rack = document.createElement('div');
        rack.className = 'aep-rack aep-type-' + type;
        rack.innerHTML = '<div class="aep-rack-head"><span class="aep-rack-accent"></span><div><div class="aep-rack-title"></div><div class="aep-rack-model"></div></div></div><div class="aep-rack-body"></div>';
        rack.querySelector('.aep-rack-title').textContent = t('stage.' + type);
        rack.querySelector('.aep-rack-model').textContent = MODEL[type] || '';
        container.appendChild(rack);
        this.rack = rack;
        this.head = rack.querySelector('.aep-rack-head');
        this.body = rack.querySelector('.aep-rack-body');
    }
    Unit.prototype.changed = function () {
        this.redraws.forEach(function (fn) { fn(); });
        this.onChange(JSON.parse(JSON.stringify(this.params)));
    };
    Unit.prototype.section = function (title, parent) {
        var s = document.createElement('div');
        s.className = 'aep-rack-section';
        if (title) {
            var h = document.createElement('div');
            h.className = 'aep-rack-section-title';
            h.textContent = title;
            s.appendChild(h);
        }
        (parent || this.body).appendChild(s);
        return s;
    };
    Unit.prototype.knob = function (parent, name, opts, target, schemaOverride) {
        var self = this;
        var obj = target || this.params;
        var def = schemaOverride || this.schema[name] || {};
        opts = opts || {};
        var unit = def.unit || '';
        var el = AEP.Knob.create({
            label: opts.label || t('fx.param.' + name),
            min: def.min, max: def.max, step: def.step,
            value: obj[name], defaultValue: def['default'],
            unit: unit,
            scale: opts.scale || ((unit === 'Hz' || unit === 'ms' || name === 'q' || name === 'ratio') && def.min > 0 ? 'log' : 'lin'),
            color: opts.color || '',
            size: opts.size || '',
            format: opts.format,
            onChange: function (v) { obj[name] = v; self.changed(); }
        });
        parent.appendChild(el);
        return el.knob;
    };
    Unit.prototype.toggle = function (parent, name, label, target) {
        var self = this;
        var obj = target || this.params;
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'aep-hw-btn aep-toggle' + (obj[name] ? ' active' : '');
        button.textContent = label || t('fx.param.' + name);
        button.setAttribute('aria-pressed', obj[name] ? 'true' : 'false');
        button.addEventListener('click', function () {
            obj[name] = !obj[name];
            button.classList.toggle('active', obj[name]);
            button.setAttribute('aria-pressed', obj[name] ? 'true' : 'false');
            self.changed();
        });
        parent.appendChild(button);
        return button;
    };
    Unit.prototype.segmented = function (parent, name, options, target, labelKey) {
        var self = this;
        var obj = target || this.params;
        var wrap = document.createElement('div');
        wrap.className = 'd-flex flex-column align-items-center gap-1';
        var group = document.createElement('div');
        group.className = 'aep-segmented';
        group.setAttribute('role', 'group');
        options.forEach(function (opt) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'aep-hw-btn' + (String(obj[name]) === String(opt.value) ? ' active' : '');
            button.textContent = opt.label;
            button.addEventListener('click', function () {
                obj[name] = opt.value;
                group.querySelectorAll('button').forEach(function (b) { b.classList.toggle('active', b === button); });
                self.changed();
            });
            group.appendChild(button);
        });
        wrap.appendChild(group);
        var label = document.createElement('div');
        label.className = 'aep-rack-label';
        label.textContent = t(labelKey || 'fx.param.' + name);
        wrap.appendChild(label);
        parent.appendChild(wrap);
        return group;
    };
    Unit.prototype.screen = function (parent, options) {
        var screen = new Screen(parent || this.body, options);
        this.redraws.push(function () { screen.redraw(); });
        var self = this;
        document.addEventListener('aep:theme', function () { screen.redraw(); });
        setTimeout(function () { screen.redraw(); }, 0);
        self.screens = (self.screens || []).concat([screen]);
        return screen;
    };

    /* ------------------------------------------------------------------ */
    /* Shared displays                                                     */
    /* ------------------------------------------------------------------ */
    var drawResponse = function (ctx, w, h, range, curveFn, color, fill) {
        var yFor = function (db) { return h / 2 - db / range * (h / 2 - 8); };
        ctx.beginPath();
        for (var x = 0; x <= w; x += 2) {
            var db = curveFn(xToF(x, w));
            var y = yFor(Math.max(-range * 1.2, Math.min(range * 1.2, db)));
            if (x === 0) { ctx.moveTo(x, y); } else { ctx.lineTo(x, y); }
        }
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.shadowColor = color;
        ctx.shadowBlur = 6;
        ctx.stroke();
        ctx.shadowBlur = 0;
        if (fill) {
            ctx.lineTo(w, h / 2); ctx.lineTo(0, h / 2); ctx.closePath();
            ctx.fillStyle = fill;
            ctx.fill();
        }
        return yFor;
    };
    var eqGrid = function (screen, ctx, w, h, range) {
        var ys = [];
        for (var db = -range; db <= range; db += range / 3) {
            ys.push({ px: h / 2 - db / range * (h / 2 - 8), label: (db > 0 ? '+' : '') + Math.round(db), strong: Math.abs(db) < 0.01 });
        }
        screen.grid(ctx, w, h, freqGrid(w), ys);
    };

    // dynamics transfer curve: inDb -> outDb
    var transferScreen = function (unit, parent, fn, markers) {
        var screen = unit.screen(parent, { small: false });
        var MIN = -80;
        screen.draw = function (ctx, w, h) {
            var size = Math.min(w, h);
            var ox = (w - size) / 2;
            var toX = function (db) { return ox + (db - MIN) / -MIN * size; };
            var toY = function (db) { return h - (db - MIN) / -MIN * h; };
            var xs = [], ys = [];
            for (var d = MIN; d <= 0; d += 10) {
                xs.push({ px: toX(d), label: d % 20 === 0 ? String(d) : '' });
                ys.push({ px: toY(d), label: d % 20 === 0 && d > MIN ? String(d) : '' });
            }
            screen.grid(ctx, w, h, xs, ys);
            ctx.setLineDash([3, 4]);
            ctx.strokeStyle = 'rgba(157,255,138,.25)';
            ctx.beginPath(); ctx.moveTo(toX(MIN), toY(MIN)); ctx.lineTo(toX(0), toY(0)); ctx.stroke();
            ctx.setLineDash([]);
            (markers ? markers() : []).forEach(function (m) {
                ctx.strokeStyle = m.color; ctx.globalAlpha = 0.5;
                ctx.beginPath(); ctx.moveTo(toX(m.db), 0); ctx.lineTo(toX(m.db), h); ctx.stroke();
                ctx.globalAlpha = 1;
            });
            ctx.beginPath();
            for (var i = 0; i <= 160; i++) {
                var input = MIN + i * (-MIN / 160);
                var output = Math.max(MIN - 20, Math.min(12, fn(input)));
                if (i === 0) { ctx.moveTo(toX(input), toY(output)); } else { ctx.lineTo(toX(input), toY(output)); }
            }
            ctx.strokeStyle = '#ffd43b';
            ctx.lineWidth = 2.2;
            ctx.shadowColor = '#ffd43b'; ctx.shadowBlur = 6;
            ctx.stroke();
            ctx.shadowBlur = 0;
        };
        return screen;
    };

    var softKnee = function (x, T, R, kneeDb) {
        var W = Math.max(0.01, kneeDb);
        var d = x - T;
        if (2 * d < -W) { return x; }
        if (2 * Math.abs(d) <= W) { return x + (1 / R - 1) * Math.pow(d + W / 2, 2) / (2 * W); }
        return T + d / R;
    };

    /* ------------------------------------------------------------------ */
    /* Effect definitions                                                  */
    /* ------------------------------------------------------------------ */
    var builders = {};

    builders.noise_reduction = function (u) {
        var p = u.params;
        var screen = u.screen(u.body, { small: true });
        // Stylised spectrum: noise floor before/after reduction.
        var noise = [];
        for (var i = 0; i < 200; i++) { noise.push(Math.random() * 6 - 3); }
        screen.draw = function (ctx, w, h) {
            var yFor = function (db) { return h - (db + 100) / 100 * h; };
            var ys = [-80, -60, -40, -20].map(function (d) { return { px: yFor(d), label: d + ' dB' }; });
            screen.grid(ctx, w, h, freqGrid(w), ys);
            var floor = p.noise_floor;
            var after = floor - p.reduction;
            var path = function (level, color, fill, voice) {
                ctx.beginPath();
                for (var x = 0; x <= w; x += 2) {
                    var f = xToF(x, w);
                    var tilt = p.noise_type === 'v' ? -Math.log2(f / 1000) * 3 : (p.noise_type === 's' ? 0 : -Math.log2(f / 1000) * 1.5);
                    var speech = voice ? 38 * Math.exp(-Math.pow(Math.log2(f / 400), 2) / 2.2) : 0;
                    var level2 = level + tilt + noise[Math.floor(x / w * 199)] + speech;
                    var y = yFor(level2);
                    if (x === 0) { ctx.moveTo(x, y); } else { ctx.lineTo(x, y); }
                }
                ctx.strokeStyle = color; ctx.lineWidth = 1.6; ctx.stroke();
                if (fill) { ctx.lineTo(w, h); ctx.lineTo(0, h); ctx.closePath(); ctx.fillStyle = fill; ctx.fill(); }
            };
            path(floor, 'rgba(255,107,107,.7)', 'rgba(255,107,107,.08)', true);
            path(Math.max(-100, after), '#69db7c', 'rgba(105,219,124,.12)', true);
            screen.readout.textContent = t('fx.param.noise_floor') + ' ' + floor + ' dB → −' + p.reduction.toFixed(1) + ' dB';
        };
        var s = u.section(t('fx.section.reduction'));
        u.knob(s, 'reduction', { color: 'green' });
        u.knob(s, 'noise_floor', { color: 'red' });
        var s2 = u.section(t('fx.section.mode'));
        u.segmented(s2, 'noise_type', [
            { value: 'w', label: t('fx.noise.w') }, { value: 'v', label: t('fx.noise.v') }, { value: 's', label: t('fx.noise.s') }
        ]);
        u.toggle(s2, 'track_noise');
    };

    builders.low_cut = function (u) {
        var p = u.params;
        var screen = u.screen(u.body, { small: true });
        screen.draw = function (ctx, w, h) {
            eqGrid(screen, ctx, w, h, 24);
            var stages = Math.max(1, Math.floor(Number(p.slope) / 12));
            var c = biquad('high_pass', p.frequency, 0, 0.707);
            drawResponse(ctx, w, h, 24, function (f) { return magnitudeDb(c, f) * stages; }, '#20c997', 'rgba(32,201,151,.12)');
            screen.readout.textContent = fmtHz(p.frequency) + ' · ' + p.slope + ' dB/oct';
        };
        var s = u.section(t('fx.section.filter'));
        u.knob(s, 'frequency', { color: 'green' });
        u.segmented(s, 'slope', [12, 24, 36, 48].map(function (v) { return { value: v, label: v }; }), null, 'fx.param.slope');
        attachHandleDrag(screen, function () {
            return [{ f: p.frequency, db: 0, color: '#20c997' }];
        }, function (index, f) {
            p.frequency = Math.round(Math.min(500, Math.max(10, f)));
            u.knobs.frequency.set(p.frequency, true);
            u.changed();
        }, 24);
    };

    builders.parametric_eq = function (u) {
        var p = u.params;
        var range = 18;
        var selected = 0;
        var screen = u.screen(u.body);
        var bandCoeffs = function (band) {
            if (!band.enabled) { return null; }
            if ((band.type === 'bell' || band.type === 'low_shelf' || band.type === 'high_shelf') && Math.abs(band.gain) < 0.05) { return null; }
            return biquad(band.type, band.frequency, band.gain, band.q);
        };
        screen.draw = function (ctx, w, h) {
            eqGrid(screen, ctx, w, h, range);
            p.bands.forEach(function (band, i) {
                var c = bandCoeffs(band);
                if (c) {
                    ctx.globalAlpha = i === selected ? 0.65 : 0.3;
                    drawResponse(ctx, w, h, range, function (f) { return magnitudeDb(c, f); }, BAND_COLORS[i]);
                    ctx.globalAlpha = 1;
                }
            });
            var coeffs = p.bands.map(bandCoeffs).filter(Boolean);
            var yFor = drawResponse(ctx, w, h, range, function (f) {
                return coeffs.reduce(function (sum, c) { return sum + magnitudeDb(c, f); }, 0) + p.output_gain;
            }, '#f1f3f5', 'rgba(255,255,255,.06)');
            p.bands.forEach(function (band, i) {
                var x = fToX(band.frequency, w);
                var y = yFor(['low_pass', 'high_pass', 'notch'].indexOf(band.type) !== -1 ? 0 : band.gain);
                ctx.beginPath();
                ctx.arc(x, y, i === selected ? 8 : 6.5, 0, Math.PI * 2);
                ctx.fillStyle = band.enabled ? BAND_COLORS[i] : '#555';
                ctx.fill();
                ctx.fillStyle = '#111';
                ctx.font = 'bold 9px ui-monospace, monospace';
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                ctx.fillText(String(i + 1), x, y + 0.5);
                ctx.textAlign = 'start'; ctx.textBaseline = 'alphabetic';
            });
            var b = p.bands[selected];
            screen.readout.textContent = (selected + 1) + ' · ' + t('fx.band.' + b.type) + ' · ' + fmtHz(b.frequency) + ' · ' + (b.gain > 0 ? '+' : '') + b.gain.toFixed(1) + ' dB · Q ' + b.q.toFixed(2);
        };

        var grid = document.createElement('div');
        grid.className = 'aep-bands';
        u.body.appendChild(grid);
        var bandSchema = u.schema.bands ? u.schema.bands.fields : {};
        var bandKnobs = [];
        p.bands.forEach(function (band, i) {
            var strip = document.createElement('div');
            strip.className = 'aep-band' + (band.enabled ? '' : ' off');
            strip.style.setProperty('--band-color', BAND_COLORS[i]);
            var head = document.createElement('div');
            head.className = 'aep-band-head';
            head.innerHTML = '<span class="aep-band-num">' + (i + 1) + '</span>';
            var power = document.createElement('button');
            power.type = 'button';
            power.className = 'aep-hw-btn aep-toggle py-0' + (band.enabled ? ' active' : '');
            power.style.setProperty('--toggle-color', BAND_COLORS[i]);
            power.textContent = 'ON';
            power.setAttribute('aria-label', t('fx.band_on', { n: i + 1 }));
            power.addEventListener('click', function () {
                band.enabled = !band.enabled;
                power.classList.toggle('active', band.enabled);
                strip.classList.toggle('off', !band.enabled);
                selected = i;
                u.changed();
            });
            head.appendChild(power);
            strip.appendChild(head);
            var select = document.createElement('select');
            select.className = 'form-select form-select-sm';
            select.setAttribute('aria-label', t('fx.param.type'));
            (bandSchema.type ? bandSchema.type.options : ['bell']).forEach(function (opt) {
                var o = document.createElement('option');
                o.value = opt; o.textContent = t('fx.band.' + opt); o.selected = band.type === opt;
                select.appendChild(o);
            });
            select.addEventListener('change', function () {
                band.type = select.value; selected = i;
                knobs.gain.setDisabled(['low_pass', 'high_pass', 'notch'].indexOf(band.type) !== -1);
                u.changed();
            });
            strip.appendChild(select);
            var knobs = {
                frequency: u.knob(strip, 'frequency', { size: 'small', color: 'white', label: t('fx.param.frequency') }, band, bandSchema.frequency),
                gain: u.knob(strip, 'gain', { size: 'small', color: 'red' }, band, bandSchema.gain),
                q: u.knob(strip, 'q', { size: 'small', color: 'blue', label: 'Q' }, band, bandSchema.q)
            };
            knobs.gain.setDisabled(['low_pass', 'high_pass', 'notch'].indexOf(band.type) !== -1);
            strip.addEventListener('pointerdown', function () { if (selected !== i) { selected = i; screen.redraw(); } });
            bandKnobs.push(knobs);
            grid.appendChild(strip);
        });
        var out = u.section(t('fx.section.output'));
        u.knob(out, 'output_gain', { color: 'yellow' });

        attachHandleDrag(screen, function () {
            return p.bands.map(function (band, i) {
                return { f: band.frequency, db: ['low_pass', 'high_pass', 'notch'].indexOf(band.type) !== -1 ? 0 : band.gain, color: BAND_COLORS[i] };
            });
        }, function (index, f, db) {
            var band = p.bands[index];
            selected = index;
            band.frequency = Math.round(Math.min(20000, Math.max(20, f)));
            if (['low_pass', 'high_pass', 'notch'].indexOf(band.type) === -1) {
                band.gain = Math.round(Math.min(18, Math.max(-18, db)) * 10) / 10;
            }
            bandKnobs[index].frequency.set(band.frequency, true);
            bandKnobs[index].gain.set(band.gain, true);
            u.changed();
        }, range, function (index, deltaY) {
            var band = p.bands[index];
            selected = index;
            band.q = Math.round(Math.min(18, Math.max(0.1, band.q * (deltaY < 0 ? 1.1 : 0.9))) * 100) / 100;
            bandKnobs[index].q.set(band.q, true);
            u.changed();
        });
    };

    builders.dynamic_eq = function (u) {
        var p = u.params;
        var selected = 0;
        var screen = u.screen(u.body);
        screen.draw = function (ctx, w, h) {
            eqGrid(screen, ctx, w, h, 18);
            p.bands.forEach(function (band, i) {
                if (!band.enabled) { return; }
                // show the maximum possible reduction (signal far above threshold) as shaded area
                var maxCut = -Math.min(18, (1 - 1 / band.ratio) * 24);
                var type = band.type === 'low_shelf' ? 'low_shelf' : (band.type === 'high_shelf' ? 'high_shelf' : 'bell');
                var c = biquad(type, band.frequency, maxCut, band.q);
                ctx.globalAlpha = i === selected ? 1 : 0.55;
                drawResponse(ctx, w, h, 18, function (f) { return magnitudeDb(c, f); }, BAND_COLORS[i + 1], 'rgba(255,255,255,.05)');
                ctx.globalAlpha = 1;
            });
            var b = p.bands[selected];
            screen.readout.textContent = (selected + 1) + ' · ' + fmtHz(b.frequency) + ' · ' + t('fx.param.threshold') + ' ' + b.threshold + ' dB · ' + b.ratio + ':1';
        };
        var grid = document.createElement('div');
        grid.className = 'aep-bands four';
        u.body.appendChild(grid);
        var bandSchema = u.schema.bands ? u.schema.bands.fields : {};
        var bandKnobs = [];
        p.bands.forEach(function (band, i) {
            var strip = document.createElement('div');
            strip.className = 'aep-band' + (band.enabled ? '' : ' off');
            strip.style.setProperty('--band-color', BAND_COLORS[i + 1]);
            var head = document.createElement('div');
            head.className = 'aep-band-head';
            head.innerHTML = '<span class="aep-band-num">' + (i + 1) + '</span>';
            var power = document.createElement('button');
            power.type = 'button';
            power.className = 'aep-hw-btn aep-toggle py-0' + (band.enabled ? ' active' : '');
            power.style.setProperty('--toggle-color', BAND_COLORS[i + 1]);
            power.textContent = 'ON';
            power.addEventListener('click', function () {
                band.enabled = !band.enabled; selected = i;
                power.classList.toggle('active', band.enabled);
                strip.classList.toggle('off', !band.enabled);
                u.changed();
            });
            head.appendChild(power);
            strip.appendChild(head);
            var select = document.createElement('select');
            select.className = 'form-select form-select-sm';
            (bandSchema.type ? bandSchema.type.options : ['bell']).forEach(function (opt) {
                var o = document.createElement('option');
                o.value = opt; o.textContent = t('fx.band.' + opt); o.selected = band.type === opt;
                select.appendChild(o);
            });
            select.addEventListener('change', function () { band.type = select.value; selected = i; u.changed(); });
            strip.appendChild(select);
            var row1 = document.createElement('div');
            row1.className = 'd-flex flex-wrap justify-content-center';
            strip.appendChild(row1);
            var knobs = {
                frequency: u.knob(row1, 'frequency', { size: 'small', color: 'white' }, band, bandSchema.frequency),
                q: u.knob(row1, 'q', { size: 'small', color: 'blue', label: 'Q' }, band, bandSchema.q),
                threshold: u.knob(row1, 'threshold', { size: 'small', color: 'red' }, band, bandSchema.threshold),
                ratio: u.knob(row1, 'ratio', { size: 'small', color: 'yellow' }, band, bandSchema.ratio),
                attack: u.knob(row1, 'attack', { size: 'small', color: 'white' }, band, bandSchema.attack),
                release: u.knob(row1, 'release', { size: 'small', color: 'white' }, band, bandSchema.release)
            };
            strip.addEventListener('pointerdown', function () { if (selected !== i) { selected = i; screen.redraw(); } });
            bandKnobs.push(knobs);
            grid.appendChild(strip);
        });
        attachHandleDrag(screen, function () {
            return p.bands.map(function (band, i) { return { f: band.frequency, db: 0, color: BAND_COLORS[i + 1], hidden: !band.enabled }; });
        }, function (index, f) {
            selected = index;
            p.bands[index].frequency = Math.round(Math.min(20000, Math.max(20, f)));
            bandKnobs[index].frequency.set(p.bands[index].frequency, true);
            u.changed();
        }, 18);
    };

    builders.compressor = function (u) {
        var p = u.params;
        var screen = transferScreen(u, u.body, function (x) {
            var wet = softKnee(x, p.threshold, p.ratio, p.knee * 2) + p.makeup;
            var mix = p.mix / 100;
            return 20 * Math.log10(mix * Math.pow(10, wet / 20) + (1 - mix) * Math.pow(10, x / 20));
        }, function () { return [{ db: p.threshold, color: '#ff6b6b' }]; });
        var oldDraw = screen.draw;
        screen.draw = function (ctx, w, h) {
            oldDraw(ctx, w, h);
            screen.readout.textContent = p.threshold + ' dB · ' + p.ratio + ':1 · +' + p.makeup + ' dB';
        };
        var s = u.section(t('fx.section.dynamics'));
        u.knob(s, 'threshold', { color: 'red' });
        u.knob(s, 'ratio', { color: 'yellow' });
        u.knob(s, 'knee', { color: 'white' });
        var s2 = u.section(t('fx.section.time'));
        u.knob(s2, 'attack', { color: 'blue' });
        u.knob(s2, 'release', { color: 'blue' });
        var s3 = u.section(t('fx.section.output'));
        u.knob(s3, 'makeup', { color: 'green' });
        u.knob(s3, 'mix', { color: 'violet' });
        var s4 = u.section(t('fx.section.detector'));
        u.segmented(s4, 'detection', [{ value: 'rms', label: 'RMS' }, { value: 'peak', label: 'Peak' }]);
        u.segmented(s4, 'link', [{ value: 'average', label: 'AVG' }, { value: 'maximum', label: 'MAX' }]);
    };

    var gateLike = function (u) {
        var p = u.params;
        var screen = transferScreen(u, u.body, function (x) {
            if (x >= p.threshold) { return x; }
            return Math.max(p.threshold + (x - p.threshold) * p.ratio, x + p.range);
        }, function () { return [{ db: p.threshold, color: '#ff6b6b' }]; });
        var oldDraw = screen.draw;
        screen.draw = function (ctx, w, h) {
            oldDraw(ctx, w, h);
            screen.readout.textContent = p.threshold + ' dB · ' + p.ratio + ':1 · ' + p.range + ' dB';
        };
        var s = u.section(t('fx.section.dynamics'));
        u.knob(s, 'threshold', { color: 'red' });
        u.knob(s, 'ratio', { color: 'yellow' });
        u.knob(s, 'range', { color: 'pink' });
        u.knob(s, 'knee', { color: 'white' });
        var s2 = u.section(t('fx.section.time'));
        u.knob(s2, 'attack', { color: 'blue' });
        u.knob(s2, 'release', { color: 'blue' });
        var s3 = u.section(t('fx.section.detector'));
        u.segmented(s3, 'detection', [{ value: 'rms', label: 'RMS' }, { value: 'peak', label: 'Peak' }]);
    };
    builders.gate = gateLike;
    builders.expander = gateLike;

    builders.master_leveler = function (u) {
        var p = u.params;
        var screen = u.screen(u.body, { small: true });
        var presets = [
            { label: 'Spotify', lufs: -14 }, { label: 'Apple / Podcast', lufs: -16 }, { label: 'Mono podcast', lufs: -19 },
            { label: 'EBU R128', lufs: -23 }, { label: 'ATSC A/85', lufs: -24 }
        ];
        screen.draw = function (ctx, w, h) {
            var MIN = -30, MAX = -8;
            var toX = function (l) { return (l - MIN) / (MAX - MIN) * (w - 20) + 10; };
            var xs = [];
            for (var l = MIN; l <= MAX; l += 2) { xs.push({ px: toX(l), label: l % 4 === 0 ? String(l) : '' }); }
            screen.grid(ctx, w, h, xs, []);
            var barY = h * 0.38, barH = h * 0.2;
            var grad = ctx.createLinearGradient(toX(MIN), 0, toX(MAX), 0);
            grad.addColorStop(0, '#1c7ed6'); grad.addColorStop(0.55, '#37d67a'); grad.addColorStop(0.8, '#ffb020'); grad.addColorStop(1, '#ff4d4f');
            ctx.fillStyle = grad;
            ctx.globalAlpha = 0.35;
            ctx.fillRect(toX(MIN), barY, toX(MAX) - toX(MIN), barH);
            ctx.globalAlpha = 1;
            ctx.fillRect(toX(MIN), barY, toX(p.target) - toX(MIN), barH);
            presets.forEach(function (pr) {
                ctx.fillStyle = 'rgba(255,255,255,.55)';
                ctx.fillRect(toX(pr.lufs) - 0.5, barY - 6, 1, barH + 12);
                ctx.font = '9px ui-monospace, monospace';
                ctx.save(); ctx.translate(toX(pr.lufs) + 3, barY - 8); ctx.rotate(-0.5);
                ctx.fillText(pr.label, 0, 0); ctx.restore();
            });
            ctx.fillStyle = '#ffd43b';
            ctx.beginPath(); ctx.moveTo(toX(p.target), barY + barH + 2); ctx.lineTo(toX(p.target) - 6, barY + barH + 12); ctx.lineTo(toX(p.target) + 6, barY + barH + 12); ctx.fill();
            ctx.font = 'bold 12px ui-monospace, monospace';
            ctx.fillText(p.target.toFixed(1) + ' LUFS', Math.min(w - 90, toX(p.target) + 8), barY + barH + 14);
            screen.readout.textContent = 'TP ' + p.true_peak.toFixed(1) + ' dBTP · LRA ' + p.lra + ' LU';
        };
        var s = u.section(t('fx.section.loudness'));
        var target = u.knob(s, 'target', { color: 'yellow' });
        u.knob(s, 'true_peak', { color: 'red' });
        u.knob(s, 'lra', { color: 'blue' });
        var s2 = u.section(t('fx.section.presets'));
        var group = document.createElement('div');
        group.className = 'd-flex flex-wrap gap-1';
        presets.forEach(function (pr) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'aep-hw-btn';
            b.textContent = pr.label + ' ' + pr.lufs;
            b.addEventListener('click', function () { p.target = pr.lufs; target.set(pr.lufs, true); u.changed(); });
            group.appendChild(b);
        });
        s2.appendChild(group);
        var s3 = u.section(t('fx.section.mode'));
        u.toggle(s3, 'two_pass');
        u.toggle(s3, 'dual_mono');
    };

    /* ------------------------------------------------------------------ */
    /* Dragging handles on frequency displays                              */
    /* ------------------------------------------------------------------ */
    function attachHandleDrag(screen, handles, onMove, range, onWheel) {
        var canvas = screen.canvas;
        var active = -1;
        var posFor = function (hd) {
            return { x: fToX(hd.f, screen.w), y: screen.h / 2 - hd.db / range * (screen.h / 2 - 8) };
        };
        var find = function (e) {
            var rect = canvas.getBoundingClientRect();
            var x = e.clientX - rect.left, y = e.clientY - rect.top;
            var best = -1, bestDist = 18;
            handles().forEach(function (hd, i) {
                if (hd.hidden) { return; }
                var p = posFor(hd);
                var d = Math.hypot(p.x - x, p.y - y);
                if (d < bestDist) { best = i; bestDist = d; }
            });
            return { index: best, x: x, y: y };
        };
        canvas.style.cursor = 'pointer';
        canvas.addEventListener('pointerdown', function (e) {
            var hit = find(e);
            if (hit.index === -1) { return; }
            active = hit.index;
            canvas.setPointerCapture(e.pointerId);
            e.preventDefault();
        });
        canvas.addEventListener('pointermove', function (e) {
            if (active === -1) { return; }
            var rect = canvas.getBoundingClientRect();
            var x = e.clientX - rect.left, y = e.clientY - rect.top;
            var db = (screen.h / 2 - y) / (screen.h / 2 - 8) * range;
            onMove(active, xToF(x, screen.w), db);
        });
        var end = function () { active = -1; };
        canvas.addEventListener('pointerup', end);
        canvas.addEventListener('pointercancel', end);
        if (onWheel) {
            canvas.addEventListener('wheel', function (e) {
                var hit = find(e);
                if (hit.index === -1) { return; }
                e.preventDefault();
                onWheel(hit.index, e.deltaY);
            }, { passive: false });
        }
    }

    /* ------------------------------------------------------------------ */
    /* Public API                                                          */
    /* ------------------------------------------------------------------ */
    AEP.Effects = {
        category: function (type) { return CATEGORY[type] || 'other'; },
        has: function (type) { return Object.prototype.hasOwnProperty.call(builders, type); },
        render: function (container, type, params, schema, onChange) {
            var unit = new Unit(container, type, params, schema, onChange);
            // keep references to top level knobs so displays can update them
            unit.knobs = {};
            var originalKnob = unit.knob;
            unit.knob = function (parent, name, opts, target, schemaOverride) {
                var k = originalKnob.call(unit, parent, name, opts, target, schemaOverride);
                if (!target) { unit.knobs[name] = k; }
                return k;
            };
            builders[type](unit);
            return unit;
        },
        // exported for tests / other views
        biquad: biquad,
        magnitudeDb: magnitudeDb
    };
})();
