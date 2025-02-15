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
	
	// Ensure Action Scheduler's runner is initialized
	add_action( 'init', function() {
		if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
			error_log('ActionScheduler_QueueRunner not found!');
			return;
		}

		$runner = \ActionScheduler_QueueRunner::instance();
		
		// Let Action Scheduler handle its own queue running
		add_filter( 'action_scheduler_run_schedule', '__return_false' );
		
		// Optionally increase how many actions are processed per batch
		add_filter( 'action_scheduler_queue_runner_batch_size', function() {
			return 25; // Process up to 25 actions at a time
		});

		// Ensure the queue runner is initiated
		add_action( 'shutdown', array( $runner, 'run' ) );

	}, 0);

	// Initialize the background importer immediately
	$importer = new BackgroundImporter();
	$importer->init();

	$admin = new AlfIgImportAdmin();
	$admin->init();

	// Register WP-CLI command
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'alf-ig-import', new CLI() );
	}
} else {
	wp_die( esc_html__( 'Please run composer install to use the ALF Instagram Import plugin.', 'antelope-ig-import' ) );
}
