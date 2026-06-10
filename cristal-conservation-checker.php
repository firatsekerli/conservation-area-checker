<?php
/**
 * Plugin Name: Cristal Windows Conservation Area Checker
 * Description: Lets visitors enter a UK postcode anywhere on the site via a shortcode, then redirects them to a dedicated results page that checks the service area, conservation areas, and Article 4 Direction areas.
 * Version: 1.0.0
 * Author: Cristal Windows
 * Text Domain: cristal-conservation-checker
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 *
 * @package Cristal_Conservation_Checker
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Core constants used across the plugin.
define( 'CRISTAL_CC_VERSION', '1.0.0' );
define( 'CRISTAL_CC_FILE', __FILE__ );
define( 'CRISTAL_CC_PATH', plugin_dir_path( __FILE__ ) );
define( 'CRISTAL_CC_URL', plugin_dir_url( __FILE__ ) );
define( 'CRISTAL_CC_PAGE_OPTION', 'cristal_checker_page_id' );
define( 'CRISTAL_CC_PAGE_SLUG', 'conservation-area-checker' );

// Load the plugin classes.
require_once CRISTAL_CC_PATH . 'includes/class-geocheck.php';
require_once CRISTAL_CC_PATH . 'includes/class-shortcode.php';
require_once CRISTAL_CC_PATH . 'includes/class-results-page.php';

/**
 * Main plugin controller.
 *
 * Wires up the shortcode and results page, and owns the shared asset
 * registration so that CSS and JS only load where they are needed.
 */
final class Cristal_Conservation_Checker {

	/**
	 * Singleton instance.
	 *
	 * @var Cristal_Conservation_Checker|null
	 */
	private static $instance = null;

	/**
	 * Shortcode handler.
	 *
	 * @var Cristal_CC_Shortcode
	 */
	public $shortcode;

	/**
	 * Results page handler.
	 *
	 * @var Cristal_CC_Results_Page
	 */
	public $results_page;

	/**
	 * Boot the plugin once.
	 *
	 * @return Cristal_Conservation_Checker
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
		$this->shortcode    = new Cristal_CC_Shortcode();
		$this->results_page = new Cristal_CC_Results_Page();

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		$this->shortcode->register();
		$this->results_page->register();
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
			'cristal-checker',
			CRISTAL_CC_URL . 'assets/checker.css',
			array(),
			CRISTAL_CC_VERSION
		);

		wp_register_script(
			'cristal-checker',
			CRISTAL_CC_URL . 'assets/checker.js',
			array(),
			CRISTAL_CC_VERSION,
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
		if ( ! wp_style_is( 'cristal-checker', 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_style( 'cristal-checker' );
		wp_enqueue_script( 'cristal-checker' );

		wp_localize_script(
			'cristal-checker',
			'cristalChecker',
			array(
				'resultsUrl' => $this->results_page->get_results_url(),
			)
		);
	}
}

/**
 * Convenience accessor for the main plugin instance.
 *
 * @return Cristal_Conservation_Checker
 */
function cristal_cc() {
	return Cristal_Conservation_Checker::instance();
}

// Start the plugin.
add_action( 'plugins_loaded', 'cristal_cc' );

/**
 * Activation: create the dedicated results page and remember its ID.
 *
 * Lives on the main plugin file so it can run before plugins_loaded fires.
 */
function cristal_cc_activate() {
	$page_id = (int) get_option( CRISTAL_CC_PAGE_OPTION );

	// If we already have a valid, non-trashed page, do nothing.
	if ( $page_id > 0 ) {
		$existing = get_post( $page_id );
		if ( $existing && 'trash' !== $existing->post_status ) {
			return;
		}
	}

	// Reuse a page that already lives on the expected slug if one exists.
	$by_slug = get_page_by_path( CRISTAL_CC_PAGE_SLUG );
	if ( $by_slug ) {
		update_option( CRISTAL_CC_PAGE_OPTION, (int) $by_slug->ID );
		return;
	}

	$new_id = wp_insert_post(
		array(
			'post_title'   => 'Conservation Area Checker',
			'post_name'    => CRISTAL_CC_PAGE_SLUG,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			// Placeholder content. The plugin swaps this out at render time.
			'post_content' => 'Enter your postcode to check for conservation areas and Article 4 Direction areas.',
		)
	);

	if ( $new_id && ! is_wp_error( $new_id ) ) {
		update_option( CRISTAL_CC_PAGE_OPTION, (int) $new_id );
	}

	// Make sure the pretty permalink for the new slug works straight away.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cristal_cc_activate' );

/**
 * Deactivation: intentionally leaves the results page in place.
 *
 * We only tidy rewrite rules here. Page and option removal happen in
 * uninstall.php so a simple deactivate and reactivate keeps the page.
 */
function cristal_cc_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'cristal_cc_deactivate' );
