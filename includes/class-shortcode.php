<?php
/**
 * Registers the [conservation_postcode_search] shortcode.
 *
 * The shortcode renders a compact postcode entry form. Assets are enqueued
 * only from the render callback, so the CSS and JS never load on pages that
 * do not use the shortcode.
 *
 * @package Conservation_Area_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Postcode search shortcode.
 */
class CAC_Shortcode {

	/**
	 * Hook the shortcode into WordPress.
	 */
	public function register() {
		add_shortcode( 'conservation_postcode_search', array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * Self-contained so it works anywhere, including page builders such as
	 * Breakdance that bypass the the_content filter. When the URL carries a
	 * postcode it renders the full results; otherwise it renders the form.
	 *
	 * Attributes:
	 *   inline - "true" to show the result on the current page instead of
	 *            redirecting to the dedicated results page. Default "false".
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts = array() ) {
		// Enqueue assets here so they load only where the shortcode appears.
		cac()->enqueue_assets();

		$atts   = shortcode_atts( array( 'inline' => 'false' ), $atts, 'conservation_postcode_search' );
		$inline = in_array( strtolower( (string) $atts['inline'] ), array( '1', 'true', 'yes', 'on' ), true );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public tool.
		$has_postcode = isset( $_GET['postcode'] ) && '' !== trim( (string) wp_unslash( $_GET['postcode'] ) );

		if ( $inline ) {
			// Keep the form on the page; show the result in a holder below it.
			// Pre-fill the holder server-side so shared links and refreshes work
			// without JavaScript. The AJAX path updates it without a reload.
			$result = $has_postcode ? cac()->results_page->render_output( null, false ) : '';
			return $this->form_html( true, $result );
		}

		if ( $has_postcode ) {
			return cac()->results_page->render_output();
		}

		return $this->form_html();
	}

	/**
	 * The postcode entry form markup.
	 *
	 * Works in Breakdance Custom Code blocks, Classic and Block editor Custom
	 * HTML blocks, text widgets, and any page or post.
	 *
	 * @param bool   $inline      When true, the form stays on the page and a
	 *                            result holder is rendered below it.
	 * @param string $result_html Pre-rendered result HTML for the holder.
	 * @return string Form markup.
	 */
	public function form_html( $inline = false, $result_html = '' ) {
		ob_start();
		?>
		<div class="cac-checker">
			<form class="cac-search" data-cac-search<?php echo $inline ? ' data-cac-inline="1"' : ''; ?> novalidate>
				<label class="cac-search-label" for="cac-postcode-input">
					<?php esc_html_e( 'Enter your postcode', 'conservation-area-checker' ); ?>
				</label>
				<div class="cac-search-row">
					<input
						type="text"
						id="cac-postcode-input"
						name="postcode"
						class="cac-search-input"
						autocomplete="postal-code"
						autocapitalize="characters"
						spellcheck="false"
						placeholder="<?php esc_attr_e( 'e.g. GU51 4BY', 'conservation-area-checker' ); ?>"
						aria-describedby="cac-postcode-error"
					/>
					<button type="submit" class="cac-search-button">
						<?php esc_html_e( 'Check my postcode', 'conservation-area-checker' ); ?>
					</button>
				</div>
				<p
					id="cac-postcode-error"
					class="cac-search-error"
					role="alert"
					hidden
				>
					<?php esc_html_e( 'Please enter a valid UK postcode, for example GU51 4BY.', 'conservation-area-checker' ); ?>
				</p>
			</form>
			<?php if ( $inline ) : ?>
				<div class="cac-inline-result" data-cac-inline-result aria-live="polite"><?php echo $result_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered, escaped result markup. ?></div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
