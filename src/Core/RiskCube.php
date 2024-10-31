<?php

namespace Cube\Core;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Cube\Helper\ClaimDataHelper;
use Cube\Helper\FileHelper;
use Cube\Helper\Logger;
use Exception;
use WC_Order;
use WC_Session_Handler;

if (!defined('WPINC')) {
    die();
}

class RiskCube
{
    protected static $initiated = false;
    protected static Connector $rca;
    protected static ?bool $isClassicCheckout = null;

    const CHECKOUT_CLASSIC = 1;
    const CHECKOUT_BLOCKS = 0;
    private static $cache_blacklist;
    private static $cache_whitelist;

    public static function init()
    {
        if (!self::$initiated) {
            self::$initiated = true;
            self::$rca = new Connector();

            self::$isClassicCheckout = get_option('wc_riskcube_wc_checkout_version') == self::CHECKOUT_CLASSIC;

            $jsFiles = [];
            $jsFiles[] = 'assets/riskcube.min.js';

            if (!is_admin()) {
                $jsFiles[] = 'assets/riskcube.front.js';

                /**
                 * depending on $newCheckoutActivated, different callbacks are hooked.
                 * If the plugin is configured the wrong way, it will not work
                 */
                // $newCheckoutActivated = get_option('woocommerce_riskcube_misc_wc_checkout_version', self::LEGACY_CHECKOUT) == self::NEW_CHECKOUT;
                //if ($newCheckoutActivated) {
                //    add_action('woocommerce_blocks_payment_method_type_registration', [__CLASS__, 'blocks_payment_method'], 10, 1);
                //    add_action('woocommerce_checkout_create_order', [__CLASS__, 'before_checkout_create_order'], 20, 2);
                //} else {
                //    add_filter('woocommerce_available_payment_gateways', [__CLASS__, 'controlPaymentGateways'], 10, 1);
                //    // add_action('woocommerce_checkout_create_order', [__CLASS__, 'before_checkout_create_order'], 20, 2);
                //}

                if(self::$isClassicCheckout) {
                    add_filter('woocommerce_available_payment_gateways', [__CLASS__, 'controlPaymentGateways'], 10, 1);
                } else {
                    add_action('woocommerce_blocks_payment_method_type_registration', [__CLASS__, 'blocks_payment_method'], 10, 1);
                    add_action('woocommerce_checkout_create_order', [__CLASS__, 'before_checkout_create_order'], 20, 2);
                }

                add_action('wp_head', [__CLASS__, 'new_checkout_header']);
            }

            // Frontend Assets
            foreach ($jsFiles as $jsFile) {
                wp_enqueue_script(basename($jsFile), RISKCUBE__PLUGIN_URL . $jsFile, [], FileHelper::get_file_version($jsFile), true);
            }

            if (get_option('wc_riskcube_invoice_payment_gateway', 0) == 1) {
                add_filter('woocommerce_payment_gateways', [__CLASS__, 'add_custom_gateway_class']);
            }

            add_action('woocommerce_order_status_changed', [__CLASS__, 'on_order_state_change'], 20, 3);
            add_action('woocommerce_new_order', [__CLASS__, 'woocommerce_new_order'], 10, 1);
            add_action('woocommerce_checkout_update_order_review', [__CLASS__, 'woocommerce_checkout_update_order_review']);
            add_action('woocommerce_order_partially_refunded', [__CLASS__, 'refund_partial'], 50, 2);
            add_action('woocommerce_order_fully_refunded', [__CLASS__, 'refund_full'], 50, 2);
            add_action('wp_ajax_wc_riskcube_reprocess', [__CLASS__, 'ajax_reprocess']);
            add_action('wp_ajax_nopriv_wc_riskcube_reprocess', [__CLASS__, 'ajax_reprocess']);
            add_action('wp_ajax_wc_riskcube_refund', [__CLASS__, 'ajax_refund']);
            add_action('wp_ajax_nopriv_wc_riskcube_refund', [__CLASS__, 'ajax_refund']);
            add_filter('woocommerce_payment_complete_order_status', [__CLASS__, 'on_order_complete'], 10, 3);

            $log_status = get_option('wc_riskcube_log_status');
            if ($log_status < 4 && file_exists(RISKCUBE__PLUGIN_DIR . 'log')) {
                // relocate log directory
                rename(RISKCUBE__PLUGIN_DIR . 'log', WP_CONTENT_DIR . '/uploads/riskcube');
                update_option('wc_riskcube_log_status', 4);
            }
        }
    }

