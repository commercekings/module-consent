const test = require('node:test');
const assert = require('node:assert/strict');
const {browser, load} = require('./harness.cjs');

test('withdrawal confirmation revokes only the configured purpose, even before provider acknowledgement', () => {
    const revoked = [];
    const sync = load(browser(), 'module-consent/view/frontend/web/js/withdrawal-banner.js', {
        'CommerceKings_Consent/js/consent/provider': {revoke: purpose => revoked.push(purpose)}
    });
    sync({purpose: 'another_feature'});
    sync({}); sync(null); sync({purpose: ''});
    assert.deepEqual(revoked, ['another_feature']);
});
