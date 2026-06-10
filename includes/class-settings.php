<?php
/**
 * Plugin settings: the admin configuration page and value accessors.
 *
 * Everything that is specific to a particular installer (the service-area
 * centre, radius, allowed counties, and the call to action) is configured
 * here rather than hardcoded, so the plugin can be reused on any site.
 *
 * @package Conservation_Area_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings handler.
 */
class CAC_Settings {

	/**
	 * Settings page slug (under Settings in wp-admin).
	 */
	const PAGE_SLUG = 'conservation-area-checker';

	/**
	 * Settings group used by the Settings API.
	 */
	const GROUP = 'cac_settings_group';

	/**
	 * Hook into WordPress.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'wp_ajax_cac_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_cac_test_postcode', array( $this, 'ajax_test_postcode' ) );
	}

	/**
	 * Enqueue the settings-page helper script, only on our settings page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'cac-admin',
			CAC_URL . 'assets/admin.js',
			array(),
			CAC_VERSION,
			true
		);

		wp_localize_script(
			'cac-admin',
			'cacAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cac_test_connection' ),
				'testing' => __( 'Testing the connection to the planning data service...', 'conservation-area-checker' ),
				'okMsg'   => __( 'Connected. The live lookup can reach the planning data service.', 'conservation-area-checker' ),
				'failMsg' => __( 'There is a problem reaching the planning data service. See the details below.', 'conservation-area-checker' ),
				'failed'  => __( 'The test could not be completed. Please try again.', 'conservation-area-checker' ),
				'pc'      => array(
					'nonce'    => wp_create_nonce( 'cac_test_postcode' ),
					'checking' => __( 'Checking postcode...', 'conservation-area-checker' ),
					'enter'    => __( 'Please enter a postcode.', 'conservation-area-checker' ),
					'failed'   => __( 'The check could not be completed. Please try again.', 'conservation-area-checker' ),
					'yes'      => __( 'Yes', 'conservation-area-checker' ),
					'no'       => __( 'No', 'conservation-area-checker' ),
					'none'     => __( '(none returned)', 'conservation-area-checker' ),
					'labels'   => array(
						'postcode'     => __( 'Postcode', 'conservation-area-checker' ),
						'coords'       => __( 'Coordinates', 'conservation-area-checker' ),
						'county'       => __( 'County (admin_county)', 'conservation-area-checker' ),
						'district'     => __( 'District (admin_district)', 'conservation-area-checker' ),
						'constituency' => __( 'Constituency', 'conservation-area-checker' ),
						'distance'     => __( 'Distance from service centre', 'conservation-area-checker' ),
						'inArea'       => __( 'In service area', 'conservation-area-checker' ),
						'conservation' => __( 'Conservation area', 'conservation-area-checker' ),
						'article4'     => __( 'Article 4 Direction area', 'conservation-area-checker' ),
						'final'        => __( 'Result shown to visitor', 'conservation-area-checker' ),
						'miles'        => __( 'miles', 'conservation-area-checker' ),
					),
					'states'   => array(
						'outside'      => __( 'Outside service area', 'conservation-area-checker' ),
						'none'         => __( 'No restrictions found', 'conservation-area-checker' ),
						'conservation' => __( 'Conservation area', 'conservation-area-checker' ),
						'article4'     => __( 'Article 4 Direction', 'conservation-area-checker' ),
						'both'         => __( 'Conservation area and Article 4', 'conservation-area-checker' ),
						'unknown'      => __( 'Could not check planning data', 'conservation-area-checker' ),
					),
				),
			)
		);
	}

	/**
	 * AJAX: probe the Planning Data API from this server and report back.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'cac_test_connection', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'conservation-area-checker' ) ), 403 );
		}

		// Two reachability checks plus one geometry check against a point that
		// sits inside the Bath conservation area, to confirm the spatial query
		// works and not just that the host responds.
		$checks = array(
			array_merge(
				array( 'label' => __( 'Conservation area dataset reachable', 'conservation-area-checker' ) ),
				$this->probe( 'conservation-area', null, null )
			),
			array_merge(
				array( 'label' => __( 'Article 4 dataset reachable', 'conservation-area-checker' ) ),
				$this->probe( 'article-4-direction-area', null, null )
			),
			array_merge(
				array( 'label' => __( 'Location lookup test (a known conservation area)', 'conservation-area-checker' ) ),
				$this->probe( 'conservation-area', 51.3811, -2.3590 )
			),
		);

		// The connection is considered good when both datasets are reachable.
		$ok = ! empty( $checks[0]['ok'] ) && ! empty( $checks[1]['ok'] );

		wp_send_json_success(
			array(
				'ok'     => $ok,
				'checks' => $checks,
			)
		);
	}

	/**
	 * AJAX: run a postcode through the full live pipeline and report the
	 * breakdown, so an admin can spot-check results and see why a postcode
	 * gives a particular answer.
	 */
	public function ajax_test_postcode() {
		check_ajax_referer( 'cac_test_postcode', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'conservation-area-checker' ) ), 403 );
		}

		$postcode = isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '';
		$geo      = new CAC_Geocheck();
		$result   = array( 'postcode' => $postcode );

		if ( ! $geo->is_valid_format( $postcode ) ) {
			$result['error'] = __( 'That does not look like a valid UK postcode.', 'conservation-area-checker' );
			wp_send_json_success( $result );
		}

		$location = $geo->lookup( $postcode );
		if ( is_wp_error( $location ) ) {
			$result['error'] = $location->get_error_message();
			wp_send_json_success( $result );
		}

		$result['found']        = true;
		$result['postcode']     = $location['postcode'];
		$result['lat']          = $location['latitude'];
		$result['lon']          = $location['longitude'];
		$result['county']       = $location['admin_county'];
		$result['district']     = $location['admin_district'];
		$result['constituency'] = $location['parliamentary_constituency'];
		$result['distance']     = round( $geo->distance_from_centre( $location['latitude'], $location['longitude'] ), 1 );

		$in_area           = $geo->is_in_service_area( $location );
		$result['in_area'] = $in_area;

		// Always query the live data so the admin can see the real answer, even
		// for out-of-area postcodes that a visitor would not get this far on.
		$areas = $geo->check_planning_areas( $location['latitude'], $location['longitude'] );
		if ( is_wp_error( $areas ) ) {
			$result['planning_error'] = $areas->get_error_message();
			$result['conservation']   = null;
			$result['article4']       = null;
		} else {
			$result['conservation'] = (bool) $areas['conservation'];
			$result['article4']     = (bool) $areas['article4'];
		}

		// The state a visitor would actually see.
		if ( ! $in_area ) {
			$result['final'] = 'outside';
		} elseif ( null === $result['conservation'] ) {
			$result['final'] = 'unknown';
		} elseif ( $result['conservation'] && $result['article4'] ) {
			$result['final'] = 'both';
		} elseif ( $result['conservation'] ) {
			$result['final'] = 'conservation';
		} elseif ( $result['article4'] ) {
			$result['final'] = 'article4';
		} else {
			$result['final'] = 'none';
		}

		wp_send_json_success( $result );
	}

	/**
	 * Make a single Planning Data API request and summarise the outcome.
	 *
	 * @param string     $dataset Dataset slug.
	 * @param float|null $lat     Optional latitude for a geometry query.
	 * @param float|null $lon     Optional longitude for a geometry query.
	 * @return array
	 */
	private function probe( $dataset, $lat, $lon ) {
		$base = apply_filters( 'cac_planning_api_base', 'https://www.planning.data.gov.uk/entity.json' );

		$args = array(
			'dataset' => $dataset,
			'limit'   => 1,
		);
		if ( null !== $lat && null !== $lon ) {
			$args['geometry_relation'] = 'intersects';
			$args['geometry']          = sprintf( 'POINT(%s %s)', (float) $lon, (float) $lat );
		}

		$url      = $base . '?' . http_build_query( $args );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'count'  => null,
				'detail' => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		$count = null;
		if ( is_array( $body ) ) {
			if ( isset( $body['count'] ) ) {
				$count = (int) $body['count'];
			} elseif ( isset( $body['entities'] ) && is_array( $body['entities'] ) ) {
				$count = count( $body['entities'] );
			}
		}

		$ok = ( 200 === $status && null !== $count );

		return array(
			'ok'     => $ok,
			'status' => $status,
			'count'  => $count,
			'detail' => $ok ? '' : __( 'Unexpected response from the service.', 'conservation-area-checker' ),
		);
	}

	/**
	 * Default values. Place names are used as sensible starting points; the
	 * call-to-action URL is left blank on purpose so no link is shipped by
	 * default. Configure these under Settings > Conservation Area Checker.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'centre_label'       => 'Fleet',
			'centre_lat'         => '51.2832',
			'centre_lon'         => '-0.8444',
			'radius_miles'       => '30',
			'service_area_label' => 'Hampshire, Surrey, and Berkshire',
			'allowed_areas'      => implode(
				"\n",
				array(
					'Hampshire',
					'Surrey',
					'Berkshire',
					'Surrey Heath',
					'Hart',
					'Waverley',
					'Guildford',
					'Rushmoor',
					'Basingstoke and Deane',
					'West Berkshire',
					'Wokingham',
					'Reading',
					'Bracknell Forest',
					'Windsor and Maidenhead',
				)
			),
			'data_source'        => 'api',
			'conservation_url'   => '',
			'article4_url'       => '',
			'cta_heading'        => 'Not sure what applies to your home? Book a free survey and our team will check everything for you.',
			'cta_button_label'   => 'Book a free survey',
			'cta_button_url'     => '',
			'msg_none'           => 'This postcode does not appear to be within a conservation area or Article 4 Direction area. Standard permitted development rules are likely to apply. Your surveyor will confirm during your free visit.',
			'msg_conservation'   => 'This postcode appears to be within a conservation area. Replacement windows and doors may need to match the existing style and material. Our surveyor will advise during your free visit.',
			'msg_article4'       => 'This area may be subject to an Article 4 Direction. This can mean planning permission is required before replacing windows or doors on elevations visible from the road. Rules vary by street and property type, so our surveyor will confirm what applies to your home.',
			'msg_disclaimer'     => 'This check is for guidance only. The homeowner is responsible for confirming any planning requirements with their local planning authority before work begins.',
			'advisory_summary'   => 'Other things worth checking before you book',
			'advisory_items'     => implode(
				"\n",
				array(
					'Recent new-build developments: Some planning consents restrict external materials or styles. Check your original purchase documents or ask your solicitor.',
					'Flats, leasehold, and housing association properties: You may need written consent from your freeholder, management company, or housing association before replacing windows or doors.',
					'Properties above shops or on high streets: Commercial planning rules may apply. Your local planning authority can confirm.',
					'Estate-wide style or colour covenants: Some estates have covenants requiring matching styles, colours, or materials. Your title deeds will show if this applies.',
					'Small villages and rural settings: Even outside formal conservation areas, some parishes and councils apply informal character policies. It is worth checking with your local parish or district council.',
					'Parish council constraints: Some parish councils maintain local design guides. These are not legally binding in the same way as an Article 4 Direction, but they can influence planning decisions.',
				)
			),
		);
	}

	/**
	 * Advisory items as a clean array, one per line.
	 *
	 * @return string[]
	 */
	public static function advisory_items() {
		$raw   = (string) self::get( 'advisory_items' );
		$lines = preg_split( '/[\r\n]+/', $raw );
		$out   = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * Read a single setting, falling back to its default.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function get( $key ) {
		$stored   = get_option( CAC_SETTINGS_OPTION, array() );
		$merged   = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		return isset( $merged[ $key ] ) ? $merged[ $key ] : '';
	}

	/**
	 * Allowed counties and districts as a clean array.
	 *
	 * Accepts one entry per line, and also splits on commas, so the textarea
	 * is forgiving about how the list is pasted in.
	 *
	 * @return string[]
	 */
	public static function allowed_areas() {
		$raw   = (string) self::get( 'allowed_areas' );
		$parts = preg_split( '/[\r\n,]+/', $raw );
		$out   = array();

		foreach ( (array) $parts as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}

		return $out;
	}

	/**
	 * Add the settings page under the Settings menu.
	 */
	public function add_menu() {
		add_options_page(
			__( 'Conservation Area Checker', 'conservation-area-checker' ),
			__( 'Conservation Area Checker', 'conservation-area-checker' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the single option and its sanitiser.
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			CAC_SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitise the submitted settings.
	 *
	 * @param array $input Raw input from the form.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = self::defaults();

		if ( isset( $input['centre_label'] ) ) {
			$output['centre_label'] = sanitize_text_field( $input['centre_label'] );
		}
		if ( isset( $input['centre_lat'] ) ) {
			$output['centre_lat'] = (string) (float) $input['centre_lat'];
		}
		if ( isset( $input['centre_lon'] ) ) {
			$output['centre_lon'] = (string) (float) $input['centre_lon'];
		}
		if ( isset( $input['radius_miles'] ) ) {
			$radius                 = (float) $input['radius_miles'];
			$output['radius_miles'] = (string) ( $radius > 0 ? $radius : 0 );
		}
		if ( isset( $input['service_area_label'] ) ) {
			$output['service_area_label'] = sanitize_text_field( $input['service_area_label'] );
		}
		if ( isset( $input['allowed_areas'] ) ) {
			$output['allowed_areas'] = sanitize_textarea_field( $input['allowed_areas'] );
		}
		if ( isset( $input['data_source'] ) ) {
			$output['data_source'] = ( 'geojson' === $input['data_source'] ) ? 'geojson' : 'api';
		}
		if ( isset( $input['conservation_url'] ) ) {
			$output['conservation_url'] = esc_url_raw( trim( $input['conservation_url'] ) );
		}
		if ( isset( $input['article4_url'] ) ) {
			$output['article4_url'] = esc_url_raw( trim( $input['article4_url'] ) );
		}
		if ( isset( $input['cta_heading'] ) ) {
			$output['cta_heading'] = sanitize_text_field( $input['cta_heading'] );
		}
		if ( isset( $input['cta_button_label'] ) ) {
			$output['cta_button_label'] = sanitize_text_field( $input['cta_button_label'] );
		}
		if ( isset( $input['cta_button_url'] ) ) {
			$output['cta_button_url'] = esc_url_raw( trim( $input['cta_button_url'] ) );
		}
		if ( isset( $input['msg_none'] ) ) {
			$output['msg_none'] = sanitize_textarea_field( $input['msg_none'] );
		}
		if ( isset( $input['msg_conservation'] ) ) {
			$output['msg_conservation'] = sanitize_textarea_field( $input['msg_conservation'] );
		}
		if ( isset( $input['msg_article4'] ) ) {
			$output['msg_article4'] = sanitize_textarea_field( $input['msg_article4'] );
		}
		if ( isset( $input['msg_disclaimer'] ) ) {
			$output['msg_disclaimer'] = sanitize_textarea_field( $input['msg_disclaimer'] );
		}
		if ( isset( $input['advisory_summary'] ) ) {
			$output['advisory_summary'] = sanitize_text_field( $input['advisory_summary'] );
		}
		if ( isset( $input['advisory_items'] ) ) {
			$output['advisory_items'] = sanitize_textarea_field( $input['advisory_items'] );
		}

		return $output;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Conservation Area Checker', 'conservation-area-checker' ); ?></h1>
			<p><?php esc_html_e( 'Configure the service area and the call to action. Add the [conservation_postcode_search] shortcode anywhere to show the postcode search form.', 'conservation-area-checker' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Check a postcode', 'conservation-area-checker' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Run any postcode through the live checks and see the full breakdown. This reflects the live planning data lookup. Nothing is saved.', 'conservation-area-checker' ); ?></p>
			<p>
				<input type="text" id="cac-pc-input" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. GU51 4BY', 'conservation-area-checker' ); ?>" autocomplete="off" />
				<button type="button" class="button button-secondary" id="cac-pc-btn"><?php esc_html_e( 'Check postcode', 'conservation-area-checker' ); ?></button>
			</p>
			<div id="cac-pc-result"></div>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'Service area', 'conservation-area-checker' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cac_centre_label"><?php esc_html_e( 'Service centre name', 'conservation-area-checker' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[centre_label]" id="cac_centre_label" type="text" class="regular-text" value="<?php echo esc_attr( self::get( 'centre_label' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Town or place at the centre of your service area, for example Fleet.', 'conservation-area-checker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_centre_lat"><?php esc_html_e( 'Service centre latitude', 'conservation-area-checker' ); ?></label></th>
						<td><input name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[centre_lat]" id="cac_centre_lat" type="text" class="small-text" value="<?php echo esc_attr( self::get( 'centre_lat' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_centre_lon"><?php esc_html_e( 'Service centre longitude', 'conservation-area-checker' ); ?></label></th>
						<td><input name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[centre_lon]" id="cac_centre_lon" type="text" class="small-text" value="<?php echo esc_attr( self::get( 'centre_lon' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_radius_miles"><?php esc_html_e( 'Service radius (miles)', 'conservation-area-checker' ); ?></label></th>
						<td><input name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[radius_miles]" id="cac_radius_miles" type="number" min="1" step="1" class="small-text" value="<?php echo esc_attr( self::get( 'radius_miles' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_service_area_label"><?php esc_html_e( 'Service area description', 'conservation-area-checker' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[service_area_label]" id="cac_service_area_label" type="text" class="regular-text" value="<?php echo esc_attr( self::get( 'service_area_label' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Used in the out-of-area message, for example: Hampshire, Surrey, and Berkshire.', 'conservation-area-checker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_allowed_areas"><?php esc_html_e( 'Allowed counties and districts', 'conservation-area-checker' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[allowed_areas]" id="cac_allowed_areas" rows="8" class="large-text code"><?php echo esc_textarea( self::get( 'allowed_areas' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. A postcode counts as in area when it is within the radius and its county or district matches this list. Postcodes.io returns district names for some postcodes, so include both. Leave blank to check distance only.', 'conservation-area-checker' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Boundary data', 'conservation-area-checker' ); ?></h2>
				<?php $source = self::get( 'data_source' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Data source', 'conservation-area-checker' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[data_source]" value="api" <?php checked( 'geojson' !== $source ); ?> />
									<?php esc_html_e( 'Live Planning Data lookup (recommended)', 'conservation-area-checker' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Checks the official government conservation area and Article 4 data over the internet for each postcode, and caches the answer. Nothing to host or upload, and the data stays current. Your server must be able to reach planning.data.gov.uk.', 'conservation-area-checker' ); ?></p>
								<br />
								<label>
									<input type="radio" name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[data_source]" value="geojson" <?php checked( 'geojson' === $source ); ?> />
									<?php esc_html_e( 'GeoJSON files', 'conservation-area-checker' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Checks boundary files instead. Uses data/conservation-areas.json and data/article-4-areas.json when present (build them with tools/build-datasets.php), or the URLs below, or the bundled sample as a last resort. This is also the automatic fallback if the live lookup ever fails.', 'conservation-area-checker' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_conservation_url"><?php esc_html_e( 'Conservation area GeoJSON URL', 'conservation-area-checker' ); ?></label></th>
						<td><input name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[conservation_url]" id="cac_conservation_url" type="url" class="large-text" placeholder="<?php echo esc_attr( CAC_URL . 'data/conservation-areas.json' ); ?>" value="<?php echo esc_attr( self::get( 'conservation_url' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_article4_url"><?php esc_html_e( 'Article 4 GeoJSON URL', 'conservation-area-checker' ); ?></label></th>
						<td><input name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[article4_url]" id="cac_article4_url" type="url" class="large-text" placeholder="<?php echo esc_attr( CAC_URL . 'data/article-4-areas.json' ); ?>" value="<?php echo esc_attr( self::get( 'article4_url' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Live lookup check', 'conservation-area-checker' ); ?></th>
						<td>
							<button type="button" class="button" id="cac-test-connection"><?php esc_html_e( 'Test connection', 'conservation-area-checker' ); ?></button>
							<p class="description"><?php esc_html_e( 'Checks that this server can reach the planning data service used by the live lookup. No settings are changed.', 'conservation-area-checker' ); ?></p>
							<div id="cac-test-result"></div>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Call to action', 'conservation-area-checker' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cac_cta_heading"><?php esc_html_e( 'Heading text', 'conservation-area-checker' ); ?></label></th>
						<td><input name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[cta_heading]" id="cac_cta_heading" type="text" class="large-text" value="<?php echo esc_attr( self::get( 'cta_heading' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_cta_button_label"><?php esc_html_e( 'Button label', 'conservation-area-checker' ); ?></label></th>
						<td><input name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[cta_button_label]" id="cac_cta_button_label" type="text" class="regular-text" value="<?php echo esc_attr( self::get( 'cta_button_label' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_cta_button_url"><?php esc_html_e( 'Button URL', 'conservation-area-checker' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[cta_button_url]" id="cac_cta_button_url" type="url" class="regular-text" placeholder="https://example.com/free-quote/" value="<?php echo esc_attr( self::get( 'cta_button_url' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'The call to action is only shown when a URL is set.', 'conservation-area-checker' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Result messages', 'conservation-area-checker' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Shown inside the result box on the results page. The "both" state (conservation area and Article 4) shows the conservation and Article 4 messages together, so it has no separate field.', 'conservation-area-checker' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cac_msg_conservation"><?php esc_html_e( 'Conservation area message', 'conservation-area-checker' ); ?></label></th>
						<td><textarea name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[msg_conservation]" id="cac_msg_conservation" rows="3" class="large-text"><?php echo esc_textarea( self::get( 'msg_conservation' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_msg_article4"><?php esc_html_e( 'Article 4 Direction message', 'conservation-area-checker' ); ?></label></th>
						<td><textarea name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[msg_article4]" id="cac_msg_article4" rows="3" class="large-text"><?php echo esc_textarea( self::get( 'msg_article4' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_msg_none"><?php esc_html_e( 'No restrictions message', 'conservation-area-checker' ); ?></label></th>
						<td><textarea name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[msg_none]" id="cac_msg_none" rows="3" class="large-text"><?php echo esc_textarea( self::get( 'msg_none' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_msg_disclaimer"><?php esc_html_e( 'Guidance disclaimer', 'conservation-area-checker' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[msg_disclaimer]" id="cac_msg_disclaimer" rows="3" class="large-text"><?php echo esc_textarea( self::get( 'msg_disclaimer' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown in smaller grey text below every result.', 'conservation-area-checker' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Advisory section', 'conservation-area-checker' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cac_advisory_summary"><?php esc_html_e( 'Section heading', 'conservation-area-checker' ); ?></label></th>
						<td><input name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[advisory_summary]" id="cac_advisory_summary" type="text" class="large-text" value="<?php echo esc_attr( self::get( 'advisory_summary' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cac_advisory_items"><?php esc_html_e( 'List items', 'conservation-area-checker' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( CAC_SETTINGS_OPTION ); ?>[advisory_items]" id="cac_advisory_items" rows="10" class="large-text"><?php echo esc_textarea( self::get( 'advisory_items' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One bullet per line. Leave blank to hide the advisory section entirely.', 'conservation-area-checker' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