    /**
     * Add our payment method data to the footer, so it can be used in the client JavaScript
     * @return void
     */
    public static function new_checkout_header(): void
    {
        $gateways = WC()->payment_gateways->payment_gateways();
        $gateway_id = self::getInvoicePaymentMethod();
        if (!key_exists($gateway_id, $gateways)) {
            return;
        }
        /** @var InvoiceGateway $gateway */
        $gateway = $gateways[$gateway_id];

        $cf = [
            'label' => $gateway->title,
            'id' => $gateway->id,
            'description' => $gateway->method_description,
        ];
        ?>
        <script>
			window.riskCubeCf = <?php echo json_encode($cf); ?>
        </script>
        <?php
    }

    public static function blocks_payment_method(PaymentMethodRegistry $method_registry)
    {
        $method_registry->register(new InvoiceGateway());
    }

    public static function ajax_reprocess()
    {
        check_ajax_referer('riskcube_reprocess', 'nonce');

        $id_order = filter_var($_POST['order'], FILTER_SANITIZE_NUMBER_INT);
        if (!$id_order) {
            return wp_send_json_error(['msg' => 'Id not set']);
        }

        // allow a purchase query to trigger again
        delete_post_meta($id_order, 'rc_confirmed');

        $rca = new Connector();

        $data = ClaimDataHelper::prepareDataOrder(wc_get_order($id_order));

        $res = $rca->doClaim($data, $id_order);

        if (Connector::isInvoiceAuthorization($res)) {
            $rca->doPurchaseConfirmation($id_order); // FK and ZS
            $rca->doInvoiceRedemption($id_order); // only FK
        }

        wp_send_json_success([]);
    }

    public static function ajax_refund()
    {
        check_ajax_referer('riskcube_refund', 'nonce');

        $id_order = filter_var($_POST['order'], FILTER_SANITIZE_NUMBER_INT);
        if (!$id_order) {
            return wp_send_json_error(['msg' => 'Id not set']);
        }
        $post = get_post($id_order);
        $post_id = $post->ID;

        // allow a refund query to trigger again
        delete_post_meta($id_order, 'rc_cancelled');

        $order = wc_get_order($id_order);
        $is_invoiced = get_post_meta($post_id, 'rc_invoiced',true);

        // if (!$order->get_meta('rc_invoiced', true)) { // already invoiced
        if(!$is_invoiced) {
            wp_send_json_error(['msg' => 'Not invoiced']);

            return;
        }

        $rca = new Connector();

        $refunds = $order->get_refunds();

        if (!count($refunds)) {
            wp_send_json_error(['msg' => 'No refund found']);

            return;
        }

        $refund = reset($refunds);
        $rca->doCancellation($id_order, $refund->ID); // only FK

        wp_send_json_success([]);
    }

    public static function on_order_complete($status, $id_order, $order)
    {
        if (RiskCube::getSession()) {
            self::addDataToOrder($order);
            $order->save();
        }

        $is_confirmed = get_post_meta($id_order, 'rc_confirmed', true);
        if(!$is_confirmed) {
            self::$rca->doPurchaseConfirmation($id_order);
        }
        // self::$rca->doInvoiceRedemption($id_order); // only FK

        if (!self::isRiskCubeInvoicePayment($order->get_payment_method())) {
            return $status;
        }
        
        return get_option('wc_riskcube_fk_confirm_state', 'wc-processing');
    }

