<?php
/**
 * Server-side postcode validation, geocoding, and service-area checks.
 *
 * The GeoJSON polygon (point-in-polygon) check runs client-side in checker.js.
 * This class only handles the parts that are best done on the server: format
 * validation, the Postcodes.io lookup, and the distance and county checks.
 * The service-area centre, radius, and allowed areas come from the settings.
 *
 * @package Conservation_Area_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Geo helper for the checker.
 */
class CAC_Geocheck {

	/**
	 * Validate a UK postcode format.
	 *
	 * Accepts the postcode with or without the internal space.
	 *
	 * @param string $postcode Raw postcode.
	 * @return bool True when the format looks like a UK postcode.
	 */
	public function is_valid_format( $postcode ) {
		$postcode = strtoupper( trim( (string) $postcode ) );
		return (bool) preg_match( '/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/', $postcode );
	}

	/**
	 * Service-area centre latitude from the settings.
	 *
	 * @return float
	 */
	public function centre_lat() {
		return (float) CAC_Settings::get( 'centre_lat' );
	}

	/**
	 * Service-area centre longitude from the settings.
	 *
	 * @return float
	 */
	public function centre_lon() {
		return (float) CAC_Settings::get( 'centre_lon' );
	}

	/**
	 * Maximum install distance from the centre, in miles, from the settings.
	 *
	 * @return float
	 */
	public function max_distance_miles() {
		return (float) CAC_Settings::get( 'radius_miles' );
	}

	/**
	 * Counties and districts we treat as inside the service area.
	 *
	 * @return string[]
	 */
	public function allowed_areas() {
		return CAC_Settings::allowed_areas();
	}

	/**
	 * Look up a postcode with the Postcodes.io REST API.
	 *
	 * @param string $postcode Validated postcode (spaces are fine).
	 * @return array|WP_Error Normalised location data, or WP_Error on failure.
	 */
	public function lookup( $postcode ) {
		$clean = rawurlencode( strtoupper( str_replace( ' ', '', (string) $postcode ) ) );
		$url   = 'https://api.postcodes.io/postcodes/' . $clean;

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'cac_lookup_failed',
				__( 'We could not look up that postcode. Please check it and try again.', 'conservation-area-checker' )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['result'] ) || ! is_array( $body['result'] ) ) {
			return new WP_Error(
				'cac_no_result',
				__( 'We could not find that postcode. Please check it and try again.', 'conservation-area-checker' )
			);
		}

		$result = $body['result'];

		return array(
			'postcode'                   => isset( $result['postcode'] ) ? (string) $result['postcode'] : '',
			'latitude'                   => isset( $result['latitude'] ) ? (float) $result['latitude'] : null,
			'longitude'                  => isset( $result['longitude'] ) ? (float) $result['longitude'] : null,
			'admin_county'               => isset( $result['admin_county'] ) ? (string) $result['admin_county'] : '',
			'admin_district'             => isset( $result['admin_district'] ) ? (string) $result['admin_district'] : '',
			'parliamentary_constituency' => isset( $result['parliamentary_constituency'] ) ? (string) $result['parliamentary_constituency'] : '',
		);
	}

	/**
	 * Great-circle distance between two points using the Haversine formula.
	 *
	 * @param float $lat1 First latitude.
	 * @param float $lon1 First longitude.
	 * @param float $lat2 Second latitude.
	 * @param float $lon2 Second longitude.
	 * @return float Distance in miles.
	 */
	public function haversine_miles( $lat1, $lon1, $lat2, $lon2 ) {
		$earth_radius_miles = 3958.8;

		$lat1_rad  = deg2rad( (float) $lat1 );
		$lat2_rad  = deg2rad( (float) $lat2 );
		$delta_lat = deg2rad( (float) $lat2 - (float) $lat1 );
		$delta_lon = deg2rad( (float) $lon2 - (float) $lon1 );

		$a = sin( $delta_lat / 2 ) * sin( $delta_lat / 2 )
			+ cos( $lat1_rad ) * cos( $lat2_rad )
			* sin( $delta_lon / 2 ) * sin( $delta_lon / 2 );

		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

		return $earth_radius_miles * $c;
	}

	/**
	 * Distance of a location from the configured service centre, in miles.
	 *
	 * @param float $lat Latitude.
	 * @param float $lon Longitude.
	 * @return float Distance in miles.
	 */
	public function distance_from_centre( $lat, $lon ) {
		return $this->haversine_miles( $this->centre_lat(), $this->centre_lon(), $lat, $lon );
	}

	/**
	 * Whether a county or district string is in the allowed list.
	 *
	 * @param string $area County or district name.
	 * @return bool
	 */
	public function is_allowed_area( $area ) {
		$area = trim( (string) $area );
		if ( '' === $area ) {
			return false;
		}

		foreach ( $this->allowed_areas() as $allowed ) {
			if ( 0 === strcasecmp( $allowed, $area ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decide whether a looked-up location sits inside the service area.
	 *
	 * In area means within the install radius of the centre and inside an
	 * allowed county or district. Both the admin_county and admin_district
	 * fields are checked because Postcodes.io populates them inconsistently.
	 * When no allowed areas are configured, distance alone decides.
	 *
	 * @param array $location Normalised data from lookup().
	 * @return bool
	 */
	public function is_in_service_area( $location ) {
		if ( null === $location['latitude'] || null === $location['longitude'] ) {
			return false;
		}

		$distance = $this->distance_from_centre( $location['latitude'], $location['longitude'] );
		if ( $distance > $this->max_distance_miles() ) {
			return false;
		}

		$allowed = $this->allowed_areas();
		if ( empty( $allowed ) ) {
			// No county filter configured: rely on the distance check only.
			return true;
		}

		return $this->is_allowed_area( $location['admin_county'] )
			|| $this->is_allowed_area( $location['admin_district'] );
	}
}
