<?php
/**
 * Plugin Name: Antelope Instagram Import
 * Plugin URI: https://github.com/markbiek/alf-ig-import
 * Description: A WordPress plugin to automatically import an Instagram export into WordPress.
 * Version: 0.1.0
 * Author: Mark Biek
 * Author URI: https://mark.biek.org
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl.txt
 * Text Domain: antelope-ig-import
 * Requires PHP: 8.1
 * Requires at least: 6.0
 *
 * @category  Social_Media
 * @package   Antelope_Ig_Import
 * @author    Mark Biek <markbiek@duck.com>
 * @copyright 2024 Mark Biek
 * @license   GPL v2 or newer <https://www.gnu.org/licenses/gpl.txt>
 */

namespace AlfIgImport;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';

	// Initialize Action Scheduler
	require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

	$admin = new AlfIgImportAdmin();
	$admin->init();
} else {
	wp_die( esc_html__( 'Please run composer install to use the ALF Instagram Import plugin.', 'antelope-ig-import' ) );
}