    public static function woocommerce_checkout_update_order_review($post_data)
    {
        if (!empty($post_data)) {
            parse_str($post_data, $data);
        } else {
            return false;
        }

        WC()->customer->set_props([
            'billing_first_name' => isset($data['billing_first_name']) ? wp_unslash($data['billing_first_name']) : null,
            'billing_last_name' => isset($data['billing_last_name']) ? wp_unslash($data['billing_last_name']) : null,
            'billing_company' => isset($data['billing_company']) ? wp_unslash($data['billing_company']) : null,
            'billing_phone' => isset($data['billing_phone']) ? wp_unslash($data['billing_phone']) : null,
            'billing_email' => isset($data['billing_email']) ? wp_unslash($data['billing_email']) : null,
        ]);

        if (wc_ship_to_billing_address_only()) {
            WC()->customer->set_props([
                'shipping_first_name' => isset($data['billing_first_name']) ? wp_unslash($data['billing_first_name']) : null,
                'shipping_last_name' => isset($data['billing_last_name']) ? wp_unslash($data['billing_last_name']) : null,
                'shipping_company' => isset($data['billing_company']) ? wp_unslash($data['billing_company']) : null,
                'shipping_phone' => isset($data['billing_phone']) ? wp_unslash($data['billing_phone']) : null,
                'shipping_email' => isset($data['billing_email']) ? wp_unslash($data['billing_email']) : null,
            ]);
        } else {
            WC()->customer->set_props([
                'shipping_first_name' => isset($data['shipping_first_name']) ? wp_unslash($data['shipping_first_name']) : null,
                'shipping_last_name' => isset($data['shipping_last_name']) ? wp_unslash($data['shipping_last_name']) : null,
                'shipping_company' => isset($data['shipping_company']) ? wp_unslash($data['shipping_company']) : null,
                'shipping_phone' => isset($data['shipping_phone']) ? wp_unslash($data['billing_phone']) : null,
                'shipping_email' => isset($data['shipping_email']) ? wp_unslash($data['shipping_email']) : null,
            ]);
        }

        // cancel the shipping address that woocommerce tries to set if the checkbox is not marked
        if (array_key_exists('ship_to_different_address', $data) && !$data['ship_to_different_address']) {
            WC()->customer->set_props([
                'shipping_first_name' => '',
                'shipping_last_name' => '',
                'shipping_company' => '',
                'shipping_phone' => '',
                'shipping_email' => '',

                'shipping_country' => '',
                'shipping_state' => '',
                'shipping_postcode' => '',
                'shipping_city' => '',
                'shipping_address_1' => '',
                'shipping_address_2' => '',
            ]);
            WC()->customer->save();

            // 20220304 disabled, was breaking tax/shipping calculation
            //foreach (['s_country', 's_state', 's_postcode', 's_city', 's_address', 's_address_2'] as $field) {
            //unset($_POST[$field]);
            //}
        }
    }

    public static function woocommerce_new_order($id_order)
    {
        static::on_order_state_change($id_order);
    }

    public static function on_order_state_change($id_order)
    {
        $order = wc_get_order($id_order);

        if (get_option('wc_riskcube_fk_confirm_state') === 'wc-' . $order->get_status()) {
            // NXTLVL-182 in wc classic, the hooks are called in the wrong order.
            // with this workaround, the order is checked wether it has been confirmed
            // and will do this if not, before invoicing.
            $is_confirmed = get_post_meta($id_order, 'rc_confirmed', true);
            $user_id = apply_filters( 'determine_current_user', false );
            $isOnWhitelist = ClaimDataHelper::checkCustomerWhitelist($user_id);

            if (!$is_confirmed && self::$isClassicCheckout) {

                // NXTLVL-223
                if (!($user_id && $isOnWhitelist)) {
                    self::$rca->doPurchaseConfirmation($id_order);
                }

            }

            // $is_confirmed = get_post_meta($id_order, 'rc_confirmed', true);
            // if(!$is_confirmed) {
            //    Logger::logDev('on_order_state_change can not invoice, not confirmed');
            // } else {

            // NXTLVL-223
            if (!($user_id && $isOnWhitelist)) {
                self::$rca->doInvoiceRedemption($id_order); // only FK
            }
                // self::$rca->doPurchaseConfirmation($id_order);
            // }
        }

        self::cleanupSession();
    }

