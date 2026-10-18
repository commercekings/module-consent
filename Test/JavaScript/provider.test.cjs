const test = require('node:test');
const assert = require('node:assert/strict');
const {browser, load, pendingRequest} = require('./harness.cjs');

function fixture(b = browser({'commercekings-consent-config': {
    purposes: ['analytics', 'cart_reminder'], cookieLifetimeDays: 30
}}), initial = {}) {
    const requests = [], beacons = [], callbacks = [], checks = [];
    const states = {analytics: false, cart_reminder: false, ...initial}, listeners = {};
    let choice = Object.keys(initial).length > 0;
    const adapter = {
        hasChoice: () => choice,
        hasConsent: purpose => states[purpose] === true,
        onChange: (purpose, fn) => { listeners[purpose] = fn; },
        revoke(purpose) { this.change(purpose, false); },
        change(purpose, value) { choice = true; states[purpose] = value; listeners[purpose](value); }
    };
    const jquery = arg => { if (typeof arg === 'function') arg(); };
    jquery.mage = {cookies: {get: () => 'form-key', defaults: {path: '/default-shop/', domain: '.default.test'}}};
    jquery.ajax = request => {
        if (request.type === 'GET') {
            let done;
            checks.push({request, answer: body => done(body)});
            return {done: fn => { done = fn; }};
        }
        const pending = pendingRequest(request);
        requests.push(pending);
        return pending.promise;
    };
    b.context.BASE_URL = 'https://shop.example.test/';
    b.context.FormData = FormData;
    b.context.navigator.sendBeacon = (url, data) => { beacons.push({url, purpose: data.get('purpose')}); return true; };
    const consent = load(b, 'module-consent/view/frontend/web/js/consent/provider.js', {
        jquery, 'CommerceKings_Consent/js/consent/adapter!': adapter, 'mage/cookies': {}
    });
    consent.onChange('analytics', value => callbacks.push(value));
    const openPreferences = () => (b.events['ck-consent:preferences'] || []).forEach(fn => fn());
    return {b, adapter, consent, requests, callbacks, beacons, states, checks, openPreferences};
}

test('loader waits for the configuration node when RequireJS starts during HTML parsing', () => {
    const configs = {};
    const b = browser(configs);
    const plugin = load(b, 'module-consent/view/frontend/web/js/consent/adapter.js');
    const adapter = {};
    let resolved;
    plugin.load('', (names, done) => { assert.equal(names[0], 'Source/js/adapter'); done(adapter); }, value => { resolved = value; }, {});
    assert.equal(resolved, undefined);
    configs['commercekings-consent-config'] = {adapter: 'Source/js/adapter'};
    b.document.readyState = 'interactive';
    b.events.DOMContentLoaded.forEach(callback => callback());
    assert.equal(resolved, adapter);
});

test('initial purpose grants serialize, so subsequent requests can use the server-issued identity', () => {
    const f = fixture();
    f.adapter.change('analytics', true);
    f.adapter.change('cart_reminder', true);
    assert.equal(f.requests.length, 1);
    assert.equal(f.consent.hasConsent('cart_reminder'), false, 'features cannot capture data while the initial identity request is pending');
    f.requests[0].complete();
    assert.equal(f.requests.length, 2);
    assert.equal(f.requests[1].request.data.purpose, 'cart_reminder');
    assert.deepEqual(f.callbacks, [true]);
    f.requests[1].complete();
    assert.equal(f.consent.hasConsent('cart_reminder'), true);
});

test('rejection stops every feature immediately and waits behind the pending grant for withdrawal', () => {
    const f = fixture();
    f.adapter.change('analytics', true);
    f.adapter.change('analytics', false);
    assert.deepEqual(f.callbacks, [false]);
    assert.equal(f.requests.length, 1);
    f.requests[0].complete();
    assert.deepEqual(f.callbacks, [false], 'stale affirmative response cannot reach any feature');
    assert.equal(f.requests[1].request.url, 'https://shop.example.test/ckconsent/withdraw');
    f.requests[1].complete();
    assert.equal(JSON.parse(decodeURIComponent(f.b.cookies.get('ck_consent_seen'))).analytics, undefined);
});

test('failed withdrawal keeps its hint, retries online and sends a navigation beacon', () => {
    const f = fixture();
    f.adapter.change('analytics', false);
    f.requests[0].fail();
    assert.equal(JSON.parse(decodeURIComponent(f.b.cookies.get('ck_consent_seen'))).analytics, 1);
    f.b.events.pagehide.forEach(fn => fn());
    assert.equal(f.beacons[0].purpose, 'analytics');
    f.b.events.online.forEach(fn => fn());
    assert.equal(f.requests.length, 2);
    f.requests[1].complete();
    assert.equal(JSON.parse(decodeURIComponent(f.b.cookies.get('ck_consent_seen'))).analytics, undefined);
});

test('a refused or failed grant does not notify features and seen lifetime follows configuration', () => {
    const f = fixture();
    f.adapter.change('analytics', true);
    f.requests[0].complete({granted: false});
    assert.equal(f.consent.hasConsent('analytics'), false);
    assert.deepEqual(f.callbacks, []);
    f.adapter.change('analytics', true);
    f.requests[1].fail();
    assert.equal(f.consent.hasConsent('analytics'), false);
    assert.deepEqual(f.callbacks, []);
    assert.match(f.b.writes.at(-1), /max-age=2592000; Secure$/);
});

