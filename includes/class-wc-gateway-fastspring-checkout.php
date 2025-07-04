<?php
/**
 * FastSpring Gateway Checkout Class
 * 
 * Extends WC_Checkout class to add Fast Spring specific checkout methods.
 * 
 * @class WC_Gateway_FastSpring_Checkout
 * @extends WC_Checkout
 * 
 * @package WooCommerce_FastSpring
 * @since 2.0.0
 */

defined( 'ABSPATH' ) OR die;

class WC_Gateway_FastSpring_Checkout extends WC_Checkout {

	/**
	 * Fastspring Validate Checkout
	 * 
	 * @return WP_Error
	 * 
	 * @since 2.0.0
	 */
	public function fs_validate_checkout() {
		$errors      = new WP_Error();
		$posted_data = $this->get_posted_data();

		// Validate posted data and cart items before proceeding.
		$this->validate_checkout( $posted_data, $errors );

		return $errors;
	}

	public function fs_process_customer( $form_data ) {
		try {
			$this->process_customer( $form_data );
		} catch ( Exception $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}
}