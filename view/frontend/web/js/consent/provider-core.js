/**
 * Shared purpose API. Grants are serialized and acknowledged before features are notified.
 * Withdrawal hints survive failed requests and navigation. Entry points supply only cookies,
 * DOM readiness and the POST and GET transport; source and permission behavior is shared across themes.
 */
(function (root, factory) {
    'use strict';

    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.CommerceKingsConsent = root.CommerceKingsConsent || {};
        root.CommerceKingsConsent.createProvider = factory();
    }
}(window, function () {
    'use strict';

    return function (adapter, transport) {
        var SEEN = 'ck_consent_seen',
            listeners = Object.create(null),
            versions = Object.create(null),
            acknowledged = Object.create(null),
            queue = [],
            busy = false,
            changes = 0,
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
                defaults = transport.cookies.defaults,
                path = config().cookiePath || defaults.path,
                domain = config().cookieDomain || defaults.domain,
                days = Number(config().cookieLifetimeDays),
                lifetime = Number.isFinite(days) && days > 0 ? '; max-age=' + days * 86400 : '';
            if (pending) {
                Object.defineProperty(seen, purpose, {value: 1, enumerable: true, configurable: true});
            } else {
                delete seen[purpose];
            }
            document.cookie = SEEN + '=' + encodeURIComponent(JSON.stringify(seen))
                + (path ? '; path=' + path : '') + (domain ? '; domain=' + domain : '')
                + '; SameSite=Lax' + lifetime + secure;
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
            var action;
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
            transport.post(
                window.BASE_URL + 'ckconsent/' + (action.isOn ? 'grant' : 'withdraw'),
                {form_key: transport.cookies.get('form_key'), purpose: action.purpose},
                function (response) {
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
                },
                function () {
                    busy = false;
                    drain();
                }
            );
        }

        function sync(purpose, isOn) {
            changes++;
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

        /** Purposes of this browser's current choice withdrawn elsewhere (the email link opened on another device). */
        function applyWithdrawn(data) {
            var withdrawn = data && data.withdrawn;
            if (!Array.isArray(withdrawn) || !adapter.hasChoice()) {
                return;
            }
            withdrawn.forEach(function (purpose) {
                if (typeof purpose === 'string' && adapter.hasConsent(purpose)) {
                    adapter.revoke(purpose);
                }
            });
        }

        /**
         * The visitor opened the preferences: ask whether a "yes" shown there was withdrawn elsewhere. Only with
         * something accepted; an answer for a choice changed meanwhile is ignored.
         */
        function checkWithdrawn() {
            var asked = changes;
            if (typeof transport.get !== 'function' || !adapter.hasChoice()
                || !(config().purposes || []).some(function (purpose) { return adapter.hasConsent(purpose); })
            ) {
                return;
            }
            transport.get(window.BASE_URL + 'ckconsent/status', function (response) {
                if (asked === changes) {
                    applyWithdrawn(response);
                }
            });
        }

        (config().purposes || []).forEach(watch);
        // Sources announce their preferences opening (e.g. Cookie settings) - one small request per opening.
        window.addEventListener('ck-consent:preferences', checkWithdrawn);
        transport.ready(catchUp);
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
                    data.append('form_key', transport.cookies.get('form_key'));
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
    };
}));
