/*
 * Applies the color mode before first paint to avoid flashing.
 * Preference order: local choice (localStorage) > user/server preference > OS setting.
 */
(function () {
    'use strict';
    var root = document.documentElement;
    var stored = null;
    try { stored = localStorage.getItem('aep-theme'); } catch (e) { /* storage blocked */ }
    var pref = stored || root.getAttribute('data-theme-pref') || 'auto';
    var dark = pref === 'dark' || (pref === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    root.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
    root.setAttribute('data-theme-active-pref', pref);
})();
