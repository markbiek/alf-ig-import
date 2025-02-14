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
		$this->importer->init();
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
	 * Reset the import by canceling pending actions and cleaning up canceled ones.
	 */
	private function reset_import() {
		// Cancel all pending actions
		as_unschedule_all_actions( BackgroundImporter::PROCESS_IMPORT_ACTION );
		
		// Delete all canceled actions
		$canceled_actions = as_get_scheduled_actions(
			array(
				'group' => 'alf-instagram-import',
				'status' => \ActionScheduler_Store::STATUS_CANCELED,
				'per_page' => -1
			),
			'ids'
		);

		foreach ( $canceled_actions as $action_id ) {
			as_unschedule_action( '', array(), 'alf-instagram-import', array( 'action_id' => $action_id ) );
		}

		// Reset the import status
		$this->importer->update_import_status( array(
			'status' => 'none',
			'progress' => 0,
			'error' => null
		) );

		add_settings_error(
			'antelope_ig_import',
			'import_reset',
			__( 'Import has been reset successfully.', 'antelope-ig-import' ),
			'success'
		);
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

			<?php if ( in_array( $import_status['status'], array( 'processing', 'queued', 'failed' ), true ) ) : ?>
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
			<?php endif; ?>
		</div>
		<?php
	}
}