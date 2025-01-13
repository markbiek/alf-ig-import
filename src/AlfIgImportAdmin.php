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

/**
 * Class to handle admin functionality.
 */
class AlfIgImportAdmin {
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
		if ( ! isset( $_POST['antelope_ig_import_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['antelope_ig_import_nonce'], 'antelope_ig_import_action' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		try {
			$export_path = plugin_dir_path( dirname( __FILE__ ) ) . 'ig-data';
			$importer = new MediaImporter( $export_path );
			$importer->import_media();
			add_settings_error(
				'antelope_ig_import',
				'import_success',
				__( 'Instagram data imported successfully.', 'antelope-ig-import' ),
				'success'
			);
		} catch ( \Exception $e ) {
			add_settings_error(
				'antelope_ig_import',
				'import_error',
				sprintf(
					/* translators: %s: Error message */
					__( 'Error importing Instagram data: %s', 'antelope-ig-import' ),
					$e->getMessage()
				),
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
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Instagram Import', 'antelope-ig-import' ); ?></h1>
			<form method="post" action="">
				<?php wp_nonce_field( 'antelope_ig_import_action', 'antelope_ig_import_nonce' ); ?>
				<?php submit_button( __( 'Import Instagram Data', 'antelope-ig-import' ) ); ?>
			</form>
		</div>
		<?php
	}
}