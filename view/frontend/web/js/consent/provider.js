/** Magento transport for the shared purpose API; public AMD module and behavior stay unchanged. */
define([
    'jquery',
    'CommerceKings_Consent/js/consent/adapter!',
    'CommerceKings_Consent/js/consent/provider-core',
    'mage/cookies'
], function ($, adapter, createProvider) {
    'use strict';

    return createProvider(adapter, {
        cookies: $.mage.cookies,
        ready: function (callback) { $(callback); },
        post: function (url, data, done, always) {
            $.ajax({url: url, type: 'POST', dataType: 'json', timeout: 10000, data: data, global: false})
                .done(done).always(always);
        }
    });
});
