/**
 * Copyright GenMagic. All rights reserved.
 * See LICENSE for license details.
 */
define([
    'jquery',
    'uiComponent',
    'mage/translate'
], function ($, Component, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'GenMagic_ProductPhotos/photos',
            style: 'studio',
            extra: '',
            model: '',
            models: [],
            busy: false,
            message: '',
            failed: false,
            showPricing: false,
            preview: null,
            useAsMain: false,
            added: false,
            photoAlt: $t('The new product photo')
        },

        /**
         * @returns {Object}
         */
        initObservable: function () {
            this._super().observe([
                'style', 'extra', 'model', 'models', 'busy', 'message', 'failed', 'showPricing', 'preview',
                'useAsMain', 'added'
            ]);

            return this;
        },

        /**
         * Loads the model list when the section opens for a product with a key.
         *
         * @returns {Object}
         */
        initialize: function () {
            this._super();

            if (this.productId && this.hasKey) {
                this.loadModels();
            }

            return this;
        },

        /**
         * @returns {String}
         */
        makeLabel: function () {
            return this.busy() ? $t('Working...') : $t('Make photo');
        },

        /**
         * @returns {Boolean}
         */
        canMake: function () {
            return this.productId > 0 && this.hasKey && !this.busy();
        },

        /**
         * Sends one admin request with the form key.
         *
         * @param {String} url
         * @param {Object} data
         * @returns {jQuery.Deferred}
         */
        post: function (url, data) {
            return $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                data: $.extend({
                    'form_key': window.FORM_KEY,
                    'product_id': this.productId
                }, data)
            });
        },

        /**
         * Shows a result message or an error.
         *
         * @param {Object} response
         */
        fail: function (response) {
            this.failed(true);
            this.message(response && response.message ? response.message : $t('Something went wrong. Try again.'));
            this.showPricing(Boolean(response && response.pricing_url));
        },

        /**
         * Fills the model picker.
         */
        loadModels: function () {
            var self = this;

            this.post(this.modelsUrl, {}).done(function (response) {
                if (response.success) {
                    self.models(response.models);
                    self.model(response['default']);
                }
            });
        },

        /**
         * Label of one model in the picker, with its estimated price when known.
         *
         * @param {Object} item
         * @returns {String}
         */
        modelLabel: function (item) {
            return item.est_usd === null || item.est_usd === undefined ?
                item.name :
                item.name + ' (' + $t('about $%1').replace('%1', Number(item.est_usd).toFixed(2)) + ')';
        },

        /**
         * Makes a new photo.
         */
        make: function () {
            var self = this;

            if (!this.canMake()) {
                return;
            }
            this.busy(true);
            this.failed(false);
            this.showPricing(false);
            this.added(false);
            this.preview(null);
            this.message($t('Making your photo. This can take up to a minute.'));

            this.post(this.generateUrl, {
                style: this.style(),
                extra: this.extra(),
                model: this.model()
            }).done(function (response) {
                if (!response.success) {
                    self.fail(response);

                    return;
                }
                self.preview(response);
                self.message(self.costLine(response));
            }).fail(function () {
                self.fail(null);
            }).always(function () {
                self.busy(false);
            });
        },

        /**
         * What the photo cost and what is left.
         *
         * @param {Object} response
         * @returns {String}
         */
        costLine: function (response) {
            var line = $t('Your photo is ready.');

            if (response.cost_usd) {
                line += ' ' + $t('Cost: $%1.').replace('%1', response.cost_usd);
            }
            if (response.balance_usd) {
                line += ' ' + $t('Balance left: $%1.').replace('%1', response.balance_usd);
            }

            return line;
        },

        /**
         * Adds the shown photo to the product media gallery.
         */
        addToGallery: function () {
            var self = this,
                current = this.preview();

            if (!current || this.busy()) {
                return;
            }
            this.busy(true);
            this.failed(false);

            this.post(this.addUrl, {
                token: current.token,
                'use_as_main': this.useAsMain() ? 1 : 0
            }).done(function (response) {
                if (!response.success) {
                    self.fail(response);

                    return;
                }
                self.added(true);
                self.preview(null);
                self.message(response.message);
            }).fail(function () {
                self.fail(null);
            }).always(function () {
                self.busy(false);
            });
        },

        /**
         * Drops the shown photo without adding it.
         */
        discard: function () {
            this.preview(null);
            this.message('');
        }
    });
});
