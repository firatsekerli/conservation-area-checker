<?php
/**
 * Registers the [cristal_postcode_search] shortcode.
 *
 * The shortcode renders a compact postcode entry form. Assets are enqueued
 * only from the render callback, so the CSS and JS never load on pages that
 * do not use the shortcode.
 *
 * @package Cristal_Conservation_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Postcode search shortcode.
 */
class Cristal_CC_Shortcode {

	/**
	 * Hook the shortcode into WordPress.
	 */
	public function register() {
		add_shortcode( 'cristal_postcode_search', array( $this, 'render' ) );
	}

	/**
	 * Render the postcode entry form.
	 *
	 * Works in Breakdance Custom Code blocks, Classic and Block editor Custom
	 * HTML blocks, text widgets, and any page or post, because it is a standard
	 * shortcode and enqueues its own assets on demand.
	 *
	 * @param array $atts Shortcode attributes (unused, reserved for future use).
	 * @return string Form markup.
	 */
	public function render( $atts = array() ) {
		// Enqueue assets here so they load only where the shortcode appears.
		cristal_cc()->enqueue_assets();

		ob_start();
		?>
		<div class="cristal-checker">
			<form class="cristal-search" data-cristal-search novalidate>
				<label class="cristal-search-label" for="cristal-postcode-input">
					<?php esc_html_e( 'Enter your postcode', 'cristal-conservation-checker' ); ?>
				</label>
				<div class="cristal-search-row">
					<input
						type="text"
						id="cristal-postcode-input"
						name="postcode"
						class="cristal-search-input"
						autocomplete="postal-code"
						autocapitalize="characters"
						spellcheck="false"
						placeholder="<?php esc_attr_e( 'e.g. GU51 4BY', 'cristal-conservation-checker' ); ?>"
						aria-describedby="cristal-postcode-error"
					/>
					<button type="submit" class="cristal-search-button">
						<?php esc_html_e( 'Check my postcode', 'cristal-conservation-checker' ); ?>
					</button>
				</div>
				<p
					id="cristal-postcode-error"
					class="cristal-search-error"
					role="alert"
					hidden
				>
					<?php esc_html_e( 'Please enter a valid UK postcode, for example GU51 4BY.', 'cristal-conservation-checker' ); ?>
				</p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
