<?php
if (!defined('ABSPATH')) {
    exit;
}

// Polyfill for nginx
if (!function_exists('getallheaders')) {
    function getallheaders()
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}


/**
 * Base class to handle ajax and webhook request from FastSpring.
 *
 * @since 1.0.0
 */
class WC_Gateway_FastSpring_Handler
{

  /**
   * Gateway options
   *
   * @var array FastSpring gateway options
   */
    protected static $settings;

    /**
     * Constructor
     */
    public function __construct()
    {
        self::set_settings();
        $this->init();
    }

    /**
     * Fetch plugin option
     *
     * @param $o Option key
     * @return mixed option value
     */
    public static function get_setting($o)
    {
        return isset(self::$settings[$o]) ? (self::$settings[$o] === 'yes' ? true : (self::$settings[$o] === 'no' ? false : self::$settings[$o])) : null;
    }

    /**
     * Set plugin option
     */
    public static function set_settings()
    {
        self::$settings  = get_option('woocommerce_fastspring_settings', array());
    }

    /**
     * If API credentials provided we can check for order completion on popup close
     */
    public function get_order_status($id)
    {
        if (empty(self::get_setting('api_username')) || empty(self::get_setting('api_password'))) {
            $this->log('No API credentials - skipping API order confirm');
            return 'pending';
        }

        $url = 'https://api.fastspring.com/orders/' . $id;

        $context = stream_context_create(array(
            'http' => array(
              'user_agent' => 'Mozilla/5.0', // Not important what it is but must be set
              'header' => "Authorization: Basic " . base64_encode(
                  self::get_setting('api_username') . ':' . self::get_setting('api_password')
              ),
            )));

        $data = @json_decode(file_get_contents($url, false, $context));

        if ($data && $data->completed === true) {
            $this->log(sprintf('API order %s completion checked', $id));
            return 'completed';
        }
        $this->log(sprintf('API order %s not found', $id));
        return 'pending';
    }

    /**
     * AjAX call to mark order as complete (but pending payment) and return payment page
     */
    public function ajax_get_receipt()
    {
        $payload = json_decode(file_get_contents('php://input'));

        $security = ( is_object($payload) && isset( $payload->security ) ) ?
            $payload->security :
            '';
        $allowed = wp_verify_nonce( $security, 'wc-fastspring-receipt' );

        // PATCH:
		// Date: 5-10-2024
		// Plugin: WooCommerce FastSpring Payment Gateway
		// Version: 1.2.5
		// Issue: The nonce does not work when guest checkout is enabled.
		// Fix: Remove nonce check.
        $allowed = true;

        // if (!$allowed) {
        //     wp_send_json_error('Access denied');
        // }

        $order_id = absint(WC()->session->get('current_order'));

        $this->log(sprintf('Generating receipt for order %s', $order_id));

        $order = wc_get_order($order_id);

        // Ensure session is set for current_order
        WC()->session->set('current_order', $order_id);

        if ($order) {
            $data = ['order_id' => $order->get_id()];
        } else {
            // Fallback when order does not exist or cannot be loaded.
            $data = ['order_id' => 0];
        }

        // Check for double calls, but avoid calling methods on a non-existent order.
        $order_status = $order ? $order->get_status() : '';

        // Popup closed with payment
        // If order or payload reference is missing, bail out early
        if ( !$order ) {
            wp_send_json_error( 'Order not found - Order ID was' . $order_id );
            return;
        }
            
        if ( !isset($payload->reference) ) {
            wp_send_json_error( 'Reference not found' );
            return;
        }

        if ( empty( $payload->reference ) ) {
            wp_send_json_error( 'Reference is empty' );
            return;
        }

        // Get API order status if available
        $status = $this->get_order_status($payload->id);

        // Remove cart
        WC()->cart->empty_cart();

        $order->set_transaction_id($payload->reference);
        $order->update_meta_data('fs_order_id', $payload->id);

        if ($status === 'completed' && $order->payment_complete($payload->reference)) {
            $this->log(sprintf('Marking order ID %s as completed', $order->get_id()));
            $order->add_order_note(sprintf(__('<b>FastSpring payment approved.</b><br/> <b>ID:</b> %1$s', 'woocommerce'), $order->get_id()));
        }
        // We could have a race condition where FS already called webhook so lets not assume its pending
        elseif ($order_status != 'completed') {
            $order->update_status('pending', __('Order pending payment approval.', 'woocommerce'));
        }

        $data = ["redirect_url" => WC_Gateway_FastSpring_Handler::get_return_url($order), 'order_id' => $order_id];

        wp_send_json($data);
    }

