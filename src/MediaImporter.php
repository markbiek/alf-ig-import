<?php
/**
 * Class to handle importing Instagram media into WordPress.
 *
 * @package Antelope_Ig_Import
 */

namespace AlfIgImport;

/**
 * Handles importing Instagram media into WordPress.
 */
class MediaImporter {
	/**
	 * Path to the Instagram export directory.
	 *
	 * @var string
	 */
	private string $export_path;

	/**
	 * Meta key for storing Instagram media identifier.
	 *
	 * @var string
	 */
	private const INSTAGRAM_MEDIA_KEY = '_instagram_media_id';

	/**
	 * Constructor.
	 *
	 * @param string $export_path Path to the Instagram export directory.
	 */
	public function __construct( string $export_path ) {
		$this->export_path = $export_path;
	}

	/**
	 * Import media from all posts_*.json files into WordPress.
	 *
	 * @return void
	 * @throws \Exception When unable to read or parse posts JSON files.
	 */
	public function import_media(): void {
		$content_dir = $this->export_path . '/your_instagram_activity/content';
		$posts_files = glob( $content_dir . '/posts*.json' );

		if ( empty( $posts_files ) ) {
			throw new \Exception( 'No posts JSON files found in content directory' );
		}

		foreach ( $posts_files as $json_path ) {
			$this->import_posts_file( $json_path );
		}
	}

	/**
	 * Import media from a single posts JSON file.
	 *
	 * @param string $json_path Path to the JSON file.
	 * @return void
	 * @throws \Exception When unable to read or parse the JSON file.
	 */
	private function import_posts_file( string $json_path ): void {
		$json_content = file_get_contents( $json_path );

		if ( ! $json_content ) {
			throw new \Exception( sprintf( 'Unable to read %s', basename( $json_path ) ) );
		}

		$posts = json_decode( $json_content, true );

		if ( ! is_array( $posts ) ) {
			throw new \Exception( sprintf( 'Invalid JSON format in %s', basename( $json_path ) ) );
		}

		foreach ( $posts as $post ) {
			if ( ! isset( $post['media'] ) || ! is_array( $post['media'] ) ) {
				continue;
			}

			foreach ( $post['media'] as $media ) {
				$this->import_media_item( $media );
			}
		}
	}

	/**
	 * Generate a unique identifier for an Instagram media item.
	 *
	 * @param array $media Media item data.
	 * @return string Unique identifier.
	 */
	private function generate_media_identifier( array $media ): string {
		return md5( $media['uri'] . $media['creation_timestamp'] );
	}

	/**
	 * Check if a media item has already been imported.
	 *
	 * @param string $identifier Media identifier.
	 * @return bool True if already imported, false otherwise.
	 */
	private function is_media_imported( string $identifier ): bool {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::INSTAGRAM_MEDIA_KEY,
				$identifier
			)
		);

		return ! empty( $result );
	}

	/**
	 * Import a single media item into WordPress.
	 *
	 * @param array $media Media item data.
	 * @return int|false The attachment ID on success, false on failure.
	 */
	private function import_media_item( array $media ): int|false {
		if ( ! isset( $media['uri'], $media['creation_timestamp'], $media['title'] ) ) {
			return false;
		}

		// Generate unique identifier for this media item.
		$identifier = $this->generate_media_identifier( $media );

		// Skip if already imported.
		if ( $this->is_media_imported( $identifier ) ) {
			return false;
		}

		$file_path = $this->export_path . '/' . $media['uri'];

		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		// Prepare file for upload.
		$file = array(
			'name'     => basename( $file_path ),
			'tmp_name' => $file_path,
			'error'    => 0,
			'size'     => filesize( $file_path ),
		);

		// WordPress upload handling.
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/media.php' );

		// Set up the array of post data for this media item.
		$post_data = array(
			'post_title'   => $media['title'],
			'post_content' => $media['title'],
			'post_date'    => gmdate( 'Y-m-d H:i:s', $media['creation_timestamp'] ),
			'post_status'  => 'publish',
		);

		// Insert the attachment.
		$attachment_id = media_handle_sideload( $file, 0, $media['title'], $post_data );

		if ( is_wp_error( $attachment_id ) ) {
			return false;
		}

		// Store the Instagram identifier as post meta.
		update_post_meta( $attachment_id, self::INSTAGRAM_MEDIA_KEY, $identifier );

		return $attachment_id;
	}

	/**
	 * Import a chunk of media from a specific posts JSON file.
	 *
	 * @param int $file_index Index of the posts file to process.
	 * @return bool True if there are more files to process, false if complete.
	 * @throws \Exception When unable to read or parse the JSON file.
	 */
	public function import_media_chunk( int $file_index ): bool {
		$content_dir = $this->export_path . '/your_instagram_activity/content';
		$posts_files = glob( $content_dir . '/posts*.json' );

		if ( empty( $posts_files ) ) {
			throw new \Exception( 'No posts JSON files found in content directory' );
		}

		if ( ! isset( $posts_files[ $file_index ] ) ) {
			return false; // No more files to process.
		}

		$this->import_posts_file( $posts_files[ $file_index ] );

		// Return true if there are more files to process.
		return isset( $posts_files[ $file_index + 1 ] );
	}
} 