    public static function refund_full($id_order, $id_refund)
    {
        self::$rca->doCancellation($id_order, $id_refund); // only FK
    }

    public static function refund_partial($id_order, $id_refund)
    {
        self::$rca->doCancellation($id_order, $id_refund); // only FK
    }

    /**
     * Add riskcube token to order meta if found.
     * @param WC_Order $order
     * @param array $data
     */
    public static function before_checkout_create_order($order, $data)
    {
        self::addDataToOrder($order);
    }

    /**
     * Adds our custom gateway class to the list.
     * @param array $methods
     * @return array modified list
     */
    public static function add_custom_gateway_class($methods)
    {
        $methods[] = InvoiceGateway::class;

        return $methods;
    }

    /**
     * Handles payment method control for both ZS and FK modes.
     * @param array $available_gateways
     * @return array
     * @throws Exception
     */
    public static function controlPaymentGateways($available_gateways)
    {
        if (is_admin()) {
            return $available_gateways;
        }

        //if (is_object(WC()->cart) && !empty(WC()->cart->get_totals()['total'])) {
        //    return $available_gateways;
        //}

        //// Old Version
        //try {
        //    if(!is_object(WC()->cart) || !method_exists(WC()->cart, 'get_customer')) {
        //        throw new Exception('WC()->cart->get_customer is not a method');
        //    }
        //
        //    $customer = WC()->cart->get_customer();
        //    $billing_address = $customer->get_billing();
        //    $shipping_address = $customer->get_shipping();
        //
        //    if(!$shipping_address || $billing_address) {
        //        throw new Exception('Coult not fetch billing or customer address');
        //    }
        //
        //} catch (Exception $e) {
        // New Version

        // NXTLVL-223 - Is on whitelist?
        $user_id = get_current_user_id();
        if($user_id && ClaimDataHelper::checkCustomerWhitelist($user_id)) {
            return $available_gateways;
        }

        $billing_address = [];
        $shipping_address = [];

        if ($user_id > 0) {
            $billing_address = [
                'first_name' => get_user_meta($user_id, 'billing_first_name', true),
                'last_name' => get_user_meta($user_id, 'billing_last_name', true),
                'address_1' => get_user_meta($user_id, 'billing_address_1', true),
                'address_2' => get_user_meta($user_id, 'billing_address_2', true),
                'city' => get_user_meta($user_id, 'billing_city', true),
                'state' => get_user_meta($user_id, 'billing_state', true),
                'postcode' => get_user_meta($user_id, 'billing_postcode', true),
                'country' => get_user_meta($user_id, 'billing_country', true),
            ];

            $shipping_address = [
                'first_name' => get_user_meta($user_id, 'shipping_first_name', true),
                'last_name' => get_user_meta($user_id, 'shipping_last_name', true),
                'address_1' => get_user_meta($user_id, 'shipping_address_1', true),
                'address_2' => get_user_meta($user_id, 'shipping_address_2', true),
                'city' => get_user_meta($user_id, 'shipping_city', true),
                'state' => get_user_meta($user_id, 'shipping_state', true),
                'postcode' => get_user_meta($user_id, 'shipping_postcode', true),
                'country' => get_user_meta($user_id, 'shipping_country', true),
            ];
        }
        // }

        $session = self::getSession();
        $sessionData = [];
        if (is_object($session) && method_exists($session, 'get_session_data')) {
            $sessionData = (array)$session->get_session_data();
        }

        $do_api = true;
        if (array_key_exists('riskcube_auth', $sessionData) && array_key_exists('riskcube_total', $sessionData)) {
            if ($sessionData['riskcube_total'] == WC()->cart->get_totals()['total']) {
                $res = $sessionData['riskcube_auth'];
                $do_api = false;
            }
        }

        // time cache
        if (array_key_exists('riskcube_time', $sessionData) && $sessionData['riskcube_time'] < strtotime('-90 seconds')) {
            $do_api = true;
        }

        // address cache
        if (!array_key_exists('riskcube_ahash', $sessionData) || (Connector::hashArray($billing_address) . Connector::hashArray($shipping_address)) != $sessionData['riskcube_ahash']) {
            $do_api = true;
        }

        if (is_order_received_page() || is_cart() || is_view_order_page() || is_shop()) {
            $do_api = false;
        }

        $res = null;
        $isOnBlacklist = $user_id && ClaimDataHelper::checkCustomerBlacklist($user_id);

        // NXTLVL-233 - cart must be present
        if (!$isOnBlacklist && $do_api && is_object(WC()->cart)) {
            $data = ClaimDataHelper::prepareDataCart(WC()->cart);
            $rca = new Connector();
            $res = $rca->doClaim($data);
        }

        // control invoice payment option and other payment options based on response
        if ($isOnBlacklist || (!Connector::isInvoiceAuthorization($res) && !(!is_array($res) && $res))) {
            if (get_option('wc_riskcube_service_type', 0) == Connector::MODE_ZS) {
                foreach ($available_gateways as $key => $val) {
                    if (get_option('wc_riskcube_active_' . $key, 0)) {
                        unset($available_gateways[$key]);
                    }
                }
            } else {
                $key = self::getInvoicePaymentMethod();
                unset($available_gateways[$key]);
            }
        }

        return $available_gateways;
    }