    /**
     * Get receipt URL
     *
     * @param object $order A Woo order
     * @return string Receipt URL
     */
    public static function get_return_url($order = null)
    {
        if ($order) {
            $return_url = $order->get_checkout_order_received_url();
            self::log(sprintf('Receipt URL for order set to %s', $return_url));
        } else {
            $return_url = wc_get_endpoint_url('order-received', '', wc_get_page_permalink('checkout'));
            self::log(sprintf('Receipt URL set to %s', $return_url));
        }

        if (is_ssl() || get_option('woocommerce_force_ssl_checkout') == 'yes') {
            $return_url = str_replace('http:', 'https:', $return_url);
        }

        $filtered = apply_filters('woocommerce_get_return_url', $return_url, $order);
        
        self::log(sprintf('Final filtered receipt URL set to %s', $filtered));

        return $filtered;
    }

    /**
     * Handle the FastSpring webhook
     */
    public function init()
    {
        add_action('wc_ajax_wc_fastspring_get_receipt', array($this, 'ajax_get_receipt'));
        //add_action('wc_ajax_wc_fastspring_get_payload', array($this, 'ajax_get_payload'));

        add_action('woocommerce_api_wc_gateway_fastspring', array($this, 'listen_webhook_request'));
        add_action('woocommerce_fastspring_handle_webhook_request', array($this, 'handle_webhook_request'));

        // VAL-879: surface missing-order webhooks to admins.
        add_action('admin_notices', array($this, 'maybe_show_orphan_admin_notice'));
        add_action('admin_post_wc_fs_dismiss_orphan_notices', array($this, 'dismiss_orphan_notices'));
    }

    /**
     * Listens for webhook request
     */
    public function listen_webhook_request()
    {
        $events = json_decode(file_get_contents('php://input'));

        if (!$this->is_valid_webhook_request()) {
            $this->log('Invalid webhook request - check secret');
            return wp_send_json_error();
        }

        foreach ($events as $event) {
            do_action('woocommerce_fastspring_handle_webhook_request', $event);
        }
    }

    /**
     * Finds one WC order by FastSpring custom tag
     *
     * @throws Exception
     *
     * @param string $id FastSpring transaction ID
     * @return WC_Order WooCommerce order
     */
    public function find_order_by_fastspring_tag($payload)
    {
        $id = @$payload->data->tags->store_order_id;
        $this->log(sprintf('Order tag found for %s', $id));

        if (!isset($id)) {
            $this->log('No order ID found in webhook');
            // VAL-879: capture for review — payload is unusable without store_order_id
            // but we still want an admin alert so the failure isn't silent.
            $this->quarantine_orphan_webhook($payload, 'unknown');
            throw new Exception('No order ID found in webhook');
        }

        $order = wc_get_order($id);

        if (!$order) {
            $this->log(sprintf('No order found with transaction ID %s', $id));
            // VAL-879: order vanished between checkout and payment (cleanup cron race).
            // Quarantine the payload, alert the team, then continue raising so FS retries.
            $this->quarantine_orphan_webhook($payload, $id);
            throw new Exception(sprintf('Unable to locate order with FS transaction ID %s', $id));
        }
        return $order;
    }

