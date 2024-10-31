<?php

namespace Cube\Helper;

use Cube\Core\Connector;
use Cube\Core\RiskCube;
use Cube\Model\ClaimData;
use Cube\Model\CustomerAddress;
use Exception;
use WC_Cart;
use WC_Customer;
use WC_Order;

class ClaimDataHelper
{
    public static function getMode()
    {
        return (int)get_option('wc_riskcube_service_type', Connector::MODE_FK);
    }

    public static function isZSMode()
    {
        return self::getMode() === Connector::MODE_ZS;
    }

    public static function prepareDataPost(): ClaimData
    {
        $data = new ClaimData();

        $shippingSameAsBilling = (bool)(isset($_POST['shipping_same_as_billing']) ? $_POST['shipping_same_as_billing'] : false) === true;
        
        $billing = self::getCustomerFromPostArray($_POST['shipping']);
        $shipping = self::getCustomerFromPostArray($_POST['billing']);

        $billing->email = $shipping->email;

        $data->orderProcessId = self::getSessionId();
        $data->ipAddress = self::getRealIpAddr();
        //$data->customerId = 1;
        //$data->customerId = get_current_user_id();
        $data->customerId = apply_filters('determine_current_user', false);
        $data->billingAddress = $billing;
        $data->orderAmount = filter_var($_POST['cart']['total_price'], FILTER_SANITIZE_NUMBER_INT) / 100;

        if (!$shippingSameAsBilling) {
            $data->shippingAddress = $shipping;
        }

        return $data;
    }

    public static function prepareDataOrder(WC_Order $order): ClaimData
    {
        $data = new ClaimData();

        $total = $order->get_total() - $order->get_total_refunded();
        $id_customer = $order->get_customer_id(); // note: not set for guests...
        $addresses = self::getAddressesFromOrder($order);

        $data->orderAmount = $total;
        $data->customerId = $id_customer;
        // $data->billingAddress = self::getCustomerFromPostArray($addresses['billing']);
        $billingAddress = self::getCustomerFromPostArray($addresses['billing']);
        // $data->shippingAddress = self::getCustomerFromPostArray($addresses['shipping']);
        $shippingAddress = self::getCustomerFromPostArray($addresses['shipping']);

        $data->billingAddress = $billingAddress;
        if (!$shippingAddress->isSameAs($billingAddress)) {
            $data->shippingAddress = self::getCustomerFromPostArray($addresses['shipping']);
        }

        $data->orderProcessId = $order->get_id();

        return $data;
    }

    public static function prepareDataCart(WC_Cart $cart): ClaimData
    {
        $data = new ClaimData();

        $totals = $cart->get_totals();
        $data->orderAmount = $totals['total'];

        $customer = $cart->get_customer();
        $customerData = self::getAddressesFromCustomer($customer);
        $data->orderProcessId = self::getSessionId();
        $data->ipAddress = self::getRealIpAddr();
        $data->customerId = $customer->get_id();
        $billing = self::getCustomerFromPostArray($customerData['billing']);
        $shipping = self::getCustomerFromPostArray($customerData['shipping']);
        $data->billingAddress = $billing;

        if (!$shipping->isSameAs($billing)) {
            $data->shippingAddress = $shipping;
        }

        return $data;
    }

