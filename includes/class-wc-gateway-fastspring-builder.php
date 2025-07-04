<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Gateway_FastSpring_Builder class.
 *
 * Povides FS builder payload building functionality
 *
 */
class WC_Gateway_FastSpring_Builder
{

  /**
   * Constructer
   */
  public function __construct()
  {
    // add_filter('wc_fastspring_discount_item_amount', array( $this, 'get_discount_item_amount' ), 10, 3);

    // add_filter( 'wc_fastspring_add_discount_details_to_item', array( $this, 'add_discount_details_to_item' ), 10, 2 );
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
     * Calculates discounted item price based on overall discount share
     * 
     * @param float $price
     * @param int $quantity
     * @param WC_Product $product
     *
     * @return float - Discounted price
     */
    public static function get_discount_item_amount($price, $quantity, $product)
    {
        $total = WC()->cart->subtotal;
        $discount = WC()->cart->discount_cart;
        $cost = $price > 0 ? ($price - $discount / ($total / $price)) : $price;

        self::log('Calculating pricing for amount of '.$price.' and qty of '.$quantity.' with cart total of '.$total.' and discount of '.$discount.': '.$cost);
        return $cost;
    }

    /**
     * Calculates the discount amount for an item based on the total cost and the item cost.
     *
     * @param float $discount_amount - The total discount amount
     * @param float $item_cost - The cost of the item
     * @param float $total_cost - The total cost of the cart/order
     *
     * @return float - The discount amount for the item
     */
    public static function calculate_discount( $discount_amount, $item_cost, $total_cost ) {
      $discounted_amount = 0;

      if ( $total_cost <= 0 )
        return $discounted_amount;

      // Calculate the item's proportion of the total cost
      $item_proportion = round( $item_cost / $total_cost, 4 );

      // Calculate the discount for this item
      $discounted_amount = $discount_amount * $item_proportion;

      return $discounted_amount;
    }

    /**
     * Adds discount details to the item if one is applied.
     *
     * @param array $item - FastSpring Item to add discount details to
     * @param float $total_cost - Total cost of the cart/order
     *
     * @return array - FastSpring Item with discount details
     * 
     * @hooked filter wc_fastspring_add_discount_details_to_item
     */
    public static function add_discount_details_to_item( $item, $total_cost ) {
      $currency = get_woocommerce_currency();

      // Skip if the item has no pricing or the pricing is not numeric
      if (
        ! isset( $item['pricing']['price'][$currency] ) ||
        ! is_numeric( $item['pricing']['price'][$currency] )
      )
        return $item;

      $item_cost  = $item['pricing']['price'][$currency];

      // Get the currency EG USD, EUR, etc
      $currency               = get_woocommerce_currency();
      $quantity_discounts_key = 1;
      $total_discount_amount  = 0;

      // Default discount details
      $discount_details       = array(
          'discountType'      => 'amount', // Default type
          'quantityDiscounts' => array(
            $quantity_discounts_key => array(
             $currency => 0, // Default value
            ),
          ),
          'discountReason'    => array(
              'en' => '',
              'de' => ''
          ), // Default reason
      );

      // Get the applied coupons
      $coupons = WC()->cart->get_coupons();
      if ( ! empty( $coupons ) ) :
        $coupon_code = array_keys($coupons)[0]; // Assuming only one coupon is applied
        $coupon      = new WC_Coupon($coupon_code);

        $discount_details['discountType'] = $coupon->get_discount_type();
        $coupon_value                     = $coupon->get_amount();
        $discount_details['quantityDiscounts'][$quantity_discounts_key][$currency] = $coupon_value;
        $discount_details['discountReason'] = array(
            'en' => $coupon->get_description(),
            'de' => $coupon->get_description()
        ); // Assuming same description for all languages

        $total_discount_amount += $coupon_value;
      endif;

      $discount_amount = $discount_details['quantityDiscounts'][$quantity_discounts_key][$currency];

      /**
       * Filter the discount amount.
       *
       * @param float $discount_amount The Discount amount.
       * 
       * @return float $discount_amount The Discount amount.
       */
      $discount_amount = apply_filters( 'wc_fastspring_discount_amount', $discount_amount );

      if ( $discount_amount <= 0 )
        return $item;

      $calculated_discount = self::calculate_discount( $discount_amount, $item_cost, $total_cost );

      $discount_details['quantityDiscounts'][$quantity_discounts_key][$currency] = $calculated_discount;

      // Merge the discount details with the item pricing.
      $item['pricing'] = array_merge( $item['pricing'], $discount_details );

      return $item;
    }

    /**
     * Add discount details to items
     * @param array $items - The FastSpring Items to Add the Discount Details to
     * @return array $items - The FastSpring Items to Add with the Added Discount Details
     */
    public static function add_discount_details_to_items( $items ) {
      $total_cost = WC()->cart->get_subtotal();
      foreach ( $items as $key => $item ) :
        $items[$key] = self::add_discount_details_to_item( $item, $total_cost );
      endforeach;

      $items = apply_filters( 'wc_fastspring_add_discount_details_to_items', $items );

      return $items;
    }


    /**
     * Builds cart payload
     *
     * @return object
     */
    public static function get_cart_items()
    {
        $items = array();

        $has_subscription_support = class_exists('WC_Subscriptions_Product');

        foreach (WC()->cart->cart_contents as $cart_item_key => $values) {
            $price = $values['line_subtotal'] / $values['quantity'];
            $fee = 0;

            $product = $values['data'];

            $item = array(
             

              'product' => $product->get_slug(),
              'pricing' => [
                'quantityBehavior' => 'lock',
                'quantityDefault' =>  $values['quantity']

              ],
              // Customer visible product display name or title
              'display' => [
                'en' => $product->get_name(),
              ],
              'description' => [
                'summary' => [
                  'en' => $product->get_short_description(),
                ],
                'full' => [
                  'en' => $product->get_short_description(),
                ],
              ],
              'image' => self::get_image($product->get_image_id()),
              // Boolean - controls whether or not product can be removed from the cart by the customer
              'removable' => false,
              // String - optional product SKU ID (e.g. to match your internal SKU or product ID)
              'sku' => $product->get_sku(),

            );

            // Subscriptions?
            if ($has_subscription_support) {

                // Subsciption details such as period, length, etc
                $trial_end_date = WC_Subscriptions_Product::get_trial_expiration_date($product->get_id());
                $trial = $trial_end_date != 0 ? $trial_end_date['d'] : 0;
                $period = WC_Subscriptions_Product::get_period($product->get_id());
                $interval = WC_Subscriptions_Product::get_interval($product->get_id());
                $count = WC_Subscriptions_Product::get_length($product->get_id());

                // When period is not set this item is not a sub product
                $is_subscription = !empty($period);

                if ($is_subscription) {

                     // If sub then the price we send FS needs to be subscription price not including fee
                    $price = WC_Subscriptions_Product::get_price($product->get_id());

                    // The signup fee - we need special handling for this later
                    $fee = WC_Subscriptions_Product::get_sign_up_fee($product->get_id());

                    // Integer - number of free trial days for a subscription (required for subscription only)
                    $item['pricing']['trial'] = $trial;

                    //  'adhoc', 'day', 'week', 'month', or 'year',  - interval unit for scheduled billings; 'adhoc' = managed / ad hoc subscription (required for subscription only)
                    $item['pricing']['interval'] = $period;

                    // Integer - number of interval units per billing (e.g. if interval = 'MONTH', and intervalLength = 1, billings will occur once per month)(required for subscription only)
                    $item['pricing']['intervalLength'] = $interval;

                    // Integer - otal number of billings; pass null for unlimited / until cancellation subscription (required for subscription only)
                    $item['pricing']['intervalCount'] = $count > 0 ? $count : null;

                    // Boolean - controls whether or not payment reminder email messages are enabled for the product (subscription only)
                    // $item['pricing']['reminder_enabled'] = false;

                    // 'adhoc', 'day', 'week', 'month', or 'year' - interval unit for payment reminder email messages (subscription only)
                    // $item['pricing']['reminder_value'] = 'adhoc';

                    // Integer - number of interval units prior to the next billing date when payment reminder email messages will be sent (subscription only)
                    // $item['pricing']['reminder_count'] = 0;

                    // Boolean - controls whether or not payment overdue notification email messages are enabled for the product (subscription only)
                    // $item['pricing']['payment_overdue'] = false;

                    // 'adhoc', 'day', 'week', 'month', or 'year' - interval unit for payment overdue notification email messages (subscription only)
                    // $item['pricing']['overdue_interval_value'] = 'adhoc';

                    // Integer - total number of payment overdue notification messages to send (subscription only)
                    // $item['pricing']['overdue_interval_count'] = 0;

                    // Integer - number of overdue_interval units between each payment overdue notification message (subscription only)
                    // $item['pricing']['overdue_interval_amount'] = 0;

                    // Integer - number of cancellation_interval units prior to subscription cancellation (subscription only)
                    // $item['pricing']['cancellation_interval_count'] = 0;

                    // 'adhoc', 'day', 'week', 'month', or 'year' - interval unit for subscription cancellation (subscription only)
                    // $item['pricing']['cancellation_interval_value'] = 'adhoc';
                }
            }

            // Set our determined price
            $item['pricing']['price'] = [
                get_woocommerce_currency() => apply_filters( 'wc_fastspring_discount_item_amount', $price, $values['quantity'], $product ),
            ];

            $items[] = $item;

            // FS cannot handle signup fees when there is a trial. We create a separate item just for that
            if ($fee > 0) {
                $items[] = array(
                    'product' => $product->get_slug() . '-signup-fee',
                    'quantity' => $values['quantity'],
                    'pricing' => [
                      'quantityBehavior' => 'lock',
                      'price' => [
                        get_woocommerce_currency() => apply_filters( 'wc_fastspring_discount_item_amount', $fee, $values['quantity'], $product )
                      ],
                    ],
                    'display' => [
                      'en' => $product->get_name() . ' signup fee',
                    ],
                    'description' => [
                      'summary' => [
                        'en' => 'Subscription signup fee',
                      ],
                    ],
                    'removable' => false
                  );
            }

          // Get Total Items with an actual cost.
          $discountable_items = array();
          foreach ( $items as $item ) :
            if ($item['pricing']['price'][get_woocommerce_currency()] > 0)
              $discountable_items[] = $item;
          endforeach;

          $discountable_items_count = count( $discountable_items );
        }

        // Add discount details to the items
        $items = self::add_discount_details_to_items( $items );

        return $items;
    }

    /**
     * Gets a product image URL
     *
     * @return string
     */
    public static function get_image($id, $size = 'shop_thumbnail', $attr = array(), $placeholder = true)
    {
        $data = wp_get_attachment_image_src($id, $size);

        if ($data) {
            $image = $data[0];
        } elseif ($placeholder) {
            $image = wc_placeholder_img($size);
        } else {
            $image = '';
        }
        return str_replace(array('https://', 'http://'), '//', $image);
    }

    /**
     * Get cart info
     *
     * @return object
     */
    public static function get_cart_customer_details()
    {
        $order_id = absint(WC()->session->get('order_awaiting_payment'));

        if (!$order_id) {
            return [];
        }

        // Set another session var that wont get erased - we need that for receipt
        // We sometimes get a race condition
        WC()->session->set('current_order', $order_id);

        $order = wc_get_order($order_id);

        return [
          'email' => $order->get_billing_email(),
          'firstName' => $order->get_billing_first_name(),
          'lastName' => $order->get_billing_last_name(),
          'company' => $order->get_billing_company(),
          'addressLine1' => $order->get_billing_address_1(),
          'addressLine2' => $order->get_billing_address_2(),
          'region' => $order->get_billing_state(),
          'city' => $order->get_billing_city(),
          'postalCode' => $order->get_billing_postcode(),
          'country' => $order->get_billing_country(),
          'phoneNumber' => $order->get_billing_phone(),
        ];
    }

    /**
     * Returns order ID as tag array for FS reference
     *
     * @return array
     */
    public static function get_order_tags()
    {
        return array("store_order_id" => absint(WC()->session->get('order_awaiting_payment')));
    }

    /**
     * Builds JSON payload
     *
     * @return object
     */
    public static function get_json_payload()
    {
        return array(
          'tags' => self::get_order_tags(),
          'contact' => self::get_cart_customer_details(),
          'items' => self::get_cart_items(),
        );
    }

    /**
     * Builds encrypted JSON payload
     *
     * @return object
     */
    public static function get_secure_json_payload()
    {
        $aes_key = self::aes_key_generate();
        $payload = self::get_json_payload();

        $encypted = self::encrypt_payload($aes_key, json_encode($payload));
        $key = self::encrypt_key($aes_key);

        return self::get_setting('testmode') === 'yes' ? [
            'payload' => $payload,
            'key' => ''

          ] : [
            'payload' => $encypted,
            'key' => $key

          ];
    }

    /**
     * Encrypts payload
     *
     * @param object
     * @return string
     */
    public static function encrypt_payload($aes_key, $string)
    {
        $cipher = openssl_encrypt($string, "AES-128-ECB", $aes_key, OPENSSL_RAW_DATA);
        return base64_encode($cipher);
    }

    /**
     * Create secure key
     *
     * @param object
     * @return string
     */
    public static function encrypt_key($aes_key)
    {
        $private_key = openssl_pkey_get_private(self::get_setting('private_key'));
        openssl_private_encrypt($aes_key, $aes_key_encrypted, $private_key);
        return base64_encode($aes_key_encrypted);
    }

    /**
     * Generate AES key
     *
     * @return string
     */
    public static function aes_key_generate()
    {
        return openssl_random_pseudo_bytes(16);
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
