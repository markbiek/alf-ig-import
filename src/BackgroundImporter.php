<?php
/**
 * Background processing for Instagram imports using Action Scheduler.
 *
 * @category  Social_Media
 * @package   Antelope_Ig_Import
 * @author    Mark Biek <markbiek@duck.com>
 * @copyright 2024 Mark Biek
 * @license   GPL v2 or newer <https://www.gnu.org/licenses/gpl.txt>
 */

namespace AlfIgImport;

use function add_action;
use function as_enqueue_async_action;
use function as_next_scheduled_action;
use function get_option;
use function time;
use function update_option;

/**
 * Class to handle background importing of Instagram data.
 */
class BackgroundImporter {
	/**
	 * Action hook for processing imports.
	 *
	 * @var string
	 */
	private const PROCESS_IMPORT_ACTION = 'alf_process_instagram_import';

	/**
	 * Action hook for completing imports.
	 *
	 * @var string
	 */
	private const COMPLETE_IMPORT_ACTION = 'alf_complete_instagram_import';

	/**
	 * Option name for storing import status.
	 *
	 * @var string
	 */
	private const IMPORT_STATUS_OPTION = 'alf_instagram_import_status';

	/**
	 * Initialize the background importer.
	 */
	public function init() {
		add_action( self::PROCESS_IMPORT_ACTION, array( $this, 'process_chunk' ), 10, 2 );
		add_action( self::COMPLETE_IMPORT_ACTION, array( $this, 'complete_import' ) );
	}

	/**
	 * Schedule the import process.
	 *
	 * @param string $export_path Path to the Instagram export directory.
	 * @return bool True if scheduled successfully, false otherwise.
	 */
	public function schedule_import( string $export_path ): bool {
		if ( as_next_scheduled_action( self::PROCESS_IMPORT_ACTION ) ) {
			return false; // Import already in progress.
		}

		// Initialize import status.
		$this->update_import_status( array(
			'status'     => 'queued',
			'progress'   => 0,
			'started_at' => time(),
		) );

		// Schedule the first chunk.
		as_enqueue_async_action(
			self::PROCESS_IMPORT_ACTION,
			array(
				'export_path' => $export_path,
				'file_index'  => 0,
			),
			'alf-instagram-import'
		);

		return true;
	}

	/**
	 * Process a chunk of the import.
	 *
	 * @param string $export_path Path to the Instagram export directory.
	 * @param int    $file_index Current file index being processed.
	 */
	public function process_chunk( string $export_path, int $file_index ) {
		try {
			$importer = new MediaImporter( $export_path );
			$has_more = $importer->import_media_chunk( $file_index );

			if ( $has_more ) {
				// Schedule next chunk.
				as_enqueue_async_action(
					self::PROCESS_IMPORT_ACTION,
					array(
						'export_path' => $export_path,
						'file_index'  => $file_index + 1,
					),
					'alf-instagram-import'
				);

				$this->update_import_status( array(
					'status'   => 'processing',
					'progress' => $file_index + 1,
				) );
			} else {
				// Schedule completion action.
				as_enqueue_async_action(
					self::COMPLETE_IMPORT_ACTION,
					array(),
					'alf-instagram-import'
				);
			}
		} catch ( \Exception $e ) {
			$this->update_import_status( array(
				'status' => 'failed',
				'error'  => $e->getMessage(),
			) );
		}
	}

	/**
	 * Complete the import process.
	 */
	public function complete_import() {
		$this->update_import_status( array(
			'status'       => 'completed',
			'completed_at' => time(),
		) );
	}

	/**
	 * Get the current import status.
	 *
	 * @return array Import status data.
	 */
	public function get_import_status(): array {
		return get_option( self::IMPORT_STATUS_OPTION, array(
			'status'   => 'none',
			'progress' => 0,
		) );
	}

	/**
	 * Update the import status.
	 *
	 * @param array $data Status data to update.
	 */
	private function update_import_status( array $data ) {
		$current_status = $this->get_import_status();
		update_option( self::IMPORT_STATUS_OPTION, array_merge( $current_status, $data ) );
	}
} 