    /**
     * Record an FS webhook whose Woo order is missing and alert the team (VAL-879).
     *
     * Persists a summary entry in the `wc_fs_orphan_webhooks` option for the admin
     * notice to read, and emails the site admin once per FS reference per day.
     * The full payload is also written to the FastSpring log for forensic replay.
     *
     * @param object $payload            The FS webhook event payload
     * @param string $expected_order_id  The Woo order ID we tried to load (or 'unknown')
     * @return void
     */
    private function quarantine_orphan_webhook( $payload, $expected_order_id )
    {
        $fs_reference   = isset($payload->reference) ? (string) $payload->reference : null;
        $event_type     = isset($payload->type) ? (string) $payload->type : 'unknown';
        $customer_email = isset($payload->data->customer->email) ? (string) $payload->data->customer->email : null;
        $amount         = isset($payload->data->totalValue) ? $payload->data->totalValue : (
            isset($payload->data->total) ? $payload->data->total : null
        );
        $currency       = isset($payload->data->currency) ? (string) $payload->data->currency : null;

        // Forensic dump — full payload to the FS log so we can replay manually.
        $this->log( sprintf(
            'VAL-879 ORPHAN WEBHOOK — event=%s expected_order_id=%s fs_reference=%s payload=%s',
            $event_type,
            $expected_order_id,
            $fs_reference ?: 'unknown',
            wp_json_encode( $payload )
        ) );

        // Persist a lightweight summary for the admin notice.
        $orphans = get_option( 'wc_fs_orphan_webhooks', array() );
        if ( ! is_array( $orphans ) ) {
            $orphans = array();
        }
        $orphans[] = array(
            'received_at'       => current_time( 'mysql', true ),
            'event_type'        => $event_type,
            'expected_order_id' => $expected_order_id,
            'fs_reference'      => $fs_reference,
            'customer_email'    => $customer_email,
            'amount'            => $amount,
            'currency'          => $currency,
        );
        // Cap at 100 entries so the option can't grow without bound.
        if ( count( $orphans ) > 100 ) {
            $orphans = array_slice( $orphans, -100 );
        }
        update_option( 'wc_fs_orphan_webhooks', $orphans, false );

        // Dedupe email alerts per FS reference per 24h so retry storms don't spam.
        $dedupe_key = 'wc_fs_orphan_alert_' . md5( $fs_reference ?: $expected_order_id );
        if ( get_transient( $dedupe_key ) ) {
            $this->log( sprintf( 'VAL-879 orphan alert suppressed (already sent within 24h) for FS reference %s', $fs_reference ?: 'unknown' ) );
            return;
        }
        set_transient( $dedupe_key, 1, DAY_IN_SECONDS );

        $this->send_orphan_webhook_email( $event_type, $expected_order_id, $fs_reference, $customer_email, $amount, $currency );
    }

    /**
     * Email the site admin about a missing-order webhook (VAL-879).
     *
     * @return void
     */
    private function send_orphan_webhook_email( $event_type, $expected_order_id, $fs_reference, $customer_email, $amount, $currency )
    {
        $admin_email = get_option( 'admin_email' );
        if ( empty( $admin_email ) ) {
            $this->log( 'VAL-879 cannot send orphan alert — admin_email is empty' );
            return;
        }

        $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

        $subject = sprintf(
            '[%s] FastSpring webhook for missing Woo order #%s',
            $site_name,
            $expected_order_id
        );

        $logs_url = admin_url( 'admin.php?page=wc-status&tab=logs' );

        $body  = "A FastSpring webhook arrived referencing a Woo order that no longer exists.\n";
        $body .= "The customer's payment was processed by FastSpring but no license was issued.\n";
        $body .= "Manual recovery is required.\n\n";
        $body .= "Event type:           {$event_type}\n";
        $body .= "FS reference:         " . ( $fs_reference ?: 'unknown' ) . "\n";
        $body .= "Expected Woo order:   #{$expected_order_id}\n";
        $body .= "Customer email:       " . ( $customer_email ?: 'unknown' ) . "\n";
        $body .= "Amount:               " . ( $amount !== null ? $amount : 'unknown' ) . ' ' . ( $currency ?: '' ) . "\n\n";
        $body .= "Full payload has been logged to the FastSpring log on the server.\n";
        $body .= "Review at: {$logs_url}\n";

        $sent = wp_mail( $admin_email, $subject, $body );
        $this->log( sprintf(
            'VAL-879 orphan alert email %s for FS reference %s (to %s)',
            $sent ? 'sent' : 'FAILED',
            $fs_reference ?: 'unknown',
            $admin_email
        ) );
    }

