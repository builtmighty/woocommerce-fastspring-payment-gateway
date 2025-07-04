<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Gateway_FastSpring_Orders Class
 * 
 * Handles the creation of temporary and actual orders
 * 
 * @class WC_Gateway_FastSpring_Orders
 * 
 * @package WooCommerce_FastSpring/Classes
 * 
 * @version 1.0.0
 * 
 * @since 1.0.0
 *
 */
class WC_Gateway_FastSpring_Orders
{

    /**
     * @var Singleton The reference the *Singleton* instance of this class
     */
    private static $instance;

    /**
     * @var WC_Gateway_FastSpring_Checkout The FastSpring Checkout object
     */
    private $checkout;

    /**
     * Returns the *Singleton* instance of this class.
     *
     * @return Singleton The *Singleton* instance.
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     * 
     * @return void
     */
    public function __construct(){
        // Initialize the FastSpring Checkout object
        $this->checkout = new WC_Gateway_FastSpring_Checkout();

        // Schedule events on init
        add_action( 'init', array( $this, 'schedule_events' ) );

        // Hook into the scheduled event
        add_action( 'wc_fs_delete_old_temp_orders', array( $this, 'delete_old_temp_orders' ) );

        // Hook into WooCommerce login and registration actions
        add_action('woocommerce_login', array($this, 'transfer_order_session'), 10, 2);
        add_action('woocommerce_created_customer', array($this, 'transfer_order_session'), 10, 1);

        // Ajax Action for creating temporary order
        add_action('wp_ajax_wc_fs_create_temp_order', array( $this, 'create_temp_order_cb') );
        add_action('wp_ajax_nopriv_wc_fs_create_temp_order', array( $this, 'create_temp_order_cb') );

        // Ajax Action for creating actual order
        add_action('wp_ajax_wc_fs_create_actual_order', array( $this, 'create_actual_order') );
        add_action('wp_ajax_nopriv_wc_fs_create_actual_order', array( $this, 'create_actual_order') );

        // Ajax Action for deleting temporary order
        add_action( 'wp_ajax_nopriv_wc_fs_delete_temp_order', array( $this, 'handle_delete_temp_order' ) );
        add_action( 'wp_ajax_wc_fs_delete_temp_order', array( $this, 'handle_delete_temp_order' ) );

        // Delete temporary order when cart is emptied
        add_action('woocommerce_cart_emptied', array($this, 'cart_emptied'));
    }

    /**
     * Schedule Delete Old Temporary Orders
     * 
     * @return void
     */
    public function schedule_delete_old_temp_orders() {

        // Get the temp order deletion time in seconds
        $deletion_time = WC_Gateway_FastSpring::get_temp_order_timeout();

        // Determine the schedule interval
        $interval = ( $deletion_time < HOUR_IN_SECONDS ) ?
            'wc_fs_temp_order_interval' :
            'hourly';

        // Register a custom interval if needed
        if ( $deletion_time < HOUR_IN_SECONDS ) :
            add_filter( 'cron_schedules', function ( $schedules ) use ( $deletion_time ) {
                $schedules['wc_fs_temp_order_interval'] = array(
                    'interval' => $deletion_time,
                    'display'  => sprintf(
                        __( 'Every %d seconds', 'woocommerce-gateway-fastspring' ),
                        $deletion_time
                    ),
                );

                // Add the custom interval to the schedules
                return $schedules;
            });
        endif; // endif ( $deletion_time < HOUR_IN_SECONDS ) :

        // Check if the current schedule matches the desired interval
        $next_scheduled = wp_next_scheduled('wc_fs_delete_old_temp_orders');

        if ( $next_scheduled )
            // Clear the existing schedule
            wp_unschedule_event( $next_scheduled, 'wc_fs_delete_old_temp_orders' );

        // Schedule the event with the new interval
        wp_schedule_event( time(), $interval, 'wc_fs_delete_old_temp_orders' );
    }

    /**
     * Schedule Events
     * 
     * @return void
     * 
     * @hook init
     */
    public function schedule_events() {
        $this->schedule_delete_old_temp_orders();
    }

