(function () {
    'use strict';
    // Voluntary opt-out signals are honoured before anything else happens. The
    // server also decides whether this visit is in the sample at all, so a visit
    // that reaches this file has already been selected server-side.
    if (navigator.doNotTrack === '1' || navigator.doNotTrack === 'yes' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true) { return; }
    if (!window.performance || !window.pfcRum || !window.webVitals) { return; }

    var metrics = {};
    var token = null;
    var sent = false;
    var pending = false;
    var wantsSend = false;
    var generation = 0;
    var attempts = 0;
    var MAX_ATTEMPTS = 2;
    var refreshTimer = null;

    function getToken() {
        if (pending || sent) { return; }
        pending = true;
        var startedGeneration = generation;
        // keepalive lets the token request survive an unload that races it, which
        // is the common case for a visitor who bounces immediately.
        fetch(pfcRum.tokenEndpoint + '?route_group=' + encodeURIComponent(pfcRum.route_group), {
            credentials: 'omit', cache: 'no-store', keepalive: true, headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            if (!response.ok) { throw new Error('RUM token unavailable'); }
            return response.json();
        }).then(function (data) {
            if (startedGeneration !== generation) { return; }
            if (!data || !data.token || !data.nonce) {
                // Resolve rather than reject so the pending flag is always cleared;
                // a malformed token must not wedge the client for this visit.
                pending = false;
                return;
            }
            pending = false;
            token = data;
            scheduleRefresh(data.expires);
            if (wantsSend) { send(); }
        }).catch(function () {
            if (startedGeneration === generation) { pending = false; }
        });
    }

    // Refresh shortly before expiry so a final flush never has to start a network
    // request from an unloading document. Timers are clamped in background tabs,
    // so also refresh when the tab becomes visible again.
    function scheduleRefresh(expires) {
        if (refreshTimer) { clearTimeout(refreshTimer); refreshTimer = null; }
        var ms = Number(expires) * 1000 - Date.now();
        if (!isFinite(ms) || ms <= 0) { return; }
        refreshTimer = setTimeout(function () {
            if (sent || document.visibilityState !== 'visible') { return; }
            token = null;
            getToken();
        }, Math.max(5000, ms - 60000));
    }

    // Field attribution: the element or resource behind a bad score. Only a small
    // whitelist of derived scalars leaves the browser -- never the raw entries,
    // selectors, class names, URLs or text content.
    var TAGS = ['IMG', 'PICTURE', 'VIDEO', 'H1', 'H2', 'H3', 'P', 'A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA', 'DIV', 'SPAN', 'SECTION', 'ARTICLE', 'HEADER', 'FOOTER', 'LI', 'UL', 'SVG', 'CANVAS', 'IFRAME', 'FORM', 'TABLE', 'TD', 'BODY', 'MAIN'];
    var attribution = {};
    var longTasks = { count: 0, max: 0, total: 0 };

    function tagOf(element) {
        if (!element || !element.tagName) { return ''; }
        var tag = String(element.tagName).toUpperCase();
        return TAGS.indexOf(tag) === -1 ? 'OTHER' : tag;
    }

    function sameOrigin(url) {
        if (!url || typeof URL !== 'function' || !window.location || !window.location.href) { return null; }
        try { return new URL(url, window.location.href).origin === window.location.origin; } catch (error) { return null; }
    }

    function captureLcp(metric) {
        var entries = metric.entries || [];
        var last = entries[entries.length - 1];
        if (!last) { return; }
        if (last.element) { attribution.lcp_tag = tagOf(last.element); }
        if (Number.isFinite(last.size) && last.size > 0) { attribution.lcp_bytes = Math.round(last.size / 1024) * 1024; }
        var same = sameOrigin(last.url);
        if (same !== null) { attribution.lcp_same_origin = same ? 1 : 0; }
    }

    function captureInp(metric) {
        var entries = metric.entries || [];
        var last = entries[entries.length - 1];
        if (!last || !last.target) { return; }
        attribution.inp_tag = tagOf(last.target);
    }

    function attributionPayload() {
        var out = {};
        if (attribution.lcp_tag) { out.lcp_tag = attribution.lcp_tag; }
        if (attribution.lcp_bytes) { out.lcp_bytes = attribution.lcp_bytes; }
        if (typeof attribution.lcp_same_origin === 'number') { out.lcp_same_origin = attribution.lcp_same_origin; }
        if (attribution.inp_tag) { out.inp_tag = attribution.inp_tag; }
        if (longTasks.count) {
            out.long_task_count = longTasks.count;
            out.long_task_max = longTasks.max;
            out.long_task_total = longTasks.total;
        }
        return Object.keys(out).length ? out : null;
    }

    // Keep the largest value seen per metric, not the last one. The dashboard
    // labels the server's GREATEST() aggregate "Worst observed", so sending only
    // the final callback value made that column understate the visit -- a CLS that
    // peaked early or an INP spike before the final callback were lost.
    function collect(metric) {
        var name = metric.name.toLowerCase();
        if (['ttfb', 'fcp', 'lcp', 'cls', 'inp'].indexOf(name) === -1 || !Number.isFinite(metric.value)) { return; }
        if (typeof metrics[name] !== 'number' || metric.value > metrics[name]) { metrics[name] = metric.value; }
        if (name === 'lcp') { captureLcp(metric); }
        else if (name === 'inp') { captureInp(metric); }
    }

    // Keep the vendor's lifecycle, interaction and session-window algorithms intact.
    ['onTTFB', 'onFCP', 'onLCP', 'onCLS', 'onINP'].forEach(function (name) {
        webVitals[name](collect, { reportAllChanges: true });
    });

    // Long tasks explain a bad INP: the main thread was busy, which no amount of
    // handler tuning will fix. Guarded because the API is absent on older browsers.
    if (window.PerformanceObserver && PerformanceObserver.supportedEntryTypes && PerformanceObserver.supportedEntryTypes.indexOf('longtask') !== -1) {
        try {
            new PerformanceObserver(function (list) {
                var entries = list.getEntries() || [];
                for (var i = 0; i < entries.length; i++) {
                    var duration = Number(entries[i].duration);
                    if (!isFinite(duration) || duration <= 0) { continue; }
                    longTasks.count++;
                    longTasks.total = Math.round(longTasks.total + duration);
                    if (duration > longTasks.max) { longTasks.max = Math.round(duration); }
                }
            }).observe({ type: 'longtask', buffered: true });
        } catch (error) {}
    }

    function send() {
        if (sent || !Object.keys(metrics).length) { return; }
        wantsSend = true;
        if (!token || Number(token.expires) * 1000 <= Date.now()) { getToken(); return; }
        var payload = Object.assign({}, token, metrics, { metric_version: 2 });
        var extra = attributionPayload();
        if (extra) { payload.attribution = extra; }
        var body = JSON.stringify(payload);
        try {
            var queued = navigator.sendBeacon && navigator.sendBeacon(pfcRum.endpoint, new Blob([body], { type: 'application/json' }));
            if (queued) { sent = true; return; }
        } catch (error) {}
        fetch(pfcRum.endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'omit' })
            .then(function (response) {
                if (response.ok) { sent = true; return; }
                // The token was rejected (expired, or already consumed). Retry once
                // with a fresh token instead of dropping the visit silently.
                retry();
            })
            .catch(function () { retry(); });
    }

    function retry() {
        if (sent || attempts >= MAX_ATTEMPTS) { return; }
        attempts++;
        token = null;
        getToken();
    }

    getToken();
    // Belt-and-braces refresh: visibilitychange handles the resumed-tab case, this
    // covers a tab that stays visible for a long session.
    setInterval(function () {
        if (sent || document.visibilityState !== 'visible') { return; }
        if (!token || Number(token.expires) * 1000 - Date.now() < 120000) { token = null; getToken(); }
    }, 4 * 60 * 1000);

    // Batch after the vendor's synchronous hidden-event handlers flush observers.
    addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { Promise.resolve().then(send); }
        else if (!sent && (!token || Number(token.expires) * 1000 <= Date.now())) { token = null; getToken(); }
    });
    addEventListener('pagehide', function () { Promise.resolve().then(send); });
    addEventListener('pageshow', function (event) {
        if (!event.persisted) { return; }
        generation++;
        metrics = {}; token = null; sent = false; pending = false; wantsSend = false; attempts = 0;
        attribution = {}; longTasks = { count: 0, max: 0, total: 0 };
        if (refreshTimer) { clearTimeout(refreshTimer); refreshTimer = null; }
        getToken();
    });
}());