    public static function isRiskCubeInvoicePayment(string $opt): bool
    {
        return self::getInvoicePaymentMethod() === $opt;
    }

    private static function getInvoicePaymentMethod(): string
    {
        if (get_option('wc_riskcube_invoice_payment_gateway') == 0) {
            $key = get_option('wc_riskcube_invoice_payment_method', 'invoice');
        } else {
            $key = 'riskcube_invoice';
        }

        return $key;
    }

    private static function addDataToOrder(WC_Order $order): void
    {
        $session = self::getSession();
        $sessionData = $session->get_session_data();
        $post = get_post($order->get_id());
        $post_id = $post->ID;

        if (isset($sessionData['rc_orderprocesstoken']) && $sessionData['rc_orderprocesstoken']) {
            $order->update_meta_data('rc_orderprocesstoken', $sessionData['rc_orderprocesstoken']);
            update_post_meta($post_id, 'rc_orderprocesstoken', $sessionData['rc_orderprocesstoken']);
        }

        // keeping remarks for now
        if (isset($sessionData['rc_remarks']) && $sessionData['rc_remarks']) {
            $order->update_meta_data('rc_remarks', $sessionData['rc_remarks']);
            update_post_meta($post_id, 'rc_remarks', $sessionData['rc_remarks']);
        }

        // cleanup
        self::cleanupSession();
    }

    private static function cleanupSession() {
        $session = self::getSession();
        $session->set('riskcube_total', '');
        $session->set('riskcube_ahash', '');
        $session->set('riskcube_auth', '');
        $session->set('rc_orderprocesstoken', '');
        $session->set('rc_remarks', '');
        $session->set('riskcube_time', 0);
        $session->save_data();
    }

    public static function getSession() {

        if(!is_object(WC()->session)) {
            WC()->session = new WC_Session_Handler();
        }

        if(!WC()->session->has_session()) {
            WC()->session->init();
        }

        return WC()->session;
    }

    public static function get_blacklist_entries()
    {
        if (static::$cache_blacklist === null) {
            $args = [
                'meta_query' => [
                    [
                        'key' => 'riskcube_status',
                        'value' => '1',
                        'compare' => '==',
                    ],
                ],
            ];
            static::$cache_blacklist = get_users($args);
        }

        return static::$cache_blacklist;
    }

    public static function get_whitelist_entries()
    {
        if (static::$cache_whitelist === null) {
            $args = [
                'meta_query' => [
                    [
                        'key' => 'riskcube_status',
                        'value' => '2',
                        'compare' => '==',
                    ],
                ],
            ];
            static::$cache_whitelist = get_users($args);
        }

        return static::$cache_whitelist;
    }
}
