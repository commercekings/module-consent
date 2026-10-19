const test = require('node:test');
const assert = require('node:assert/strict');
const {browser, load} = require('./harness.cjs');

/**
 * The adapter loader (consent/adapter.js) fails closed: whatever goes wrong with the source's configuration or its
 * adapter, the page gets the null adapter - no choice, no consent for any purpose - and nothing breaks.
 */
function loader(text) {
    const b = browser();
    b.document.readyState = 'complete';
    const node = text === null ? null : {textContent: text};
    b.document.getElementById = id => (id === 'commercekings-consent-config' ? node : null);
    const plugin = load(b, 'module-consent/view/frontend/web/js/consent/adapter.js');
    const required = [];
    return {
        b,
        /** @param fails the source adapter's RequireJS load fails (a 404, a script error) */
        resolve(fails = false, adapter = {}) {
            let resolved;
            plugin.load('', (names, done, failed) => {
                required.push(...names);
                fails ? failed(new Error('Script error for "' + names[0] + '"')) : done(adapter);
            }, value => { resolved = value; }, {});
            return resolved;
        },
        required
    };
}

function assertGrantsNothing(adapter) {
    assert.equal(adapter.hasChoice(), false);
    for (const purpose of ['analytics', 'cart_reminder', 'marketing', '']) {
        assert.equal(adapter.hasConsent(purpose), false, purpose);
    }
    let called = false;
    adapter.onChange('analytics', () => { called = true; });
    adapter.accept('analytics');
    adapter.revoke('analytics');
    assert.equal(called, false, 'accept/revoke do nothing and announce nothing');
    assert.equal(adapter.hasConsent('analytics'), false, 'not even after accept()');
}

test('broken configuration JSON gives the null adapter without loading any source', () => {
    for (const text of ['{"adapter": "Source/js/adapter"', 'not json', '', '<!-- -->']) {
        const l = loader(text);
        const adapter = l.resolve();
        assert.deepEqual(l.required, [], JSON.stringify(text));
        assertGrantsNothing(adapter);
    }
});

test('no configuration node, or one without an adapter, gives the null adapter', () => {
    for (const text of [null, '{}', '{"adapter": ""}', '{"adapter": null}', 'null', 'false', '0', '""']) {
        const l = loader(text);
        const adapter = l.resolve();
        assert.deepEqual(l.required, [], String(text));
        assertGrantsNothing(adapter);
    }
});

test('a source adapter that fails to load gives the null adapter', () => {
    const l = loader('{"adapter": "Source/js/adapter"}');
    const adapter = l.resolve(true);
    assert.deepEqual(l.required, ['Source/js/adapter'], 'the configured adapter was requested');
    assertGrantsNothing(adapter);
});

test('the configured adapter is used as it is when it loads', () => {
    const source = {hasChoice: () => true, hasConsent: () => true};
    assert.equal(loader('{"adapter": "Source/js/adapter"}').resolve(false, source), source);
});

test('a bundling build resolves to nothing instead of reading the page', () => {
    const b = browser();
    b.document.getElementById = () => { throw new Error('no page during a build'); };
    const plugin = load(b, 'module-consent/view/frontend/web/js/consent/adapter.js');
    let resolved = 'unset';
    plugin.load('', () => assert.fail('nothing is required during a build'), value => { resolved = value; },
        {isBuild: true});
    assert.equal(resolved, null);
});

for (const [name, text] of [['a configuration without an adapter', '{"purposes": ["analytics", "cart_reminder"]}'],
    ['a configuration of "null"', 'null'], ['broken configuration JSON', '{"purposes": [']]) {
    test('the provider over the null adapter grants no purpose and sends nothing: ' + name, () => {
        providerOverNullAdapter(text);
    });
}

function providerOverNullAdapter(text) {
    const b = browser();
    b.document.readyState = 'complete';
    b.document.getElementById = id => id === 'commercekings-consent-config' ? {textContent: text} : null;
    const plugin = load(b, 'module-consent/view/frontend/web/js/consent/adapter.js');
    let adapter;
    plugin.load('', (names, done, failed) => failed(new Error('404')), value => { adapter = value; }, {});
    const requests = [];
    const jquery = arg => { if (typeof arg === 'function') arg(); };
    jquery.mage = {cookies: {get: () => 'form-key', defaults: {path: '/', domain: ''}}};
    jquery.ajax = request => {
        requests.push(request);
        return {done() { return this; }, always() { return this; }};
    };
    b.context.BASE_URL = 'https://shop.example.test/';
    b.context.FormData = FormData;
    b.context.navigator.sendBeacon = () => { requests.push('beacon'); return true; };
    const consent = load(b, 'module-consent/view/frontend/web/js/consent/provider.js', {
        jquery, 'CommerceKings_Consent/js/consent/adapter!': adapter, 'mage/cookies': {}
    });
    const changes = [];
    consent.onChange('cart_reminder', isOn => changes.push(isOn));
    consent.accept('cart_reminder');
    (b.events.pagehide || []).forEach(fn => fn());
    assert.equal(consent.hasConsent('analytics'), false);
    assert.equal(consent.hasConsent('cart_reminder'), false);
    assert.deepEqual(changes, []);
    assert.deepEqual(requests, [], 'no grant, withdrawal or beacon');
}
