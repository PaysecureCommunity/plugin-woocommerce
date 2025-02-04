<?php
/**
 * Plugin Name: PaySecure Payments for WooCommerce
 * Plugin URI: https://paysecure.net/woocommerce/paysecure/
 * Description: PaySecure Payment Gateway for WooCommerce
 * Version: 1.2.5
 * Author: PaySecure Technology Limited
 * Author URI: https://paysecure.net
 * Developer: Suresh Shinde
 * Developer URI: https://www.weverve.com
 *
 * WC requires at least: 3.3.4
 * WC tested up to: 6.3.1
 *
 * Copyright: © 2023 PaySecure Technology Limited
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

add_action('plugins_loaded', 'woocommerce_paysecure_payments_gateway_init',0);
function woocommerce_paysecure_payments_gateway_init(): void
{
    if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
    /**
     * Localisation
     */
    load_plugin_textdomain('woocommerce-paysecure-payments', false, dirname( plugin_basename( __FILE__ ) ) . 'i18n' . DIRECTORY_SEPARATOR . 'languages');

    /**
     * Gateway class
     */

    require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'WC_PaySecure_Payments_Gateway.php';

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_paysecure_payments_gateway($methods)
    {
        $methods[] = WC_PaySecure_Payments_Gateway::class;
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_paysecure_payments_gateway');

    function woocommerce_add_paysecure_payments_setting_link($links)
    {
        $url = get_admin_url()
            . '/admin.php?page=wc-settings&tab=checkout&section=paysecure';
        $settings_link = '<a href="' . $url . '">' . __('Settings', 'woocommerce-paysecure-payments')
            . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    add_filter(
        'plugin_action_links_' . plugin_basename(__FILE__),
        'woocommerce_add_paysecure_payments_setting_link'
    );

    function add_paysecure_payments_styles(): void
    {
        wp_enqueue_style( 'paysecure_payments_styles', plugins_url('assets/css/paysecure.css',__FILE__) );
    }

    add_action( 'wp_enqueue_scripts', 'add_paysecure_payments_styles' );

    function paysecure_payments_cron_run() {

    }

    add_action( 'paysecure_payments_cron', 'paysecure_payments_cron_run' );
}
