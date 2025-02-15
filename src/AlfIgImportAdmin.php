<?php
/**
 * Admin functionality for the Antelope Instagram Import plugin.
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
use function as_unschedule_action;
use function get_option;
use function time;
use function update_option;
use AlfIgImport\MediaImporter;

/**
 * Class to handle admin functionality.
 */
class AlfIgImportAdmin {
	/**
	 * Background importer instance.
	 *
	 * @var BackgroundImporter
	 */
	private BackgroundImporter $importer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->importer = new BackgroundImporter();
	}

	/**
	 * Initialize the admin functionality.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
	}

	/**
	 * Handle the form submission for importing Instagram data.
	 */
	public function handle_form_submission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle reset action
		if ( 
			isset( $_POST['action'] ) && 
			'reset_import' === $_POST['action'] && 
			isset( $_POST['reset_import_nonce'] ) && 
			wp_verify_nonce( $_POST['reset_import_nonce'], 'reset_import_action' )
		) {
			$this->reset_import();
			return;
		}

		if ( ! isset( $_POST['antelope_ig_import_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['antelope_ig_import_nonce'], 'antelope_ig_import_action' ) ) {
			return;
		}

		$export_path = plugin_dir_path( dirname( __FILE__ ) ) . 'ig-data';

		if ( ! $this->importer->schedule_import( $export_path ) ) {
			add_settings_error(
				'antelope_ig_import',
				'import_error',
				__( 'An import is already in progress.', 'antelope-ig-import' ),
				'error'
			);
			return;
		}

		add_settings_error(
			'antelope_ig_import',
			'import_scheduled',
			__( 'Instagram import has been scheduled and will begin shortly.', 'antelope-ig-import' ),
			'success'
		);
	}

	/**
	 * Reset the import by canceling pending actions and cleaning up actions.
	 */
	private function reset_import() {
		Logger::info( 'Resetting import' );
		try {
			// Delete the import status and export path options
			delete_option( BackgroundImporter::IMPORT_STATUS_OPTION );
			delete_option( BackgroundImporter::EXPORT_PATH_OPTION );
			Logger::info( 'Import status and export path options deleted' );

			// Cancel all pending actions
			as_unschedule_all_actions( BackgroundImporter::PROCESS_IMPORT_ACTION );
			Logger::info( 'All pending actions canceled' );
			
			// Clean up actions using WP-CLI command
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				\WP_CLI::runcommand( 'action-scheduler clean' );
				Logger::info( 'Actions cleaned up using WP-CLI' );
			} else {
				// Fallback for non-CLI environment - clean up canceled actions
				global $wpdb;
				$table_name = $wpdb->prefix . 'actionscheduler_actions';
				
				// Delete all actions related to our import
				$wpdb->delete(
					$table_name,
					array( 'hook' => BackgroundImporter::PROCESS_IMPORT_ACTION ),
					array( '%s' )
				);
				Logger::info( 'Actions cleaned up using fallback method' );
			}

			// Delete all Instagram media post meta entries
			global $wpdb;
			$wpdb->delete(
				$wpdb->postmeta,
				array('meta_key' => MediaImporter::INSTAGRAM_MEDIA_KEY),
				array('%s')
			);
			Logger::info( 'Instagram media post meta entries deleted' );

			// Reset the import status
			$this->importer->update_import_status( array(
				'status' => 'none',
				'progress' => 0,
				'error' => null
			) );
			Logger::info( 'Import status reset to none' );

			add_settings_error(
				'antelope_ig_import',
				'import_reset',
					__( 'Import has been reset successfully.', 'antelope-ig-import' ),
					'success'
				);
		} catch ( Exception $e ) {
			Logger::error( 'Error resetting import: %s', $e->getMessage() );
			add_settings_error(
				'antelope_ig_import',
				'import_reset_error',
				__( 'Error resetting import: %s', 'antelope-ig-import' ),
				'error'
			);
		}
	}

	/**
	 * Add the admin menu item.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'tools.php',
			__( 'Instagram Import', 'antelope-ig-import' ),
			__( 'Instagram Import', 'antelope-ig-import' ),
			'manage_options',
			'antelope-ig-import',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_admin_page() {
		$import_status = $this->importer->get_import_status();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Instagram Import', 'antelope-ig-import' ); ?></h1>

			<?php settings_errors( 'antelope_ig_import' ); ?>

			<?php if ( 'processing' === $import_status['status'] ) : ?>
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: %d: number of files processed */
							esc_html__( 'Import in progress. Files processed: %d', 'antelope-ig-import' ),
							esc_html( $import_status['progress'] )
						);
						?>
					</p>
				</div>
			<?php elseif ( 'completed' === $import_status['status'] ) : ?>
				<div class="notice notice-success">
					<p><?php esc_html_e( 'Import completed successfully.', 'antelope-ig-import' ); ?></p>
				</div>
			<?php elseif ( 'failed' === $import_status['status'] ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						printf(
							/* translators: %s: error message */
							esc_html__( 'Import failed: %s', 'antelope-ig-import' ),
							esc_html( $import_status['error'] ?? __( 'Unknown error', 'antelope-ig-import' ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! in_array( $import_status['status'], array( 'processing', 'queued' ), true ) ) : ?>
				<form method="post" action="">
					<?php wp_nonce_field( 'antelope_ig_import_action', 'antelope_ig_import_nonce' ); ?>
					<?php submit_button( __( 'Import Instagram Data', 'antelope-ig-import' ) ); ?>
				</form>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'reset_import_action', 'reset_import_nonce' ); ?>
				<input type="hidden" name="action" value="reset_import" />
				<?php submit_button( 
					__( 'Reset Import', 'antelope-ig-import' ),
					'secondary',
					'reset_import',
					false
				); ?>
			</form>
		</div>
		<?php
	}
}