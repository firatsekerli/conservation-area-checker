<?php
/**
 * Plugin Name: Conservation Area Checker
 * Plugin URI: https://asparagents.com/
 * Description: Lets visitors enter a UK postcode anywhere on the site via a shortcode, then redirects them to a dedicated results page that checks the service area, conservation areas, and Article 4 Direction areas. Service area, allowed counties, and the call to action are configurable from the settings page.
 * Version: 1.0.4
 * Author: asparagents
 * Author URI: https://asparagents.com/
 * Text Domain: conservation-area-checker
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 *
 * @package Conservation_Area_Checker
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Core constants used across the plugin.
define( 'CAC_VERSION', '1.0.4' );
define( 'CAC_FILE', __FILE__ );
define( 'CAC_PATH', plugin_dir_path( __FILE__ ) );
define( 'CAC_URL', plugin_dir_url( __FILE__ ) );
define( 'CAC_PAGE_OPTION', 'cac_results_page_id' );
define( 'CAC_PAGE_SLUG', 'conservation-area-checker' );
define( 'CAC_SETTINGS_OPTION', 'cac_settings' );

// Load the plugin classes.
require_once CAC_PATH . 'includes/class-settings.php';
require_once CAC_PATH . 'includes/class-geocheck.php';
require_once CAC_PATH . 'includes/class-shortcode.php';
require_once CAC_PATH . 'includes/class-results-page.php';

/**
 * Main plugin controller.
 *
 * Wires up the settings, shortcode, and results page, and owns the shared
 * asset registration so CSS and JS only load where they are needed.
 */
final class Conservation_Area_Checker {

	/**
	 * Singleton instance.
	 *
	 * @var Conservation_Area_Checker|null
	 */
	private static $instance = null;

	/**
	 * Settings handler.
	 *
	 * @var CAC_Settings
	 */
	public $settings;

	/**
	 * Shortcode handler.
	 *
	 * @var CAC_Shortcode
	 */
	public $shortcode;

	/**
	 * Results page handler.
	 *
	 * @var CAC_Results_Page
	 */
	public $results_page;

	/**
	 * Boot the plugin once.
	 *
	 * @return Conservation_Area_Checker
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers hooks and feature handlers.
	 */
	private function __construct() {
		$this->settings     = new CAC_Settings();
		$this->shortcode    = new CAC_Shortcode();
		$this->results_page = new CAC_Results_Page();

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CAC_FILE ), array( $this, 'action_links' ) );

		$this->settings->register();
		$this->shortcode->register();
		$this->results_page->register();
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url      = admin_url( 'options-general.php?page=' . CAC_Settings::PAGE_SLUG );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'conservation-area-checker' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Register (but do not enqueue) the front-end assets.
	 *
	 * Registering on wp_enqueue_scripts means the handles are known, while the
	 * actual enqueue happens on demand from the shortcode render callback and
	 * the results page renderer. That keeps the CSS and JS off every other page.
	 */
	public function register_assets() {
		wp_register_style(
			'cac-checker',
			CAC_URL . 'assets/checker.css',
			array(),
			CAC_VERSION
		);

		wp_register_script(
			'cac-checker',
			CAC_URL . 'assets/checker.js',
			array(),
			CAC_VERSION,
			true
		);
	}

	/**
	 * Enqueue the shared assets and hand the JS the data it needs.
	 *
	 * Safe to call from inside a shortcode render callback or the_content
	 * filter: WordPress prints late-enqueued styles and footer scripts for us.
	 */
	public function enqueue_assets() {
		// Make sure the handles exist even if wp_enqueue_scripts already ran.
		if ( ! wp_style_is( 'cac-checker', 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_style( 'cac-checker' );
		wp_enqueue_script( 'cac-checker' );

		wp_localize_script(
			'cac-checker',
			'cacChecker',
			array(
				'resultsUrl' => $this->results_page->get_results_url(),
				// Result-state copy is editable from the settings page. The JS
				// uses these and falls back to its own defaults if absent.
				'copy'       => array(
					'none'         => CAC_Settings::get( 'msg_none' ),
					'conservation' => CAC_Settings::get( 'msg_conservation' ),
					'article4'     => CAC_Settings::get( 'msg_article4' ),
				),
			)
		);
	}
}

/**
 * Convenience accessor for the main plugin instance.
 *
 * @return Conservation_Area_Checker
 */
function cac() {
	return Conservation_Area_Checker::instance();
}

// Start the plugin.
add_action( 'plugins_loaded', 'cac' );

/**
 * Activation: create the dedicated results page and remember its ID.
 *
 * Lives on the main plugin file so it can run before plugins_loaded fires.
 */
function cac_activate() {
	$page_id = (int) get_option( CAC_PAGE_OPTION );

	// If we already have a valid, non-trashed page, do nothing.
	if ( $page_id > 0 ) {
		$existing = get_post( $page_id );
		if ( $existing && 'trash' !== $existing->post_status ) {
			return;
		}
	}

	// Reuse a page that already lives on the expected slug if one exists.
	$by_slug = get_page_by_path( CAC_PAGE_SLUG );
	if ( $by_slug ) {
		update_option( CAC_PAGE_OPTION, (int) $by_slug->ID );
		return;
	}

	$new_id = wp_insert_post(
		array(
			'post_title'   => 'Conservation Area Checker',
			'post_name'    => CAC_PAGE_SLUG,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			// Placeholder content. The plugin swaps this out at render time.
			'post_content' => 'Enter your postcode to check for conservation areas and Article 4 Direction areas.',
		)
	);

	if ( $new_id && ! is_wp_error( $new_id ) ) {
		update_option( CAC_PAGE_OPTION, (int) $new_id );
	}

	// Make sure the pretty permalink for the new slug works straight away.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cac_activate' );

/**
 * Deactivation: intentionally leaves the results page in place.
 *
 * We only tidy rewrite rules here. Page and option removal happen in
 * uninstall.php so a simple deactivate and reactivate keeps the page.
 */
function cac_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'cac_deactivate' );