    /**
     * Validate Form Data
     * 
     * @param array $form_data - The Checkout Form Data
     * 
     * @return array - An array of error messages if validation fails, empty array otherwise
     */
    public function validate_form_data($form_data) {
        // Initialize WooCommerce checkout object
        $checkout = $this->checkout;

        // Backup the original $_POST data
        $original_post_data = $_POST;

        // Set the $_POST data to the form data
        $_POST = $form_data;

        $_POST['_wpnonce'] = wp_create_nonce('woocommerce-process_checkout');

        // Validate the checkout form data
        $valid_checkout = $checkout->fs_validate_checkout();

        // Restore the original $_POST data
        $_POST = $original_post_data;

        // Convert validation errors to an array of messages
        $error_messages = array();
        if (is_wp_error($valid_checkout)) :
            $error_messages = $valid_checkout->get_error_messages();
        endif;

        return $error_messages;
    }

    /**
     * Transfer the order_awaiting_payment session variable when the user logs in or creates an account.
     *
     * @param int $user_id The user ID.
     * @param WP_User|null $user The user object (optional).
     * 
     * @return void
     * 
     * @since 1.3.0
     * 
     * @hook action woocommerce_login
     * @hook action woocommerce_created_customer
     */
    public function transfer_order_session( $user_id, $user = null ) {
        if ( is_null($user ) )
            $user = get_user_by('id', $user_id);

        if ( ! $user )
            return;

        $order_awaiting_payment = WC()->session->get('order_awaiting_payment');

        // Set the session for the logged-in user
        WC()->session->set_customer_session_cookie(true);
        WC()->session->set('order_awaiting_payment', $order_awaiting_payment);
    }

    /**
     * Ensure the order is associated with the current logged-in user.
     *
     * @param WC_Order $order
     * 
     * @return void
     */
    public function ensure_order_customer_is_current_user( $order ) {
        $current_user_id = get_current_user_id();
        if (
            $current_user_id &&
            $order->get_customer_id() != $current_user_id
        )
            $order->set_customer_id( $current_user_id );
    }

    /**
     * Update Existing Order
     * 
     * @param int $order_id - The Order ID
     * @param array $form_data - The Checkout Form Data
     * 
     * @return void
     */
    public function update_existing_order( $order_id, $form_data ) {
        $order               = wc_get_order( $order_id );
        $disallowed_statuses = [ 'completed', 'refunded' ];

        /**
         * Statuses Not Allowed to Update Existing Order Filter
         * 
         * @param array $disallowed_statuses - The allowed statuses to update the existing order
         * 
         * @return array - The allowed statuses to update the existing order
         * 
         * @hook filter wc_fastspring_disallowed_statuses_to_update_existing_order
         */
        $disallowed_statuses = apply_filters( 'wc_fastspring_disallowed_statuses_to_update_existing_order', $disallowed_statuses );

        // Check if the order exists and is not in a disallowed status
        if (
            ! $order ||
            in_array( $order->get_status(), $disallowed_statuses, true )
        )
            return;

        // Update the order with customer data
        $this->ensure_order_customer_is_current_user( $order );

        // Update the order with the current cart contents
        $order->remove_order_items();
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :

            $item = new WC_Order_Item_Product();

            if ( isset( $cart_item['product_id'] ) )
                $item->set_product_id( $cart_item['product_id'] );

            if ( isset( $cart_item['variation_id'] ) )
                $item->set_variation_id( $cart_item['variation_id'] );

            if ( isset( $cart_item['variation'] ) )
                $item->set_variation( $cart_item['variation'] );

            if (
                isset( $cart_item['data'] ) &&
                $product_name = $cart_item['data']->get_name()
            )
                $item->set_name( $product_name );

            if ( isset( $cart_item['quantity'] ) )
                $item->set_quantity( $cart_item['quantity'] );

            if ( isset( $cart_item['line_subtotal'] ) )
                $item->set_subtotal( $cart_item['line_subtotal'] );

            if ( isset( $cart_item['line_tax_data'] ) )
                $item->set_taxes( $cart_item['line_tax_data'] );

            if ( isset( $cart_item['line_total'] ) )
                $item->set_total( $cart_item['line_total'] );

            if ( isset( $cart_item['line_tax'] ) )
                $item->set_subtotal_tax( $cart_item['line_tax'] );

            if ( isset( $cart_item['line_subtotal_tax'] ) )
                $item->set_total_tax( $cart_item['line_subtotal_tax'] );


            // Transfer metadata from cart item to order item
            if ( isset( $cart_item['meta_data'] ) ) :
                foreach ($cart_item['meta_data'] as $meta_key => $meta_value) :
                    $item->add_meta_data( $meta_key, $meta_value, true );
                endforeach;
            endif;

            // Trigger the woocommerce_checkout_create_order_line_item filter
            apply_filters( 'woocommerce_checkout_create_order_line_item', $item, $cart_item_key, $cart_item, $order );

            $order->add_item( $item );
        endforeach;

        // Save the updated order
        $order->calculate_totals();
        $order->save();
    }

