/**
 * Hyvä entry point of the purpose API: the same provider as the AMD module, without RequireJS. The selected source
 * registers its adapter factory in window.CommerceKingsConsent.adapters under its "adapter" code; without one there is
 * no provider and no consent. Sources start their banner on "ck-consent:source"; features use "ck-consent:ready"
 * (event.detail.provider) or window.CommerceKingsConsent.provider.
 */
(function () {
    'use strict';

    var core = window.CommerceKingsConsent = window.CommerceKingsConsent || {};
    core.adapters = core.adapters || {};

    function readConfig(id) {
        var node = document.getElementById(id);
        try {
            return node ? JSON.parse(node.textContent) : null;
        } catch (error) {
            return null;
        }
    }

    function post(url, data, done, always) {
        var controller = new AbortController(),
            timer = window.setTimeout(function () { controller.abort(); }, 10000);

        window.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest'},
            body: new URLSearchParams(data).toString(),
            signal: controller.signal
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Consent request failed.');
            }
            return response.json();
        }).then(done).catch(function () {
            // The shared provider keeps pending withdrawals and refuses unacknowledged grants.
        }).finally(function () {
            window.clearTimeout(timer);
            always();
        });
    }

    function get(url, done) {
        var controller = new AbortController(),
            timer = window.setTimeout(function () { controller.abort(); }, 10000);

        window.fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            signal: controller.signal
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Consent request failed.');
            }
            return response.json();
        }).then(done).catch(function () {
            // Only the banner display waits for this; the server ignores a withdrawn choice either way.
        }).finally(function () {
            window.clearTimeout(timer);
        });
    }

    function start() {
        var source = readConfig('commercekings-consent-config'),
            factory = source && core.adapters[source.adapter],
            adapter, provider, withdrawal, action;

        if (!source || typeof factory !== 'function' || !window.hyva || core.hyvaStarted
            || typeof core.createProvider !== 'function' || typeof window.hyva.getCookie !== 'function'
            || typeof window.hyva.getFormKey !== 'function'
        ) {
            return;
        }
        adapter = factory();
        if (!adapter) {
            return;
        }
        core.hyvaStarted = true;
        provider = core.createProvider(adapter, {
            cookies: {
                get: function (name) {
                    return name === 'form_key' ? window.hyva.getFormKey() : window.hyva.getCookie(name);
                },
                defaults: {path: source.cookiePath || '/', domain: source.cookieDomain || ''}
            },
            ready: function (callback) { callback(); },
            post: post,
            get: get
        });
        core.provider = provider;
        // The source starts its banner now, after the provider listens.
        window.dispatchEvent(new CustomEvent('ck-consent:source', {detail: {provider: provider, source: source}}));

        // This JSON is emitted only for the server-confirmed withdrawal of this browser's current choice.
        withdrawal = readConfig('commercekings-consent-withdrawal');
        action = withdrawal && withdrawal['*'] && withdrawal['*']['CommerceKings_Consent/js/withdrawal-banner'];
        if (action && typeof action.purpose === 'string' && action.purpose !== '') {
            provider.revoke(action.purpose);
        }
        window.dispatchEvent(new CustomEvent('ck-consent:ready', {detail: {provider: provider}}));
    }

    // Deferred scripts run before DOMContentLoaded: every source has registered its adapter by then.
    if (document.readyState === 'complete') {
        start();
    } else {
        document.addEventListener('DOMContentLoaded', start, {once: true});
    }
}());
