<?php

namespace Cube\Core;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use WC_Payment_Gateway;

if (!defined('WPINC')) {
    die();
}

class InvoiceGateway extends WC_Payment_Gateway implements IntegrationInterface
{
    public $domain = null;
    public $instructions = null;
    public $order_status = null;

    public function __construct()
    {
        $this->domain = 'custom_payment';
        $this->id = 'riskcube_invoice';
        $this->icon = apply_filters('woocommerce_custom_gateway_icon', '');
        $this->has_fields = false;
        $this->method_title = __('Invoice', $this->domain);
        $this->method_description = __('Allows payments with invoice (managed by RiskCube).', $this->domain);

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions', $this->description);
        $this->order_status = $this->get_option('order_status', 'completed');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
    }

    public function initialize() { }

    public function get_name(): string
    {
        return $this->title;
    }

    public function get_script_handles(): array
    {
        return [];
    }

    public function get_editor_script_handles(): array
    {
        return [];
    }

    public function get_script_data(): array
    {
        return [];
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', $this->domain),
                'type' => 'checkbox',
                'label' => __('Enable Invoice Payment', $this->domain),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Title', $this->domain),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', $this->domain),
                'default' => __('Invoice Payment (RiskCube)', $this->domain),
                'desc_tip' => true,
            ],
            'order_status' => [
                'title' => __('Order Status', $this->domain),
                'type' => 'select',
                'class' => 'wc-enhanced-select',
                'description' => __('Choose order status after checkout.', $this->domain),
                'default' => 'wc-completed',
                'desc_tip' => true,
                'options' => wc_get_order_statuses(),
            ],
            'description' => [
                'title' => __('Description', $this->domain),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see at checkout.', $this->domain),
                'default' => __('Payment Information', $this->domain),
                'desc_tip' => true,
            ],
            'instructions' => [
                'title' => __('Instructions', $this->domain),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page and emails.', $this->domain),
                'default' => '',
                'desc_tip' => true,
            ],
        ];
    }

    /**
     * Output for the order received page.
     */
    public function thankyou_page()
    {
        if ($this->instructions) {
            echo wp_kses(wpautop(wptexturize($this->instructions)), wp_kses_allowed_html());
        }
    }

    /**
     * Add content to the WC emails.
     * @access public
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false)
    {
        if ($this->instructions && !$sent_to_admin && 'custom' === $order->payment_method && $order->has_status('on-hold')) {
            echo wp_kses(wpautop(wptexturize($this->instructions)) . PHP_EOL, wp_kses_allowed_html());
        }
    }

    /**
     * Process the payment and return the result.
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);

        $status = 'wc-' === substr($this->order_status, 0, 3) ? substr($this->order_status, 3) : $this->order_status;

        // Set order status
        $order->update_status($status, __('Checkout with custom payment. ', $this->domain));

        // Reduce stock levels
        $order->reduce_order_stock();

        // Remove cart
        WC()->cart->empty_cart();

        // Return thankyou redirect
        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }
}
