/**
 * Jlowcode sites
 *
 * @copyright:   Copyright (C) 2025 Jlowcode Org - All rights reserved.
 * @license  : GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */
define(['jquery', 'fab/fabrik'], function (jQuery, Fabrik) {
	'use strict';

	var FabrikJlowcode_sites = new Class({
		Implements: [Events],
 
        /**
		 * Initialize
         * 
		 * @param {object} options
		 */
        initialize: function (options) {
            var self = this;
            self.options = options;

            jQuery("#itens_do_menu___menu_item").closest('.fabrikinput').css('padding', '0px');

            switch (options.process) {
                case 'website':
                    self.initWebsite(options);
                    break;

                case 'itens':
                    self.initItens(options);
                    break;
            }
        },

        /**
         * Initialize for website form
         * 
         */
        initWebsite: function () {
            var self = this;
            
            // Future configurations for website form
        },

        /**
         * Initialize for itens form
         * 
         */
        initItens: function () {
            var self = this;

            self.setEventsHomePage();
            self.setEventsMenuItensType();
            self.setEventsMenuList();

            self.toggleTypeOptions(jQuery('input[name="itens_do_menu___menu_home_page[]"]:checked').val());
            self.toggleElementsByMenuType(jQuery('#itens_do_menu___menu_type').html());
            self.toggleElementsByMenuType(jQuery('#itens_do_menu___menu_type').val());

            if(self.options.newWebsite) {
                self.configureForNewWebsite();
            }
        },

        /**
         * This method configure the itens form for a new website
         * 
         */
        configureForNewWebsite: function() {
            var self = this;

            // Set homepage as checked
            jQuery('input[name="itens_do_menu___menu_home_page[]"][value="1"]').prop('checked', true);
            jQuery('input[name="itens_do_menu___menu_home_page[]"]').click();

            // Set type as "lista"
            jQuery('#itens_do_menu___menu_type').val('lista').change();
        },

        /**
         * This method set events for homepage radio button
         * 
         */
        setEventsHomePage: function () {
            var self = this;
            var elHomePage = jQuery('input[name="itens_do_menu___menu_home_page[]"]');

            // Event click for homepage radio button
            elHomePage.on('click', function () {
                jQuery('#itens_do_menu___menu_type').val('lista').change();
                var value = jQuery(this).val();
                self.toggleTypeOptions(value);
            });
        },

        /**
         * This method set events for type select. 
         * 
         */
        setEventsMenuItensType: function() {
            var self = this;
            var elType = jQuery('#itens_do_menu___menu_type');

            // Event change for type select
            elType.on('change', function() {
                self.toggleElementsByMenuType(jQuery(this).val());
            });
        },

        setEventsMenuList: function() {
            jQuery('#itens_do_menu___menu_list-auto-complete').on('change', function() {
                var elMenuType = jQuery('#itens_do_menu___menu_type');
                elMenuType.val(elMenuType.val()).trigger('change');
            });
        },

        /**
         * This method show/hide elements based on the selected menu type
         * 
         * @param {string} value
         */
        toggleElementsByMenuType: function(value) {
            var self = this;

            var elList = jQuery('#itens_do_menu___menu_list');
            var elLink = jQuery('#itens_do_menu___menu_link');
            var elItem = jQuery('#itens_do_menu___menu_item');
            var elHomePage = jQuery('#itens_do_menu___menu_home_page');

            switch (value) {
                case 'Lista':
                case 'lista':
                    elList.closest('.fabrikElementContainer').removeClass('fabrikHide');
                    elLink.closest('.fabrikElementContainer').addClass('fabrikHide');
                    elItem.closest('.fabrikElementContainer').addClass('fabrikHide');
                    elHomePage.closest('.fabrikElementContainer').removeClass('fabrikHide');
                    break;

                case 'Formulário adicionar':
                case 'formulario_adicionar':
                    elList.closest('.fabrikElementContainer').removeClass('fabrikHide');
                    elLink.closest('.fabrikElementContainer').addClass('fabrikHide');
                    elItem.closest('.fabrikElementContainer').addClass('fabrikHide');
                    elHomePage.closest('.fabrikElementContainer').addClass('fabrikHide');
                    break;

                case 'Visualização do item':
                case 'visualizacao_do_item':
                    elList.closest('.fabrikElementContainer').removeClass('fabrikHide');
                    elLink.closest('.fabrikElementContainer').addClass('fabrikHide');
                    elItem.closest('.fabrikElementContainer').removeClass('fabrikHide');
                    elHomePage.closest('.fabrikElementContainer').addClass('fabrikHide');
                    break;

                case 'Link externo':
                case 'link_externo':
                    elList.closest('.fabrikElementContainer').addClass('fabrikHide');                    
                    elLink.closest('.fabrikElementContainer').removeClass('fabrikHide');
                    elItem.closest('.fabrikElementContainer').addClass('fabrikHide');
                    elHomePage.closest('.fabrikElementContainer').addClass('fabrikHide');
                    break;
            }
        },

        /**
         * This method disable type options when homepage is selected, unless the type is "lista"
         * 
         * @param {string} show
         */
        toggleTypeOptions: function(show) {
            var elType = jQuery('#itens_do_menu___menu_type');

            if(show === '1') {
                elType.find('option').not('[value="lista"]').hide();
            } else {
                elType.find('option').not('[value="lista"]').show();
            }
        }
    });

	return FabrikJlowcode_sites;
});