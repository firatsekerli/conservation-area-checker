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
			'cta_heading'        => 'Not sure what applies to your home? Book a free survey and our team will check everything for you.',
			'cta_button_label'   => 'Book a free survey',
			'cta_button_url'     => '',
		);
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
		if ( isset( $input['cta_heading'] ) ) {
			$output['cta_heading'] = sanitize_text_field( $input['cta_heading'] );
		}
		if ( isset( $input['cta_button_label'] ) ) {
			$output['cta_button_label'] = sanitize_text_field( $input['cta_button_label'] );
		}
		if ( isset( $input['cta_button_url'] ) ) {
			$output['cta_button_url'] = esc_url_raw( trim( $input['cta_button_url'] ) );
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

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