    public static function verifyClaimData(ClaimData $data): bool
    {
        $isValid = true;

        // blacklist/whitelist checks
        if ($data->customerId && self::checkCustomerBlacklist($data->customerId)) {
            Logger::logw('CLAIM : CUSTOMER #' . $data->customerId . ' BLACKLISTED');
            return false;
        }
        //if ($data->customerId && self::checkCustomerWhitelist($data->customerId)) {
        //    Logger::logw('CLAIM : CUSTOMER #' . $data->customerId . ' WHITELISTED');
        //}

        // ZS min value check
        if (Connector::MODE_ZS === self::getMode()) { // Zahlartensteuerung
            $minValueCH = get_option('wc_riskcube_zs_min_val', 0);
            $minValueOther = get_option('wc_riskcube_zs_min_val2', 0);
            if ($data->billingAddress->country == 'CH' && $data->orderAmount < $minValueCH) {
                Logger::logw("CLAIM : SAME COUNTRY (CH) MIN VALUE (${minValueCH}) PASS");
                return false;
            }
            if ($data->billingAddress->country != 'CH' && $data->orderAmount < $minValueOther) {
                Logger::logw("CLAIM : OTHER COUNTRY MIN VALUE (${minValueOther}) PASS");
                return false;
            }
        }

        if (!$data->billingAddress->email) {
            Logger::logw('CLAIM : NO E-MAIL ADDRESS');
            return false;
        }

        if (!self::hasUsableName($data->billingAddress) || !$data->billingAddress->locationName || !$data->billingAddress->postCode || !$data->billingAddress->street) {
            Logger::logw('CLAIM : INCOMPLETE BILLING ADDRESS');
            return false;
        }

        if ($data->orderAmount < 1) {
            Logger::logw("CLAIM : MIN VALUE PASS");
            return false;
        }

        return $isValid;
    }

    // NXTLVL-191 using WC instead of making native sessions
    private static function getSessionId(?int $orderId = null): string
    {
        if ($orderId) {
            $session_id = md5(time());
        } else {
            $session = RiskCube::getSession();

            $session_id = $session->get('riskcube_session_id');
            if (!$session_id) {
                $session_id = md5(time());
                $session->set('riskcube_session_id', $session_id);
                $session->save_data();
            }

            if (!$session_id) {
                $session_id = md5(time());
            }

            if (!$session_id) {
                Logger::logw('CLAIM : NO SESSION TOKEN');
                throw new Exception('CLAIM : NO SESSION TOKEN');
            }
        }

        return $session_id;
    }

