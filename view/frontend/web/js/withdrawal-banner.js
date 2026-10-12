/** Synchronize only the withdrawn purpose in its original browser and source choice. */
define(['CommerceKings_Consent/js/consent/provider'], function (consent) {
    'use strict';

    return function (config) {
        if (config && typeof config.purpose === 'string' && config.purpose !== '') {
            consent.revoke(config.purpose);
        }
    };
});