    /**
     * Create Temporary Order
     * 
     * @param array $form_data - The Checkout Form Data
     * @param WC_Gateway_FastSpring_Checkout $wc_checkout - The FastSpring Checkout object
     * 
     * @return int|bool - The Order ID if successful, false otherwise
     */
    public function create_temp_order( $form_data, $wc_checkout ) {
        // Create the order
        $order_id = $wc_checkout->create_order( $form_data );
        $order    = wc_get_order( $order_id );

        if ( ! $order )
            return false;

        // Add custom meta to mark as temporary
        $order->update_meta_data( '_is_temp_order', 'yes' );

        // Add an order note.
        $order->add_order_note( 'Temporary order created.' );

        $temp_order_id = $order->save();

        // Store the order ID in the session
        WC()->session->set('order_awaiting_payment', $temp_order_id );

        return $temp_order_id;
    }
    
    /**
     * Create or Update Temporary Order
     * 
     * @param array $form_data - The Checkout Form Data
     * 
     * @return int|array - The Order ID if successful, array of error messages otherwise
     */
    public function create_or_update_temp_order( $form_data ) {
        // Check if temporary order id is set in session
        $existing_temp_order_id = WC()->session->get( 'order_awaiting_payment' );

        // Validate the form data before creating the order
        $validation_errors = $this->validate_form_data($form_data);

        if ( ! empty( $validation_errors ) )
            return $validation_errors;

        // $order = wc_create_order( $order_args );
        $wc_checkout = new WC_Gateway_FastSpring_Checkout();

        // Process customer to create account and log in the user if needed
        $customer_result = $wc_checkout->fs_process_customer( $form_data );

        if ( isset( $customer_result['error'] ) )
            return array( $customer_result['error'] );

         // If a temporary order already exists, update it
        if ( $existing_temp_order_id ) :
            $this->update_existing_order( $existing_temp_order_id, $form_data );
            $temp_order_id = $existing_temp_order_id;
        else : // Else Create a new temporary order
            $temp_order_id = $this->create_temp_order( $form_data, $wc_checkout );

            // Bail if the order creation failed
            if ( ! $temp_order_id )
                return array( 'Failed to create order' );
        endif; // endif ( $existing_temp_order_id ) :

        // Generate a new create actual order nonce
        $temp_order_nonce = wp_create_nonce( 'wc-fastspring-create-actual-order' );

        $temp_order_data  = array(
            'temp_order_id'    => $temp_order_id,
            'temp_order_nonce' => $temp_order_nonce,
        );

        return $temp_order_data;
    }

