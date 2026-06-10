<?php
/**
 * Uninstall routine for the Cristal Windows Conservation Area Checker.
 *
 * Runs only when the plugin is deleted from the WordPress admin (not on
 * deactivation). It removes the auto-created results page and the stored
 * option so the site is left clean.
 *
 * @package Cristal_Conservation_Checker
 */

// Exit if WordPress did not call this file as part of an uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cristal_cc_option = 'cristal_checker_page_id';
$cristal_cc_page_id = (int) get_option( $cristal_cc_option, 0 );

if ( $cristal_cc_page_id > 0 ) {
	// Force delete: bypass the trash and remove the page outright.
	wp_delete_post( $cristal_cc_page_id, true );
}

delete_option( $cristal_cc_option );
