<?php
/**
 * Checkout field editor: add your own fields, rename, require or hide the
 * built-in ones, and show a field only when another has a given value.
 *
 * Settings → Store tools → Checkout fields:
 *   cf_fields  rows: key, label, type (text, textarea, email, tel, number,
 *              date, select), required (yes), choices (select: one|two|three),
 *              show_if (e.g. country=US or delivery=pickup).
 *   cf_edit    rows: key of a built-in field (phone, address_2…), new label,
 *              required (yes / no / empty = unchanged), hide (yes).
 * Values are saved on the order and shown with the order, like fields added
 * by add-ons. A field hidden by its condition is not required.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Checkout\Checkout;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Checkout_Fields
 */
class Checkout_Fields extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$this->settings(
			'fields',
			__( 'Checkout fields', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'cf_fields' => array(
					__( 'Extra fields', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'table',
					__( 'Key: letters, numbers and underscores (for example gift_note). Type: text, textarea, email, tel, number, date or select. Required: yes. Choices: for select, separated by | . Show if: key=value of another field, for example country=US.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					array(
						'key'      => array( __( 'Key', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', 'gift_note' ),
						'label'    => array( __( 'Label', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', 'Gift message' ),
						'type'     => array( __( 'Type', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', 'textarea' ),
						'required' => array( __( 'Required', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', '' ),
						'choices'  => array( __( 'Choices', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', '' ),
						'show_if'  => array( __( 'Show if', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', '' ),
					),
				),
				'cf_edit'   => array(
					__( 'Change built-in fields', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'table',
					__( 'Key: first_name, last_name, email, phone, address_1, address_2, city, postcode, state. Required: yes or no (empty keeps it). Hide: yes. The email field can’t be hidden.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					array(
						'key'      => array( __( 'Key', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', 'phone' ),
						'label'    => array( __( 'New label', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', '' ),
						'required' => array( __( 'Required', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', 'yes' ),
						'hide'     => array( __( 'Hide', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', '' ),
					),
				),
			)
		);
		add_filter( 'sidcraft_commerce_checkout_fields', array( $this, 'fields' ), 30 );
		add_filter( 'sidcraft_commerce_js_config', array( $this, 'config' ) );
	}

	/**
	 * Custom field definitions, cleaned.
	 *
	 * @return array[]
	 */
	protected function custom() {
		$out   = array();
		$types = array( 'text', 'textarea', 'email', 'tel', 'number', 'date', 'select' );
		foreach ( (array) setting( 'cf_fields', array() ) as $row ) {
			$key = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $row['key'] ?? '' ) ) );
			if ( '' === $key || ! empty( $out[ $key ] ) ) {
				continue;
			}
			$type = in_array( strtolower( (string) ( $row['type'] ?? '' ) ), $types, true ) ? strtolower( (string) $row['type'] ) : 'text';
			$out[ $key ] = array(
				'label'    => '' !== trim( (string) ( $row['label'] ?? '' ) ) ? (string) $row['label'] : $key,
				'type'     => $type,
				'required' => in_array( strtolower( (string) ( $row['required'] ?? '' ) ), array( 'yes', '1', 'true' ), true ),
				'choices'  => array_filter( array_map( 'trim', explode( '|', (string) ( $row['choices'] ?? '' ) ) ), 'strlen' ),
				'show_if'  => trim( (string) ( $row['show_if'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * Does a "key=value" condition hold for the submitted values?
	 *
	 * @param string $rule Rule.
	 * @param array  $vals Values.
	 * @return bool
	 */
	protected function holds( $rule, array $vals ) {
		if ( '' === $rule || false === strpos( $rule, '=' ) ) {
			return true;
		}
		list( $k, $v ) = array_map( 'trim', explode( '=', $rule, 2 ) );
		return isset( $vals[ $k ] ) && (string) $vals[ $k ] === $v;
	}

	/**
	 * Apply the editor to the field list.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public function fields( $fields ) {
		foreach ( (array) setting( 'cf_edit', array() ) as $row ) {
			$key = (string) ( $row['key'] ?? '' );
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}
			if ( 'email' !== $key && in_array( strtolower( (string) ( $row['hide'] ?? '' ) ), array( 'yes', '1', 'true' ), true ) && ! is_admin() ) {
				unset( $fields[ $key ] );
				continue;
			}
			if ( '' !== trim( (string) ( $row['label'] ?? '' ) ) ) {
				$fields[ $key ][0] = (string) $row['label'];
			}
			$req = strtolower( (string) ( $row['required'] ?? '' ) );
			if ( in_array( $req, array( 'yes', '1', 'true' ), true ) ) {
				$fields[ $key ][2] = true;
			} elseif ( in_array( $req, array( 'no', '0', 'false' ), true ) && 'email' !== $key ) {
				$fields[ $key ][2] = false;
			}
		}
		$input = Checkout::input(); // Empty while the form is drawn; the submission while it is checked.
		foreach ( $this->custom() as $key => $f ) {
			if ( isset( $fields[ $key ] ) ) {
				continue;
			}
			$required = $f['required'] && ( ! $input || $this->holds( $f['show_if'], $input ) );
			$def      = array( $f['label'], $f['type'], $required, 'off', 'textarea' === $f['type'] ? 'full' : 'half' );
			if ( 'select' === $f['type'] ) {
				$def[5] = array_combine( $f['choices'], $f['choices'] );
			}
			$fields[ $key ] = $def;
		}
		return $fields;
	}

	/**
	 * Conditions for the script: field => rule.
	 *
	 * @param array $config Config.
	 * @return array
	 */
	public function config( $config ) {
		$rules = array();
		foreach ( $this->custom() as $key => $f ) {
			if ( '' !== $f['show_if'] ) {
				$rules[ $key ] = $f['show_if'];
			}
		}
		if ( $rules ) {
			$config['fieldRules'] = $rules;
		}
		return $config;
	}
}
