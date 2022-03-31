<?php
/**
 * Plugin Name: Integrate WPForms And Mailercloud
 * Description: Mailercloud integration with WPForms.
 * Version: 1.0.0
 * Author: Sanjeev Aryal
 * Author URI: https://www.sanjeebaryal.com.np
 * Text Domain: integrate-wpforms-and-mailercloud
 *
 * @package Integrate WPForms And Mailercloud
 * @author Sanjeev Aryal
 */

defined( 'ABSPATH' ) || die();

define( 'INTEGRATE_WPFORMS_AND_MAILERCLOUD_PLUGIN_FILE', __FILE__ );
define( 'INTEGRATE_WPFORMS_AND_MAILERCLOUD_PLUGIN_PATH', __DIR__ );

/**
 * Plugin version.
 */
const INTEGRATE_WPFORMS_AND_MAILERCLOUD_VERSION = '1.0.0';

add_action(
	'wpforms_loaded',
	function() {

		require_once INTEGRATE_WPFORMS_AND_MAILERCLOUD_PLUGIN_PATH . '/src/class-mailercloud.php';

		// Load translated strings.
		load_plugin_textdomain( 'integrate-wpforms-and-mailercloud', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	}
);
