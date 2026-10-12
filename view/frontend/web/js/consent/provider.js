/**
 * Purpose API shared by features. Banner startup waits for this module's listeners.
 * Serialize server actions so initial grants share one browser identity. Notify grants only
 * after acknowledgement and while that saved choice is still current. Withdrawal hints stay
 * in the browser until acknowledged, allowing retries after failed requests or navigation.
 * A purpose the shop does not record (config "recorded") never asks the server: the source's
 * choice alone decides and features hear it at once. Only a withdrawal still pending from the time it
 * was recorded is sent, so that old record is withdrawn too.
 */
define(['jquery', 'CommerceKings_Consent/js/consent/adapter!', 'mage/cookies'], function ($, adapter) {
    'use strict';

    var SEEN = 'ck_consent_seen',
        listeners = Object.create(null),
        versions = Object.create(null),
        acknowledged = Object.create(null),
        queue = [],
        busy = false,
        settings = null;

    function config() {
        var element;
        if (settings === null) {
            element = document.getElementById('commercekings-consent-config');
            try {
                settings = JSON.parse(element ? element.textContent : '{}');
            } catch (e) {
                settings = {};
            }
        }
        return settings;
    }

    function readSeen() {
        var match = document.cookie.match(new RegExp('(?:^|; )' + SEEN + '=([^;]*)')),
            value;
        try {
            value = match ? JSON.parse(decodeURIComponent(match[1])) : {};
            return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
        } catch (e) {
            return {};
        }
    }

    function remember(purpose, pending) {
        var seen = readSeen(),
            secure = location.protocol === 'https:' ? '; Secure' : '',
            days = Math.max(1, Number(config().cookieLifetimeDays) || 182);
        if (pending) {
            Object.defineProperty(seen, purpose, {value: 1, enumerable: true, configurable: true});
        } else {
            delete seen[purpose];
        }
        document.cookie = SEEN + '=' + encodeURIComponent(JSON.stringify(seen))
            + '; path=/; SameSite=Lax; max-age=' + days * 86400 + secure;
    }

    /** Missing list (page cached before the setting existed): everything is recorded. */
    function isRecorded(purpose) {
        var recorded = config().recorded;
        return !Array.isArray(recorded) || recorded.indexOf(purpose) !== -1;
    }

    function notify(purpose, isOn) {
        (listeners[purpose] || []).forEach(function (callback) { callback(isOn); });
    }

    function drain() {
        var action, request;
        if (busy || !queue.length) {
            return;
        }
        action = queue.shift();
        if (action.version !== versions[action.purpose] || !adapter.hasChoice()
            || adapter.hasConsent(action.purpose) !== action.isOn) {
            drain();
            return;
        }
        busy = true;
        request = $.ajax({
            url: window.BASE_URL + 'ckconsent/' + (action.isOn ? 'grant' : 'withdraw'),
            type: 'POST',
            dataType: 'json',
            timeout: 10000,
            data: {form_key: $.mage.cookies.get('form_key'), purpose: action.purpose},
            global: false
        });
        request.done(function (response) {
            if (action.version !== versions[action.purpose] || !adapter.hasChoice()
                || adapter.hasConsent(action.purpose) !== action.isOn) {
                return;
            }
            if (action.isOn) {
                if (response && response.granted === true) {
                    acknowledged[action.purpose] = true;
                    notify(action.purpose, true);
                }
            } else if (response && typeof response.withdrawn === 'number') {
                remember(action.purpose, false);
            }
        });
        request.always(function () {
            busy = false;
            drain();
        });
    }

    function sync(purpose, isOn) {
        versions[purpose] = (versions[purpose] || 0) + 1;
        acknowledged[purpose] = false;
        // Also remember OFF until the server confirms it; a beacon has no acknowledgement.
        remember(purpose, true);
        if (!isOn) {
            notify(purpose, false);
        }
        queue.push({purpose: purpose, isOn: isOn, version: versions[purpose]});
        drain();
    }

    function watch(purpose) {
        if (listeners[purpose]) {
            return;
        }
        listeners[purpose] = [];
        adapter.onChange(purpose, function (isOn) {
            if (isRecorded(purpose) || (!isOn && readSeen()[purpose])) {
                sync(purpose, isOn);
            } else {
                notify(purpose, isOn);
            }
        });
    }

    function catchUp() {
        var seen = readSeen();
        (config().purposes || []).forEach(function (purpose) {
            if (adapter.hasConsent(purpose)) {
                if (!seen[purpose] && isRecorded(purpose)) {
                    remember(purpose, true);
                }
            } else if (seen[purpose] && adapter.hasChoice()) {
                sync(purpose, false);
            }
        });
    }

    (config().purposes || []).forEach(watch);
    $(catchUp);
    window.addEventListener('online', catchUp);
    window.addEventListener('pagehide', function () {
        var seen = readSeen();
        if (!navigator.sendBeacon || !adapter.hasChoice()) {
            return;
        }
        (config().purposes || []).forEach(function (purpose) {
            var data;
            if (seen[purpose] && !adapter.hasConsent(purpose)) {
                data = new FormData();
                data.append('form_key', $.mage.cookies.get('form_key'));
                data.append('purpose', purpose);
                navigator.sendBeacon(window.BASE_URL + 'ckconsent/withdraw', data);
            }
        });
    });

    return {
        hasConsent: function (purpose) {
            return adapter.hasConsent(purpose) && (!isRecorded(purpose) || acknowledged[purpose] !== false);
        },
        onChange: function (purpose, callback) {
            watch(purpose);
            listeners[purpose].push(callback);
        },
        accept: function (purpose) {
            if (typeof adapter.accept === 'function') {
                adapter.accept(purpose);
            }
        },
        revoke: function (purpose) { adapter.revoke(purpose); }
    };
});
