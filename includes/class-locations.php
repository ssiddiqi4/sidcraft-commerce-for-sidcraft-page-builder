<?php
/**
 * Countries and states (ISO 3166) for addresses, tax rates and shipping zones.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Class Locations
 */
class Locations {

	/**
	 * ISO 3166-1 alpha-2 => name.
	 *
	 * @return array
	 */
	public static function countries() {
		static $list = null;
		if ( null === $list ) {
			$list = array(
				'AF' => __( 'Afghanistan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AL' => __( 'Albania', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'DZ' => __( 'Algeria', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AS' => __( 'American Samoa', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AD' => __( 'Andorra', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AO' => __( 'Angola', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AI' => __( 'Anguilla', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AQ' => __( 'Antarctica', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AG' => __( 'Antigua and Barbuda', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AR' => __( 'Argentina', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AM' => __( 'Armenia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AW' => __( 'Aruba', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AU' => __( 'Australia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AT' => __( 'Austria', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AZ' => __( 'Azerbaijan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BS' => __( 'Bahamas', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BH' => __( 'Bahrain', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BD' => __( 'Bangladesh', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BB' => __( 'Barbados', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BY' => __( 'Belarus', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BE' => __( 'Belgium', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BZ' => __( 'Belize', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BJ' => __( 'Benin', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BM' => __( 'Bermuda', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BT' => __( 'Bhutan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BO' => __( 'Bolivia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BQ' => __( 'Bonaire, Sint Eustatius and Saba', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BA' => __( 'Bosnia and Herzegovina', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BW' => __( 'Botswana', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BV' => __( 'Bouvet Island', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BR' => __( 'Brazil', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'IO' => __( 'British Indian Ocean Territory', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BN' => __( 'Brunei', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BG' => __( 'Bulgaria', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BF' => __( 'Burkina Faso', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BI' => __( 'Burundi', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CV' => __( 'Cabo Verde', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'KH' => __( 'Cambodia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CM' => __( 'Cameroon', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CA' => __( 'Canada', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'KY' => __( 'Cayman Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CF' => __( 'Central African Republic', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TD' => __( 'Chad', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CL' => __( 'Chile', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CN' => __( 'China', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CX' => __( 'Christmas Island', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CC' => __( 'Cocos (Keeling) Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CO' => __( 'Colombia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'KM' => __( 'Comoros', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CG' => __( 'Congo', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CD' => __( 'Congo (DRC)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CK' => __( 'Cook Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CR' => __( 'Costa Rica', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'HR' => __( 'Croatia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CU' => __( 'Cuba', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CW' => __( 'Curaçao', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CY' => __( 'Cyprus', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CZ' => __( 'Czechia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CI' => __( 'Côte d\'Ivoire', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'DK' => __( 'Denmark', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'DJ' => __( 'Djibouti', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'DM' => __( 'Dominica', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'DO' => __( 'Dominican Republic', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'EC' => __( 'Ecuador', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'EG' => __( 'Egypt', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SV' => __( 'El Salvador', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GQ' => __( 'Equatorial Guinea', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'ER' => __( 'Eritrea', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'EE' => __( 'Estonia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SZ' => __( 'Eswatini', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'ET' => __( 'Ethiopia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'FK' => __( 'Falkland Islands (Malvinas)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'FO' => __( 'Faroe Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'FJ' => __( 'Fiji', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'FI' => __( 'Finland', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'FR' => __( 'France', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GF' => __( 'French Guiana', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PF' => __( 'French Polynesia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TF' => __( 'French Southern Territories', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GA' => __( 'Gabon', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GM' => __( 'Gambia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GE' => __( 'Georgia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'DE' => __( 'Germany', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GH' => __( 'Ghana', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GI' => __( 'Gibraltar', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GR' => __( 'Greece', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GL' => __( 'Greenland', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GD' => __( 'Grenada', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GP' => __( 'Guadeloupe', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GU' => __( 'Guam', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GT' => __( 'Guatemala', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GG' => __( 'Guernsey', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GN' => __( 'Guinea', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GW' => __( 'Guinea-Bissau', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GY' => __( 'Guyana', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'HT' => __( 'Haiti', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'HM' => __( 'Heard Island and McDonald Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'HN' => __( 'Honduras', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'HK' => __( 'Hong Kong', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'HU' => __( 'Hungary', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'IS' => __( 'Iceland', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'IN' => __( 'India', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'ID' => __( 'Indonesia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'IR' => __( 'Iran', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'IQ' => __( 'Iraq', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'IE' => __( 'Ireland', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'IM' => __( 'Isle of Man', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'IL' => __( 'Israel', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'IT' => __( 'Italy', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'JM' => __( 'Jamaica', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'JP' => __( 'Japan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'JE' => __( 'Jersey', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'JO' => __( 'Jordan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'KZ' => __( 'Kazakhstan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'KE' => __( 'Kenya', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'KI' => __( 'Kiribati', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'KW' => __( 'Kuwait', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'KG' => __( 'Kyrgyzstan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'LA' => __( 'Laos', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'LV' => __( 'Latvia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'LB' => __( 'Lebanon', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'LS' => __( 'Lesotho', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'LR' => __( 'Liberia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'LY' => __( 'Libya', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'LI' => __( 'Liechtenstein', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'LT' => __( 'Lithuania', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'LU' => __( 'Luxembourg', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MO' => __( 'Macao', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MG' => __( 'Madagascar', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MW' => __( 'Malawi', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MY' => __( 'Malaysia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MV' => __( 'Maldives', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'ML' => __( 'Mali', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MT' => __( 'Malta', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MH' => __( 'Marshall Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MQ' => __( 'Martinique', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MR' => __( 'Mauritania', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MU' => __( 'Mauritius', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'YT' => __( 'Mayotte', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MX' => __( 'Mexico', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'FM' => __( 'Micronesia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MD' => __( 'Moldova', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MC' => __( 'Monaco', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MN' => __( 'Mongolia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'ME' => __( 'Montenegro', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MS' => __( 'Montserrat', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MA' => __( 'Morocco', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MZ' => __( 'Mozambique', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MM' => __( 'Myanmar', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'NA' => __( 'Namibia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'NR' => __( 'Nauru', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'NP' => __( 'Nepal', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'NL' => __( 'Netherlands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'NC' => __( 'New Caledonia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'NZ' => __( 'New Zealand', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'NI' => __( 'Nicaragua', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'NE' => __( 'Niger', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'NG' => __( 'Nigeria', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'NU' => __( 'Niue', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'NF' => __( 'Norfolk Island', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'KP' => __( 'North Korea', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MK' => __( 'North Macedonia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MP' => __( 'Northern Mariana Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'NO' => __( 'Norway', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'OM' => __( 'Oman', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PK' => __( 'Pakistan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PW' => __( 'Palau', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PS' => __( 'Palestine', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PA' => __( 'Panama', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PG' => __( 'Papua New Guinea', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PY' => __( 'Paraguay', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PE' => __( 'Peru', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PH' => __( 'Philippines', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PN' => __( 'Pitcairn', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PL' => __( 'Poland', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PT' => __( 'Portugal', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PR' => __( 'Puerto Rico', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'QA' => __( 'Qatar', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'RO' => __( 'Romania', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'RU' => __( 'Russia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'RW' => __( 'Rwanda', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'RE' => __( 'Réunion', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'BL' => __( 'Saint Barthélemy', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SH' => __( 'Saint Helena, Ascension and Tristan da Cunha', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'KN' => __( 'Saint Kitts and Nevis', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'LC' => __( 'Saint Lucia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'MF' => __( 'Saint Martin (French part)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'PM' => __( 'Saint Pierre and Miquelon', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'VC' => __( 'Saint Vincent and the Grenadines', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'WS' => __( 'Samoa', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SM' => __( 'San Marino', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'ST' => __( 'Sao Tome and Principe', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SA' => __( 'Saudi Arabia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SN' => __( 'Senegal', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'RS' => __( 'Serbia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SC' => __( 'Seychelles', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SL' => __( 'Sierra Leone', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SG' => __( 'Singapore', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SX' => __( 'Sint Maarten (Dutch part)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SK' => __( 'Slovakia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SI' => __( 'Slovenia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SB' => __( 'Solomon Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SO' => __( 'Somalia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'ZA' => __( 'South Africa', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GS' => __( 'South Georgia and the South Sandwich Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'KR' => __( 'South Korea', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SS' => __( 'South Sudan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'ES' => __( 'Spain', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'LK' => __( 'Sri Lanka', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SD' => __( 'Sudan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SR' => __( 'Suriname', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SJ' => __( 'Svalbard and Jan Mayen', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SE' => __( 'Sweden', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'CH' => __( 'Switzerland', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'SY' => __( 'Syria', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TW' => __( 'Taiwan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TJ' => __( 'Tajikistan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TZ' => __( 'Tanzania', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TH' => __( 'Thailand', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TL' => __( 'Timor-Leste', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TG' => __( 'Togo', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TK' => __( 'Tokelau', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TO' => __( 'Tonga', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TT' => __( 'Trinidad and Tobago', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TN' => __( 'Tunisia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TM' => __( 'Turkmenistan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TC' => __( 'Turks and Caicos Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TV' => __( 'Tuvalu', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'TR' => __( 'Türkiye', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'UG' => __( 'Uganda', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'UA' => __( 'Ukraine', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AE' => __( 'United Arab Emirates', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'GB' => __( 'United Kingdom', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'US' => __( 'United States', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'UM' => __( 'United States Minor Outlying Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'UY' => __( 'Uruguay', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'UZ' => __( 'Uzbekistan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'VU' => __( 'Vanuatu', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'VA' => __( 'Vatican City', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'VE' => __( 'Venezuela', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'VN' => __( 'Vietnam', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'VG' => __( 'Virgin Islands, British', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'VI' => __( 'Virgin Islands, U.S.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'WF' => __( 'Wallis and Futuna', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'EH' => __( 'Western Sahara', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'YE' => __( 'Yemen', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'ZM' => __( 'Zambia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'ZW' => __( 'Zimbabwe', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'AX' => __( 'Åland Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			);
			/**
			 * Filter the country list (e.g. rename or remove entries).
			 *
			 * @param array $list Code => name.
			 */
			$list = (array) apply_filters( 'sidcraft_commerce_countries', $list );
		}
		return $list;
	}

	/**
	 * States/provinces for the countries where they matter for tax.
	 *
	 * @return array Country => [ code => name ].
	 */
	public static function all_states() {
		static $list = null;
		if ( null === $list ) {
			$list = array(
				'US' => array(
					'AL' => __( 'Alabama', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'AK' => __( 'Alaska', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'AZ' => __( 'Arizona', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'AR' => __( 'Arkansas', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'CA' => __( 'California', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'CO' => __( 'Colorado', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'CT' => __( 'Connecticut', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'DE' => __( 'Delaware', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'DC' => __( 'District of Columbia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'FL' => __( 'Florida', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'GA' => __( 'Georgia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'HI' => __( 'Hawaii', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'ID' => __( 'Idaho', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'IL' => __( 'Illinois', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'IN' => __( 'Indiana', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'IA' => __( 'Iowa', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'KS' => __( 'Kansas', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'KY' => __( 'Kentucky', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'LA' => __( 'Louisiana', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'ME' => __( 'Maine', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'MD' => __( 'Maryland', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'MA' => __( 'Massachusetts', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'MI' => __( 'Michigan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'MN' => __( 'Minnesota', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'MS' => __( 'Mississippi', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'MO' => __( 'Missouri', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'MT' => __( 'Montana', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NE' => __( 'Nebraska', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NV' => __( 'Nevada', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NH' => __( 'New Hampshire', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NJ' => __( 'New Jersey', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NM' => __( 'New Mexico', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NY' => __( 'New York', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NC' => __( 'North Carolina', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'ND' => __( 'North Dakota', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'OH' => __( 'Ohio', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'OK' => __( 'Oklahoma', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'OR' => __( 'Oregon', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'PA' => __( 'Pennsylvania', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'PR' => __( 'Puerto Rico', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'RI' => __( 'Rhode Island', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'SC' => __( 'South Carolina', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'SD' => __( 'South Dakota', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'TN' => __( 'Tennessee', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'TX' => __( 'Texas', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'UT' => __( 'Utah', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'VT' => __( 'Vermont', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'VA' => __( 'Virginia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'WA' => __( 'Washington', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'WV' => __( 'West Virginia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'WI' => __( 'Wisconsin', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'WY' => __( 'Wyoming', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'CA' => array(
					'AB' => __( 'Alberta', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'BC' => __( 'British Columbia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'MB' => __( 'Manitoba', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NB' => __( 'New Brunswick', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NL' => __( 'Newfoundland and Labrador', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NT' => __( 'Northwest Territories', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NS' => __( 'Nova Scotia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NU' => __( 'Nunavut', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'ON' => __( 'Ontario', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'PE' => __( 'Prince Edward Island', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'QC' => __( 'Quebec', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'SK' => __( 'Saskatchewan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'YT' => __( 'Yukon', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'AU' => array(
					'ACT' => __( 'Australian Capital Territory', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NSW' => __( 'New South Wales', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NT'  => __( 'Northern Territory', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'QLD' => __( 'Queensland', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'SA'  => __( 'South Australia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'TAS' => __( 'Tasmania', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'VIC' => __( 'Victoria', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'WA'  => __( 'Western Australia', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'IN' => array(
					'AN' => __( 'Andaman and Nicobar Islands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'AP' => __( 'Andhra Pradesh', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'AR' => __( 'Arunachal Pradesh', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'AS' => __( 'Assam', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'BR' => __( 'Bihar', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'CH' => __( 'Chandigarh', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'CG' => __( 'Chhattisgarh', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'DL' => __( 'Delhi', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'DH' => __( 'Dadra and Nagar Haveli and Daman and Diu', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'GA' => __( 'Goa', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'GJ' => __( 'Gujarat', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'HR' => __( 'Haryana', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'HP' => __( 'Himachal Pradesh', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'JK' => __( 'Jammu and Kashmir', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'JH' => __( 'Jharkhand', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'KA' => __( 'Karnataka', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'KL' => __( 'Kerala', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'LA' => __( 'Ladakh', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'LD' => __( 'Lakshadweep', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'MP' => __( 'Madhya Pradesh', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'MH' => __( 'Maharashtra', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'MN' => __( 'Manipur', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'ML' => __( 'Meghalaya', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'MZ' => __( 'Mizoram', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'NL' => __( 'Nagaland', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'OD' => __( 'Odisha', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'PY' => __( 'Puducherry', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'PB' => __( 'Punjab', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'RJ' => __( 'Rajasthan', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'SK' => __( 'Sikkim', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'TN' => __( 'Tamil Nadu', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'TS' => __( 'Telangana', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'TR' => __( 'Tripura', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'UP' => __( 'Uttar Pradesh', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'UK' => __( 'Uttarakhand', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'WB' => __( 'West Bengal', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
			);
			/**
			 * Filter state lists (add a country's regions, keyed by code).
			 *
			 * @param array $list Country => [ code => name ].
			 */
			$list = (array) apply_filters( 'sidcraft_commerce_states', $list );
		}
		return $list;
	}

	/**
	 * States of one country ([] when the country has no list).
	 *
	 * @param string $country Code.
	 * @return array
	 */
	public static function states( $country ) {
		$all = self::all_states();
		return $all[ strtoupper( (string) $country ) ] ?? array();
	}

	/**
	 * Country name.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function country_name( $code ) {
		$list = self::countries();
		$code = strtoupper( (string) $code );
		return $list[ $code ] ?? $code;
	}

	/**
	 * Turn "us", "United States" or "United States (US)" into "US"; '' if unknown.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	public static function normalize_country( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$list = self::countries();
		$code = strtoupper( $value );
		if ( isset( $list[ $code ] ) ) {
			return $code;
		}
		$aliases = array(
			'USA'           => 'US',
			'UNITED STATES' => 'US',
			'UK'            => 'GB',
			'GREAT BRITAIN' => 'GB',
			'ENGLAND'       => 'GB',
		);
		if ( isset( $aliases[ $code ] ) ) {
			return $aliases[ $code ];
		}
		foreach ( $list as $c => $name ) {
			if ( 0 === strcasecmp( $name, $value ) || 0 === strcasecmp( trim( preg_replace( '/\s*\([^)]*\)$/', '', $name ) ), $value ) ) {
				return $c;
			}
		}
		return '';
	}

	/**
	 * Turn a state name or code into its code for countries with a list;
	 * other countries keep the text as typed.
	 *
	 * @param string $country Country code.
	 * @param string $value   Input.
	 * @return string
	 */
	public static function normalize_state( $country, $value ) {
		$value  = trim( (string) $value );
		$states = self::states( $country );
		if ( '' === $value || ! $states ) {
			return $value;
		}
		$code = strtoupper( $value );
		if ( isset( $states[ $code ] ) ) {
			return $code;
		}
		foreach ( $states as $c => $name ) {
			if ( 0 === strcasecmp( $name, $value ) ) {
				return $c;
			}
		}
		return $value;
	}

	/**
	 * State name for display.
	 *
	 * @param string $country Country code.
	 * @param string $state   State code or text.
	 * @return string
	 */
	public static function state_name( $country, $state ) {
		$states = self::states( $country );
		return $states[ $state ] ?? (string) $state;
	}
}
