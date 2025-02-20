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
	 * Selected categories option.
	 *
	 * @var string
	 */
	private const SELECTED_CATEGORIES_OPTION = 'antelope_ig_import_categories';

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
		if (!current_user_can('manage_options')) {
			return;
		}

		// Handle reset action
		if (
			isset($_POST['action']) && 
			'reset_import' === $_POST['action'] && 
			isset($_POST['reset_import_nonce']) && 
			wp_verify_nonce($_POST['reset_import_nonce'], 'reset_import_action')
		) {
			$this->reset_import();
			return;
		}

		if (!isset($_POST['antelope_ig_import_nonce'])) {
			return;
		}

		if (!wp_verify_nonce($_POST['antelope_ig_import_nonce'], 'antelope_ig_import_action')) {
			return;
		}

		// Check if export file was selected
		if (!isset($_POST['export_file']) || empty($_POST['export_file'])) {
			add_settings_error(
				'antelope_ig_import',
				'file_required',
				__('Please select an export file.', 'antelope-ig-import'),
				'error'
			);
			return;
		}

		// Check if categories were selected
		if (!isset($_POST['import_categories']) || !is_array($_POST['import_categories'])) {
			add_settings_error(
				'antelope_ig_import',
				'categories_required',
				__('Please select at least one category.', 'antelope-ig-import'),
				'error'
			);
			return;
		}

		// Sanitize and save selected categories
		$selected_categories = array_map('absint', $_POST['import_categories']);
		update_option(self::SELECTED_CATEGORIES_OPTION, $selected_categories);

		// Get the full path to the selected file
		$upload_dir = wp_upload_dir();
		$zip_path = path_join($upload_dir['basedir'], sanitize_text_field($_POST['export_file']));

		try {
			// Extract the ZIP file
			$extracted_path = $this->extract_zip($zip_path);
			Logger::info('Extracted Instagram export to: %s', $extracted_path);

			// Schedule the import with the extracted path
			if (!$this->importer->schedule_import($extracted_path)) {
				$this->cleanup_temp_dir($extracted_path);
				add_settings_error(
					'antelope_ig_import',
					'import_error',
					__('An import is already in progress.', 'antelope-ig-import'),
					'error'
				);
				return;
			}

			add_settings_error(
				'antelope_ig_import',
				'import_scheduled',
				__('Instagram import has been scheduled and will begin shortly.', 'antelope-ig-import'),
				'success'
			);
		} catch (\Exception $e) {
			Logger::error('Failed to extract ZIP file: %s', $e->getMessage());
			add_settings_error(
				'antelope_ig_import',
				'extract_error',
				sprintf(
					/* translators: %s: error message */
					__('Failed to extract ZIP file: %s', 'antelope-ig-import'),
					$e->getMessage()
				),
				'error'
			);
		}
	}

	/**
	 * Reset the import by canceling pending actions and cleaning up actions.
	 */
	private function reset_import() {
		Logger::info('Resetting import');
		try {
			// Clean up extracted files if they exist
			$paths = get_option('alf_instagram_export_path');
			if ($paths && !empty($paths['temp_dir'])) {
				$this->cleanup_temp_dir($paths['temp_dir']);
				delete_option('alf_instagram_export_path');
			}

			// Delete the import status and export path options
			delete_option(BackgroundImporter::IMPORT_STATUS_OPTION);
			delete_option(BackgroundImporter::EXPORT_PATH_OPTION);
			Logger::info('Import status and export path options deleted');

			// Cancel all pending actions
			as_unschedule_all_actions(BackgroundImporter::PROCESS_IMPORT_ACTION);
			Logger::info('All pending actions canceled');
			
			// Clean up actions using WP-CLI command
			if (defined('WP_CLI') && WP_CLI) {
				\WP_CLI::runcommand('action-scheduler clean');
				Logger::info('Actions cleaned up using WP-CLI');
			} else {
				// Fallback for non-CLI environment - clean up canceled actions
				global $wpdb;
				$table_name = $wpdb->prefix . 'actionscheduler_actions';
				
				// Delete all actions related to our import
				$wpdb->delete(
					$table_name,
					array('hook' => BackgroundImporter::PROCESS_IMPORT_ACTION),
					array('%s')
				);
				Logger::info('Actions cleaned up using fallback method');
			}

			// Delete all Instagram media post meta entries
			global $wpdb;
			$wpdb->delete(
				$wpdb->postmeta,
				array('meta_key' => MediaImporter::INSTAGRAM_MEDIA_KEY),
				array('%s')
			);
			Logger::info('Instagram media post meta entries deleted');

			// Reset the import status
			$this->importer->update_import_status(array(
				'status' => 'none',
				'progress' => 0,
				'error' => null
			));
			Logger::info('Import status reset to none');

			add_settings_error(
				'antelope_ig_import',
				'import_reset',
				__( 'Import has been reset successfully.', 'antelope-ig-import' ),
				'success'
			);
		} catch (Exception $e) {
			Logger::error('Error resetting import: %s', $e->getMessage());
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
	public function render_admin_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}
		
		$import_status = $this->importer->get_import_status();
		$available_files = $this->get_available_export_files();
		$selected_categories = get_option(self::SELECTED_CATEGORIES_OPTION, array());
		
		// Get all categories
		$categories = get_categories(array(
			'hide_empty' => false,
			'orderby' => 'name',
			'order' => 'ASC'
		));
		
		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

			<?php settings_errors('antelope_ig_import'); ?>

			<?php if ('processing' === $import_status['status']): ?>
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: %d: number of files processed */
							esc_html__('Import in progress. Files processed: %d', 'antelope-ig-import'),
							esc_html($import_status['progress'])
						);
						?>
					</p>
				</div>
			<?php elseif ('completed' === $import_status['status']): ?>
				<div class="notice notice-success">
					<p><?php esc_html_e('Import completed successfully.', 'antelope-ig-import'); ?></p>
				</div>
			<?php elseif ('failed' === $import_status['status']): ?>
				<div class="notice notice-error">
					<p>
						<?php
						printf(
							/* translators: %s: error message */
							esc_html__('Import failed: %s', 'antelope-ig-import'),
							esc_html($import_status['error'] ?? __('Unknown error', 'antelope-ig-import'))
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if (!in_array($import_status['status'], array('processing', 'queued'), true)): ?>
				<?php if (empty($available_files)): ?>
					<div class="notice notice-warning">
						<p><?php _e('No Instagram export files found in uploads directory. Please upload a file named instagram-*.zip to your WordPress uploads folder.', 'antelope-ig-import'); ?></p>
					</div>
				<?php else: ?>
					<form method="post" action="">
						<?php wp_nonce_field('antelope_ig_import_action', 'antelope_ig_import_nonce'); ?>
						
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="export_file"><?php _e('Select Export File', 'antelope-ig-import'); ?></label>
								</th>
								<td>
									<select name="export_file" id="export_file" class="regular-text">
										<?php foreach ($available_files as $path => $label): ?>
											<option value="<?php echo esc_attr($path); ?>">
												<?php echo esc_html($label); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php _e('Choose the Instagram export file to import.', 'antelope-ig-import'); ?>
									</p>
								</td>
							</tr>
						</table>

						<div class="category-selection">
							<h3><?php esc_html_e('Select Categories', 'antelope-ig-import'); ?></h3>
							<p class="description">
								<?php esc_html_e('Choose one or more categories for imported posts.', 'antelope-ig-import'); ?>
							</p>
							
							<div class="categories-list" style="margin: 20px 0;">
								<?php foreach ($categories as $category): ?>
									<label style="display: block; margin-bottom: 10px;">
										<input type="checkbox" 
											   name="import_categories[]" 
											   value="<?php echo esc_attr($category->term_id); ?>"
											   <?php checked(in_array($category->term_id, $selected_categories, true)); ?>>
										<?php echo esc_html($category->name); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<?php submit_button(__('Import Instagram Data', 'antelope-ig-import')); ?>
					</form>
				<?php endif; ?>

				<form method="post" action="">
					<?php wp_nonce_field('reset_import_action', 'reset_import_nonce'); ?>
					<input type="hidden" name="action" value="reset_import" />
					<?php submit_button(
						__('Reset Import', 'antelope-ig-import'),
						'secondary',
						'reset_import',
						false
					); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get list of potential Instagram export ZIP files from uploads directory.
	 *
	 * @return array Array of files with paths relative to uploads dir.
	 */
	private function get_available_export_files() {
		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];
		
		// Look for files matching instagram-*-*.zip pattern
		$files = glob( $base_dir . '/instagram-*-*.zip' );
		
		// Convert to relative paths and create labels
		$options = array();
		foreach ( $files as $file ) {
			$relative_path = str_replace( $base_dir . '/', '', $file );
			$file_info = pathinfo( $file );
			$modified = date( 'Y-m-d H:i', filemtime( $file ) );
			$size = size_format( filesize( $file ) );
			
			$options[ $relative_path ] = sprintf(
				'%s (%s, %s)',
				$file_info['filename'],
				$modified,
				$size
			);
		}
		
		return $options;
	}

	/**
	 * Extract Instagram export ZIP file to a temporary directory.
	 *
	 * @param string $zip_path Full path to ZIP file.
	 * @return string Path to extracted directory.
	 * @throws \Exception If extraction fails.
	 */
	private function extract_zip(string $zip_path): string {
		if (!class_exists('ZipArchive')) {
			throw new \Exception('PHP ZIP extension is not available');
		}

		// Create a unique temporary directory
		$temp_dir = sys_get_temp_dir() . '/instagram-import-' . bin2hex(random_bytes(6));
		if (!mkdir($temp_dir, 0777, true)) {
			throw new \Exception('Failed to create temporary directory');
		}

		// Open and extract the ZIP file
		$zip = new \ZipArchive();
		$res = $zip->open($zip_path);
		
		if ($res !== true) {
			rmdir($temp_dir);
			throw new \Exception('Failed to open ZIP file');
		}

		if (!$zip->extractTo($temp_dir)) {
			$zip->close();
			$this->cleanup_temp_dir($temp_dir);
			throw new \Exception('Failed to extract ZIP file');
		}

		$zip->close();

		// Store the paths for later cleanup
		update_option('alf_instagram_export_path', [
			'zip_path' => $zip_path,
			'temp_dir' => $temp_dir
		]);

		return $temp_dir;
	}

	/**
	 * Clean up temporary directory recursively.
	 *
	 * @param string $dir Directory to remove.
	 */
	private function cleanup_temp_dir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($files as $file) {
			if ($file->isDir()) {
				rmdir($file->getRealPath());
			} else {
				unlink($file->getRealPath());
			}
		}

		rmdir($dir);
	}
}