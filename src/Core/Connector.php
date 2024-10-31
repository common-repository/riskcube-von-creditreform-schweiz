<?php

namespace Cube\Core;

use Cube\Helper\ClaimDataHelper;
use Cube\Helper\Logger;
use Cube\Helper\TransactionHistory;
use Cube\Model\ClaimData;
use DateTime;
use Exception;
use WC_Abstract_Order;
use WC_Coupon;
use WC_Order;
use WC_Order_Refund;
use WC_Product;
use WC_Tax;

class Connector
{
    public const ROUNDING_PRECISION = 2;

    public const MODE_FK = 0;
    public const MODE_ZS = 1;

    public const LIVE_API = 'https://service.riskcube.ch/api/v1/RiskCube/';
    public const TEST_API = 'https://accservice.riskcube.ch/api/v1/RiskCube/';

    public const LIVE_API_ZS = 'https://service-zs.riskcube.ch/api/v1/RiskCube/';
    public const TEST_API_ZS = 'https://accservice-zs.riskcube.ch/api/v1/RiskCube/';

    public const CACHE_BLACKLIST = ['cancel', 'purchase', 'invoice'];

    protected $mode = 0; // 0 = Forderungsmanagement, 1 = Zahlartensteuerung
    protected bool $live = false;
    protected string $key;
    protected string $api;
    protected $shop_id;
    protected bool $enabled = false;

    // NXTLVL DEV
    protected bool $useMockServer = false;
    public const MOCK_API = 'http://rcmockweb/';

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->mode = (int)get_option('wc_riskcube_service_type', static::MODE_FK);

        if (static::MODE_ZS === $this->mode) { // Zahlartensteuerung
            $this->live = (bool)get_option('wc_riskcube_zs_live', 0);
            $this->shop_id = get_option('wc_riskcube_zs_api_id', 0);

            $this->key = trim($this->live ? get_option('wc_riskcube_zs_live_api_key') : get_option('wc_riskcube_zs_test_api_key'));
            $live_url = trim(get_option('wc_riskcube_zs_live_api_url', ''));
            $test_url = trim(get_option('wc_riskcube_zs_test_api_url', ''));

            $this->api = $this->live ? ($live_url ?: static::LIVE_API_ZS) : ($test_url ?: static::TEST_API_ZS);
        } else { // Forderungsmanagement
            $this->live = (bool)get_option('wc_riskcube_live', 0);
            $this->shop_id = get_option('wc_riskcube_api_id', 0);

            $this->key = trim($this->live ? get_option('wc_riskcube_live_api_key') : get_option('wc_riskcube_test_api_key'));
            $live_url = trim(get_option('wc_riskcube_live_api_url', ''));
            $test_url = trim(get_option('wc_riskcube_test_api_url', ''));

            $this->api = $this->live ? ($live_url ?: static::LIVE_API) : ($test_url ?: static::TEST_API);
        }

        // // NXTLVL DEV
        if ($this->useMockServer) {
            $this->api = static::MOCK_API;
        }

        if (!$this->shop_id) {
            Logger::logw('Shop ID not set, request aborted.');

            return;
        }
        if (!$this->key) {
            Logger::logw('API Key not set, request aborted.');

            return;
        }

