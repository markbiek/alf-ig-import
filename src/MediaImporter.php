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
	 * Meta key for storing Instagram media identifier.
	 *
	 * @var string
	 */
	public const INSTAGRAM_MEDIA_KEY = '_instagram_media_id';

	/**
	 * Path to the Instagram export directory.
	 *
	 * @var string
	 */
	private string $export_path;

	/**
	 * Constructor.
	 *
	 * @param string $export_path Path to the Instagram export directory.
	 */
	public function __construct( string $export_path ) {
		$this->export_path = $export_path;
	}

	/**
	 * Import media items into WordPress.
	 *
	 * @return void
	 * @throws \Exception When unable to read or parse posts JSON files.
	 */
	public function import_media( array $media_items ): void {
		foreach ( $media_items as $media ) {
			$attachment_id = $this->import_media_item( $media );
			
			if ( $attachment_id ) {
				error_log( 'Imported media item: ' . $attachment_id );
				// Get or create the Instagram category
				$category = get_category_by_slug( 'instagram' );
				if ( ! $category ) {
					$category_id = wp_create_category( 'Instagram' );
				} else {
					$category_id = $category->term_id;
				}

				// Clean up the title
				$clean_title = preg_replace('/#\w+\s*/', '', $media['title']); // Remove hashtags
				$clean_title = trim($clean_title); // Remove extra whitespace
				
				// Get first sentence if multiple exist
				$sentences = preg_split('/(?<=[.!?])\s+/', $clean_title, 2);
				$clean_title = $sentences[0];

				// Create the post
				$post_args = array(
					'post_title'    => $clean_title,
					'post_status'   => 'publish',
					'post_type'     => 'post',
					'post_date'     => gmdate( 'Y-m-d H:i:s', $media['creation_timestamp'] ),
					'post_category' => array( $category_id ),
					'post_content'  => $media['title'],
				);

				$post_id = wp_insert_post( $post_args );

				if ( is_wp_error( $post_id ) ) {
					error_log( sprintf( 'Failed to create post for media %s: %s', $media['uri'], $post_id->get_error_message() ) );
				} else {
					// Set the featured image
					set_post_thumbnail( $post_id, $attachment_id );
				}
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
			error_log( 'Media already imported: ' . $identifier );
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
} 