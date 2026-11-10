/*
 * Account page: renders the TOTP provisioning QR code locally (the secret
 * never leaves the browser/server pair – no external QR service).
 */
(function () {
    'use strict';
    document.querySelectorAll('[data-qr]').forEach(function (el) {
        if (typeof window.qrcode !== 'function') { return; }
        var qr = window.qrcode(0, 'M');
        qr.addData(el.getAttribute('data-qr'));
        qr.make();
        el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
    });
    // Only digits in code inputs
    document.querySelectorAll('.aep-code-input').forEach(function (input) {
        input.addEventListener('input', function () { input.value = input.value.replace(/[^0-9 ]/g, ''); });
    });
})();
