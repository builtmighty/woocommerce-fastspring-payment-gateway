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
     * Confirm with the FastSpring Orders API that a FastSpring order paid for a Woo order (VAL-924)
     *
     * Neither the webhook body nor the browser can be trusted to say an order is paid, so
     * both completion paths ask FastSpring directly. The FastSpring order must be completed,
     * live (or a test order when the gateway is in test mode), tagged with this Woo order's
     * ID, in the same currency and for the same amount.
     *
     * @param string   $fs_order_id FastSpring order ID. The API does not accept order references.
     * @param WC_Order $order       Woo order being paid
     * @return array {
     *     @type bool   $ok          True if the payment is confirmed
     *     @type bool   $transient   True if the check couldn't be completed and is worth retrying
     *     @type bool   $pending     True if FastSpring has the order but it isn't completed (yet)
     *     @type string $reason      Why the payment wasn't confirmed
     *     @type string $fs_order_id FastSpring order ID that was checked
     *     @type string $reference   FastSpring order reference, used as the transaction ID
     * }
     */
    public function confirm_fastspring_payment($fs_order_id, $order)
    {
        $fs_order_id = is_scalar($fs_order_id) ? trim((string) $fs_order_id) : '';
        $result      = array(
            'ok'          => false,
            'transient'   => false,
            'pending'     => false,
            'reason'      => '',
            'fs_order_id' => $fs_order_id,
            'reference'   => '',
        );

        // The ID is concatenated into the API URL, and on the browser path it comes from the client.
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $fs_order_id)) {
            $result['reason'] = 'missing or malformed FastSpring order ID';
            return $result;
        }

        if (empty(self::get_setting('api_username')) || empty(self::get_setting('api_password'))) {
            $result['transient'] = true;
            $result['reason']    = 'no FastSpring API credentials in the gateway settings';
            return $result;
        }

        $fs_order = null;

        for ($attempt = 1; $attempt <= 2 && !$fs_order; $attempt++) {
            if ($attempt > 1) {
                sleep(2);
            }
            $result['pending'] = false;

            $response = wp_remote_get('https://api.fastspring.com/orders/' . rawurlencode($fs_order_id), array(
                'timeout' => 10,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode(self::get_setting('api_username') . ':' . self::get_setting('api_password')),
                    'Accept'        => 'application/json',
                    // FastSpring rejects API requests without a User-Agent
                    'User-Agent'    => 'WooCommerce-FastSpring-Gateway/' . WC_FASTSPRING_VERSION,
                ),
            ));

            if (is_wp_error($response)) {
                $result['transient'] = true;
                $result['reason']    = 'FastSpring API unreachable: ' . $response->get_error_message();
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $data = json_decode(wp_remote_retrieve_body($response));

            // A known ID returns the order itself. An unknown one returns HTTP 400 with an "orders" error list.
            if (is_object($data) && isset($data->orders) && is_array($data->orders)) {
                $data = reset($data->orders);
            }

            if (200 === $code && is_object($data) && isset($data->id, $data->completed)) {
                if (true === $data->completed) {
                    $fs_order = $data;
                    continue;
                }
                // FastSpring may not show a just-paid order as completed yet
                $result['transient'] = true;
                $result['pending']   = true;
                $result['reason']    = 'FastSpring order is not completed';
                continue;
            }

            if (429 === $code || $code >= 500) {
                $result['transient'] = true;
                $result['reason']    = sprintf('FastSpring API returned HTTP %d', $code);
                continue;
            }

            // 401/403 means the API credentials need fixing, after which a retry will work
            $result['transient'] = in_array($code, array(401, 403), true);
            $result['reason']    = in_array($code, array(401, 403), true) ?
                sprintf('FastSpring API rejected the gateway\'s API credentials (HTTP %d)', $code) :
                sprintf('FastSpring order not found (HTTP %d)', $code);
            return $result;
        }

        if (!$fs_order) {
            return $result;
        }

        // A first attempt may have failed before the retry succeeded
        $result['transient'] = false;
        $result['reason']    = '';

        $testmode = (bool) self::get_setting('testmode');
        if ((bool) $fs_order->live === $testmode) {
            $result['reason'] = $testmode ? 'live FastSpring order while the gateway is in test mode' : 'FastSpring test order';
            return $result;
        }

        $tag = isset($fs_order->tags->store_order_id) ? (string) $fs_order->tags->store_order_id : '';
        if ($tag !== (string) $order->get_id()) {
            $result['reason'] = sprintf('FastSpring order belongs to Woo order %s', '' !== $tag ? $tag : '(none)');
            return $result;
        }

        $currency = isset($fs_order->currency) ? strtoupper((string) $fs_order->currency) : '';
        if ($currency !== strtoupper($order->get_currency())) {
            $result['reason'] = sprintf('currency mismatch: FastSpring %s, order %s', $currency, $order->get_currency());
            return $result;
        }

        // FastSpring's subtotal is after discounts and before tax; tax is FastSpring's, so the Woo
        // total excludes it. A temp order doesn't carry its coupons and gift cards until the popup
        // closes, so a webhook that arrives first is matched on the pre-discount amounts instead.
        $fs_subtotal = isset($fs_order->subtotal) ? (float) $fs_order->subtotal : -1;
        $fs_discount = isset($fs_order->discount) ? (float) $fs_order->discount : 0;
        $tolerance   = 0.01 * max(1, count($order->get_items()));

        $paid_matches         = abs($fs_subtotal - (float) $order->get_total()) <= $tolerance;
        $pre_discount_matches = WC_Gateway_FastSpring_Orders::is_temp_order($order) &&
            abs(($fs_subtotal + $fs_discount) - (float) $order->get_subtotal()) <= $tolerance;

        if (!$paid_matches && !$pre_discount_matches) {
            $result['reason'] = sprintf(
                'amount mismatch: FastSpring subtotal %s (discount %s), order total %s (subtotal %s)',
                wc_format_decimal($fs_subtotal, 2),
                wc_format_decimal($fs_discount, 2),
                wc_format_decimal($order->get_total(), 2),
                wc_format_decimal($order->get_subtotal(), 2)
            );
            return $result;
        }

        $result['ok']        = true;
        $result['reference'] = isset($fs_order->reference) ? (string) $fs_order->reference : '';
        $this->log(sprintf('FastSpring payment %s (%s) confirmed for order %s', $fs_order_id, $result['reference'], $order->get_id()));

        return $result;
    }

    /**
     * Record and report a payment FastSpring didn't confirm (VAL-924)
     *
     * @param WC_Order $order        Woo order
     * @param array    $confirmation Result of confirm_fastspring_payment()
     * @param string   $source       Which completion path asked: 'webhook' or 'browser return'
     * @return void
     */
    private function handle_unconfirmed_payment($order, $confirmation, $source)
    {
        $message = sprintf(
            'VAL-924 FastSpring payment %s not confirmed for order %s (%s): %s',
            '' !== $confirmation['fs_order_id'] ? $confirmation['fs_order_id'] : '(none)',
            $order->get_id(),
            $source,
            $confirmation['reason']
        );

        // A transient failure is retried: FastSpring resends the webhook, or the webhook completes the order.
        // Only API trouble is worth an alert; an order that isn't completed yet isn't a fault.
        if ($confirmation['transient']) {
            $this->log($message, 'warning');
            if ($confirmation['pending']) {
                return;
            }
            $this->send_admin_alert(
                'confirm_transient',
                6 * HOUR_IN_SECONDS,
                'FastSpring payment checks are failing',
                $message . "\n\nThe order stays unpaid until FastSpring confirms the payment. FastSpring retries webhooks for up to 7 days.\nRepeated failures usually mean the FastSpring API is down or the API credentials in the gateway settings no longer work.\nFurther alerts like this are suppressed for 6 hours."
            );
            return;
        }

        $this->log($message, 'error');
        $order->add_order_note(sprintf(
            __('FastSpring payment not confirmed (%1$s): %2$s. The order was not marked paid.', 'woocommerce-gateway-fastspring'),
            $source,
            esc_html($confirmation['reason'])
        ));
        $this->send_admin_alert(
            'confirm_order_' . $order->get_id(),
            DAY_IN_SECONDS,
            sprintf('FastSpring payment not confirmed for order #%s', $order->get_id()),
            $message . "\n\nThe order was left unpaid and no license was issued.\nIf the customer did pay, find the order in FastSpring, check it matches, and complete the Woo order manually.\nOrder: " . $order->get_edit_order_url()
        );
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

        // VAL-924: the FastSpring order ID comes from the browser, so confirm with FastSpring
        // that it's a completed payment for this order before marking the order paid. An order
        // the webhook already completed needs no second check.
        $confirmation = $order_status != 'completed' ?
            $this->confirm_fastspring_payment(isset($payload->id) ? $payload->id : '', $order) :
            array('ok' => false);

        // Remove cart
        WC()->cart->empty_cart();

        if ($confirmation['ok']) {
            $order->update_meta_data('fs_order_id', $confirmation['fs_order_id']);
        } elseif ($order_status != 'completed') {
            $this->handle_unconfirmed_payment($order, $confirmation, 'browser return');
        }

        if ($confirmation['ok'] && $order->payment_complete($confirmation['reference'])) {
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
        $raw_body = file_get_contents('php://input');

        if (!$this->is_valid_webhook_request($raw_body)) {
            // VAL-924: any non-2xx makes FastSpring retry for up to 7 days, so a genuine
            // webhook rejected by mistake is delayed rather than lost
            return wp_send_json_error('Invalid webhook signature', 401);
        }

        $events = json_decode($raw_body);

        if (!is_object($events) && !is_array($events)) {
            $this->log('Webhook body is not valid JSON', 'warning');
            return wp_send_json_error('Invalid webhook payload', 400);
        }

        // VAL-924: FastSpring also sends test-mode events to the live site (and live events to
        // staging). Their order tags are another environment's order IDs, which here belong to
        // unrelated orders, so only events from this site's own mode reach any handler.
        if (is_object($events) && isset($events->events) && is_array($events->events)) {
            $live_mode = !self::get_setting('testmode');
            $kept      = array();
            $ignored   = array();

            foreach ($events->events as $event) {
                if (is_object($event) && isset($event->live) && (bool) $event->live !== $live_mode) {
                    $ignored[] = $event;
                    continue;
                }
                $kept[] = $event;
            }

            if ($ignored) {
                $this->log(sprintf(
                    'Ignored %s-mode webhook event(s) because the gateway is in %s mode: %s',
                    $live_mode ? 'test' : 'live',
                    $live_mode ? 'live' : 'test',
                    $this->describe_events($ignored)
                ));
            }

            if (!$kept) {
                return wp_send_json_success();
            }

            $events->events = $kept;
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
            // VAL-924: a 503 code asks FastSpring to retry; everything else keeps the 200 it always had
            return wp_send_json_error($e->getMessage(), 503 === $e->getCode() ? 503 : null);
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
     * @param object $payload Webhook event
     */
    public function handle_webhook_request_order_completed($payload)
    {
        $order    = $this->find_order_by_fastspring_tag($payload);
        $order_id = $order->get_id();

        // Add address to order
        $this->add_address_to_order( $order, $payload );

        // Only mark complete if not already - webhook can hit multiple times
        if ($order->get_status() === 'completed') {
            $this->log(sprintf('Order ID %s is already complete', $order_id));
            return;
        }

        // VAL-924: never complete an order on the webhook's word; confirm the payment with FastSpring first
        $fs_order_id  = isset($payload->data->id) ? $payload->data->id : (isset($payload->data->order) ? $payload->data->order : '');
        $confirmation = $this->confirm_fastspring_payment($fs_order_id, $order);

        if (!$confirmation['ok']) {
            $this->handle_unconfirmed_payment($order, $confirmation, 'webhook');
            throw new Exception(
                sprintf('FastSpring payment not confirmed: %s', $confirmation['reason']),
                $confirmation['transient'] ? 503 : 0
            );
        }

        $order->update_meta_data('fs_order_id', $confirmation['fs_order_id']);

        if ($order->payment_complete($confirmation['reference'])) {
            $this->log(sprintf('Marking order ID %s as complete', $order_id));
            // Extract invoice link
            $invoice_link = isset( $payload->data->invoiceUrl ) ?
                '<a href="' . esc_url($payload->data->invoiceUrl) . '" target="_blank">' . esc_html($payload->data->invoiceUrl) . '</a>' :
                'N/A';

            // Add order note
            ob_start();
            ?>
            <p><b><?php _e('FastSpring Order Completed.', 'woocommerce'); ?></b></p>
            <p><b>ID:</b> <?php echo $order_id; ?></p>
            <p><b>FastSpring Order:</b> <?php echo esc_html($confirmation['reference']); ?> (payment confirmed with the FastSpring API)</p>
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
     * Check the FastSpring HMAC signature on a webhook (VAL-924)
     *
     * In "Report only" mode anything but a valid signature is logged and the webhook is still
     * processed; in "Enforce" mode it's rejected. Payments are confirmed with the FastSpring API
     * in both modes, so Report only never lets a forged webhook mark an order paid.
     *
     * @param string|null $raw_body Raw request body, read from php://input if omitted
     * @return bool True if the webhook should be processed
     */
    public function is_valid_webhook_request($raw_body = null)
    {
        if (null === $raw_body) {
            $raw_body = file_get_contents('php://input');
        }

        $signature = isset($_SERVER['HTTP_X_FS_SIGNATURE']) ? wp_unslash($_SERVER['HTTP_X_FS_SIGNATURE']) : '';
        $status    = $this->get_webhook_signature_status($raw_body, $signature);
        $enforce   = 'enforce' === self::get_setting('webhook_signature_mode');
        $events    = $this->describe_webhook_events($raw_body);

        $this->record_webhook_signature_status($status);

        if ('valid' === $status) {
            $this->log(sprintf('Webhook signature valid (%s)', $events));
            return true;
        }

        $labels = array(
            'missing'   => 'no signature',
            'invalid'   => 'an invalid signature',
            'no_secret' => 'no Webhook Secret set in the gateway settings to check it against',
        );
        $message = sprintf(
            'VAL-924 FastSpring webhook with %s (%s, from %s): %s',
            $labels[$status],
            $events,
            WC_Geolocation::get_ip_address(),
            $enforce ? 'rejected' : 'processed anyway because the signature check is set to Report only'
        );
        $this->log($message, $enforce ? 'error' : 'warning');

        if ($enforce) {
            $this->send_admin_alert(
                'signature_' . $status,
                HOUR_IN_SECONDS,
                sprintf('FastSpring webhook rejected: %s', $labels[$status]),
                $message . "\n\nFastSpring retries a rejected webhook for up to 7 days, and it can be resent from FastSpring's Webhook Log.\nIf genuine webhooks are being rejected, check that the Webhook Secret in the gateway settings matches the HMAC SHA256 Secret in FastSpring, or set the Webhook Signature Check to Report only.\nFurther alerts like this are suppressed for an hour."
            );
        }

        return !$enforce;
    }

    /**
     * Classify a webhook's FastSpring signature (VAL-924)
     *
     * FastSpring signs the raw body with the webhook's HMAC SHA256 Secret and sends the
     * base64-encoded digest in the X-FS-Signature header.
     *
     * @param string $raw_body  Raw request body
     * @param string $signature Value of the X-FS-Signature header
     * @return string 'valid', 'missing', 'invalid' or 'no_secret'
     */
    public function get_webhook_signature_status($raw_body, $signature)
    {
        $secret    = (string) self::get_setting('webhook_secret');
        $signature = trim((string) $signature);

        if ('' === $secret) {
            return 'no_secret';
        }

        if ('' === $signature) {
            return 'missing';
        }

        $expected = base64_encode(hash_hmac('sha256', (string) $raw_body, $secret, true));

        return hash_equals($expected, $signature) ? 'valid' : 'invalid';
    }

    /**
     * Count webhook signature results for the settings page (VAL-924)
     *
     * Approximate: concurrent webhooks can race on the option. It only needs to show
     * whether anything other than valid signatures is arriving.
     *
     * @param string $status Result of get_webhook_signature_status()
     * @return void
     */
    private function record_webhook_signature_status($status)
    {
        $tally = get_option('wc_fastspring_webhook_signature_tally', array());

        if (!is_array($tally) || empty($tally['since'])) {
            $tally = array('since' => current_time('mysql', true));
        }

        $tally[$status]           = (isset($tally[$status]) ? (int) $tally[$status] : 0) + 1;
        $tally['last_' . $status] = current_time('mysql', true);

        update_option('wc_fastspring_webhook_signature_tally', $tally, false);
    }

    /**
     * Summarize a webhook's events for the log without trusting its contents (VAL-924)
     *
     * @param string $raw_body Raw request body
     * @return string For example "order.completed EVWB5SXET64NFJD6RPVLQYZ4ZSNSZE"
     */
    private function describe_webhook_events($raw_body)
    {
        $body = json_decode($raw_body);

        return $this->describe_events(is_object($body) && isset($body->events) && is_array($body->events) ? $body->events : array());
    }

    /**
     * Summarize webhook events for the log (VAL-924)
     *
     * @param array $events Decoded webhook events
     * @return string For example "order.completed EVWB5SXET64NFJD6RPVLQYZ4ZSNSZE"
     */
    private function describe_events(array $events)
    {
        $parts = array();

        foreach (array_slice($events, 0, 3) as $event) {
            $type    = isset($event->type) && is_scalar($event->type) ? (string) $event->type : '?';
            $id      = isset($event->id) && is_scalar($event->id) ? (string) $event->id : '?';
            $parts[] = preg_replace('/[^A-Za-z0-9._-]/', '', substr($type, 0, 64)) . ' ' . preg_replace('/[^A-Za-z0-9_-]/', '', substr($id, 0, 64));
        }

        if (count($events) > 3) {
            $parts[] = sprintf('and %d more', count($events) - 3);
        }

        return $parts ? implode(', ', $parts) : 'no events';
    }

    /**
     * Email the site admin, at most once per key per period (VAL-924)
     *
     * @param string $key     What the alert is about, for deduplication
     * @param int    $period  Seconds to suppress repeats of the same key
     * @param string $subject Email subject, without the site name
     * @param string $body    Email body
     * @return void
     */
    private function send_admin_alert($key, $period, $subject, $body)
    {
        $dedupe_key = 'wc_fs_alert_' . md5($key);
        if (get_transient($dedupe_key)) {
            return;
        }
        set_transient($dedupe_key, 1, $period);

        $admin_email = get_option('admin_email');
        if (empty($admin_email)) {
            $this->log('VAL-924 cannot send alert because admin_email is empty', 'error');
            return;
        }

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $body     .= "\n\nSite: " . home_url('/') . "\nFastSpring log: " . admin_url('admin.php?page=wc-status&tab=logs') . "\n";

        $sent = wp_mail($admin_email, sprintf('[%s] %s', $site_name, $subject), $body);
        $this->log(sprintf('VAL-924 alert email %s: %s', $sent ? 'sent' : 'FAILED', $subject), $sent ? 'notice' : 'error');
    }

    /**
     * Logs
     *
     * @param string $message
     * @param string $level   WooCommerce log level
     */
    public static function log($message, $level = 'notice')
    {
        WC_FastSpring::log($message, $level);
    }
}

new WC_Gateway_FastSpring_Handler();