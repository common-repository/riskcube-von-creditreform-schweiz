<?php

namespace Cube\API;

use Cube\Helper\FileHelper;
use Exception;
use WP_Query;

class CheckInvoicesEndpoint
{
    protected static $initiated = false;
    const FILE_DIR = WP_CONTENT_DIR . '/uploads/riskcube/reconciliation/';
    const RC_PAYMENT_METHOD = 'riskcube_invoice';

    public static function init()
    {
        if (!self::$initiated) {
            add_action('rest_api_init', function () {
                register_rest_route('riskcube/v1', '/check-invoices', [
                    'methods' => 'GET',
                    'callback' => [__CLASS__, 'check_invoices_popup'],
                    'permission_callback' => function () {
                        return current_user_can('view_woocommerce_reports');
                    },
                ]);
                register_rest_route('riskcube/v1', '/upload-files', [
                    'methods' => 'POST',
                    'callback' => [__CLASS__, 'upload_invoices_csv'],
                    'permission_callback' => function () {
                        return current_user_can('view_woocommerce_reports');
                    },
                ]);
            });
        }
    }

    public static function check_invoices_popup()
    {
        add_option('wc_rc_last-check-invoices');
        update_option('wc_rc_last-check-invoices', time());

        $wpIds = [];
        $files = FileHelper::getCsvFiles(self::FILE_DIR);
        if ($files && count($files)) {
            $fileContent = array_map(fn($file) => self::read_csv($file), $files);
            $data = array_merge(...$fileContent);
            $wpIds = array_map(fn($it) => $it['wp_id'], $data);
        }

        $unprocessedOrders = [];
        $query = new \WC_Order_Query([
            'payment_method' => 'riskcube_invoice',
            // 'post_status' => wc_get_order_statuses(),
            //'posts_per_page' => -1,
            //// $rc_reconciliation_done
            //'meta_query' => [
            //    [
            //        'key' => 'rc_reconciliation_done',
            //        'compare' => 'NOT EXISTS',
            //    ],
            //],
            'orderby' => [
                'ID' => 'DESC',
            ],
        ]);
        $orders = $query->get_orders();
        foreach ($orders as $order) {
            $order_id = $order->get_order_number();
            // $post = get_post($order->id);
            $postId = get_post($order_id)->ID;
            //$query->the_post();
            //$postId = get_the_ID();
            //$order = wc_get_order($postId);
            //
            //if ($order->get_payment_method() !== self::RC_PAYMENT_METHOD) {
            //    continue;
            //}

            $rc_reconciliation_done = get_post_meta($postId, 'rc_reconciliation_done', true) === 'found';
            if ($rc_reconciliation_done) {
                continue;
            }

            $orderFound = in_array($order_id, $wpIds);
            if ($orderFound) {
                update_post_meta($postId, 'rc_reconciliation_done', 'found');
                continue;
            }

            $firstName = $order->get_shipping_first_name();
            $lastName = $order->get_shipping_last_name();
            $city = $order->get_billing_city();
            $total = $order->get_total();
            $currency = $order->get_currency();

            $unprocessedOrders[] = [
                'id' => $postId,
                'date' => $order->get_date_created()->format(get_option('date_format')),
                'url' => $order->get_edit_order_url(),
                'name' => $firstName . ' ' . $lastName,
                'city' => $city,
                'total' => $total,
                'currency' => $currency,
                'order_id' => $order_id,
            ];
        }
        wp_reset_postdata();

        // Remove uploaded files
        array_map(fn($file) => FileHelper::deleteFile($file), $files);

        return ['orders' => $unprocessedOrders];
    }

    public static function upload_invoices_csv(): bool
    {
        if (!is_dir(self::FILE_DIR)) {
            mkdir(self::FILE_DIR);
        }
        foreach ($_FILES as $uploadedFile) {
            if ('text/csv' === $uploadedFile['type']) {
                $filename = sha1_file($uploadedFile['tmp_name']) . '.csv';
                file_put_contents(self::FILE_DIR . $filename, file_get_contents($uploadedFile['tmp_name']));
            }
        }

        return true;
    }

    public static function read_csv($path)
    {
        try {
            if (!file_exists($path)) {
                return [];
            }

            $file = fopen($path, 'r');

            if (!$file) {
                return [];
            }

            $data = [];
            $head = ['1?', '2?', '3?', '4?', '5?', '6?', 'date Sale?', '8?', 'wp_id', 'empty?', '11?', '12?', 'currency', '14?', '15?', 'currency2', '17?', 'currency3', '19?', '20?', 'empty2?', '22?', '23?', '24?', '25?', '26?', '27?', '28?', '29?', '30?', '31?', '32?'];
            while ($body = fgetcsv($file, null, ';')) {
                $tmp = [];
                for ($i = 0; $i < count($head); $i++) {
                    $tmp[$head[$i]] = $body[$i];
                }
                $data[] = $tmp;
            }

            fclose($file);

            return $data;
        } catch (Exception $exception) {
            return [];
        }
    }
}