<?php
/**
 * WP-CLI commands for the Antelope Instagram Import plugin.
 *
 * @category  Social_Media
 * @package   Antelope_Ig_Import
 * @author    Mark Biek <markbiek@duck.com>
 * @copyright 2024 Mark Biek
 * @license   GPL v2 or newer <https://www.gnu.org/licenses/gpl.txt>.
 */

namespace AlfIgImport;

use WP_CLI;

/**
 * Manages Instagram import data.
 */
class CLI {
	/**
	 * Resets WordPress by deleting all posts and media.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt
	 *
	 * ## EXAMPLES
	 *
	 *     # Reset all content with confirmation prompt
	 *     $ wp alf-ig-import reset
	 *
	 *     # Reset all content without confirmation
	 *     $ wp alf-ig-import reset --yes
	 */
	public function reset( $args, $assoc_args ) {
		WP_CLI::error( 'You will need to remove this line in order to use the reset command. NOTE: This will delete ALL posts and media items, NOT just the ones imported by this plugin.' );
		// First delete all posts
		$posts = get_posts( array(
			'post_type'      => 'post',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		) );

		if ( ! empty( $posts ) ) {
			$progress = WP_CLI\Utils\make_progress_bar( 'Deleting posts', count( $posts ) );

			foreach ( $posts as $post ) {
				wp_delete_post( $post->ID, true );
				$progress->tick();
			}

			$progress->finish();
			WP_CLI::success( sprintf( 'Deleted %d posts.', count( $posts ) ) );
		}

		// Then delete all media items
		$attachments = get_posts( array(
			'post_type'      => 'attachment',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		) );

		if ( ! empty( $attachments ) ) {
			$progress = WP_CLI\Utils\make_progress_bar( 'Deleting media items', count( $attachments ) );

			foreach ( $attachments as $attachment ) {
				wp_delete_attachment( $attachment->ID, true );
				$progress->tick();
			}

			$progress->finish();
			WP_CLI::success( sprintf( 'Deleted %d media items.', count( $attachments ) ) );
		}

		if ( empty( $posts ) && empty( $attachments ) ) {
			WP_CLI::warning( 'No content found to delete.' );
		}
	}
} 