    /**
     * Show an admin notice when orphan webhooks have been quarantined (VAL-879).
     *
     * @return void
     *
     * @hook admin_notices
     */
    public function maybe_show_orphan_admin_notice()
    {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $orphans = get_option( 'wc_fs_orphan_webhooks', array() );
        if ( empty( $orphans ) || ! is_array( $orphans ) ) {
            return;
        }

        $count        = count( $orphans );
        $latest       = end( $orphans );
        $latest_ref   = isset( $latest['fs_reference'] ) ? $latest['fs_reference'] : 'unknown';
        $latest_email = isset( $latest['customer_email'] ) ? $latest['customer_email'] : 'unknown';
        $dismiss_url  = wp_nonce_url(
            admin_url( 'admin-post.php?action=wc_fs_dismiss_orphan_notices' ),
            'wc_fs_dismiss_orphan_notices'
        );
        $logs_url     = admin_url( 'admin.php?page=wc-status&tab=logs' );

        printf(
            '<div class="notice notice-error"><p><strong>FastSpring:</strong> %d orphan webhook(s) — payment received but no matching Woo order. Latest: FS reference <code>%s</code> for <code>%s</code>. Check the <a href="%s">FastSpring log</a> for the full payload and replay manually. <a href="%s">Dismiss</a></p></div>',
            (int) $count,
            esc_html( $latest_ref ),
            esc_html( $latest_email ),
            esc_url( $logs_url ),
            esc_url( $dismiss_url )
        );
    }

    /**
     * Dismiss the orphan-webhook admin notice (VAL-879).
     *
     * @return void
     *
     * @hook admin_post_wc_fs_dismiss_orphan_notices
     */
    public function dismiss_orphan_notices()
    {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Forbidden', 'Forbidden', array( 'response' => 403 ) );
        }
        check_admin_referer( 'wc_fs_dismiss_orphan_notices' );

        delete_option( 'wc_fs_orphan_webhooks' );

