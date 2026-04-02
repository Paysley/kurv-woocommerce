<?php

declare(strict_types=1);

/**
 * Kurv API Class
 *
 * @package Kurv
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all HTTP communication with the Kurv API.
 *
 * Authentication is Bearer-token based. Merchants enter their API key in the
 * WooCommerce gateway settings; this class receives it via the static
 * $access_key property set by WC_Kurv::init_api().
 *
 * @since 1.0.0
 */
class Kurv_API {

	/**
	 * API access key (Bearer token), set from gateway settings.
	 *
	 * @var string
	 */
	public static string $access_key = '';

	/**
	 * Whether to use the sandbox API endpoint.
	 *
	 * @var bool
	 */
	public static bool $is_test_mode = false;

	/**
	 * Live API base URL.
	 *
	 * @var string
	 */
	public static string $api_live_url = 'https://live.kurv.app';

	/**
	 * Sandbox API base URL.
	 *
	 * @var string
	 */
	public static string $api_test_url = 'https://api-sandbox.kurv.app';

	/**
	 * Return the active API base URL based on mode.
	 */
	public static function get_api_url(): string {
		return self::$is_test_mode ? self::$api_test_url : self::$api_live_url;
	}

	/**
	 * Send an authenticated request to the Kurv API.
	 *
	 * Automatically retries on 429 (rate limit) responses using exponential
	 * backoff: 1s → 2s → 4s, up to 3 attempts total.
	 *
	 * Returns a WP_Error on network/transport failure. Callers must check
	 * is_wp_error() before accessing the response body.
	 *
	 * @param string              $url    Full endpoint URL.
	 * @param array<mixed>|string $body   Request body — array for POST/PUT, empty string for GET.
	 * @param string              $method HTTP method (GET, POST, PUT).
	 * @param int                 $attempt Internal retry counter — do not pass manually.
	 * @return array<mixed>|\WP_Error
	 */
	public static function send_request( string $url, array|string $body = '', string $method = 'GET', int $attempt = 1 ): array|\WP_Error {
		$api_args = [
			'headers' => [ 'Authorization' => 'Bearer ' . self::$access_key ],
			'method'  => strtoupper( $method ),
			'timeout' => 70,
		];

		if ( 'POST' === $method || 'PUT' === $method ) {
			$api_args['headers']['Content-Type'] = 'application/json';
			$api_args['body']                    = wp_json_encode( $body );
		} else {
			$api_args['body'] = $body;
		}

		$response = wp_remote_request( $url, $api_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Retry on 429 (rate limited) with exponential backoff: 1s, 2s, 4s.
		if ( 429 === (int) wp_remote_retrieve_response_code( $response ) && $attempt <= 3 ) {
			sleep( (int) pow( 2, $attempt - 1 ) );
			return self::send_request( $url, $body, $method, $attempt + 1 );
		}

		if ( is_string( $response['body'] ) ) {
			$response['body'] = json_decode( $response['body'], true );
		}

		return $response;
	}

	/**
	 * Create a payment request (POS link).
	 *
	 * @param array<mixed> $body
	 * @return array<mixed>|\WP_Error
	 */
	public static function generate_pos_link( array $body ): array|\WP_Error {
		return self::send_request( self::get_api_url() . '/payment-requests/', $body, 'POST' );
	}

	/**
	 * Fetch payment details by payment ID.
	 *
	 * @return array<mixed>|\WP_Error
	 */
	public static function get_payment( string $payment_id ): array|\WP_Error {
		return self::send_request( self::get_api_url() . '/payments/' . rawurlencode( $payment_id ) );
	}

	/**
	 * Submit a refund for a payment.
	 *
	 * @param array<mixed> $body Keys: amount (required), email, mobile_number.
	 * @return array<mixed>|\WP_Error
	 */
	public static function do_refund( string $payment_id, array $body ): array|\WP_Error {
		return self::send_request( self::get_api_url() . '/refunds/' . rawurlencode( $payment_id ), $body, 'POST' );
	}

	/**
	 * Capture a pre-authorised payment (payment_type: PA).
	 *
	 * @param array<mixed> $body Keys: amount (required, cannot exceed original authorised amount).
	 * @return array<mixed>|\WP_Error
	 */
	public static function capture_payment( string $payment_id, array $body ): array|\WP_Error {
		return self::send_request( self::get_api_url() . '/captures/' . rawurlencode( $payment_id ), $body, 'POST' );
	}

	/**
	 * Create a product category.
	 *
	 * @param array<mixed> $body
	 * @return array<mixed>|\WP_Error
	 */
	public static function create_category( array $body ): array|\WP_Error {
		return self::send_request( self::get_api_url() . '/products-services/category', $body, 'POST' );
	}

	/**
	 * Fetch the list of product categories, optionally filtered by name.
	 *
	 * @return array<mixed>|\WP_Error
	 */
	public static function category_list( ?string $category_name = null ): array|\WP_Error {
		$url = self::get_api_url() . '/products-services/category/';
		if ( $category_name ) {
			$url = add_query_arg( 'keywords', rawurlencode( $category_name ), $url );
		}
		return self::send_request( $url );
	}

	/**
	 * Create a new product/service.
	 *
	 * @param array<mixed> $body
	 * @return array<mixed>|\WP_Error
	 */
	public static function create_product( array $body ): array|\WP_Error {
		return self::send_request( self::get_api_url() . '/products-services', $body, 'POST' );
	}

	/**
	 * Update an existing product/service.
	 *
	 * @param array<mixed> $body
	 * @return array<mixed>|\WP_Error
	 */
	public static function update_product( array $body ): array|\WP_Error {
		return self::send_request( self::get_api_url() . '/products-services', $body, 'PUT' );
	}

	/**
	 * Search customers by keyword (typically email).
	 *
	 * @return array<mixed>|\WP_Error
	 */
	public static function customers( ?string $search_keyword = null ): array|\WP_Error {
		$url = self::get_api_url() . '/customers';
		if ( $search_keyword ) {
			$url = add_query_arg( 'keywords', rawurlencode( $search_keyword ), $url );
		}
		return self::send_request( $url );
	}

	/**
	 * Create a new customer record.
	 *
	 * @param array<mixed> $body
	 * @return array<mixed>|\WP_Error
	 */
	public static function create_customer( array $body ): array|\WP_Error {
		return self::send_request( self::get_api_url() . '/customers', $body, 'POST' );
	}

	/**
	 * Update an existing customer record.
	 *
	 * The customer ID is passed as a path parameter per the Kurv API spec:
	 * PUT /customers/{customer_id}
	 *
	 * @param string       $customer_id Kurv customer ID.
	 * @param array<mixed> $body        Customer fields to update (must NOT include customer_id).
	 * @return array<mixed>|\WP_Error
	 */
	public static function update_customer( string $customer_id, array $body ): array|\WP_Error {
		return self::send_request( self::get_api_url() . '/customers/' . rawurlencode( $customer_id ), $body, 'PUT' );
	}
}
