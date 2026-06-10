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
 * @package Cristal_Conservation_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Results page controller.
 */
class Cristal_CC_Results_Page {

	/**
	 * Geo helper.
	 *
	 * @var Cristal_CC_Geocheck
	 */
	private $geo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->geo = new Cristal_CC_Geocheck();
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
		return (int) get_option( CRISTAL_CC_PAGE_OPTION, 0 );
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
		return home_url( '/' . CRISTAL_CC_PAGE_SLUG . '/' );
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

		cristal_cc()->enqueue_assets();

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
		<div class="cristal-checker">
			<p class="cristal-intro">
				<?php esc_html_e( 'Enter your postcode to check for conservation areas and Article 4 Direction areas near you.', 'cristal-conservation-checker' ); ?>
			</p>
		</div>
		<?php
		// Reuse the shortcode form so behaviour stays consistent.
		$form = do_shortcode( '[cristal_postcode_search]' );
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
		<div class="cristal-checker">
			<div class="cristal-result cristal-state-outside">
				<div class="cristal-result-inner">
					<p>
						<?php
						printf(
							/* translators: %s: the postcode the visitor entered. */
							esc_html__( 'We could not read the postcode %s. Please enter a valid UK postcode, for example GU51 4BY.', 'cristal-conservation-checker' ),
							'<strong>' . esc_html( $postcode ) . '</strong>'
						);
						?>
					</p>
				</div>
			</div>
		</div>
		<?php
		$form = do_shortcode( '[cristal_postcode_search]' );
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
		<div class="cristal-checker">
			<div class="cristal-result cristal-state-outside">
				<div class="cristal-result-inner">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			</div>
		</div>
		<?php
		$form = do_shortcode( '[cristal_postcode_search]' );
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
		<div class="cristal-checker">
			<div class="cristal-result cristal-state-outside">
				<div class="cristal-result-inner">
					<p><?php esc_html_e( 'We currently install within 30 miles of Fleet in Hampshire, Surrey, and Berkshire. This postcode falls outside that area.', 'cristal-conservation-checker' ); ?></p>
				</div>
				<?php echo $this->disclaimer_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, escaped within helper. ?>
			</div>
			<?php echo $this->advisory_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, escaped within helper. ?>
			<?php echo $this->cta_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, escaped within helper. ?>
		</div>
		<?php
		return ob_get_clean();
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

		// Development data path. See checker.js for how to swap in a filtered
		// regional dataset or a serverless endpoint for production.
		$geojson_url  = CRISTAL_CC_URL . 'data/sample-conservation-areas.json';
		$article4_url = CRISTAL_CC_URL . 'data/sample-conservation-areas.json';

		ob_start();
		?>
		<div class="cristal-checker">
			<div
				class="cristal-result cristal-state-loading"
				data-cristal-result
				data-coords="<?php echo esc_attr( $coords ); ?>"
				data-conservation-url="<?php echo esc_url( $geojson_url ); ?>"
				data-article4-url="<?php echo esc_url( $article4_url ); ?>"
			>
				<div class="cristal-result-inner">
					<p class="cristal-loading"><?php esc_html_e( 'Checking this postcode...', 'cristal-conservation-checker' ); ?></p>
				</div>
				<?php echo $this->disclaimer_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, escaped within helper. ?>
			</div>
			<?php echo $this->advisory_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, escaped within helper. ?>
			<?php echo $this->cta_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, escaped within helper. ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * The guidance disclaimer appended below every result.
	 *
	 * @return string
	 */
	private function disclaimer_html() {
		return '<p class="cristal-disclaimer">'
			. esc_html__( 'This check is for guidance only. The homeowner is responsible for confirming any planning requirements with their local planning authority before work begins.', 'cristal-conservation-checker' )
			. '</p>';
	}

	/**
	 * The collapsible advisory section. No JavaScript: native details/summary.
	 *
	 * @return string
	 */
	private function advisory_html() {
		$items = array(
			__( 'Recent new-build developments: Some planning consents restrict external materials or styles. Check your original purchase documents or ask your solicitor.', 'cristal-conservation-checker' ),
			__( 'Flats, leasehold, and housing association properties: You may need written consent from your freeholder, management company, or housing association before replacing windows or doors.', 'cristal-conservation-checker' ),
			__( 'Properties above shops or on high streets: Commercial planning rules may apply. Your local planning authority can confirm.', 'cristal-conservation-checker' ),
			__( 'Estate-wide style or colour covenants: Some estates have covenants requiring matching styles, colours, or materials. Your title deeds will show if this applies.', 'cristal-conservation-checker' ),
			__( 'Small villages and rural settings: Even outside formal conservation areas, some parishes and councils apply informal character policies. It is worth checking with your local parish or district council.', 'cristal-conservation-checker' ),
			__( 'Parish council constraints: Some parish councils maintain local design guides. These are not legally binding in the same way as an Article 4 Direction, but they can influence planning decisions.', 'cristal-conservation-checker' ),
		);

		$out  = '<details class="cristal-advisory">';
		$out .= '<summary class="cristal-advisory-summary">' . esc_html__( 'Other things worth checking before you book', 'cristal-conservation-checker' ) . '</summary>';
		$out .= '<ul class="cristal-advisory-list">';
		foreach ( $items as $item ) {
			$out .= '<li>' . esc_html( $item ) . '</li>';
		}
		$out .= '</ul></details>';

		return $out;
	}

	/**
	 * The lead-generation call to action.
	 *
	 * @return string
	 */
	private function cta_html() {
		$out  = '<div class="cristal-cta">';
		$out .= '<p class="cristal-cta-text">' . esc_html__( 'Not sure what applies to your home? Book a free survey and our team will check everything for you.', 'cristal-conservation-checker' ) . '</p>';
		$out .= '<a class="cristal-cta-button" href="' . esc_url( 'https://cristalwindows.co.uk/free-quotation/' ) . '">' . esc_html__( 'Book a free survey', 'cristal-conservation-checker' ) . '</a>';
		$out .= '</div>';

		return $out;
	}
}
