<?php

namespace NimboCDN;

defined( 'ABSPATH' ) || exit;

class Api {

	const BASE = 'https://nimbocdn-prd-control.nimbocdn.workers.dev';

	public static function base() {
		if ( defined( 'NIMBOCDN_CONTROL_URL' ) && is_string( NIMBOCDN_CONTROL_URL ) && '' !== NIMBOCDN_CONTROL_URL ) {
			return rtrim( NIMBOCDN_CONTROL_URL, '/' );
		}
		return self::BASE;
	}

	const TIMEOUT = 12;

	public static function post( $path, array $body = array(), $with_auth = true, $timeout = self::TIMEOUT ) {
		$headers = array( 'Content-Type' => 'application/json' );

		if ( $with_auth ) {
			$creds = Settings_Store::credentials();
			if ( '' === $creds['license_key'] ) {
				return array(
					'ok'     => false,
					'status' => 0,
					'data'   => array(),
				);
			}
			$headers['Authorization'] = 'Bearer ' . $creds['license_key'];
		}

		$response = wp_remote_post(
			self::base() . $path,
			array(
				'timeout'     => max( 1, (int) $timeout ),
				'redirection' => 2,
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'data'   => array(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'ok'     => $status >= 200 && $status < 300,
			'status' => $status,
			'data'   => is_array( $parsed ) ? $parsed : array(),
		);
	}

	public static function get( $path, array $query = array() ) {
		$url = self::base() . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 2,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'data'   => array(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'ok'     => $status >= 200 && $status < 300,
			'status' => $status,
			'data'   => is_array( $parsed ) ? $parsed : array(),
		);
	}

	public static function account() {
		$response = self::account_response();
		return $response['ok'] && ! empty( $response['data'] ) ? $response['data'] : null;
	}

	public static function account_response() {
		$creds = Settings_Store::credentials();
		if ( '' === $creds['license_key'] ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'data'   => array(),
			);
		}

		$response = wp_remote_get(
			self::base() . '/account',
			array(
				'timeout'     => 5,
				'redirection' => 2,
				'headers'     => array(
					'Accept'            => 'application/json',
					'Authorization'     => 'Bearer ' . $creds['license_key'],
					'X-Nimbocdn-Locale' => get_locale(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'data'   => array(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		return array(
			'ok'     => $status >= 200 && $status < 300,
			'status' => $status,
			'data'   => is_array( $data ) ? $data : array(),
		);
	}

	public static function update_email( $email ) {
		$res = self::post( '/account/email', array( 'email' => $email ) );
		$saved = $res['ok'] || ( 502 === (int) $res['status'] && ! empty( $res['data']['email'] ) );
		return array(
			'ok'     => $saved,
			'synced' => $res['ok'] && ! empty( $res['data']['billing_synced'] ),
		);
	}

	public static function update_marketing( $opt_out ) {
		$res = self::post( '/account/marketing', array( 'opt_out' => (bool) $opt_out ) );
		return (bool) $res['ok'];
	}

	public static function report_marketing_notice() {
		$res = self::post( '/account/marketing', array( 'notice' => true ) );
		return array(
			'ok'      => (bool) $res['ok'],
			'opt_out' => ! empty( $res['data']['marketing_opt_out'] ),
		);
	}

	public static function checkout_url( $return_url, $interval = 'month', &$reason = '' ) {
		$res = self::post(
			'/billing/checkout',
			array(
				'return_url' => $return_url,
				'interval'   => 'year' === $interval ? 'year' : 'month',
				'locale'     => get_user_locale(),
			)
		);
		if ( 403 === $res['status'] && isset( $res['data']['error'] ) && 'unverified' === $res['data']['error'] ) {
			$reason = 'unverified';
		}
		return ( $res['ok'] && ! empty( $res['data']['url'] ) ) ? (string) $res['data']['url'] : null;
	}

	public static function plan_preview( $interval ) {
		$res = self::post( '/billing/plan/preview', array( 'interval' => $interval ) );
		return array(
			'ok'              => (bool) $res['ok'],
			'data'            => is_array( $res['data'] ) ? $res['data'] : array(),
			'no_subscription' => 409 === $res['status'],
			'manual_pro'      => 409 === $res['status'] && isset( $res['data']['reason'] ) && 'pro_without_subscription' === $res['data']['reason'],
			'unverified'      => 403 === $res['status'],
		);
	}

	public static function plan_switch( $interval, $return_url = '' ) {
		$body = array( 'interval' => $interval );
		if ( '' !== $return_url ) {
			$body['return_url'] = $return_url;
		}
		$res = self::post( '/billing/plan/switch', $body );
		return array(
			'ok'              => (bool) $res['ok'],
			'data'            => is_array( $res['data'] ) ? $res['data'] : array(),
			'no_subscription' => 409 === $res['status'],
			'manual_pro'      => 409 === $res['status'] && isset( $res['data']['reason'] ) && 'pro_without_subscription' === $res['data']['reason'],
		);
	}

	public static function portal_url( $return_url ) {
		$res = self::post( '/billing/portal', array( 'return_url' => $return_url ) );
		return array(
			'url'             => ( $res['ok'] && ! empty( $res['data']['url'] ) ) ? (string) $res['data']['url'] : null,
			'no_subscription' => 409 === $res['status'],
			'manual_pro'      => 409 === $res['status'] && isset( $res['data']['reason'] ) && 'pro_without_subscription' === $res['data']['reason'],
		);
	}
}