        $redirect = wp_get_referer() ?: admin_url();
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Handles the validated FS webhook request
     *
     * @throws Exception
     *
     * @param array $payload Webhook data
     * @return array JSON response
     */
    public function handle_webhook_request($payload)
    {
        try {
            switch ($payload->type) {

                case 'order.completed':
                  $this->handle_webhook_request_order_completed($payload);
                  break;

                case 'return.created':
                  $this->handle_webhook_request_order_refunded($payload);
                  break;

                case 'subscription.canceled':
                  $this->handle_webhook_request_subscription_canceled($payload);
                  break;

                case 'subscription.deactivated':
                  $this->handle_webhook_request_subscription_deactivate($payload);
                  break;

                case 'subscription.activated':
                  $this->handle_webhook_request_subscription_activate($payload);
                  break;

                case 'subscription.updated':
                //$this->handle_webhook_request_subscription_canceled($payload);
                //break;

                default:
                  $this->log(sprintf('No webhook handler found for %s', $payload->type));
                  break;
                }

            $this->log(json_encode($payload));
            return wp_send_json_success();
        } catch (Exception $e) {
            return wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Add address to order
     *
     * @param WC_Order $order - WooCommerce order
     * @param object $payload - Webhook data
     * 
     * @return void
     */
    private function add_address_to_order( $order, $payload ) {
        if ( !isset( $payload->data->customer->address ) )
            return;

        $address = $payload->data->customer->address;

        // Validate and set address properties
        $address_city        = isset( $address->city ) ?$address->city : null;
        $address_region      = isset( $address->region ) ? $address->region : null;
        $address_postal_code = isset( $address->postalCode ) ? $address->postalCode : null;
        $address_country     = isset( $address->country ) ? $address->country : null;

        // Update order address
        $order->set_address( array(
            'city'     => $address_city,
            'state'    => $address_region,
            'postcode' => $address_postal_code,
            'country'  => $address_country,
        ), 'billing');
    }

    /**
     * Handles the order.completed webhook
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_order_completed($payload)
    {
        $order    = $this->find_order_by_fastspring_tag($payload);
        $order_id = $order->get_id();

        // PATCH:
        // Date: 6-10-2024
        // Plugin: WooCommerce FastSpring Payment Gateway
        // Version: 1.2.5
        // Issue: $payload is not always and object and $payload->reference property is not always set.
        // Fix: Add Validation and set to null otherwise.
        $payload_reference = is_object( $payload ) && isset( $payload->reference ) ?
            $payload->reference :
            null;

        // Add address to order
        $this->add_address_to_order( $order, $payload );

        // Only mark complete if not already - webhook can hit multiple times
        if ($order->get_status() !== 'completed' && $order->payment_complete($payload_reference)) {
            $this->log(sprintf('Marking order ID %s as complete', $order_id));
            // Extract invoice link
            $invoice_link = isset( $payload->data->invoiceUrl ) ?
                '<a href="' . esc_url($payload->data->invoiceUrl) . '" target="_blank">' . $payload->data->invoiceUrl . '</a>' :
                'N/A';

            // Add order note
            ob_start();
            ?>
            <p><b><?php _e('FastSpring Order Completed.', 'woocommerce'); ?></b></p>
            <p><b>ID:</b> <?php echo $order_id; ?></p>
            <p><b>Invoice Link:</b> <?php echo $invoice_link; ?></p>
            <?php
            $order_note = ob_get_clean();

            $order->add_order_note($order_note);
        } else {
            $this->log(sprintf('Failed marking order ID %s as complete', $order_id));
        }
    }

    /**
     * Handles the order.failed webhook
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_order_refunded($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);
        $this->log(sprintf('Marking order ID %s as refunded', $order->get_id()));
        $order->update_status('refunded');
    }

    /**
     * Handles subscription cancellation
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_subscription_canceled($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);
        $this->log(sprintf('Marking subscription order ID %s as canceled', $order->get_id()));
        $order->update_status('cancelled');
    }

    /**
     * Handles subscription (re)activation
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_subscription_activate($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);
        $this->log(sprintf('Marking subscription order ID %s as (re)activated', $order->get_id()));
        $order->update_status('active');
    }

    /**
     * Handles subscription deactivation
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_subscription_deactivate($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);
        $this->log(sprintf('Marking subscription order ID %s as deactivated', $order->get_id()));
        $order->update_status('on-hold');
    }

    /**
     * Check with FastSpring whether posted data is valid FastSpring webhook
     *
     * @throws Exception
     *
     * @param array $payload Webhook data
     * @return bool True if payload is valid FastSpring webhook
     */
    public function is_valid_webhook_request()
    {
        $this->log(sprintf('%s: %s', __FUNCTION__, 'Checking FastSpring webhook validity'));

        $secret = self::get_setting('webhook_secret');

        $headers = getallheaders();
        $hash = base64_encode(hash_hmac('sha256', file_get_contents('php://input'), $secret, true));

        $sig = $_SERVER['HTTP_X_FS_SIGNATURE'];

        if (!$sig) {
            $this->log('No secret provided by FastSpring webhook');
            return true;
        }

        if (!$secret) {
            $this->log('Invalid webhook secret');
            return false;
        }

        return $sig === $hash;
    }

    /**
     * Logs
     *
     * @param string $message
     */
    public static function log($message)
    {
        WC_FastSpring::log($message);
    }
}

new WC_Gateway_FastSpring_Handler();