    /**
     * Create Temporary Order Callback
     * 
     * @return void
     * 
     * @hook wp_ajax_wc_fs_create_temp_order
     * @hook wp_ajax_nopriv_wc_fs_create_temp_order
     */
    public function create_temp_order_cb() {
        // Verify nonce
        if (
            ! isset( $_POST['nonce'] ) ||
            ! wp_verify_nonce( $_POST['nonce'], 'wc-fastspring-receipt' )
        ) :
            wp_send_json_error( 'Invalid nonce' );
            return;
        endif;

        // Unserialize the form data.
        parse_str( $_POST['form_data'], $form_data );

        $payment_method = $form_data['payment_method'];

        if ( $payment_method !== 'fastspring' )
            return wp_send_json_error( 'Invalid payment method' );

        // Check for empty cart
        if ( WC()->cart->is_empty() ) :
            $message = __( 'Your cart is empty. Please add items to your cart before attempting to checkout.', 'woocommerce-gateway-fastspring' );
            wp_send_json_success( [
                'result'   => 'error',
                'type'     => 'empty_cart',
                'messages' => [ $message ],
            ]);
            return;
        endif;

        // Default condition to allow temp order creation
        $default_temp_order_control = array(
            'allow'   => true,
            'result'  => 'success',
            'message' => '',
            'type'    => 'temp_order_created',
        );

        /**
         * Allow plugins/themes to control temp order creation and customize the response
         *
         * @param array $temp_order_control - Array controlling temp order creation and response structure.
         *               [
         *                'allow'   => bool, Whether to allow temp order creation.
         *                'result'  => string, The result status.
         *                'message' => string, The response message.
         *                'type'    => string, The response type.
         *               ]
         * 
         * @param array $form_data - The Checkout Form Data.
         *
         * @return array|WP_Error - Array with 'allow' key to control creation, or WP_Error object.
         *
         * @hook filter wc_fastspring_temp_order_condition
         */
        $temp_order_control = apply_filters(
            'wc_fastspring_temp_order_control',
            $default_temp_order_control,
            $form_data
        );
        if ( is_wp_error( $temp_order_control ) ) :
            wp_send_json_success( [
                'result'   => 'error',
                'type'     => 'temp_order_blocked',
                'messages' => [ $temp_order_control->get_error_message() ],
            ]);

            return;
        endif;

        // If not allowed, use the provided structure for the response
        if (
            is_array( $temp_order_control ) &&
            isset( $temp_order_control['allow'] ) &&
            ! $temp_order_control['allow']
        ) :
            $result = isset( $temp_order_control['result'] ) ?
                sanitize_text_field( $temp_order_control['result'] ) :
                'error';
            $message = isset( $temp_order_control['message'] ) ?
                wp_kses_post( $temp_order_control['message'] ) :
                __( 'You must be logged in to checkout with these products.', 'woocommerce-gateway-fastspring' );
            $type = isset( $temp_order_control['type'] ) ?
                sanitize_text_field( $temp_order_control['type'] ) :
                'temp_order_blocked';

            wp_send_json_success( [
                'result'   => $result,
                'type'     => $type,
                'messages' => [ $message ],
            ]);

            return;
        endif;

        // Create the placeholder order
        $temp_order_data = $this->create_or_update_temp_order( $form_data );

        if ( ! isset( $temp_order_data['temp_order_id'] ) ) :
            // Validation errors
            $response = array(
                'result'   => 'error',
                'type'     => 'validation',
                'messages' => $temp_order_data,
            );

            wp_send_json_success( $response );
            return;
        endif;

        $response = array(
            'result'          => 'success',
            'session'         => WC_Gateway_FastSpring_Builder::get_secure_json_payload(),
            'temp_order_data' => $temp_order_data,
        );

        /**
         * Allow plugins/themes to modify the response before sending it
         *
         * @param array $response - The response data
         * @param array $form_data - The Checkout Form Data
         *
         * @return array - The modified response data
         *
         * @hook filter wc_fastspring_create_temp_order_response
         */
        $response = apply_filters(
            'wc_fastspring_create_temp_order_response',
            $response,
            $form_data
        );

        wp_send_json_success( $response );
    }

    /**
     * Delete Temporary Order
     * 
     * @param int $order_id - The Order ID
     * 
     * @return void
     */
    public function delete_temp_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if (
            $order &&
            ! in_array( $order->get_status(), array( 'completed', 'on-hold', 'refunded' ), true ) &&
            $order->get_meta( '_is_temp_order' ) === 'yes'
        ) :
            // Clear the order from the session
            WC()->session->set( 'order_awaiting_payment', null );

            // Delete the order
            $order->delete( true );

