<?php
/**
 * The dedicated results page.
 *
 * Replaces the placeholder content of the auto-created page with the checker
 * output. When a ?postcode= parameter is present it runs the server-side
 * checks and prints a result container that checker.js completes with the
 * client-side polygon check. When the parameter is absent it shows the search
 * form, so the page is useful on its own.
 *
 * @package Conservation_Area_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Results page controller.
 */
class CAC_Results_Page {

	/**
	 * Geo helper.
	 *
	 * @var CAC_Geocheck
	 */
	private $geo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->geo = new CAC_Geocheck();
	}

	/**
	 * Hook into WordPress.
	 */
	public function register() {
		add_filter( 'the_content', array( $this, 'maybe_render' ) );
	}

	/**
	 * Resolve the stored results page ID.
	 *
	 * @return int Page ID, or 0 when not set.
	 */
	public function get_page_id() {
		return (int) get_option( CAC_PAGE_OPTION, 0 );
	}

	/**
	 * Public URL of the results page.
	 *
	 * Falls back to the conventional slug path if the option is missing.
	 *
	 * @return string
	 */
	public function get_results_url() {
		$page_id = $this->get_page_id();
		if ( $page_id > 0 ) {
			$permalink = get_permalink( $page_id );
			if ( $permalink ) {
				return $permalink;
			}
		}
		return home_url( '/' . CAC_PAGE_SLUG . '/' );
	}

	/**
	 * Replace the results page content with the checker when appropriate.
	 *
	 * @param string $content Original post content.
	 * @return string
	 */
	public function maybe_render( $content ) {
		if ( is_admin() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$page_id = $this->get_page_id();
		if ( $page_id <= 0 || get_the_ID() !== $page_id ) {
			return $content;
		}

		cac()->enqueue_assets();

		return $this->render();
	}

	/**
	 * Build the full checker output for the results page.
	 *
	 * @return string
	 */
	private function render() {
		// Read-only public tool, so no nonce is needed for this GET parameter.
		// If a future version posted this form to the database, verify a nonce
		// here with check_admin_referer() or wp_verify_nonce() before saving.
		$raw_postcode = isset( $_GET['postcode'] ) ? sanitize_text_field( wp_unslash( $_GET['postcode'] ) ) : '';

		if ( '' === $raw_postcode ) {
			// No postcode supplied: the page is still useful as a search form.
			return $this->render_form_intro();
		}

		if ( ! $this->geo->is_valid_format( $raw_postcode ) ) {
			return $this->render_invalid( $raw_postcode );
		}

		$location = $this->geo->lookup( $raw_postcode );
		if ( is_wp_error( $location ) ) {
			return $this->render_lookup_error( $raw_postcode, $location->get_error_message() );
		}

		if ( ! $this->geo->is_in_service_area( $location ) ) {
			return $this->render_out_of_area();
		}

		return $this->render_in_area( $location );
	}

	/**
	 * Intro plus search form, shown when no postcode is present.
	 *
	 * @return string
	 */
	private function render_form_intro() {
		ob_start();
		?>
		<div class="cac-checker">
			<p class="cac-intro">
				<?php esc_html_e( 'Enter your postcode to check for conservation areas and Article 4 Direction areas near you.', 'conservation-area-checker' ); ?>
			</p>
		</div>
		<?php
		// Reuse the shortcode form so behaviour stays consistent.
		$form = do_shortcode( '[conservation_postcode_search]' );
		return ob_get_clean() . $form;
	}

	/**
	 * Invalid-format message plus the form so the visitor can retry.
	 *
	 * @param string $postcode The rejected input.
	 * @return string
	 */
	private function render_invalid( $postcode ) {
		ob_start();
		?>
		<div class="cac-checker">
			<div class="cac-result cac-state-outside">
				<div class="cac-result-inner">
					<p>
						<?php
						printf(
							/* translators: %s: the postcode the visitor entered. */
							esc_html__( 'We could not read the postcode %s. Please enter a valid UK postcode, for example GU51 4BY.', 'conservation-area-checker' ),
							'<strong>' . esc_html( $postcode ) . '</strong>'
						);
						?>
					</p>
				</div>
			</div>
		</div>
		<?php
		$form = do_shortcode( '[conservation_postcode_search]' );
		return ob_get_clean() . $form;
	}

	/**
	 * Lookup-failure message plus the form so the visitor can retry.
	 *
	 * @param string $postcode The input that failed.
	 * @param string $message  Human-readable error.
	 * @return string
	 */
	private function render_lookup_error( $postcode, $message ) {
		ob_start();
		?>
		<div class="cac-checker">
			<div class="cac-result cac-state-outside">
				<div class="cac-result-inner">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			</div>
		</div>
		<?php
		$form = do_shortcode( '[conservation_postcode_search]' );
		return ob_get_clean() . $form;
	}

	/**
	 * Out-of-area result, rendered entirely server-side.
	 *
	 * @return string
	 */
	private function render_out_of_area() {
		ob_start();
		?>
		<div class="cac-checker">
			<div class="cac-result cac-state-outside">
				<div class="cac-result-inner">
					<p><?php echo esc_html( $this->out_of_area_message() ); ?></p>
				</div>
				<?php echo $this->disclaimer_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within helper. ?>
			</div>
			<?php echo $this->advisory_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within helper. ?>
			<?php echo $this->cta_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within helper. ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Compose the out-of-area sentence from the configured service area.
	 *
	 * @return string
	 */
	private function out_of_area_message() {
		$radius = (float) CAC_Settings::get( 'radius_miles' );
		// Show a whole number when the radius is whole.
		$radius_display = ( floor( $radius ) === $radius ) ? (string) (int) $radius : (string) $radius;
		$centre         = CAC_Settings::get( 'centre_label' );
		$area           = CAC_Settings::get( 'service_area_label' );

		return sprintf(
			/* translators: 1: radius in miles, 2: centre place name, 3: service area description. */
			__( 'We currently install within %1$s miles of %2$s in %3$s. This postcode falls outside that area.', 'conservation-area-checker' ),
			$radius_display,
			$centre,
			$area
		);
	}

	/**
	 * In-area result container. JavaScript completes the polygon check.
	 *
	 * @param array $location Normalised location data.
	 * @return string
	 */
	private function render_in_area( $location ) {
		$coords = wp_json_encode(
			array(
				'lat' => $location['latitude'],
				'lon' => $location['longitude'],
			)
		);

		// Resolve the boundary data sources. A configured URL wins; otherwise
		// the bundled real file is used, falling back to the sample until real
		// data is added. Build real files with tools/build-datasets.php.
		$geojson_url  = $this->boundary_url( 'conservation' );
		$article4_url = $this->boundary_url( 'article4' );

		ob_start();
		?>
		<div class="cac-checker">
			<div
				class="cac-result cac-state-loading"
				data-cac-result
				data-coords="<?php echo esc_attr( $coords ); ?>"
				data-conservation-url="<?php echo esc_url( $geojson_url ); ?>"
				data-article4-url="<?php echo esc_url( $article4_url ); ?>"
			>
				<div class="cac-result-inner">
					<p class="cac-loading"><?php esc_html_e( 'Checking this postcode...', 'conservation-area-checker' ); ?></p>
				</div>
				<?php echo $this->disclaimer_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within helper. ?>
			</div>
			<?php echo $this->advisory_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within helper. ?>
			<?php echo $this->cta_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within helper. ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Resolve the GeoJSON source URL for a boundary type.
	 *
	 * Order of preference:
	 *   1. A URL configured on the settings page.
	 *   2. The bundled real file (data/conservation-areas.json or
	 *      data/article-4-areas.json) once it has been built.
	 *   3. The bundled sample file, so the page still works out of the box.
	 *
	 * @param string $type Either 'conservation' or 'article4'.
	 * @return string
	 */
	private function boundary_url( $type ) {
		$setting_key = ( 'article4' === $type ) ? 'article4_url' : 'conservation_url';
		$configured  = CAC_Settings::get( $setting_key );
		if ( '' !== $configured ) {
			return $configured;
		}

		$real_file = ( 'article4' === $type ) ? 'data/article-4-areas.json' : 'data/conservation-areas.json';
		if ( file_exists( CAC_PATH . $real_file ) ) {
			return CAC_URL . $real_file;
		}

		return CAC_URL . 'data/sample-conservation-areas.json';
	}

	/**
	 * The guidance disclaimer appended below every result.
	 *
	 * @return string
	 */
	private function disclaimer_html() {
		$disclaimer = CAC_Settings::get( 'msg_disclaimer' );
		if ( '' === $disclaimer ) {
			return '';
		}
		return '<p class="cac-disclaimer">' . esc_html( $disclaimer ) . '</p>';
	}

	/**
	 * The collapsible advisory section. No JavaScript: native details/summary.
	 *
	 * @return string
	 */
	private function advisory_html() {
		$items = CAC_Settings::advisory_items();
		if ( empty( $items ) ) {
			return '';
		}

		$summary = CAC_Settings::get( 'advisory_summary' );

		$out  = '<details class="cac-advisory">';
		$out .= '<summary class="cac-advisory-summary">' . esc_html( $summary ) . '</summary>';
		$out .= '<ul class="cac-advisory-list">';
		foreach ( $items as $item ) {
			$out .= '<li>' . esc_html( $item ) . '</li>';
		}
		$out .= '</ul></details>';

		return $out;
	}

	/**
	 * The lead-generation call to action.
	 *
	 * Rendered only when a button URL has been configured, so a freshly
	 * installed copy never ships a broken or placeholder link.
	 *
	 * @return string
	 */
	private function cta_html() {
		$url = CAC_Settings::get( 'cta_button_url' );
		if ( '' === $url ) {
			return '';
		}

		$heading = CAC_Settings::get( 'cta_heading' );
		$label   = CAC_Settings::get( 'cta_button_label' );

		$out  = '<div class="cac-cta">';
		if ( '' !== $heading ) {
			$out .= '<p class="cac-cta-text">' . esc_html( $heading ) . '</p>';
		}
		$out .= '<a class="cac-cta-button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		$out .= '</div>';

		return $out;
	}
}
