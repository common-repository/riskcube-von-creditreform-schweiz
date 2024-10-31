<?php

namespace Cube\Core;

use Cube\Helper\FileHelper;
use Cube\Helper\TransactionHistory;

if (!defined('WPINC')) {
    die();
}

class Admin
{
    const ROUNDING_PRECISION = 6;

    protected static bool $initiated = false;

    public static function init(): void
    {
        if (!self::$initiated && is_admin()) {
            self::$initiated = true;

            add_filter('woocommerce_settings_tabs_array', [__CLASS__, 'add_settings_tab'], 50);
            add_action('woocommerce_settings_tabs_settings_tab_riskcube', [__CLASS__, 'settings_tab']);
            add_action('woocommerce_update_options_settings_tab_riskcube', [__CLASS__, 'update_settings']);
            add_action('woocommerce_admin_field_riskcube_payment_settings_table', [__CLASS__, 'riskcube_admin_field_riskcube_payment_settings_table']);
            add_filter('woocommerce_admin_settings_sanitize_option_riskcube_payment_settings_table', [__CLASS__, 'filter_riskcube_update_option_riskcube_payment_settings_table'], 10, 3);
            add_action('woocommerce_admin_field_riskcube_support_table', [__CLASS__, 'riskcube_admin_field_riskcube_support_table']);
            add_action('woocommerce_admin_field_riskcube_whitelist_table', [__CLASS__, 'riskcube_admin_field_riskcube_whitelist_table']);
            add_action('woocommerce_admin_field_riskcube_blacklist_table', [__CLASS__, 'riskcube_admin_field_riskcube_blacklist_table']);
            add_filter('plugin_action_links_riskcube/riskcube.php', [__CLASS__, 'addWpSettingsLink']);
            add_filter('woocommerce_customer_meta_fields', [__CLASS__, 'filter_add_customer_meta_fields'], 10, 1);
            add_action('admin_menu', [__CLASS__, 'admin_menu']);

            add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);

            // Frontend Assets
            wp_enqueue_style('riskcube.admin.css', RISKCUBE__PLUGIN_URL . 'assets/riskcube.admin.css');

            $jsFiles = [];
            $jsFiles[] = 'assets/riskcube.min.js';
            $jsFiles[] = 'assets/riskcube.admin.js';
            foreach ($jsFiles as $jsFile) {
                wp_enqueue_script(basename($jsFile), RISKCUBE__PLUGIN_URL . $jsFile, [], FileHelper::get_file_version($jsFile), true);
            }
        }
    }

    public static function admin_menu(): void
    {
        add_submenu_page('', 'View log', 'View log', 'manage_options', 'rc-view-log', [__CLASS__, 'view_log_page']);
    }

    public static function view_log_page(): void
    {
        if (!isset($_GET['file']) || !($file = $_GET['file'])) {
            esc_html_e('No file specified.', RISKCUBE__DOMAIN);

            return;
        }

        if (strpos($file, '..') !== false) {
            esc_html_e('Not today.', RISKCUBE__DOMAIN);

            return;
        }

        $file = WP_CONTENT_DIR . '/uploads/riskcube/' . $file;
        if (!file_exists($file)) {
            esc_html_e('File not found.', RISKCUBE__DOMAIN);

            return;
        }

        echo('<pre>' . file_get_contents($file) . '</pre>');
    }

    public static function filter_add_customer_meta_fields($args): array
    {
        $args['riskcube']['title'] = __('RiskCube', RISKCUBE__DOMAIN);
        $args['riskcube']['fields']['riskcube_status'] = [
            'label' => __('RiskCube status', RISKCUBE__DOMAIN),
            'description' => '',
            'type' => 'select',
            'options' => [
                0 => __('Normal (default)', RISKCUBE__DOMAIN),
                1 => __('Blacklisted', RISKCUBE__DOMAIN),
                2 => __('Whitelisted', RISKCUBE__DOMAIN),
            ],
        ];

        return $args;
    }

    public static function add_settings_tab($settings_tabs)
    {
        $settings_tabs['settings_tab_riskcube'] = __('RiskCube', 'woocommerce-settings-tab-riskcube');

        return $settings_tabs;
    }

    public static function addWpSettingsLink($links)
    {
        $url = esc_url(add_query_arg(['page' => 'wc-settings', 'tab' => 'settings_tab_riskcube'], get_admin_url() . 'admin.php'));

        $links[] = '<a href="' . $url . '">' . __('Settings') . '</a>';

        return $links;
    }

    public static function settings_tab(): void
    {
        woocommerce_admin_fields(self::get_settings());
    }

    public static function update_settings(): void
    {
        woocommerce_update_options(self::get_settings());
    }

    public static function get_settings()
    {
        $MODE = (int) get_option('wc_riskcube_service_type', 0);

        $settings = [
            [
                'type' => 'title',
                'title' => __('Service selection', RISKCUBE__DOMAIN),
                'id' => 'woocommerce_riskcube_options',
                'desc' => __('Select service type. Save first to switch to new settings.', RISKCUBE__DOMAIN),
            ],
            [
                'title' => __('Service type', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_service_type',
                'type' => 'select',
                'options' => [
                    Connector::MODE_FK => __('Forderungsmanagement', RISKCUBE__DOMAIN),
                    Connector::MODE_ZS => __('Zahlartensteuerung', RISKCUBE__DOMAIN),
                ],
            ],
            ['type' => 'sectionend', 'id' => 'woocommerce_riskcube_options'],
        ];

        if (Connector::MODE_ZS === $MODE) {
            //Zahlartensteuerung
            $settings[] = [
                'type' => 'title',
                'title' => __('Zahlartensteuerung Settings', RISKCUBE__DOMAIN),
                'id' => 'woocommerce_riskcube_options',
            ];
            $settings[] = [
                'title' => __('Test API URL Override', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_zs_test_api_url',
                'type' => 'text',
                'desc' => __('If specified, replaces default URL', RISKCUBE__DOMAIN) . ': ' . Connector::TEST_API_ZS,
            ];
            $settings[] = [
                'title' => __('Live API URL Override', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_zs_live_api_url',
                'type' => 'text',
                'desc' => __('If specified, replaces default URL', RISKCUBE__DOMAIN) . ': ' . Connector::LIVE_API_ZS,
            ];
            $settings[] = [
                'title' => __('Shop ID', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_zs_api_id',
                'type' => 'text',
            ];
            $settings[] = [
                'title' => __('Test API Key', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_zs_test_api_key',
                'type' => 'text',
            ];
            $settings[] = [
                'title' => __('Live API Key', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_zs_live_api_key',
                'type' => 'text',
            ];
            $settings[] = [
                'title' => __('Live', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_zs_live',
                'type' => 'select',
                'options' => [
                    0 => __('Test', RISKCUBE__DOMAIN),
                    1 => __('Live', RISKCUBE__DOMAIN),
                ],
            ];
            $settings[] = [
                'title' => __('API check minimum limit [CHF]', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_zs_min_val',
                'type' => 'text',
            ];
            $settings[] = [
                'title' => __('API check minimum limit, foreign [CHF]', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_zs_min_val2',
                'type' => 'text',
            ];

            $settings[] = [
                'title' => __('RiskCube Payment Gateway', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_invoice_payment_gateway',
                'type' => 'select',
                'options' => [
                    0 => __('Disabled', RISKCUBE__DOMAIN),
                    1 => __('Enabled', RISKCUBE__DOMAIN),
                ],
            ];

            $settings[] = [
                'title' => __('WooCommerce Checkout', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_wc_checkout_version',
                'type' => 'select',
                'options' => [
                    0 => __('Blocks', RISKCUBE__DOMAIN),
                    1 => __('Classic', RISKCUBE__DOMAIN),
                ],
            ];

            $settings[] = ['type' => 'riskcube_payment_settings_table', 'id' => 'riskcube_payment_settings_table'];
        } else {
            //Forderungskauf
            $settings[] = [
                'type' => 'title',
                'title' => __('Forderungskauf Settings', RISKCUBE__DOMAIN),
                'id' => 'woocommerce_riskcube_options',
            ];
            $settings[] = [
                'title' => __('Test API URL Override', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_test_api_url',
                'type' => 'text',
                'desc' => __('If specified, replaces default URL', RISKCUBE__DOMAIN) . ': ' . Connector::TEST_API,
            ];
            $settings[] = [
                'title' => __('Live API URL Override', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_live_api_url',
                'type' => 'text',
                'desc' => __('If specified, replaces default URL', RISKCUBE__DOMAIN) . ': ' . Connector::LIVE_API,
            ];
            $settings[] = [
                'title' => __('Shop ID', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_api_id',
                'type' => 'text',
            ];
            $settings[] = [
                'title' => __('Test API Key', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_test_api_key',
                'type' => 'text',
            ];
            $settings[] = [
                'title' => __('Live API Key', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_live_api_key',
                'type' => 'text',
            ];
            $settings[] = [
                'title' => __('Live', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_live',
                'type' => 'select',
                'options' => [
                    0 => __('Test', RISKCUBE__DOMAIN),
                    1 => __('Live', RISKCUBE__DOMAIN),
                ],
            ];
            $settings[] = [
                'title' => __('Invoicing order state', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_fk_confirm_state',
                'type' => 'select',
                'options' => wc_get_order_statuses(),
                'desc' => __('InvoiceRedemption call will be executed when the order enters this state.', RISKCUBE__DOMAIN),
                'default' => 'wc-processing',
            ];
            $settings[] = [
                'title' => __('Invoice sending', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_invoice_sending',
                'type' => 'select',
                'options' => [
                    0 => __('Post', RISKCUBE__DOMAIN),
                    1 => __('E-mail', RISKCUBE__DOMAIN),
                ],
            ];
            $settings[] = [
                'title' => __('RiskCube Payment Gateway', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_invoice_payment_gateway',
                'type' => 'select',
                'options' => [
                    0 => __('Disabled', RISKCUBE__DOMAIN),
                    1 => __('Enabled', RISKCUBE__DOMAIN),
                ],
            ];

            $settings[] = [
                'title' => __('WooCommerce Checkout', RISKCUBE__DOMAIN),
                'id' => 'wc_riskcube_wc_checkout_version',
                'type' => 'select',
                'options' => [
                    0 => __('Blocks', RISKCUBE__DOMAIN),
                    1 => __('Classic', RISKCUBE__DOMAIN),
                ],
            ];

            if (0 == get_option('wc_riskcube_invoice_payment_gateway')) {
                $payment_methods = self::get_available_payment_methods();
                $settings[] = [
                    'title' => __('Invoice payment method', RISKCUBE__DOMAIN),
                    'id' => 'wc_riskcube_invoice_payment_method',
                    'type' => 'select',
                    'options' => [
                            0 => __('Choose', RISKCUBE__DOMAIN),
                        ] + $payment_methods,
                ];
            }

            $settings[] = ['type' => 'riskcube_payment_settings_table', 'id' => 'riskcube_payment_settings_table'];
        }

        $settings[] = ['type' => 'sectionend', 'id' => 'woocommerce_riskcube_options'];

        if (Connector::MODE_ZS === $MODE) {
            $settings[] = [
                'type' => 'title',
                'title' => __('Blacklist / Whitelist', RISKCUBE__DOMAIN),
                'desc' => __('Edit Users in Wordpress User Editor to add them to the Black- or Whitelist.', RISKCUBE__DOMAIN),
                'id' => 'woocommerce_riskcube_options',
            ];

            $settings[] = ['type' => 'riskcube_blacklist_table', 'id' => 'riskcube_blacklist_table'];
            $settings[] = ['type' => 'riskcube_whitelist_table', 'id' => 'riskcube_whitelist_table'];
        }

        $settings[] = ['type' => 'sectionstart', 'id' => 'woocommerce_riskcube_debug'];
        $settings[] = [
            'type' => 'title',
            'title' => __('Debug', RISKCUBE__DOMAIN),
            'id' => 'woocommerce_riskcube_debug',
        ];
        $settings[] = [
            'title' => __('Send Errors to this E-Mail (empty to disable)', RISKCUBE__DOMAIN),
            'id' => 'wc_riskcube_error_recipient',
            'type' => 'email',
        ];
        $settings[] = ['type' => 'sectionend', 'id' => 'woocommerce_riskcube_debug'];
        $settings[] = ['type' => 'sectionstart', 'id' => 'woocommerce_riskcube_misc'];
        //$settings[] = [
        //    'title' => __('Miscellaneous', RISKCUBE__DOMAIN),
        //    'id' => 'woocommerce_riskcube_misc_miscellaneous_title',
        //    'type' => 'title',
        //];

        $settings[] = ['type' => 'sectionend', 'id' => 'woocommerce_riskcube_misc'];
        $settings[] = ['type' => 'riskcube_support_table', 'id' => 'riskcube_support_table'];

        return apply_filters('wc_settings_tab_riskcube_settings', $settings);
    }

    // Save submitted table data
    public static function filter_riskcube_update_option_riskcube_payment_settings_table($value, $option, $raw_value)
    {
        $riskcube_payment_settings = rest_sanitize_array($_POST['riskcube_payment_settings']);
        $payment_methods_keys = array_keys(self::get_available_payment_methods());

        if (!is_array($riskcube_payment_settings)) {
            return;
        }

        foreach ($riskcube_payment_settings as $id => $fields) {
            $name = $payment_methods_keys[$id];
            $fieldValue = $fields['riskcube_payment_method'] ?? null;

            update_option('wc_riskcube_payment_method_' . $name, $fieldValue);
            update_option('wc_riskcube_active_' . $name, (int)$fields['active']);
        }
    }

    public static function riskcube_admin_field_riskcube_payment_settings_table(): void
    {
        $payment_methods = self::get_available_payment_methods();
        $payment_methods_keys = array_keys($payment_methods);
        $riskcube_payment_methods = self::get_riskcube_payment_methods();
        ?>
        <h2><?php esc_html_e('Payment method pairing', RISKCUBE__DOMAIN); ?></h2>
        <table class="riskcube_payment_settings_table wc_input_table sortable widefat">
            <thead>
            <tr>
                <th><?php esc_html_e('Local payment method', RISKCUBE__DOMAIN); ?></th>
                <th><?php esc_html_e('RiskCube payment method', RISKCUBE__DOMAIN); ?></th>
                <th width="20px"><?php esc_html_e('Active', RISKCUBE__DOMAIN); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php
            foreach ($payment_methods_keys as $k => $localPaymentMethodName) {
                $localPaymentMethodLabel = $payment_methods[$localPaymentMethodName];
                $bpm = get_option('wc_riskcube_payment_method_' . $localPaymentMethodName, 0);
                $act = (int)get_option('wc_riskcube_active_' . $localPaymentMethodName, 0);
                ?>
                <tr class="tr_<?php echo esc_html($localPaymentMethodName) ?>">
                    <th><?php echo esc_html($localPaymentMethodLabel) ?></th>
                    <td>
                        <select name="riskcube_payment_settings[<?php echo esc_html($k) ?>][riskcube_payment_method]"
                                class="select_riskcube_payment_method">
                            <option value=""> <?php esc_html_e('-- Choose! --', RISKCUBE__DOMAIN); ?> </option>
                            <?php foreach ($riskcube_payment_methods as $bpk => $bpv): ?>
                                <option value="<?php echo esc_html($bpk) ?>" <?php echo ($bpm && $bpm == $bpk) ? 'selected="selected" ' : '' ?>><?php echo esc_html($bpv) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td align="center">
                        <input type="hidden" name="riskcube_payment_settings[<?php echo esc_html($k) ?>][active]"
                               value="0"/>
                        <input type="checkbox" name="riskcube_payment_settings[<?php echo esc_html($k) ?>][active]"
                               class="checkbox_riskcube_payment_method"
                               value="1" <?php echo $act ? 'checked="checked" ' : '' ?>/>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php
    }

    public static function riskcube_admin_field_riskcube_whitelist_table(): void
    {
        $users = RiskCube::get_whitelist_entries();
        self::printTable('whitelist', $users, 'Whitelist');
    }

    public static function riskcube_admin_field_riskcube_blacklist_table(): void
    {
        $users = RiskCube::get_blacklist_entries();
        self::printTable('blacklist', $users, 'Blacklist');
    }

    private static function printTable(string $class, array $users, string $title): void
    {
        ?>
        <h2 class="x-title-click">
            <?php esc_html_e($title, RISKCUBE__DOMAIN); ?> (<?php echo(count($users)); ?>) +
        </h2>
        <table id="table-<?php echo esc_attr($class) ?>"
               class="riskcube_payment_settings_table wc_input_table sortable widefat"
               style="display: none;">
            <thead>
            <tr>
                <th><?php esc_html_e('ID', RISKCUBE__DOMAIN); ?></th>
                <th><?php esc_html_e('Name', RISKCUBE__DOMAIN); ?></th>
                <th><?php esc_html_e('E-mail', RISKCUBE__DOMAIN); ?></th>
                <th><?php esc_html_e('Edit', RISKCUBE__DOMAIN); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php
            foreach ($users as $user) { ?>
                <tr>
                    <td><?php echo esc_html($user->ID) ?></td>
                    <td><?php echo esc_html($user->display_name) ?></td>
                    <td><?php echo esc_html($user->user_email) ?></td>
                    <td align="center"><a
                                href="/wp-admin/user-edit.php?user_id=<?php echo esc_html($user->ID) ?>">Edit</a></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <script>
			jQuery(function () {
				jQuery(document).on('click', '.x-title-click', function () {
					jQuery('#table-<?php echo esc_attr($class) ?>').toggle();
				})
			})
        </script>
        <?php
    }

    //Get available checkout methods and payment gateways
    public static function get_available_payment_methods(): array
    {
        $available = [];
        foreach (WC()->payment_gateways->payment_gateways() as $available_gateway) {
            if ($available_gateway->enabled == 'yes') {
                $available[$available_gateway->id] = $available_gateway->title;
            }
        }

        return $available;
    }

    public static function get_riskcube_payment_methods(): array
    {
        return ['invoice' => 'invoice', 'card' => 'card', 'twint' => 'twint', 'prepayment' => 'prepayment', 'others' => 'others'];
    }

    public static function riskcube_admin_field_riskcube_support_table(): void
    {
        $plugin_data = get_plugin_data(RISKCUBE__PLUGIN_DIR . 'riskcube.php');
        $plugin_version = $plugin_data['Version'];
        $MODE = (int) get_option('wc_riskcube_service_type', 0);

        $transactions = TransactionHistory::getRecords();
        $transactions = array_reverse(array_values($transactions));
        $transactions = array_slice($transactions, 0, 10);

        $resultIcons = [
            null => '-',
            true => '✔',
            false => '❌',
        ];
        ?>
        <style>
            .rc-transaction-history {
                width: 100%;
                background: white;
            }

            .rc-transaction-history th {
                text-align: left;
            }

            .rc-transaction-history tr:not(:last-of-type) td {
                border-bottom: solid 1px #eee;
                padding: 2px;
            }
        </style>
        <h2 style="margin-top: 32px;"><?php esc_html_e('Transaction History', RISKCUBE__DOMAIN); ?></h2>

        <table class="rc-transaction-history">
            <tr>
                <th>Date</th>
                <th>OrderID</th>
                <th>Purchase</th>
                <?php if($MODE === Connector::MODE_FK) { ?>
                <th>Invoice</th>
                <?php } ?>
                <th>Error</th>
            </tr>
            <?php foreach ($transactions as $transaction) { ?>
                <tr>
                    <td><?php echo $transaction['date'] ?></td>
                    <td><?php echo $transaction['orderId'] ?></td>
                    <td><?php echo $resultIcons[$transaction['purchase']] ?></td>
                    <?php if($MODE === Connector::MODE_FK) { ?>
                    <td><?php echo $resultIcons[$transaction['invoice']] ?></td>
                    <?php } ?>
                    <td><?php echo $transaction['error'] ?></td>
                </tr>
            <?php } ?>
        </table>
        <p>
            <a href="/wp-admin/admin.php?page=rc-view-log&file=transactionHistory.json" target="_blank">
                View entire actionHistory
            </a>
        </p>
        
        <table class="form-table">
            <tbody>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label for="wc_riskcube_support_code"><?php esc_html_e('Log files', RISKCUBE__DOMAIN); ?>:</label>
                </th>
                <td class="forminp forminp-text">
                    /wp-content/uploads/riskcube/
                    <br/>
                    <ul>
                        <?php foreach (static::getLast7Logfiles() as $file) { ?>
                            <li>
                                <a href="/wp-admin/admin.php?page=rc-view-log&file=<?php echo esc_html($file) ?>"
                                   target="_blank">
                                    <?php echo esc_html($file) ?>
                                </a>
                            </li>
                        <?php } ?>
                    </ul>
                </td>

                <th scope="row" class="titledesc">
                    <label for="wc_riskcube_support_code"><?php esc_html_e('Plugin version', RISKCUBE__DOMAIN); ?>
                        :</label>
                </th>
                <td class="forminp forminp-text valign-top"><?php echo esc_html($plugin_version) ?></td>
            </tr>
            </tbody>
        </table>
        <?php
    }

    public static function getLast7Logfiles(): array
    {
        if (!is_dir(FileHelper::LOG_DIR)) {
            mkdir(FileHelper::LOG_DIR, 0777, true);

            return [];
        }
        $files = scandir(FileHelper::LOG_DIR, SCANDIR_SORT_DESCENDING);
        foreach ($files as $id => $file) {
            if (!(key_exists('extension', pathinfo($file)) && pathinfo($file)['extension'] === 'txt')) {
                unset($files[$id]);
            }
        }

        return array_slice($files, 0, 7);
    }

    /**
     * Adds meta box to order page
     */
    public static function add_meta_boxes(): void
    {
        // META BOX NXTLVL-220
        // $screens = ['shop_order', 'shop_order_placehold', 'post', 'order_view'];
        $screens = null;
        add_meta_box('custom_order_option', 'RiskCube', [__CLASS__, 'render_meta_box_content'], $screens, 'side', 'high', null);
    }

    /**
     * Renders meta box content
     * @param object $post gives the data of the post object to be used
     */
    public static function render_meta_box_content(object $post): void
    {
        $order = wc_get_order($post->ID);
        $is_mode_fk = get_option('wc_riskcube_service_type', 0) == Connector::MODE_FK;
        // NXTLVL-220
        // $token = $order->get_meta('rc_orderprocesstoken', true);
        // $remarks = $order->get_meta('rc_remarks', true);
        // $order->get_meta !== get_post_meta
        // $is_confirmed = $order->get_meta('rc_confirmed', true);
        // $is_invoiced = $order->get_meta('rc_invoiced', true);
        // $is_refunded = $order->get_meta('rc_cancelled', true);

        $remarks = get_post_meta($post->ID, 'rc_remarks',true);
        $token = get_post_meta($post->ID, 'rc_orderprocesstoken',true);
        $is_confirmed = get_post_meta($post->ID, 'rc_confirmed',true);
        $is_invoiced = get_post_meta($post->ID, 'rc_invoiced',true);
        $is_refunded = get_post_meta($post->ID, 'rc_cancelled', true);
        $disable_button = $is_invoiced || (!$is_mode_fk && $is_confirmed);

        ?>
        <div id="wc-riskcube-messages"></div>
        <div>
            <p>
                <?php esc_html_e('Last token:', RISKCUBE__DOMAIN); ?>:
                <small><?php echo(esc_html($token)); ?></small>
            </p>
            <p>
                <?php esc_html_e('Last remarks:', RISKCUBE__DOMAIN); ?>:
                <strong><?php echo(esc_html($remarks)); ?></strong>
            </p>
            <p>
                <?php esc_html_e('Is confirmed:', RISKCUBE__DOMAIN); ?>:
                <strong><?php echo($is_confirmed ? _('Yes') : _('No')); ?></strong>
            </p>
            <?php if ($is_mode_fk) { ?>
                <p>
                    <?php esc_html_e('Is invoiced:', RISKCUBE__DOMAIN); ?>:
                    <strong><?php echo($is_invoiced ? _('Yes') : _('No')); ?></strong>
                </p>
                <p>
                    <?php esc_html_e('Is refunded:', RISKCUBE__DOMAIN); ?>:
                    <strong><?php echo($is_refunded ? _('Yes') : _('No')); ?></strong>
                </p>
            <?php } ?>
        </div>
        <div class="t-center" id="wc-riskcube-generate-button">
            <p>
                <a id="wc_riskcube_generate" data-order="<?php echo esc_html($post->ID) ?>"
                   data-nonce="<?php echo wp_create_nonce('riskcube_reprocess') ?>"
                   class="button button-primary" <?php if ($disable_button) { ?> disabled="disabled" <?php } ?>>
                    <?php esc_html_e('Reprocess', RISKCUBE__DOMAIN); ?>
                </a>
                <?php if (isset($_GET['dbg'])) { ?>
                    <a id="wc_riskcube_refund" data-order="<?php echo esc_attr($post->ID); ?>"
                       data-nonce="<?php echo wp_create_nonce('riskcube_refund'); ?>"
                       class="button button-primary" <?php /*if ($disable_button) { ?> disabled="disabled" <?php } */ ?>>
                        <?php esc_html_e('Re-refund', RISKCUBE__DOMAIN); ?>
                    </a>
                <?php } ?>
            </p>
            <small><?php esc_html_e('This will re-run all the steps through RiskCube API, including claim.', RISKCUBE__DOMAIN); ?></small>
        </div>
        <script>
			// assets/admin.js
			riskcube_admin = {
				confirm: '<?php esc_html__('Are you sure you want to do this?', RISKCUBE__DOMAIN) ?>',
			}
        </script>
        <?php
    }
}