        endif;
    }

    /**
     * Handle FastSpring Delete Temporary Order
     * 
     * @return void
     * 
     * @hook wp_ajax_wc_fs_delete_temp_order
     * @hook wp_ajax_nopriv_wc_fs_delete_temp_order
     */
    public function handle_delete_temp_order() {
        // Verify nonce
        if (
            ! isset( $_POST['nonce'] ) ||
            ! wp_verify_nonce( $_POST['nonce'], 'wc-fastspring-receipt' )
        ) :
            wp_send_json_error( 'Invalid nonce' );
            return;
        endif;
    
        // Get the order ID from the session
        $order_id = WC()->session->get( 'order_awaiting_payment' );

        if ( $order_id ) :
            $this->delete_temp_order( $order_id );
            WC()->session->set( 'order_awaiting_payment', null );
            $response = array(
                'message'  => 'Order deleted',
                'order_id' => $order_id,
            );
            wp_send_json_success( $response );
        else :
            wp_send_json_error( 'No order found' );
        endif;
    }

    /**
     * Cart Emptied
     * 
     * @return void
     * 
     * @hook woocommerce_cart_emptied
     */
    public function cart_emptied() {
        $order_id = WC()->session->get( 'order_awaiting_payment' );

        // Check if the order ID exists and if the 'shop_order' post type exists.
        // Sometimes the cart_emptied action is triggered before the custom post type is registered.
        if ( $order_id && post_type_exists( 'shop_order' ) ) :
            $this->delete_temp_order( $order_id );
            WC()->session->set( 'order_awaiting_payment', null );
        endif;
    }

    /**
     * Delete Old Temporary Orders
     * 
     * @return void
     * 
     * @hook wc_fs_delete_old_temp_orders
     */
    public function delete_old_temp_orders() {
        // Delete orders older than 24 hours
        $time_out = WC_Gateway_FastSpring::get_temp_order_timeout();

        $time     = ( time() - $time_out );

        // Query for pending temporary orders
        $args = array(
            'limit'        => -1,
            'meta_key'     => '_is_temp_order',
            'meta_value'   => 'yes',
            'date_created' => "<$time",
        );

        $orders = wc_get_orders( $args );

        foreach ( $orders as $order ) :
            $this->delete_temp_order( $order->get_id() );
        endforeach;
    }

    /**
     * Redeem the gift cards
     *
     * @param WC_Order $order
     * @return void
     */
    private function redeem_gift_cards($order) {
        global $pw_gift_cards_redeeming;
        if ( ! $pw_gift_cards_redeeming )
            return;

        $pw_gift_cards_redeeming->debit_gift_cards( $order->get_id(), $order, "order_id: {$order->get_id()} processing" );
    }


    /**
     * Apply discounts, gift cards, and coupons to the order
     *
     * @param WC_Order $order
     * 
     * @return void
     * 
     */
    private function apply_discounts_to_order( $order ) {
        // Apply WooCommerce coupons
        $checkout = new WC_Gateway_FastSpring_Checkout();
        $coupons  = WC()->cart->get_coupons();
        foreach ( $coupons as $code => $coupon ) :
            $checkout->apply_coupon( $code );
        endforeach;

        /**
         * Apply Discounts to Order Action
         * 
         * @param WC_Order $order - The Order Object
         * @param array $args - Additional arguments
         * 
         * @hook action wc_fs_apply_discounts_to_order
         */
        do_action( 'wc_fs_apply_discounts_to_order', $order, array() );

        // Calculate totals again to ensure discounts are applied
        $order->calculate_totals();
    }

    /**
     * Update Order with Customer Data
     * 
     * @param WC_Order $order - The Order Object
     * @param array $form_data - The Checkout Form Data
     * 
     * @return void
     */
    public function update_order_with_customer_data( $order, $form_data ) {
        // Update the order with the full details
        if ( isset( $form_data['billing_first_name'] ) )
            $order->set_billing_first_name( $form_data['billing_first_name'] );

        if ( isset( $form_data['billing_last_name'] ) )
            $order->set_billing_last_name( $form_data['billing_last_name'] );

        if ( isset( $form_data['billing_email'] ) )
            $order->set_billing_email( $form_data['billing_email'] );

        if ( isset( $form_data['billing_company'] ) )
            $order->set_billing_company( $form_data['billing_company'] );

        if ( isset( $form_data['billing_country'] ) )
            $order->set_billing_country( $form_data['billing_country'] );

        if ( isset( $form_data['billing_state'] ) )
            $order->set_billing_state( $form_data['billing_state'] );

        if ( isset( $form_data['billing_city'] ) )
            $order->set_billing_city( $form_data['billing_city'] );

        if ( isset( $form_data['billing_postcode'] ) )
            $order->set_billing_postcode( $form_data['billing_postcode'] );

        if ( isset( $form_data['billing_address_1'] ) )
            $order->set_billing_address_1( $form_data['billing_address_1'] );

        if ( isset( $form_data['billing_address_2'] ) )
            $order->set_billing_address_2( $form_data['billing_address_2'] );

        if ( isset( $form_data['billing_phone'] ) )
            $order->set_billing_phone( $form_data['billing_phone'] );

        if ( isset( $form_data['shipping_first_name'] ) )
            $order->set_shipping_first_name( $form_data['shipping_first_name'] );

        if ( isset( $form_data['shipping_last_name'] ) )
            $order->set_shipping_last_name( $form_data['shipping_last_name'] );

        if ( isset( $form_data['shipping_company'] ) )
            $order->set_shipping_company( $form_data['shipping_company'] );

        if ( isset( $form_data['shipping_country'] ) )
            $order->set_shipping_country( $form_data['shipping_country'] );

        if ( isset( $form_data['shipping_state'] ) )
            $order->set_shipping_state( $form_data['shipping_state'] );

        if ( isset( $form_data['shipping_city'] ) )
            $order->set_shipping_city( $form_data['shipping_city'] );

        if ( isset( $form_data['shipping_postcode'] ) )
            $order->set_shipping_postcode( $form_data['shipping_postcode'] );

        if ( isset( $form_data['shipping_address_1'] ) )
            $order->set_shipping_address_1( $form_data['shipping_address_1'] );

        if ( isset( $form_data['shipping_address_2'] ) )
            $order->set_shipping_address_2( $form_data['shipping_address_2'] );

        if ( isset( $form_data['shipping_phone'] ) )
            $order->set_shipping_phone( $form_data['billing_phone'] );

        if ( isset( $form_data['customer_note'] ) )
            $order->set_customer_note( $form_data['customer_note'] );

        if ( isset( $form_data['payment_method'] ) )
            $order->set_payment_method( $form_data['payment_method'] );

        if ( isset( $form_data['payment_method_title'] ) )
            $order->set_payment_method_title( $form_data['payment_method_title'] );

        // Ensure the order is associated with the current logged-in user
        $this->ensure_order_customer_is_current_user( $order );
    }

    /**
     * Create Actual Order Callback
     * 
     * @return void
     * 
     * @hook wp_ajax_wc_fs_create_actual_order
     * @hook wp_ajax_nopriv_wc_fs_create_actual_order
     */
    public function create_actual_order() {
        // Verify nonce
        if (
            ! isset( $_POST['nonce'] ) ||
            ! wp_verify_nonce( $_POST['nonce'], 'wc-fastspring-create-actual-order' )
        ) :
            wp_send_json_error( 'Invalid nonce' );
            return;
        endif;

        // Retrieve the order ID from the session
        $order_id = WC()->session->get( 'order_awaiting_payment' );

        if ( ! $order_id ) :
            wp_send_json_error( 'Order ID not found in session' );
            return;
        endif;

        // Unserialize the form data.
        parse_str( $_POST['form_data'], $form_data );

        // Retrieve the order object
        $order = wc_get_order( $order_id );

        if ( ! $order ) :
            wp_send_json_error( 'Order not found' );
            return;
        endif;

        // Update the order with the customer data
        $this->update_order_with_customer_data( $order, $form_data );


        // Set Order Created Via
        $order->set_created_via( 'checkout' );

        // Apply discounts
        $this->apply_discounts_to_order( $order );

        // Remove the temporary order flag
        $order->delete_meta_data( '_is_temp_order' );

        // Calculate totals
        $order->calculate_totals();

        // Clear the order from the session
        WC()->session->set( 'order_awaiting_payment', null );


        // Add FastSpring Order ID to WooCommerce Order
        if ( isset( $_POST['fastspring_order_id'] ) ) :
            $fastspring_order_id = sanitize_text_field( $_POST['fastspring_order_id'] );

            // Add FastSpring Order ID as order meta data
            $order->update_meta_data( '_fastspring_order_id', $fastspring_order_id );

            // Add FastSpring Order ID as order note
            $order->add_order_note( 'FastSpring Order ID: ' . $fastspring_order_id );
        endif;

        /**
         * Create Order Action
         * 
         * @param WC_Order $order - The Order Object
         * @param array $form_data - The Checkout Form Data
         * @param array $args - Additional arguments
         * 
         * @hook action wc_fastspring_create_order
         */
        do_action( 'wc_fastspring_create_order', $order, $form_data, array() );

        // Add an order note.
        $order->add_order_note( 'Temporary Order converted to Actual Order.' );

        // Save the order
        $order->save();

        // Create Response.
        $response = array(
            'result'   => 'success',
            'order_id' => $order_id,
        );

        wp_send_json_success( $response );
    }


}

WC_Gateway_FastSpring_Orders::get_instance();