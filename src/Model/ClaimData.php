<?php

namespace Cube\Model;

use Cube\Core\Connector;

class ClaimData
{
    public ?string $shopId = null;
    public ?string $orderProcessId = null;
    public ?string $ipAddress = null;
    public ?string $macAddress = null;
    public ?string $customerId = null;
    public ?CustomerAddress $billingAddress = null;
    public ?CustomerAddress $shippingAddress = null;
    public ?float $orderAmount = null;

    public function __construct()
    {
        $mode = get_option('wc_riskcube_service_type', Connector::MODE_FK);
        if (Connector::MODE_ZS === $mode) {
            $this->shopId = get_option('wc_riskcube_zs_api_id', 0);
        } else {
            $this->shopId = get_option('wc_riskcube_api_id', 0);
        }
    }
}