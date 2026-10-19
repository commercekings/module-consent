/**
 * RequireJS loader plugin: "CommerceKings_Consent/js/consent/adapter!" resolves to the JS adapter of the
 * source chosen in admin (#commercekings-consent-config "adapter"). Only that adapter is loaded. No adapter or a
 * load error -> a null adapter: never consent, nothing breaks.
 *
 * Every adapter returns {hasChoice(), hasConsent(purpose), onChange(purpose, callback(isOn)), accept(purpose), revoke(purpose)}:
 *  - hasChoice: the visitor has a saved choice valid for the current configuration (e.g. the banner revision);
 *  - accept/revoke save the mapped choice through the source, preserving other choices;
 *  - onChange callbacks run only when THAT purpose was saved on / off.
 */
define([], function () {
    'use strict';

    var nullAdapter = {
        hasChoice: function () {
            return false;
        },
        hasConsent: function () {
            return false;
        },
        onChange: function () {},
        accept: function () {},
        revoke: function () {}
    };

    return {
        load: function (name, req, onload, config) {
            var element, settings = {};
            if (config && config.isBuild) {
                onload(null);
                return;
            }
            function resolve() {
                element = document.getElementById('commercekings-consent-config');
                try {
                    // valid JSON that is no object ("null") is no configuration either
                    settings = JSON.parse(element ? element.textContent : '{}') || {};
                } catch (e) {
                    settings = {};
                }
                if (!settings.adapter) {
                    onload(nullAdapter);
                    return;
                }
                req([settings.adapter], onload, function () {
                    onload(nullAdapter);
                });
            }
            // RequireJS deps can execute before head.additional has been parsed.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', resolve, {once: true});
            } else {
                resolve();
            }
        }
    };
});