    private static function getRealIpAddr(): string
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) { // Check IP from internet.
            $result = sanitize_text_field($_SERVER['HTTP_CLIENT_IP']);
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) { // Check IP is passed from proxy.
            $result = sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']);
        } else { // Get IP address from remote address.
            $result = sanitize_text_field($_SERVER['REMOTE_ADDR']);
        }

        return $result;
    }


    public static function getCustomerFromPostArray(array $arrCustomer): CustomerAddress
    {
        $customer = new CustomerAddress();
        $customer->type = self::getVarSafe('company', $arrCustomer) ? 'Business' : 'Consumer';
        $customer->firstname = self::getVarSafe('first_name', $arrCustomer);
        $customer->lastname = self::getVarSafe('last_name', $arrCustomer);
        $customer->businessName = self::getVarSafe('company', $arrCustomer);
        $customer->phone = self::getVarSafe('phone', $arrCustomer);
        $customer->locationName = self::getVarSafe('city', $arrCustomer);
        $customer->street = self::getVarSafe('address_1', $arrCustomer) . ' ' . self::getVarSafe('address_2', $arrCustomer);
        $customer->email = self::getVarSafe('email', $arrCustomer);
        $customer->postCode = self::getVarSafe('postcode', $arrCustomer);
        $customer->country = self::getVarSafe('country', $arrCustomer);
        $customer->state = self::getVarSafe('state', $arrCustomer);

        return $customer;
    }

    public static function getVarSafe(string $key, array $arr): ?string
    {
        return key_exists($key, $arr) ? sanitize_text_field($arr[$key]) : null;
    }

    private static function hasUsableName(CustomerAddress $address): bool
    {
        return $address->businessName || ($address->firstname && $address->lastname);
    }

    public static function checkCustomerWhitelist($id_customer): bool
    {
        if (Connector::MODE_ZS !== self::getMode()) {
            return false;
        }

        // NXTLVL-223
        $entries = RiskCube::get_whitelist_entries();
        $ids = array_map(fn($it) => $it->ID, $entries);

        return in_array($id_customer, $ids);
        // return (Connector::MODE_ZS === self::$mode) && (get_post_meta($id_customer, 'riskcube_status', true) == 2);
    }

    public static function checkCustomerBlacklist($id_customer): bool
    {
        // NXTLVL-223
        $entries = RiskCube::get_blacklist_entries();
        $ids = array_map(fn($it) => $it->ID, $entries);

        return in_array($id_customer, $ids);
        // return get_post_meta($id_customer, 'riskcube_status', true) == 1;
    }

    public static function getAddressesFromCustomer(WC_Customer $customer): array
    {
        $billing_email = $customer->get_billing_email();
        $billing_phone = $customer->get_billing_phone();

        $postData = [];
        $postDataString = is_array($_POST) && array_key_exists('post_data', $_POST) ? $_POST['post_data'] ?? '' : '';
        parse_str(sanitize_text_field($postDataString), $postData);

        $billing = [
            'first_name' => $customer->get_billing_first_name(),
            'last_name' => $customer->get_billing_last_name(),
            'company' => $customer->get_billing_company(),
            'address_1' => $customer->get_billing_address_1(),
            'address_2' => $customer->get_billing_address_2(),
            'city' => $customer->get_billing_city(),
            'state' => $customer->get_billing_state(),
            'postcode' => $customer->get_billing_postcode(),
            'country' => $customer->get_billing_country(),
            'phone' => $billing_phone,
            'email' => $billing_email,
        ];

        if (key_exists('shipping_first_name', $_POST) && key_exists('shipping_last_name', $_POST)) {
            $shippingFirst = sanitize_text_field($_POST['shipping_first_name']);
            $shippingLast = sanitize_text_field($_POST['shipping_last_name']);
        } elseif (isset($postData['shipping_first_name']) && isset($postData['shipping_last_name'])) {
            $shippingFirst = sanitize_text_field($postData['shipping_first_name']);
            $shippingLast = sanitize_text_field($postData['shipping_last_name']);
        } else {
            $shippingFirst = $customer->get_billing_first_name();
            $shippingLast = $customer->get_billing_last_name();
        }
        if (!$shippingFirst) {
            $shippingFirst = $customer->get_billing_first_name();
        }
        if (!$shippingLast) {
            $shippingLast = $customer->get_billing_last_name();
        }

        $shipping = [
            'first_name' => $shippingFirst,
            'last_name' => $shippingLast,
            'company' => $customer->get_shipping_company(),
            'address_1' => $customer->get_shipping_address_1(),
            'address_2' => $customer->get_shipping_address_2(),
            'city' => $customer->get_shipping_city(),
            'state' => $customer->get_shipping_state(),
            'postcode' => $customer->get_shipping_postcode(),
            'country' => $customer->get_shipping_country(),
            'phone' => $billing_phone,
            'email' => $billing_email,
        ];

        return ['billing' => $billing, 'shipping' => $shipping];
    }

    private static function getAddressesFromOrder(WC_Order $order): array
    {
        $billing_email = $order->get_billing_email();
        $billing_phone = $order->get_billing_phone();

        $billing = [
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'company' => $order->get_billing_company(),
            'address_1' => $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'postcode' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
            'phone' => $billing_phone,
            'email' => $billing_email,
        ];

        $shipping = [
            'first_name' => $order->get_shipping_first_name(),
            'last_name' => $order->get_shipping_last_name(),
            'company' => $order->get_shipping_company(),
            'address_1' => $order->get_shipping_address_1(),
            'address_2' => $order->get_shipping_address_2(),
            'city' => $order->get_shipping_city(),
            'state' => $order->get_shipping_state(),
            'postcode' => $order->get_shipping_postcode(),
            'country' => $order->get_shipping_country(),
            'phone' => $billing_phone,
            'email' => $billing_email,
        ];

        return ['billing' => $billing, 'shipping' => $shipping];
    }
}