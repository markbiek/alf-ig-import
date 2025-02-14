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
		add_action( self::PROCESS_IMPORT_ACTION, array( $this, 'process_chunk' ), 10, 3 );
		add_action( self::COMPLETE_IMPORT_ACTION, array( $this, 'complete_import' ) );
	}

	/**
	 * Schedule the import process.
	 *
	 * @param string $export_path Path to the Instagram export directory.
	 * @return bool True if scheduled successfully, false otherwise.
	 * @throws \Exception When unable to read or parse posts JSON files.
	 */
	public function schedule_import( string $export_path ): bool {
		if ( as_next_scheduled_action( self::PROCESS_IMPORT_ACTION ) ) {
			error_log( 'Import already in progress' );
			return false; // Import already in progress
		}

		// Get all media items from JSON files
		$content_dir = $export_path . '/your_instagram_activity/content';
		$posts_files = glob( $content_dir . '/posts*.json' );
		if ( empty( $posts_files ) ) {
			throw new \Exception( 'No posts JSON files found in content directory' );
		}

		$all_media_items = [];
		foreach ( $posts_files as $json_path ) {
			$posts = json_decode( file_get_contents( $json_path ), true );
			if ( ! is_array( $posts ) ) {
				throw new \Exception( sprintf( 'Invalid JSON format in %s', basename( $json_path ) ) );
			}

			foreach ( $posts as $post ) {
				if ( isset( $post['media'] ) && is_array( $post['media'] ) ) {
					$filtered_media = array_map( function( $media_item ) {
						return array_intersect_key( $media_item, array_flip( [
							'uri',
							'creation_timestamp',
							'title'
						] ) );
					}, $post['media'] );
					
					$all_media_items = array_merge( $all_media_items, $filtered_media );
				}
			}
		}

		error_log( sprintf( 'Preparing process %d media items', count( $all_media_items ) ) );

		// Split into chunks and schedule actions
		$chunks = array_chunk( $all_media_items, 10 ); // Process 10 items at a time
		$total_chunks = count( $chunks );

		// Initialize import status
		$this->update_import_status( array(
			'status'     => 'queued',
			'progress'   => 0,
			'total'      => count( $all_media_items ),
			'started_at' => time(),
		) );

		// Schedule chunks
		foreach ( $chunks as $index => $chunk ) {
			as_enqueue_async_action(
				self::PROCESS_IMPORT_ACTION,
				array(
					'media_items'   => $chunk,
					'chunk_number'  => $index,
					'total_chunks'  => $total_chunks,
				),
				'alf-instagram-import'
			);
		}

		error_log( sprintf( 'Scheduled %d chunks', $total_chunks ) );

		return true;
	}

	/**
	 * Process a chunk of the import.
	 *
	 * @param array $media_items Array of media items to import.
	 * @param int   $chunk_number Current chunk number being processed.
	 * @param int   $total_chunks Total number of chunks.
	 */
	public function process_chunk( array $media_items, int $chunk_number, int $total_chunks ) {
		try {
			error_log( sprintf( 'Processing chunk %d of %d', $chunk_number, $total_chunks ) );

			$importer = new MediaImporter( $this->export_path );
			$importer->import_media( $media_items );

			$this->update_import_status( array(
				'status'   => 'processing',
				'progress' => ( $chunk_number + 1 ) * count( $media_items ),
			) );

			// If this was the last chunk, schedule completion
			if ( $chunk_number === $total_chunks - 1 ) {
				error_log( 'Scheduling completion' );
				as_enqueue_async_action(
					self::COMPLETE_IMPORT_ACTION,
					array(),
					'alf-instagram-import'
				);
			}
		} catch ( \Exception $e ) {
			error_log( sprintf( 'Error processing chunk %d: %s', $chunk_number, $e->getMessage() ) );
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