<?php
/**
 * @package riskcube
 */
/**
 * Plugin Name: RiskCUBE von Creditreform Schweiz
 * Plugin URI:
 * Version: 2.4.12.6
 * Description: RiskCube
 * Author: NXTLVL Development GMBH
 * Author URI: https://nxtlvl.ch
 * Text Domain: riskcube
 * Requires PHP: 7.4
 * Requires at least: 5.3
 */

use Cube\API\CheckInvoicesEndpoint;
use Cube\API\NewCheckoutEndpoint;
use Cube\Core\Admin;
use Cube\Core\CheckInvoicesPage;
use Cube\Core\RiskCube;

if (!defined('WPINC')) {
    die();
}

define('RISKCUBE__PLUGIN_URL', plugin_dir_url(__FILE__));
define('RISKCUBE__PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RISKCUBE__DOMAIN', 'riskcube-von-creditreform-schweiz');


require 'vendor/autoload.php';

add_action('init', [RiskCube::class, 'init']);
add_action('init', [CheckInvoicesEndpoint::class, 'init']);
add_action('init', [NewCheckoutEndpoint::class, 'init']);

if (is_admin()) {
    add_action('init', [Admin::class, 'init']);
    add_action('init', [CheckInvoicesPage::class, 'init']);
}

if (!file_exists(WP_CONTENT_DIR . '/uploads/riskcube/')) {
    mkdir(WP_CONTENT_DIR . '/uploads/riskcube/', 0750);
}
if (!file_exists(WP_CONTENT_DIR . '/uploads/riskcube/reconciliation/')) {
    mkdir(WP_CONTENT_DIR . '/uploads/riskcube/reconciliation/', 0750);
}

// Adding this to Admin::init did not work
if (is_admin()) {
    add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'add_action_links');
    function add_action_links($actions)
    {
        $mylinks = array(
            '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=settings_tab_riskcube' ) . '">Settings</a>',
        );

        return array_merge( $actions, $mylinks );
    }
}