        $this->enabled = true;
    }

    /**
     * Execute a claim request to RiskCube API or provide local decision (blacklist, whitelist, min values in ZS mode).
     * API Description:
     *     Request Data: {
     *         "shopId": "20071992",
     *         "orderProcessId": "ref001",
     *         "ipAddress": null,
     *         "macAddress": null,
     *         "customerId": "cus001",
     *         "billingAddress": {
     *             "type": "Consumer",
     *             "businessName": null,
     *             "firstName": "Martin",
     *             "lastName": "Früh",
     *             "co": null,
     *             "street": "Funkenbüelstrasse",
     *             "houseNumber": "1",
     *             "postCode": "9243",
     *             "locationName": "Jonschwil",
     *             "country": "CH",
     *             "email": null,
     *             "phone": null,
     *             "dateOfBirth": null
     *        },
     *        "shippingAddress": null,
     *        "orderAmount": 1200
     *    }
     *    Response:
     *    200 {
     *        "shopId": "string",
     *        "orderProcessId": "string",
     *        "orderProcessToken": "string",
     *        "validityOrderProcessToken": "2020-08-26T11:38:49.433Z",
     *        "invoiceAuthorization": true,
     *        "remarks": "string"
     *    }
     * @param ClaimData $requestData ClaimData Object containing data for the request
     * @return array|bool true/false on local decision, or remote answer array
     * @throws Exception
     */
    public function doClaim(ClaimData $requestData, ?int $id_order = null)
    {
        Logger::logw('CLAIM');

        if (!ClaimDataHelper::verifyClaimData($requestData)) {
            return false;
        }

        $res = $this->sendRequest('claim', (array)$requestData);


        self::setSessionData($res, $requestData, $id_order);

        return $res;
    }

    /**
     * API Description:
     * - Request:
     * ```
     * {
     *         "shopId": "string",
     *         "shopOrderId": "string",
     *         "orderProcessToken": "string",
     *         "cancellationReference": "string",
     *         "shoppingCart": [
     *             {
     *                 "claimType": "reduction",
     *                 "itemId": "string",
     *                 "itemDefinition": "string",
     *                 "numberOfItems": 0,
     *                 "unitAmountNet": 0,
     *                 "unitAmountGross": 0,
     *                 "totalAmountNet": 0,
     *                 "totalAmountGross": 0,
     *                 "currency": "string",
     *                 "vat": 0
     *             }
     *         ]
     * }
     * ```
     * - Response:
     * - 200
     * ```
     * {
     *         "shopId": "string",
     *         "shopOrderId": "string",
     *         "responseKey": "string",
     *         "paymentMethod": "invoice"
     * }
     * ```
     * - err
     * ```
     * {
     *         "errorMessage": "string",
     *         "hasError": true,
     *         "errorDescription": "string"
     * }
     * ```
     * @param int $id_order
     * @param int $id_refund
     * @return array|bool
     * @throws Exception
     */
    public function doCancellation(int $id_order, int $id_refund)
    {
        Logger::logw('CANCEL, ORDER #' . $id_order . ', REFUND #' . $id_refund);
        $post = get_post($id_order);
        $post_id = $post->ID;

        if (1 === $this->mode) { // not usable in Zahlartensteuerung mode
            TransactionHistory::update($id_order, TransactionHistory::ACTION_CANCEL, false, 'not usable in Zahlartensteuerung mode');

            return false;
        }

        $order = wc_get_order($id_order);
        $is_invoiced = get_post_meta($post_id, 'rc_invoiced', true);
        // if (!$order->get_meta('rc_invoiced', true)) { // already invoiced
        if (!$is_invoiced) { // already invoiced
            Logger::logw('CANCEL : NOT INVOICED', ['id_order' => $id_order]);
            TransactionHistory::update($id_order, TransactionHistory::ACTION_CANCEL, false, 'Not invoiced');

            return true;
        }

        if ('refunded' === $order->get_status()) { // already cancelled
            Logger::logw('CANCEL : ALREADY CANCELLED', ['id_order' => $id_order]);
            TransactionHistory::update($id_order, TransactionHistory::ACTION_CANCEL, false, 'Already cancelled');

            return true;
        }

        // $opt = $order->get_meta('rc_orderprocesstoken', true);
        $opt = get_post_meta($post_id, 'rc_orderprocesstoken', true);
        if (!$opt) { // consider it complete since there is no data for this order
            Logger::logw('CANCEL : NO TOKEN', ['id_order' => $id_order]);
            TransactionHistory::update($id_order, TransactionHistory::ACTION_CANCEL, false, 'No Token');

            return true;
        }

        $refund = new WC_Order_Refund($id_refund);

        if (!$refund->get_id()) {
            Logger::logw('CANCEL : REFUND NOT FOUND', ['id_refund' => $id_refund]);
            TransactionHistory::update($id_order, TransactionHistory::ACTION_CANCEL, false, sprintf("RefundId '%s' not found", $id_refund . ''));

            return true;
        }

        // decide if full or partial refund is happening
        $order_total = (float)$order->get_total();
        $refund_total = (float)$refund->get_amount();

        $reason = $refund->get_reason();

        // use original order to assemble the refund query in case of a full refund
        if ($refund_total === $order_total) {
            $refund = $order;
            $invert = true;
            Logger::logw('CANCEL : FULL REFUND');
        } else {
            $invert = false;
            Logger::logw('CANCEL : PARTIAL REFUND');
        }

        // Removed Commented Fee code - 2022-09-12

        // fetch coupon objects
        $coupon_objs = static::getCouponObjectsForOrder($order);

        $product_items = [];
        foreach ($refund->get_items() as $item) {
            $tax_rate = 0;
            $tax_rates = WC_Tax::get_rates($item->get_tax_class());
            if (!empty($tax_rates)) {
                $tax_rate = reset($tax_rates);
                $tax_rate = $tax_rate['rate'];
            }

            $prod = new WC_Product($item->get_product_id());

            $product_item = [
                'claimType' => 'reduction',
                'itemId' => $prod->get_sku() ?: $prod->get_id(),
                'itemDefinition' => $prod->get_name(),
                'numberOfItems' => round(abs($item->get_quantity()), 3),
                'unitAmountNet' => round(abs($order->get_item_total($item, false, false)), static::ROUNDING_PRECISION),
                'unitAmountGross' => round(abs($order->get_item_total($item, true, false)), static::ROUNDING_PRECISION),
                'totalAmountNet' => round(abs($item->get_total()), static::ROUNDING_PRECISION),
                'totalAmountGross' => round(abs($item->get_total() + $item->get_total_tax()), static::ROUNDING_PRECISION),
                'currency' => $order->get_currency(),
                'vat' => $tax_rate,
            ];

            if (0.00 === $product_item['unitAmountNet']) {
                $product_item['unitAmountNet'] = $product_item['totalAmountNet'];
            }
            if (0.00 === $product_item['unitAmountGross']) {
                $product_item['unitAmountGross'] = $product_item['totalAmountGross'];
            }

            $product_items[] = $product_item;
        }

        //Shipping
        $shipping_items = $refund->get_shipping_methods();
        if ($shipping_items) {
            $item = reset($shipping_items);
            $tax_rate = 0;
            $tax_rates = WC_Tax::get_rates($item->get_tax_class());
            if (!empty($tax_rates)) {
                $tax_rate = reset($tax_rates);
                $tax_rate = $tax_rate['rate'];
            }

            $order_shipping = (float)$refund->get_shipping_total();
            $order_shipping_tax = (float)$refund->get_shipping_tax();

            if (abs($order_shipping) > 0) {
                $shipping_total = $order_shipping_tax + $order_shipping;
                $vat_percentage = $order_shipping_tax / $order_shipping;
                $shipping_net_total = $shipping_total / (1 + $vat_percentage);
            } else {
                $shipping_net_total = 0;
                $shipping_total = 0;
            }

            $product_item = [
                'claimType' => 'reduction',
                'itemId' => $item['method_id'],
                'itemDefinition' => $refund->get_shipping_method(),
                'numberOfItems' => 1,
                'unitAmountNet' => round(abs($shipping_net_total), static::ROUNDING_PRECISION),
                'unitAmountGross' => round(abs($shipping_total), static::ROUNDING_PRECISION),
                'totalAmountNet' => round(abs($shipping_net_total), static::ROUNDING_PRECISION),
                'totalAmountGross' => round(abs($shipping_total), static::ROUNDING_PRECISION),
                'currency' => $refund->get_currency(),
                'vat' => $tax_rate,
            ];

            $product_items[] = $product_item;
        }

        //Extra Fees
        $fees = $refund->get_fees();
        if (!empty($fees)) {
            foreach ($fees as $fee) {
                $amount = floatval($fee['line_total']);
                $amount_tax = $amount;
                $tax_percent = 0;

                if (abs($amount) > 0.0) {
                    $tax_percent = abs(round(floatval($fee['line_tax']) / floatval($fee['line_total']) * 100));
                    $tax_rate = 1.0 + floatval($fee['line_tax']) / floatval($fee['line_total']);
                    $amount_tax = $amount * $tax_rate;
                }

                $product_item = [
                    'claimType' => $invert ? ($amount < 0.0) : (($amount > 0.0) ? 'claim' : 'reduction'), // invert if using order as refund source
                    'itemId' => 'fee',
                    'itemDefinition' => $fee['name'],
                    'numberOfItems' => 1,
                    'unitAmountNet' => round(abs($amount), static::ROUNDING_PRECISION),
                    'unitAmountGross' => round(abs($amount_tax), static::ROUNDING_PRECISION),
                    'totalAmountNet' => round(abs($amount), static::ROUNDING_PRECISION),
                    'totalAmountGross' => round(abs($amount_tax), static::ROUNDING_PRECISION),
                    'currency' => $refund->get_currency(),
                    'vat' => $tax_percent,
                ];

                $product_items[] = $product_item;
            }
        }

        $cancellation = [
            'shopId' => $this->shop_id,
            'shopOrderId' => $order->get_order_number(),
            'orderProcessToken' => $opt,
            'cancellationReference' => $reason,
            'shoppingCart' => $product_items,
        ];

        $res = $this->sendRequest('cancel', $cancellation);

        $isSuccessful = false;
        $error = null;
        if ($res && (isset($res['responseKey']) || isset($res['paymentMethod']))) { // save as cancelled
            update_post_meta($id_order, 'rc_cancelled', 1);
            $isSuccessful = true;
        } else {
            $error = sprintf("ResponseKey '%s' or paymentMethod '%s' not set", $res['responseKey'] ?? '', $res['paymentMethod'] ?? '');
        }
        TransactionHistory::update($id_order, TransactionHistory::ACTION_CANCEL, $isSuccessful, $error);

        return $res;
    }

    /**
     * API Description:
     * - Request:
     * ```
     * {
     *         "shopId": "20071992",
     *         "shopOrderId": "soi001",
     *         "orderProcessToken": "responsekey123",
     *         "language": "DE",
     *         "transmissions": "Mail",
     *         "additionalInfo": null,
     *         "shoppingCart": [
     *             {
     *                 "claimType": "Claim",
     *                 "itemId": "ite0001",
     *                 "itemDefinition": "an item",
     *                 "numberOfItems": 1,
     *                 "unitAmountNet": 9,
     *                 "unitAmountGross": 10,
     *                 "totalAmountNet": 9,
     *                 "totalAmountGross": 200,
     *                 "currency": "CHF",
     *                 "vat": 10
     *             },
     *             {
     *                 "claimType": "Claim",
     *                 "itemId": "ite0002",
     *                 "itemDefinition": "another item",
     *                 "numberOfItems": 1,
     *                 "unitAmountNet": 9,
     *                 "unitAmountGross": 10,
     *                 "totalAmountNet": 9,
     *                 "totalAmountGross": 1000,
     *                 "currency": "CHF",
     *                 "vat": 10
     *             }
     *         ]
     * }
     * ```
     * - Response:
     * - 200
     * ```
     * {
     *        "shopId": "string",
     *        "shopOrderId": "string",
     *        "responseKey": "string",
     *        "paymentMethod": "invoice"
     * }
     * ```
     * @param int $id_order
     * @param bool $skip_status_check
     * @return array|bool
     * @throws Exception
     */
    public function doInvoiceRedemption(int $id_order, bool $skip_status_check = false)
    {
        Logger::logw('INVOICE, ORDER #' . $id_order);

        $post = get_post($id_order);
        $post_id = $post->ID;

        if (static::MODE_ZS === $this->mode) { // not usable in Zahlartensteuerung mode
            return false;
        }

        $order = wc_get_order($id_order);

        if (!$skip_status_check) { // bypassed if running admin re-run
            $status = $order->get_status();
            $invoice_status = str_replace('wc-', '', get_option('wc_riskcube_fk_confirm_state', 'wc-processing'));
            if ($status != $invoice_status) {
                Logger::logw('INVOICE : STATUS DOESNT MATCH', ['id_order' => $id_order, 'status' => $status, 'wanted-status' => $invoice_status]);

                // TransactionHistory::update($id_order, TransactionHistory::ACTION_INVOICE, false, sprintf("Status '%s' does not match wanted status '%s'", $status, $invoice_status));

                return true;
            }
        }

        $is_invoiced = get_post_meta($post_id, 'rc_invoiced', true);
        if ($is_invoiced) { // already invoiced
            Logger::logw('INVOICE : ALREADY SENT', ['id_order' => $id_order]);
            TransactionHistory::update($id_order, TransactionHistory::ACTION_INVOICE, false, 'Invoice already sent');

            return true;
        }

        if (0 === get_option('wc_riskcube_invoice_payment_gateway') && get_option('wc_riskcube_invoice_payment_method') != $order->get_payment_method()) {
            Logger::logw('INVOICE : DIFFERENT PAYMENT METHOD', ['id_order' => $id_order]);
            TransactionHistory::update($id_order, TransactionHistory::ACTION_INVOICE, false, sprintf("Different payment method '%s' != '%s'", get_option('wc_riskcube_invoice_payment_method'), $order->get_payment_method()));

            return false;
        }

        // TODO
        // $opt = $order->get_meta('rc_orderprocesstoken', true);
        $opt = get_post_meta($post_id, 'rc_orderprocesstoken', true);
        if (!$opt) { // consider it complete since there is no data for this order
            Logger::logw('INVOICE : NO TOKEN', ['id_order' => $id_order]);
            TransactionHistory::update($id_order, TransactionHistory::ACTION_INVOICE, false, 'No token');

            return true;
        }

        $actionPerformed = TransactionHistory::wasActionSuccessful($id_order, 'invoice');
        if ($actionPerformed) {
            Logger::logw('INVOICE : ACTION ALREADY PERFORMED', ['id_order' => $id_order]);

            return true;
        }

        $product_items = $this->getOrderProducts($order);
        if ($shipping_item = $this->getOrderShipping($order)) {
            $product_items[] = $shipping_item;
        }

        //Extra Fees
        if ($fees = $this->getOrderFees($order)) {
            $product_items = array_merge($product_items, $fees);
        }

        $invoiceRedemption = [
            'shopId' => $this->shop_id,
            'shopOrderId' => $order->get_order_number(),
            'orderProcessToken' => $opt,
            'language' => 'DE',
            'transmissions' => get_option('wc_riskcube_invoice_sending', 0) ? 'mail' : 'post',
            'additionalInfo' => null,
            'shoppingCart' => $product_items,
        ];

        $res = $this->sendRequest('invoice', $invoiceRedemption);

        $isSuccessful = false;
        $error = null;
        if ($res && isset($res['responseKey'])) { // save as invoiced
            // Logger::logw('Should set the order to invoiced', $res);
            update_post_meta($post_id, 'rc_invoiced', 1);
            $isSuccessful = true;
        } else {
            $error = sprintf('ResponseKey "%s" not set', $res['responseKey'] ?? null);
        }
        TransactionHistory::update($id_order, TransactionHistory::ACTION_INVOICE, $isSuccessful, $error);

        return $res;
    }

    /**
     * API Description:
     * - Request:
     * ```
     * {
     *         "shopId": "20071992",
     *         "shopOrderId": "soi001",
     *         "orderProcessToken": "responsekey123",
     *         "dateOfOrder": "2020-08-26 13-36-22",
     *         "paymentMethod": "Invoice"
     * }
     * ```
     * - Response:
     * ```
     * 200 {
     *         "shopId": "string",
     *         "shopOrderId": "string",
     *         "responseKey": "string",
     *         "paymentMethod": "invoice"
     * }
     * ```
     * @param $id_order
     * @return bool
     * @throws Exception
     */
    public function doPurchaseConfirmation($id_order): bool
    {
        Logger::logw('CONFIRM, ORDER #' . $id_order);

        $order = wc_get_order($id_order);
        $post = get_post($id_order);
        $post_id = $post->ID;

        $is_confirmed = get_post_meta($post_id, 'rc_confirmed', true);
        if ($is_confirmed) { // already confirmed
            Logger::logw('CONFIRM : ALREADY SENT', ['id_order' => $id_order]);
            // TransactionHistory::update($id_order, TransactionHistory::ACTION_PURCHASE, false, 'Already sent');

            return true;
        }

        // TODO
        // $opt = $order->get_meta('rc_orderprocesstoken', true);
        $opt = get_post_meta($post_id, 'rc_orderprocesstoken', true);

        if (!$opt) { // consider it complete since there is no data for this order
            Logger::logw('CONFIRM : NO TOKEN', ['id_order' => $id_order]);
            // TransactionHistory::update($id_order, TransactionHistory::ACTION_PURCHASE, false, 'No token');

            return true;
        }

        $isInvoiced = get_post_meta($post_id, 'rc_invoiced', 1);
        if($isInvoiced) {
            Logger::logw('CONFIRM : ALREADY INVOICED', ['id_order' => $id_order]);
            
            return true;
        }

        $actionPerformed = TransactionHistory::wasActionSuccessful($id_order, 'purchase');
        if ($actionPerformed) {
            Logger::logw('CONFIRM : ACTION ALREADY PERFORMED', ['id_order' => $id_order]);

            return true;
        }

        $purchaseConfirmation = [
            'shopId' => $this->shop_id,
            //'shopOrderId' => $order->get_order_number(),
            'shopOrderId' => $id_order,
            'orderProcessToken' => $opt,
            'dateOfOrder' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'paymentMethod' => $this->getPaymentMethod($order),
        ];

        $res = $this->sendRequest('purchase', $purchaseConfirmation);

        $isSuccessful = false;
        $error = null;
        if ($res && isset($res['responseKey'])) { // save as confirmed
            update_post_meta($post_id, 'rc_confirmed', 1);
            $isSuccessful = true;
        } else {
            $error = sprintf("ResponseKey '%s' not set", $res['responseKey'] ?? null);
        }
        TransactionHistory::update($id_order, TransactionHistory::ACTION_PURCHASE, $isSuccessful, $error);

        return $isSuccessful;
    }

    public function getOrderProducts(WC_Order $order)
    {
        // fetch coupon objects
        $coupon_objs = static::getCouponObjectsForOrder($order);

        $product_items = [];
        foreach ($order->get_items() as $item) {
            $tax_rate = 0;
            $tax_rates = WC_Tax::get_rates($item->get_tax_class());
            if (!empty($tax_rates)) {
                $tax_rate = reset($tax_rates);
                $tax_rate = $tax_rate['rate'];
            }

            $prod = new WC_Product($item->get_product_id());
            $refunded_qty = $order->get_qty_refunded_for_item($item->get_id(), $item->get_type());
            $refunded_total = round($order->get_total_refunded_for_item($item->get_id(), $item->get_type()), static::ROUNDING_PRECISION);
            $tax_multiplier = 1 + $tax_rate / 100.0;
            $refunded_total_gross = round($refunded_total * $tax_multiplier, static::ROUNDING_PRECISION);
            //$refunded_tax = $refunded_total_gross - $refunded_total;
            $quantity = round($item->get_quantity() + $refunded_qty, 3);

            if (!$quantity) {
                continue;
            }

            $product_item = [
                'claimType' => 'claim',
                'itemId' => $prod->get_sku() ?: $prod->get_id(),
                'itemDefinition' => $prod->get_name(),
                'numberOfItems' => $quantity,
                'unitAmountNet' => round($order->get_item_subtotal($item, false, false), static::ROUNDING_PRECISION),
                'unitAmountGross' => round($order->get_item_subtotal($item, true, false), static::ROUNDING_PRECISION),
                'totalAmountNet' => round($item->get_subtotal(), static::ROUNDING_PRECISION) - $refunded_total,
                'totalAmountGross' => round($item->get_subtotal() + $item->get_subtotal_tax(), static::ROUNDING_PRECISION) - $refunded_total_gross,
                'currency' => $order->get_currency(),
                'vat' => $tax_rate,
            ];

            $product_items[] = $product_item;

            //add discount as extra line
            $discount = $order->get_item_total($item, false, false) - $order->get_item_subtotal($item, false, false);
            if ($discount) {
                $codes = [];
                foreach ($coupon_objs as $coupon) {
                    if ($coupon->is_valid_for_cart() || $prod->get_id() && $coupon->is_valid_for_product($prod)) {
                        $codes[] = $coupon->get_code();
                    }
                }

                $net_total = $order->get_item_total($item, false, false);
                $gross_total = $order->get_item_total($item, true, false);
                $net_subtotal = $order->get_item_subtotal($item, false, false);
                $gross_subtotal = $order->get_item_subtotal($item, true, false);

                $product_item = [
                    'claimType' => 'reduction',
                    'itemId' => 'DSC' . ($prod->get_sku() ?: $prod->get_id()),
                    'itemDefinition' => 'Discounts ' . implode(', ', $codes),
                    'numberOfItems' => $quantity,
                    'unitAmountNet' => round(abs($net_total - $net_subtotal), static::ROUNDING_PRECISION),
                    'unitAmountGross' => round(abs($gross_total - $gross_subtotal), static::ROUNDING_PRECISION),
                    'totalAmountNet' => round(abs($net_total - $net_subtotal) * $quantity, static::ROUNDING_PRECISION),
                    'totalAmountGross' => round(abs($gross_total - $gross_subtotal) * $quantity, static::ROUNDING_PRECISION),
                    'currency' => $order->get_currency(),
                    'vat' => $tax_rate,
                ];

                $product_items[] = $product_item;
            }
        }

        return $product_items;
    }

    public function getOrderShipping(WC_Order $order)
    {
        $shipping_items = $order->get_shipping_methods();
        if ($shipping_items) {
            $item = reset($shipping_items);
            $order_shipping = $order->get_shipping_total();
            $order_shipping_tax = (float)$order->get_shipping_tax();
            $shipping_refund = $order->get_total_shipping_refunded();
            $order_shipping -= $shipping_refund;
            $tax_rate = 0;

            if ($order_shipping_tax > 0) {
                if (isset($item['taxes']) && ($taxes = $item->get_taxes())) {
                    if (array_key_exists('total', $taxes)) {
                        $tax_ids = array_keys($taxes['total']);
                        $tax_rate_id = reset($tax_ids);
                        if ($tax_rate_id) {
                            $tax_rate = floatval(WC_Tax::get_rate_percent($tax_rate_id));
                        }
                    }
                }
            }

            if ($order_shipping > 0) {
                $shipping_total = $order_shipping_tax + $order_shipping;
                $shipping_net_total = $order_shipping; // $shipping_total / (1 + $tax_rate / 100.0);
            } else {
                $shipping_net_total = 0;
                $shipping_total = 0;
            }

            $product_item = [
                'claimType' => 'claim',
                'itemId' => $item['method_id'],
                'itemDefinition' => $order->get_shipping_method(),
                'numberOfItems' => 1,
                'unitAmountNet' => round(abs($shipping_net_total), static::ROUNDING_PRECISION),
                'unitAmountGross' => round(abs($shipping_total), static::ROUNDING_PRECISION),
                'totalAmountNet' => round(abs($shipping_net_total), static::ROUNDING_PRECISION),
                'totalAmountGross' => round(abs($shipping_total), static::ROUNDING_PRECISION),
                'currency' => $order->get_currency(),
                'vat' => $tax_rate,
            ];

            return $product_item;
        }
    }

    public function getOrderFees(WC_Order $order)
    {
        $product_items = [];
        $fees = $order->get_fees();
        if (!empty($fees)) {
            foreach ($fees as $fee) {
                $amount = floatval($fee['line_total']);
                $amount_gross = $amount;
                $tax_percent = 0;

                $refunded_qty = $order->get_qty_refunded_for_item($fee->get_id(), $fee->get_type());
                $refunded_total = round($order->get_total_refunded_for_item($fee->get_id(), $fee->get_type()), static::ROUNDING_PRECISION);
                $refunded_total_tax = $refunded_total;

                if (isset($fee['line_total']) && floatval($fee['line_total']) > 0.0) {
                    $tax_percent = round(floatval($fee['line_tax']) / floatval($fee['line_total']) * 100);
                    $tax_multiplier = 1.0 + floatval($fee['line_tax']) / floatval($fee['line_total']);
                    $amount_gross = $amount * $tax_multiplier;
                    $refunded_total_tax = $refunded_total * $tax_multiplier;
                }

                $quantity = 1 - $refunded_qty;

                if (!$quantity) {
                    continue;
                }

                $product_item = [
                    'claimType' => $amount_gross >= 0.0 ? 'claim' : 'reduction',
                    'itemId' => 'fee',
                    'itemDefinition' => $fee['name'],
                    'numberOfItems' => $quantity,
                    'unitAmountNet' => round(abs($amount), static::ROUNDING_PRECISION),
                    'unitAmountGross' => round(abs($amount_gross), static::ROUNDING_PRECISION),
                    'totalAmountNet' => round(abs($amount) - $refunded_total, static::ROUNDING_PRECISION),
                    'totalAmountGross' => round(abs($amount_gross) - $refunded_total_tax, static::ROUNDING_PRECISION),
                    'currency' => $order->get_currency(),
                    'vat' => $tax_percent,
                ];

                $product_items[] = $product_item;
            }
        }

        return $product_items;
    }

    /**
     * Check if response contains invoiceAuthorization and if it's true
     * @param array $res API response (from Claim)
     * @return bool true if invoiceAuthorization was true, false otherwise
     */
    public static function isInvoiceAuthorization($res): bool
    {
        return is_array($res) && array_key_exists('invoiceAuthorization', $res) && $res['invoiceAuthorization'];
    }

    public static function hashArray(array $a): string
    {
        return md5(json_encode($a));
    }

    private static function setSessionData($result, ClaimData $requestData, ?int $id_order = null): void
    {
        // if running from admin, skip session stuff
        if ($id_order) {
            if (static::isInvoiceAuthorization($result)) {
                update_post_meta($id_order, 'rc_orderprocesstoken', $result['orderProcessToken']);
                update_post_meta($id_order, 'rc_remarks', $result['remarks']);
            }
        } else {
            $session = RiskCube::getSession();
            if (!is_object($session) || !method_exists($session, 'set')) {
                return;
            }
            $session->set('riskcube_auth', static::isInvoiceAuthorization($result)); // only save if remote API responds and invoiceAuthorization is true
            $session->set('riskcube_total', $requestData->orderAmount);
            $session->set('riskcube_time', time());
            $session->set('riskcube_ahash', static::hashArray((array)$requestData->billingAddress) . static::hashArray((array)$requestData->shippingAddress));

            if (is_array($result)) {
                $session->set('rc_orderprocesstoken', $result['orderProcessToken']);
                $session->set('rc_remarks', $result['remarks']);
            }
            $session->save_data();
        }
    }

    /**
     * Make a request to the API
     * @param string $uri
     * @param array $data
     * @return array|bool
     * @throws Exception
     */
    protected function sendRequest(string $uri, array $data = [])
    {
        if (!$this->enabled) {
            return false;
        }

        $url = $this->getURL($uri);
        $data['shopId'] = $this->shop_id;

        $cacheKey = md5(json_encode(['data' => $data, 'uri' => $uri]));
        if (in_array($uri, self::CACHE_BLACKLIST)) {
            $cacheResponse = false;
        } else {
            $cacheResponse = get_transient($cacheKey);
        }

        if ($cacheResponse) {
            $cacheData = json_decode($cacheResponse, true);
            $json_data = $cacheData['response'];
            $status_code = $cacheData['status_code'];
            Logger::logw('GETTING RESPONSE FROM CACHE', $json_data);
        } else {
            $json_string = json_encode($data, JSON_UNESCAPED_UNICODE);
            $headers = [
                'X-API-KEY' => $this->key,
                'Content-Type' => 'application/json',
                'Content-Length' => strlen($json_string),
            ];

            $args = [
                'body' => $json_string,
                'headers' => $headers,
            ];

            $response = wp_remote_post($url, $args);
            $status_code = wp_remote_retrieve_response_code($response);
            $json_data = (array)json_decode(wp_remote_retrieve_body($response), true);

            Logger::logDev('sendRequest', [
                'url'=>$url,
                'data'=>$data,
            ]);

            $invoiceAuthorization = null;
            if (array_key_exists('invoiceAuthorization', $json_data)) {
                $invoiceAuthorization = $json_data['invoiceAuthorization'];
            }

            Logger::logw('API CALL', [
                'url' => $url,
                'header' => json_encode($headers),
                'post' => $json_string,
                'statusCode' => $status_code,
                'response' => $json_data,
                'auth' => ($invoiceAuthorization === true ? 'true' : 'false'),
            ]);

            set_transient($cacheKey, json_encode(['response' => $json_data, 'status_code' => $status_code]), 5);
        }

        if ($status_code != 200 || isset($json_data['ErrorMessage']) || isset($json_data['errorMessage'])) {
            $errorMessage = '';
            $errorDescription = '';
            foreach ((array)$json_data as $key => $value) {
                if (strtolower($key) === 'errormessage') {
                    $errorMessage = $value;
                }
                if (strtolower($key) === 'errordescription') {
                    $errorDescription = $value;
                }
            }
            Logger::logw("API Error: $errorMessage $status_code", (array)$json_data);

            $errorRecipient = get_option('wc_riskcube_error_recipient');

            if ($errorRecipient) {
                $timeStamp = new DateTime();
                $subject = 'New Riskcube Error on ' . get_bloginfo('name') . ' at ' . $timeStamp->format('Y-m-d h:i:s');
                $cancel_url = get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=settings_tab_riskcube#wc_riskcube_error_recipient';

                $message = 'Request Route: ' . $url . PHP_EOL;
                $orderId = $this->getOrderId($data);
                if ($orderId) {
                    $message .= 'Order ID: ' . $orderId . PHP_EOL;
                }
                $message .= 'Error Code: ' . $status_code . PHP_EOL;
                $message .= 'Message: ' . $errorMessage . PHP_EOL;
                $message .= 'Description: ' . $errorDescription . PHP_EOL;
                $message .= 'Time: ' . $timeStamp->format('Y-m-d h:i:s') . PHP_EOL . PHP_EOL;
                $message .= 'Response Json: ' . PHP_EOL . json_encode($json_data) . PHP_EOL . PHP_EOL;
                $message .= 'To stop receiving theses emails visit ' . $cancel_url . ' and remove your address from the textfield';

                wp_mail($errorRecipient, $subject, $message);
            }

            return false;
        }

        return $json_data;
    }

    private function getOrderId(array $data)
    {
        $re = '/[o|O][r|R][d|D][e|E][r|R](_?-?\.?)[i|I][d|D]/m';
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $ret = $this->getOrderId($value);
                if ($ret) {
                    return $ret;
                }
            }
            $matches = [];
            preg_match_all($re, $key, $matches);
            if ($key && $matches) {
                return $value;
            }
        }

        return false;
    }

    protected function hasUsableName($address)
    {
        return $address['company'] || ($address['first_name'] && $address['last_name']);
    }

    /**
     * Generate URL
     * @param $uri
     * @param array $data
     * @return string
     */
    protected function getURL($uri, array $data = []): string
    {
        $uri = trim($uri, '/');
        $url = $this->api . $uri;

        if (count($data) > 0) {
            $url .= '?' . http_build_query($data);
        }

        return $url;
    }

    /**
     * Gets order's coupons
     * @param WC_Abstract_Order $order
     * @return array
     */
    protected static function getCouponObjectsForOrder(WC_Abstract_Order $order)
    {
        global $woocommerce;

        $coupon_objs = [];

        if (version_compare($woocommerce->version, '3.7.0', '<')) {
            $order_coupons = $order->get_used_coupons();
        } else {
            $order_coupons = $order->get_coupon_codes();
        }

        foreach ($order_coupons as $coupon_code) {
            $coupon_post_obj = get_page_by_title($coupon_code, OBJECT, 'shop_coupon');
            $coupon_id = $coupon_post_obj->ID;

            $coupon = new WC_Coupon($coupon_id);
            $coupon_objs[] = $coupon;
        }

        return $coupon_objs;
    }

    protected function getPaymentMethod(WC_Order $order)
    {
        $pm = $order->get_payment_method();

        $mapped_pm = get_option('wc_riskcube_payment_method_' . $pm);

        return $mapped_pm ?: 'others';
    }
}
