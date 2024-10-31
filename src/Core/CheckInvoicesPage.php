<?php

namespace Cube\Core;

use Cube\Helper\FileHelper;

class CheckInvoicesPage
{
    protected static $initiated = false;

    public static function init()
    {
        if (!self::$initiated && is_admin()) {
            add_action('woocommerce_admin_field_riskcube_check_invoices', [__CLASS__, 'riskcube_admin_field_riskcube_check_invoices']);
            add_action('admin_menu', [__CLASS__, 'admin_menu']);

            $jsFiles = [];
            $jsFiles[] = 'assets/riskcube.checkInvoices.js';
            foreach ($jsFiles as $jsFile) {
                wp_enqueue_script(basename($jsFile), RISKCUBE__PLUGIN_URL . $jsFile, [], FileHelper::get_file_version($jsFile), true);
            }
        }
    }

    public static function admin_menu()
    {
        if (current_user_can('view_woocommerce_reports') && get_option('wc_riskcube_service_type', 0) == Connector::MODE_FK) {
            add_submenu_page('woocommerce', 'Check Invoices', 'Check Invoices', 'manage_product_terms', 'check_invoices', [__CLASS__, 'check_invoices_action']);
        }
    }

    public static function check_invoices_action()
    {
        add_thickbox();
        ?>
        <div class="check-invoices-head">
            <h1><?php esc_html_e('Check Invoices (RiskCube)', RISKCUBE__DOMAIN) ?></h1>
            <div>
                <a href="#TB_inline?&width=300&height=300&inlineId=rc-check-invoices-help"
                   class="thickbox no-text-decoration"><span class="dashicons dashicons-editor-help"></span>
                </a>
            </div>
        </div>

        <!-- TODO: translate -->
        <div id="rc-check-invoices-help" style="display:none;">
            <h2>What is this page?</h2>
            <p>
                This page helps you find orders that have not been properly sent to the riskcube.
                Every other week you will receive an E-Mail which has a CSV-File attached to it.
                Without making changes to it upload that file to the File Field at the top of this page and press
                "Check".
            </p>
            <p>
                The Table will show all Invoice orders which are not present in the file (or any file that's been
                uploaded
                previously),
                and need to be reprocessed. To do that click on any order in the table which brings you to the full
                order view.
                There you will need to find the panel titled "RiskCube" on the right-hand side, and click on the blue
                Button "Reprocess".
            </p>
            <p>
                Note that doing this will not immediately remove the entry from the table. For that to happen you will
                have to wait
                for the next reconciliation file, and if the order has now been processed properly, it won't be present
                in that new file anymore.
            </p>
            <p>
                It is important to know that, in order to insure good performance, this page will only display 200
                orders.
                Should you come anywhere near this number it is likely that you have a problem with your shop and
                should contact your Administrator.
            </p>
        </div>
        <label for="rc_upload-file"><?php esc_html_e('Upload a file to check', RISKCUBE__DOMAIN) ?></label>
        <br>
        <input id="rc_upload-file" multiple type="file" accept="text/csv">
        <button class="button-primary" id="rc_check_invoices"><?php esc_html_e('Check', RISKCUBE__DOMAIN) ?></button>
        <br>
        <br>
        <img id="rc-loader" src="/wp-admin/images/spinner.gif" alt="loading" width="20" height="20">
        <div id="rc-loading-error" class="notice notice-error settings-error">
            <p><?php esc_html_e('Error loading data', RISKCUBE__DOMAIN) ?></p>
        </div>
        <div id="rc-no-orders" class="notice notice-success">
            <p><?php esc_html_e('There were no unprocessed orders found', RISKCUBE__DOMAIN) ?></p>
        </div>
        <table id="rc-check-invoices-table" class="wp-list-table widefat table-view-list striped">
            <thead>
            <tr>
                <th><?php esc_html_e('id', RISKCUBE__DOMAIN) ?></th>
                <th><?php esc_html_e('date', RISKCUBE__DOMAIN) ?></th>
                <th><?php esc_html_e('name', RISKCUBE__DOMAIN) ?></th>
                <th><?php esc_html_e('total', RISKCUBE__DOMAIN) ?></th>
                <th><span class="dashicons dashicons-external"></span></th>
            </tr>
            </thead>
            <tbody id="rc-check-invoices-table-data">
            </tbody>
        </table>
        <script>
            jQuery(function () {
                checkInvoicesInit({
                    textOpen: '<?php esc_html_e('Open', RISKCUBE__DOMAIN) ?>',
                    wpRest: '<?php echo wp_create_nonce('wp_rest') ?>',
                });
            })
        </script>
        <?php
    }
}