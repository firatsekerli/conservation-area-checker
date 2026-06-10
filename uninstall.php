<?php
/**
 * Uninstall routine for the Conservation Area Checker.
 *
 * Runs only when the plugin is deleted from the WordPress admin (not on
 * deactivation). It removes the auto-created results page and the stored
 * options so the site is left clean.
 *
 * @package Conservation_Area_Checker
 */

// Exit if WordPress did not call this file as part of an uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cac_page_option     = 'cac_results_page_id';
$cac_settings_option = 'cac_settings';

$cac_page_id = (int) get_option( $cac_page_option, 0 );
if ( $cac_page_id > 0 ) {
	// Force delete: bypass the trash and remove the page outright.
	wp_delete_post( $cac_page_id, true );
}

delete_option( $cac_page_option );
delete_option( $cac_settings_option );