test('rapid changes discard obsolete queued grants and keep the newest explicit choice', () => {
    const f = fixture();
    f.adapter.change('analytics', true);
    f.adapter.change('cart_reminder', true);
    f.adapter.change('cart_reminder', false);
    f.adapter.change('analytics', false);
    f.adapter.change('analytics', true);
    f.requests[0].complete();
    assert.equal(f.requests[1].request.data.purpose, 'cart_reminder');
    assert.ok(f.requests[1].request.url.endsWith('/withdraw'));
    f.requests[1].complete();
    assert.ok(f.requests[2].request.url.endsWith('/grant'));
    f.requests[2].complete();
    assert.deepEqual(f.callbacks, [false, true]);
});

test('withdrawal hints use the configured consent lifetime and Magento cookie scope', () => {
    const f = fixture(browser({'commercekings-consent-config': {
        purposes: ['analytics'], cookieLifetimeDays: 45, cookiePath: '/shop/', cookieDomain: '.example.test'
    }}));
    f.adapter.change('analytics', false);
    assert.match(f.b.writes.at(-1), /; path=\/shop\/; domain=\.example\.test; SameSite=Lax; max-age=3888000; Secure$/);
});

test('older cached configuration inherits Magento cookie defaults without a hardcoded lifetime', () => {
    const f = fixture(browser({'commercekings-consent-config': {purposes: ['analytics']}}));
    f.adapter.change('analytics', false);
    assert.match(f.b.writes.at(-1), /; path=\/default-shop\/; domain=\.default\.test; SameSite=Lax; Secure$/);
    assert.doesNotMatch(f.b.writes.at(-1), /max-age|expires/i);
});

test('invalid hint lifetime creates a session hint and HTTP does not set Secure', () => {
    const b = browser({'commercekings-consent-config': {purposes: ['analytics'], cookieLifetimeDays: 'invalid'}});
    b.context.location.protocol = 'http:';
    const f = fixture(b);
    f.adapter.change('analytics', false);
    assert.doesNotMatch(f.b.writes.at(-1), /max-age|expires|Secure/i);
});

function unrecorded() {
    return fixture(browser({'commercekings-consent-config': {
        purposes: ['analytics', 'cart_reminder'], recorded: ['cart_reminder'], cookieLifetimeDays: 30
    }}));
}

test('an unrecorded purpose follows the source at once and never asks the server', () => {
    const f = unrecorded();
    f.adapter.change('analytics', true);
    assert.equal(f.requests.length, 0);
    assert.deepEqual(f.callbacks, [true]);
    assert.equal(f.consent.hasConsent('analytics'), true);
    f.adapter.change('analytics', false);
    f.adapter.change('analytics', true);
    assert.equal(f.requests.length, 0);
    assert.deepEqual(f.callbacks, [true, false, true]);
    assert.equal(f.consent.hasConsent('analytics'), true, 'switching off and on again needs no acknowledgement');
    assert.equal(f.b.cookies.get('ck_consent_seen'), undefined, 'no hint cookie for an unrecorded purpose');
    f.b.events.pagehide.forEach(fn => fn());
    assert.deepEqual(f.beacons, []);
});

test('a purpose whose recording is required stays recorded next to an unrecorded one', () => {
    const f = unrecorded();
    f.adapter.change('cart_reminder', true);
    assert.equal(f.requests.length, 1);
    assert.ok(f.requests[0].request.url.endsWith('/grant'));
    assert.equal(f.consent.hasConsent('cart_reminder'), false, 'still waits for the server');
});

test('a withdrawal pending from when the purpose was recorded still reaches the server', () => {
    const b = browser({'commercekings-consent-config': {
        purposes: ['analytics', 'cart_reminder'], recorded: ['cart_reminder'], cookieLifetimeDays: 30
    }});
    b.cookies.set('ck_consent_seen', encodeURIComponent(JSON.stringify({analytics: 1})));
    const f = fixture(b);
    f.adapter.change('analytics', false);
    assert.equal(f.requests.length, 1);
    assert.ok(f.requests[0].request.url.endsWith('/withdraw'));
    assert.deepEqual(f.callbacks, [false]);
});

test('opening the preferences asks the server and switches off a yes withdrawn on another device', () => {
    const f = fixture(undefined, {cart_reminder: true, analytics: true});
    f.openPreferences();
    assert.equal(f.checks.length, 1);
    assert.equal(f.checks[0].request.url, 'https://shop.example.test/ckconsent/status');
    assert.equal(f.checks[0].request.cache, false, 'never answered from the browser cache');
    f.checks[0].answer({withdrawn: ['cart_reminder']});
    assert.equal(f.states.cart_reminder, false, 'the withdrawn purpose is switched off');
    assert.equal(f.states.analytics, true, 'other purposes stay');
    assert.equal(f.requests.at(-1).request.url, 'https://shop.example.test/ckconsent/withdraw');
    f.openPreferences();
    assert.equal(f.checks.length, 2, 'every opening asks again');
});

test('nothing is asked on page load, without a choice or with nothing accepted', () => {
    assert.equal(fixture().checks.length, 0, 'page load');
    const none = fixture();
    none.openPreferences();
    assert.equal(none.checks.length, 0, 'no banner choice yet');
    const rejected = fixture(undefined, {cart_reminder: false});
    rejected.openPreferences();
    assert.equal(rejected.checks.length, 0, 'nothing accepted, nothing to switch off');
});

test('an answer for a choice changed meanwhile, or of an unknown shape, changes nothing', () => {
    const f = fixture(undefined, {cart_reminder: true, analytics: true});
    f.openPreferences();
    f.adapter.change('analytics', false);
    f.checks[0].answer({withdrawn: ['cart_reminder']});
    assert.equal(f.states.cart_reminder, true, 'the visitor chose again before the answer arrived');
    f.openPreferences();
    f.checks[1].answer({withdrawn: 'cart_reminder'});
    f.openPreferences();
    f.checks[2].answer(null);
    assert.equal(f.states.cart_reminder, true);
});
