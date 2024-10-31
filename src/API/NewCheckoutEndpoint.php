<?php

namespace Cube\API;

use Cube\Core\Connector;
use Cube\Helper\ClaimDataHelper;

class NewCheckoutEndpoint
{
    protected static $initiated = false;

    public static function init()
    {
        if (!self::$initiated) {
            add_action('rest_api_init', function () {
                register_rest_route('riskcube/v1', '/verify-customer-data', [
                    'methods' => 'POST',
                    'callback' => [__CLASS__, 'verify_customer_data'],
                    'permission_callback' => function () {
                        return true;
                    },
                ]);
            });
        }
    }

    public static function verify_customer_data(): array
    {
        // NXTLVL-223 - Check Whitelist
        // Does not work:
        // $user_id = get_current_user_id();
        // See: https://stackoverflow.com/questions/36113060/get-current-user-id-returning-zero-0
        //
        $user_id = apply_filters('determine_current_user', false);
        if ($user_id && ClaimDataHelper::checkCustomerWhitelist($user_id)) {
            return ['allowInvoice' => true];
        }

        if ($user_id && ClaimDataHelper::checkCustomerBlacklist($user_id)) {
            return ['allowInvoice' => false];
        }

        $data = ClaimDataHelper::prepareDataPost();
        $rca = new Connector();
        $res = $rca->doClaim($data);
        $allowInvoices = Connector::isInvoiceAuthorization($res);

        return ['allowInvoice' => $allowInvoices];
    }
}