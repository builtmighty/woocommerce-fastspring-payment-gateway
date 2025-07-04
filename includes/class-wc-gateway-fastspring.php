<?php
if (!defined('ABSPATH')) {
    exit;
}

include_once dirname(__FILE__) . '/class-wc-gateway-fastspring-builder.php';

/**
 * WC_Gateway_FastSpring class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_FastSpring extends WC_Payment_Gateway
{

  /**
   * Constructor
   */
    public function __construct()
    {
        $this->id = 'fastspring';
        $this->method_title = __('FastSpring', 'woocommerce-gateway-fastspring');
        $this->method_description = __('This plugin provides checkout payment processing by <a href="https://fastspring.com" target="_blank">FastSpring</a> using their hosted or popup storefronts. ');

        $this->has_fields = true;
        $this->supports = array(
            'products',
            'refunds',
            // 'tokenization',
            // 'add_payment_method',
            'subscriptions', // subscription.activated
            'subscription_cancellation', // FS subscription.canceled
            'subscription_suspension', // FS subscription.deactivated,
            'subscription_reactivation', // FS  subscription.activated
            'subscription_amount_changes', // FS subscription.updated
            'subscription_date_changes', // FS subscription.updated
            // 'subscription_payment_method_change',
            // 'subscription_payment_method_change_customer',
            // 'subscription_payment_method_change_admin',
            'multiple_subscriptions',
            //'pre-orders',
            );

        // FS not implemented for subscriptions:
        // subscription.trial.reminder
        // subscription.payment.reminder
        // subscription.payment.overdue
        // subscription.charge.completed
        // subscription.charge.failed

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get setting values.
        $this->title = self::get_setting('title');
        $this->description = self::get_setting('description');

        if (self::get_setting('testmode')) {
            $this->description .= "\n" . sprintf(__('TEST MODE ENABLED. In test mode, you can use the card numbers provided in the test panel of the FastSpring dashboard. Please check the documentation "<a target="_blank" href="%s">Testing Orders</a>" for more information.', 'woocommerce-gateway-fastspring'), 'http://docs.fastspring.com/activity-events-orders-and-subscriptions/test-orders');

            $this->description = trim($this->description);
        }

        // Action Hooks
        add_action('wc_ajax_wc_fastspring_order_complete', array($this, 'ajax_order_complete'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_wc_gateway_fastspring_commerce', array($this, 'return_handler'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'update_options') );



    }

    /**
     * Validate access key settings field
     *
     * @params $value
     */
    public function validate_access_key_field($key, $value)
    {
        if (!empty($value)) {
            return $value;
        }
        WC_Admin_Settings::add_error(esc_html__('A FastSpring access key is required.', 'woocommerce-gateway-fastspring'));
    }

    /**
     * Validate private key settings field
     *
     * @params $value
     */
    public function validate_private_key_field($key, $value)
    {
        if (@openssl_private_encrypt('abc', $aes_key_encrypted, openssl_pkey_get_private($value))) {
            return $value;
        }

        WC_Admin_Settings::add_error(esc_html__('The RSA private key field is invalid.', 'woocommerce-gateway-fastspring'));
    }

    /**
     * Validate title settings field
     *
     * @params $value
     */
    public function validate_title_field($key, $value)
    {
        if (empty($value)) {
            WC_Admin_Settings::add_error(esc_html__('Enter a valid title.', 'woocommerce-gateway-fastspring'));
        }
        return $value;
    }

    /**
     * Validate storefront path settings field
     *
     * @params $value
     */
    public function validate_storefront_path_field($key, $value)
    {
        if (empty($value)) {
            WC_Admin_Settings::add_error(esc_html__('Enter a valid storefront path.', 'woocommerce-gateway-fastspring'));
        } elseif (!empty($value)) {
            return preg_replace('#^https?://#', '', rtrim($value, '/'));
        }
    }

    /**
     * Check if this gateway is enabled
     */
    public function is_available()
    {
        if (!self::get_setting('enabled')) {
            return false;
        }

        if (self::get_setting('access_key') && self::get_setting('private_key') && self::get_setting('storefront_path')) {
            return true;
        }
        return false;
    }

    /**
     * Get_icon function.
     *
     * @return string
     */
    public function get_icon()
    {
        $icons = $this->payment_icons();
        $icons_enabled = self::get_setting('icons');

        $icons_str = '';
        $icons_str .= in_array( 'paypal', $icons_enabled ) ? $icons['paypal'] : '';
        $icons_str .= in_array( 'visa', $icons_enabled ) ? $icons['visa'] : '';
        $icons_str .= in_array( 'amex', $icons_enabled ) ? $icons['amex'] : '';
        $icons_str .= in_array( 'mastercard', $icons_enabled ) ? $icons['mastercard'] : '';
        $icons_str .= in_array( 'discover', $icons_enabled ) ? $icons['discover'] : '';
        $icons_str .= in_array( 'jcb', $icons_enabled ) ? $icons['jcb'] : '';
        $icons_str .= in_array( 'diners', $icons_enabled ) ? $icons['diners'] : '';
        $icons_str .= in_array( 'ideal', $icons_enabled ) ? $icons['ideal'] : '';
        $icons_str .= in_array( 'unionpay', $icons_enabled ) ? $icons['unionpay'] : '';
        $icons_str .= in_array( 'sofort', $icons_enabled ) ? $icons['sofort'] : '';
        $icons_str .= in_array( 'giropay', $icons_enabled ) ? $icons['giropay'] : '';

        return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
    }

    /**
     * All payment icons that work with Stripe. Some icons references
     * WC core icons.
     *
     * @return array
     */
    public function payment_icons()
    {
        return apply_filters(
            'wc_fastspring_payment_icons',
            array(
                'paypal'     => '<img src="' . WC_FASTSPRING_PLUGIN_URL . '/assets/img/paypal.svg" class="fastspring-visa-icon fastspring-icon" alt="PayPal" />',
                'visa'       => '<img src="' . WC_FASTSPRING_PLUGIN_URL . '/assets/img/visa.svg" class="fastspring-visa-icon fastspring-icon" alt="Visa" />',
                'amex'       => '<img src="' . WC_FASTSPRING_PLUGIN_URL . '/assets/img/amex.svg" class="fastspring-amex-icon fastspring-icon" alt="American Express" />',
                'mastercard' => '<img src="' . WC_FASTSPRING_PLUGIN_URL . '/assets/img/mastercard.svg" class="fastspring-mastercard-icon fastspring-icon" alt="Mastercard" />',
                'discover'   => '<img src="' . WC_FASTSPRING_PLUGIN_URL . '/assets/img/discover.svg" class="fastspring-discover-icon fastspring-icon" alt="Discover" />',
                'diners'     => '<img src="' . WC_FASTSPRING_PLUGIN_URL . '/assets/img/diners.svg" class="fastspring-diners-icon fastspring-icon" alt="Diners" />',
                'jcb'        => '<img src="' . WC_FASTSPRING_PLUGIN_URL . '/assets/img/jcb.svg" class="fastspring-jcb-icon fastspring-icon" alt="JCB" />',
                'ideal'      => '<img src="' . WC_FASTSPRING_PLUGIN_URL . '/assets/img/ideal.svg" class="fastspring-ideal-icon fastspring-icon" alt="iDeal" />',
                'giropay'    => '<img src="' . WC_FASTSPRING_PLUGIN_URL . '/assets/img/giropay.svg" class="fastspring-giropay-icon fastspring-icon" alt="Giropay" />',
                'sofort'     => '<img src="' . WC_FASTSPRING_PLUGIN_URL . '/assets/img/sofort.svg" class="fastspring-sofort-icon fastspring-icon" alt="SOFORT" />',
                'unionpay'   => '<img src="' . WC_FASTSPRING_PLUGIN_URL . '/assets/img/unionpay.svg" class="fastspring-unionpay-icon fastspring-icon" alt="Union Pay" />',
            )
        );
    }

    /**
     * Initialise gateway settings form fields
     */
    public function init_form_fields()
    {
        $this->form_fields = include 'settings-fastspring.php';

        // Add validation for the temp_order_deletion_time field
        $this->form_fields['temp_order_deletion_time']['validate_callback'] = array( $this, 'validate_temp_order_deletion_time_field' );
    }

    /**
     * Payment_scripts function.
     *
     * Outputs scripts used for fastspring payment
     */
    public function payment_scripts()
    {
        $load_scripts = false;

        if (is_checkout()) {
            $load_scripts = true;
        }

        if ($this->is_available()) {
            $load_scripts = true;
        }

        if (false === $load_scripts) {
            return;
        }

        $suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';

        if (self::get_setting('enabled')) {
            wp_enqueue_script('fastspring', WC_FASTSPRING_SCRIPT, '', false, true);
            wp_enqueue_script('woocommerce_fastspring', plugins_url('assets/js/fastspring-checkout' . $suffix . '.js', WC_FASTSPRING_MAIN_FILE), array('jquery', 'fastspring'), filemtime( plugin_dir_path( WC_FASTSPRING_MAIN_FILE ) . 'assets/js/fastspring-checkout' . $suffix . '.js' ), true);
        }

        // Get the temp order timeout value and localize to popup timout.
        $popup_timeout = self::get_temp_order_timeout();
        // Convert to milliseconds for JS
        $popup_timeout = $popup_timeout * 1000;

        $fastspring_params = array(
            'ajax_url' => WC_AJAX::get_endpoint('%%endpoint%%'),
            'nonce' => array(
              'receipt'             => wp_create_nonce( 'wc-fastspring-receipt' ),
              'create_actual_order' => wp_create_nonce( 'wc-fastspring-create-actual-order' ),
            ),
            'popup_timeout' => $popup_timeout,
          );

        $custom_css = '.woocommerce-checkout #payment ul.payment_methods li img.fastspring-icon { max-width: 40px; padding-left: 3px; margin: 0; }';
        $custom_css .= '.woocommerce-checkout #payment ul.payment_methods li img.fastspring-ideal-icon { max-height: 26px; }';
        $custom_css .= '.woocommerce-checkout #payment ul.payment_methods li img.fastspring-sofort-icon { max-width: 55px; magin-left: 3px; }';

        wp_add_inline_style( 'woocommerce-inline', $custom_css );

        wp_localize_script('woocommerce_fastspring', 'woocommerce_fastspring_params', apply_filters('woocommerce_fastspring_params', $fastspring_params));
    }

    /**
     * Process the payment.
     *
     * @param int $order_id
     *
     * @return array|void
     */
    public function process_payment($order_id)
    {
        // Now handled in WC_Gateway_FastSpring_Orders->create_temp_order_cb AJAX callback.
/*
        $order = wc_get_order($order_id);

        return array(
          'result' => 'success',
          'session' => WC_Gateway_FastSpring_Builder::get_secure_json_payload(),
        );
*/
        return array();
    }

    /**
     * Get FS Transaction URL
     *
     * @param  \WC_Order $order
     *
     * @return string
     */
    public function get_transaction_url( $order )
    {
        $transaction_id = $order->get_transaction_id();

        if ( $order->meta_exists('fs_order_id') ) {
            return 'https://dashboard.fastspring.com/order/home.xml?mRef=AcquisitionTransaction:' . $order->get_meta('fs_order_id');
        }

        return '';
    }

    /**
     * Options
     *
     * @param string $option option name
     * @return mixed option value
     */
    public static function get_setting($option)
    {
        return WC_FastSpring::get_setting($option);
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

    /**
     * Payment form on checkout page.
     */
    public function payment_fields()
    {
        $description = $this->get_description();

        if ($description) {
            echo wpautop(wptexturize(trim($description)));
        }
    }

    /**
     * Parse a human-readable time format into seconds.
     *
     * @param string $time_string The input time string (e.g., "1h 5m 3s", "3 hours", "2 minutes 1 second").
     * 
     * @return int The time in seconds.
     */
    public static function parse_time_to_seconds( $time_string ) {
        $time_string     = strtolower( trim($time_string) );
        $time_in_seconds = 0;

        // Match patterns for hours, minutes, and seconds
        if ( preg_match( '/(\d+)\s*h(?:ours?)?/', $time_string, $matches ) )
            $time_in_seconds += intval($matches[1]) * HOUR_IN_SECONDS;

        if ( preg_match( '/(\d+)\s*m(?:inutes?)?/', $time_string, $matches ) )
            $time_in_seconds += intval($matches[1]) * MINUTE_IN_SECONDS;

        if ( preg_match( '/(\d+)\s*s(?:econds?)?/', $time_string, $matches ) )
            $time_in_seconds += intval($matches[1]);

        return $time_in_seconds;
    }

    /**
     * Validate the temp order deletion time setting.
     *
     * @param string $key The setting key.
     * @param string $value The user-provided value.
     * 
     * @return string The validated and sanitized value.
     */
    public function validate_temp_order_deletion_time_field($key, $value) {
        $seconds = self::parse_time_to_seconds($value);

        if ( $seconds <= 0 ) :
            WC_Admin_Settings::add_error(
                esc_html__( 'Invalid temp order deletion time. Please provide a valid time format (e.g., "1h 5m 3s").', 'woocommerce-gateway-fastspring' )

            );
            return '24h'; // Default to 24 hours
        endif;

        return $value;
    }

    /**
     * Get the timeout value for temporary orders.
     *
     * @return int Timeout in seconds.
     */
    public static function get_temp_order_timeout() {
        return self::parse_time_to_seconds( self::get_setting( 'temp_order_deletion_time' ) ) ?: DAY_IN_SECONDS;

        // Debugging
        // return MINUTE_IN_SECONDS; // Set to 1 minute for testing
    }

    /**
     * Update options after saving settings.
     *
     * @return void
     * 
     * @hook woocommerce_update_options_payment_gateways_fastspring
     */
    public function update_options () {
        $new_deletion_time = self::get_temp_order_timeout();

        // Get the current interval
        $current_interval  = wp_get_schedule( 'wc_fs_delete_old_temp_orders' );

        // Determine the new interval
        $new_interval = ( $new_deletion_time < HOUR_IN_SECONDS ) ?
            'wc_fs_temp_order_interval__' . $new_deletion_time :
            'hourly';

        // Reset the schedule if the interval has changed
        if ( $current_interval !== $new_interval ) :
            $wc_gateway_fastpring_orders = WC_Gateway_FastSpring_Orders::get_instance();
            $wc_gateway_fastpring_orders->schedule_delete_old_temp_orders();
            debug_log( 'Scheduled deletion of old temp orders with interval: ' . $new_interval );
        endif;
    }

}
