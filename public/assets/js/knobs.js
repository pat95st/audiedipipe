/*
 * Rotary knob control in the style of studio hardware (SVG, no dependencies).
 *
 *   var el = AEP.Knob.create({ label: 'Gain', min: -18, max: 18, step: 0.1, value: 0, unit: 'dB', onChange: fn });
 *
 * Interaction: drag up/down (Shift = fine), mouse wheel, double click = default,
 * keyboard (arrows, PageUp/PageDown, Home/End), click on the value to type.
 */
(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';
    var START = 135; // degrees, 0 = 3 o'clock, clockwise
    var SWEEP = 270;
    var uid = 0;

    var polar = function (cx, cy, r, deg) {
        var rad = deg * Math.PI / 180;
        return [cx + r * Math.cos(rad), cy + r * Math.sin(rad)];
    };
    var arcPath = function (cx, cy, r, from, to) {
        if (Math.abs(to - from) < 0.01) { return ''; }
        var a = polar(cx, cy, r, from);
        var b = polar(cx, cy, r, to);
        var large = Math.abs(to - from) > 180 ? 1 : 0;
        var sweep = to > from ? 1 : 0;
        return 'M' + a[0].toFixed(2) + ' ' + a[1].toFixed(2) + ' A' + r + ' ' + r + ' 0 ' + large + ' ' + sweep + ' ' + b[0].toFixed(2) + ' ' + b[1].toFixed(2);
    };
    var el = function (name, attrs) {
        var node = document.createElementNS(SVG_NS, name);
        Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        return node;
    };
    var decimalsFor = function (step) {
        var s = String(step);
        return s.indexOf('.') === -1 ? 0 : s.length - s.indexOf('.') - 1;
    };

    function Knob(options) {
        this.o = Object.assign({ min: 0, max: 1, step: 0.01, value: 0, unit: '', scale: 'lin', label: '', color: '', size: '' }, options);
        this.o.defaultValue = this.o.defaultValue !== undefined ? this.o.defaultValue : this.o.value;
        if (this.o.scale === 'log' && this.o.min <= 0) { this.o.scale = 'lin'; }
        this.value = this.clamp(Number(this.o.value));
        this.build();
        this.render();
    }

    Knob.prototype.clamp = function (v) {
        if (isNaN(v)) { v = this.o.defaultValue; }
        v = Math.min(this.o.max, Math.max(this.o.min, v));
        var step = this.o.step || 0;
        if (step > 0) {
            v = Math.round(v / step) * step;
            v = Number(v.toFixed(decimalsFor(step)));
        }
        return v;
    };
    Knob.prototype.toNorm = function (v) {
        var o = this.o;
        if (o.scale === 'log') { return Math.log(v / o.min) / Math.log(o.max / o.min); }
        return (v - o.min) / (o.max - o.min);
    };
    Knob.prototype.fromNorm = function (n) {
        var o = this.o;
        n = Math.min(1, Math.max(0, n));
        if (o.scale === 'log') { return o.min * Math.pow(o.max / o.min, n); }
        return o.min + n * (o.max - o.min);
    };
    Knob.prototype.format = function (v) {
        if (typeof this.o.format === 'function') { return this.o.format(v); }
        var d = decimalsFor(this.o.step);
        var unit = this.o.unit;
        if (unit === 'Hz' && v >= 1000) { return (v / 1000).toFixed(v >= 10000 ? 1 : 2) + ' kHz'; }
        if (unit === 'ms' && v >= 1000) { return (v / 1000).toFixed(2) + ' s'; }
        var text = v.toFixed(Math.min(d, Math.abs(v) >= 100 ? 0 : d));
        if (this.o.min < 0 && v > 0 && unit === 'dB') { text = '+' + text; }
        return text + (unit ? (unit.charAt(0) === ':' ? unit : ' ' + unit) : '');
    };

    Knob.prototype.build = function () {
        var self = this;
        var id = 'aep-knob-' + (++uid);
        var root = document.createElement('div');
        root.className = 'aep-knob' + (this.o.size ? ' ' + this.o.size : '');
        if (this.o.color) { root.setAttribute('data-color', this.o.color); }
        root.tabIndex = 0;
        root.setAttribute('role', 'slider');
        root.setAttribute('aria-label', this.o.label);
        root.setAttribute('aria-valuemin', this.o.min);
        root.setAttribute('aria-valuemax', this.o.max);

        var svg = el('svg', { viewBox: '0 0 60 60', 'aria-hidden': 'true' });
        var defs = el('defs');
        var grad = el('radialGradient', { id: id + '-g', cx: '35%', cy: '30%', r: '75%' });
        grad.appendChild(el('stop', { offset: '0%', 'stop-color': '#6c727b' }));
        grad.appendChild(el('stop', { offset: '55%', 'stop-color': '#30353c' }));
        grad.appendChild(el('stop', { offset: '100%', 'stop-color': '#1a1d21' }));
        defs.appendChild(grad);
        svg.appendChild(defs);

        // scale ticks
        for (var i = 0; i <= 10; i++) {
            var deg = START + SWEEP * i / 10;
            var p1 = polar(30, 30, 28.5, deg);
            var p2 = polar(30, 30, i % 5 === 0 ? 26 : 27, deg);
            svg.appendChild(el('line', { x1: p1[0], y1: p1[1], x2: p2[0], y2: p2[1], stroke: 'rgba(255,255,255,.28)', 'stroke-width': 1 }));
        }
        svg.appendChild(el('path', { d: arcPath(30, 30, 24, START, START + SWEEP), fill: 'none', 'stroke-width': 3.2, 'stroke-linecap': 'round', class: 'aep-knob-ring-track' }));
        this.ring = el('path', { d: '', fill: 'none', 'stroke-width': 3.2, 'stroke-linecap': 'round', class: 'aep-knob-ring' });
        svg.appendChild(this.ring);
        svg.appendChild(el('circle', { cx: 30, cy: 30, r: 18.5, fill: '#0d0f12' }));
        svg.appendChild(el('circle', { cx: 30, cy: 30, r: 17.5, fill: 'url(#' + id + '-g)', stroke: '#0b0d10', 'stroke-width': 0.8 }));
        // knurled edge
        for (var k = 0; k < 24; k++) {
            var a = polar(30, 30, 17.2, k * 15);
            var b = polar(30, 30, 15.6, k * 15);
            svg.appendChild(el('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], stroke: 'rgba(0,0,0,.35)', 'stroke-width': 0.8 }));
        }
        this.pointer = el('line', { x1: 30, y1: 30, x2: 30, y2: 15, stroke: '#f1f3f5', 'stroke-width': 2.2, 'stroke-linecap': 'round' });
        svg.appendChild(this.pointer);

        var label = document.createElement('div');
        label.className = 'aep-rack-label';
        label.textContent = this.o.label;

        var value = document.createElement('input');
        value.className = 'aep-knob-value';
        value.type = 'text';
        value.setAttribute('aria-label', this.o.label);
        value.spellcheck = false;
        value.addEventListener('focus', function () { value.value = String(self.value); value.select(); });
        value.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { value.blur(); }
            if (e.key === 'Escape') { value.value = String(self.value); value.blur(); }
            e.stopPropagation();
        });
        value.addEventListener('blur', function () {
            var text = value.value.replace(',', '.').trim();
            var number = parseFloat(text);
            if (/k(hz)?$/i.test(text)) { number *= 1000; }
            if (!isNaN(number)) { self.set(number); } else { self.render(); }
        });

        root.appendChild(svg);
        root.appendChild(value);
        root.appendChild(label);
        this.root = root;
        this.valueEl = value;
        root.knob = this;

        // Pointer dragging
        var startY = 0;
        var startNorm = 0;
        svg.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            root.focus();
            svg.setPointerCapture(e.pointerId);
            startY = e.clientY;
            startNorm = self.toNorm(self.value);
            var move = function (ev) {
                var range = ev.shiftKey ? 800 : 180;
                self.set(self.fromNorm(startNorm + (startY - ev.clientY) / range));
            };
            var up = function () {
                svg.removeEventListener('pointermove', move);
                svg.removeEventListener('pointerup', up);
                svg.removeEventListener('pointercancel', up);
                if (typeof self.o.onCommit === 'function') { self.o.onCommit(self.value); }
            };
            svg.addEventListener('pointermove', move);
            svg.addEventListener('pointerup', up);
            svg.addEventListener('pointercancel', up);
        });
        svg.addEventListener('dblclick', function () { self.set(self.o.defaultValue); });
        svg.addEventListener('wheel', function (e) {
            e.preventDefault();
            var delta = (e.deltaY < 0 ? 1 : -1) * (e.shiftKey ? 0.002 : 0.02);
            self.set(self.fromNorm(self.toNorm(self.value) + delta));
        }, { passive: false });
        root.addEventListener('keydown', function (e) {
            if (e.target === value) { return; }
            var n = self.toNorm(self.value);
            var map = { ArrowUp: 0.01, ArrowRight: 0.01, ArrowDown: -0.01, ArrowLeft: -0.01, PageUp: 0.1, PageDown: -0.1 };
            if (map[e.key] !== undefined) {
                e.preventDefault();
                self.set(self.fromNorm(n + map[e.key] * (e.shiftKey ? 0.2 : 1)));
            } else if (e.key === 'Home') {
                e.preventDefault(); self.set(self.o.min);
            } else if (e.key === 'End') {
                e.preventDefault(); self.set(self.o.max);
            }
        });
    };

    Knob.prototype.render = function () {
        var n = this.toNorm(this.value);
        var deg = START + SWEEP * n;
        var bipolar = this.o.min < 0 && this.o.max > 0 && this.o.scale === 'lin';
        var from = bipolar ? START + SWEEP * this.toNorm(0) : START;
        this.ring.setAttribute('d', from <= deg ? arcPath(30, 30, 24, from, deg) : arcPath(30, 30, 24, deg, from));
        var tip = polar(30, 30, 15, deg);
        var base = polar(30, 30, 6, deg);
        this.pointer.setAttribute('x1', base[0].toFixed(2));
        this.pointer.setAttribute('y1', base[1].toFixed(2));
        this.pointer.setAttribute('x2', tip[0].toFixed(2));
        this.pointer.setAttribute('y2', tip[1].toFixed(2));
        if (document.activeElement !== this.valueEl) { this.valueEl.value = this.format(this.value); }
        this.root.setAttribute('aria-valuenow', this.value);
        this.root.setAttribute('aria-valuetext', this.format(this.value));
    };

    Knob.prototype.set = function (v, silent) {
        var next = this.clamp(Number(v));
        if (next === this.value) { this.render(); return; }
        this.value = next;
        this.render();
        if (!silent && typeof this.o.onChange === 'function') { this.o.onChange(this.value); }
    };

    Knob.prototype.setDisabled = function (disabled) {
        this.root.classList.toggle('disabled', !!disabled);
    };

    window.AEP = window.AEP || {};
    window.AEP.Knob = {
        create: function (options) { return new Knob(options).root; },
        Knob: Knob
    };
})();
