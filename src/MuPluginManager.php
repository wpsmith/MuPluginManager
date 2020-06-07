<?php
/**
 * WPS Mu Plugins Autoloader
 *
 * Class to handle the copying and removing of a MU plugin.
 *
 * @package    WPS\WP\MuPlugins
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2020 WP Smith, Travis Smith
 * @link       https://github.com/wpsmith/MuPluginManager/
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @version    0.1.0
 * @since      0.1.0
 */

namespace WPS\WP\MuPlugins;

use Exception;
use WP_Error;
use WP_Filesystem_Base;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\MuPluginManager' ) ) {
	/**
	 * Class MuPluginManager
	 *
	 * Class to handle the copying and removing of a MU plugin.
	 */
	class MuPluginManager {

		/**
		 * MU plugin source.
		 *
		 * @var string
		 */
		public $mu_plugin_source;

		/**
		 * MU plugin destination.
		 *
		 * @var string
		 */
		public $mu_plugin_dest;

		/**
		 * Filesystem shim.
		 *
		 * @var WP_Filesystem_Base
		 */
		public $filesystem;

		/**
		 * MU plugin version.
		 *
		 * @var string
		 */
		public $mu_plugin_version;

		/**
		 * mu-plugins directory.
		 *
		 * @var string
		 */
		public $mu_plugin_dir;

		/**
		 * Settings name.
		 *
		 * @var string
		 */
		public $settings_name;

		/**
		 * Settings from DB.
		 *
		 * @var mixed
		 */
		public $settings;

		/**
		 * Whether to throw error if activation/deactivation issues occur.
		 *
		 * Throwing an error on deactivation will prevent the plugin from deactivating.
		 *
		 * @var bool
		 */
		public $throw_errs;

		/**
		 * Activation or Deactivation result.
		 *
		 * @var bool|null|WP_Error
		 */
		public static $activation_result;

		/**
		 * MuPluginManager constructor.
		 *
		 * @param string $settings_name Name of the settings.
		 * @param string $src Source file to be moved to mu-plugins folder.
		 * @param string $dest_filename Destination within mu-plugins to move file.
		 * @param string $version Version of the compatibility plugin, to force an update of the MU plugin, increment this value
		 * @param bool   $throw_errs Whether to throw error if activation/deactivation issues occur.
		 */
		public function __construct( $src, $dest_filename, $version, $settings_name = null, $throw_errs = false ) {

			$this->mu_plugin_version = $version;
			$this->mu_plugin_dir     = self::get_mu_plugin_dir();
			$this->mu_plugin_source  = $src;
			$this->mu_plugin_dest    = trailingslashit( $this->mu_plugin_dir ) . $dest_filename;
			$this->settings_name     = $settings_name;
			$this->throw_errs        = (bool) $throw_errs;
			if ( null !== $settings_name ) {
				$this->settings = get_option( $settings_name );
			}

		}

		/**
		 * Hook into WordPress.
		 */
		public function add_hooks() {

			// Version check.
			add_action( 'admin_init', array( $this, 'init_wp_filesystem' ), 0 );
			add_action( 'admin_init', array( $this, 'muplugin_version_check' ), 1 );

			// Admin notice.
			add_action( 'admin_notices', array( $this, 'admin_notice' ), 1 );

		}

		/**
		 * Gets the admin DB settings.
		 *
		 * @return mixed
		 */
		protected function get_settings() {
			if ( null == $this->settings ) {
				$this->settings = get_option( $this->settings_name );
			}

			return $this->settings;
		}

		/**
		 * Checks if the compatibility mu-plugin requires an update based on the 'mu_plugin_version' setting in the database
		 *
		 * @return bool
		 */
		protected function is_muplugin_update_required() {
			// Return true if the mu-plugin is not installed.
			if ( ! $this->is_muplugin_installed() ) {
				return true;
			}

			// Check via Filesystem.
			$muplugin_data = get_plugin_data( $this->mu_plugin_source, false, false );
			if ( version_compare( $this->mu_plugin_version, $muplugin_data['Version'], '>' ) ) {
				return true;
			}

			// Check via DB.
			$settings = $this->get_settings();
			if (
				! isset( $settings['mu_plugin_version'] ) ||
				( isset( $settings['mu_plugin_version'] ) && version_compare( $this->mu_plugin_version, $settings['mu_plugin_version'], '>' ) )

			) {
				return true;
			}

			return false;
		}

		/**
		 * Outputs the admin notice.
		 *
		 * @param string $notice Admin notice.
		 */
		protected function add_admin_warning_notice( $notice = '' ) {
			?>
            <div class="notice notice-error is-dismissible">
                <p><?php
					if ( '' !== $notice ) {
						echo $notice;
					} else {
						printf( __( 'Your mu-plugin directory is currently not writable. Please update the permissions of the mu-plugins folder: %s', 'wps-codeable' ), $this->mu_plugin_dir );
					}
					?></p>
            </div>
			<?php
		}

		/**
		 * Returns the error that the mu-plugins directory could not be created.
		 *
		 * @return WP_Error
		 */
		protected function get_mu_plugins_not_created() {
			return new WP_Error( 'mu-plugins-not-created', sprintf( esc_html__( 'The mu-plugins directory could not be created: %s', 'wps-codeable' ), $this->mu_plugin_dir ) );
		}

		/**
		 * Returns the error that the mu-plugins directory is not writeable.
		 *
		 * @return WP_Error
		 */
		protected function get_plugins_not_writeable() {
			return new WP_Error( 'mu-plugins-not-writeable', sprintf( __( 'Your mu-plugin directory is currently not writable. Please update the permissions of the mu-plugins folder: %s', 'wps-codeable' ), $this->mu_plugin_dir ) );
		}

		/* PRIVATE WP HOOKS */

		/**
		 * Initializes WP Filesystem.
		 *
		 * @access private
		 * @return WP_Error|bool
		 */
		public function init_wp_filesystem() {
			// Make sure we have access to WP_Filesystem.
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once( ABSPATH . '/wp-admin/includes/file.php' );
			}

			// If we already have the method, let's go.
			if ( get_filesystem_method() === 'direct' ) {
				/* you can safely run request_filesystem_credentials() without any issues and don't need to worry about passing in a URL */
				$creds = request_filesystem_credentials( admin_url(), '', false, false, array() );

				/* initialize the API */
				if ( ! WP_Filesystem( $creds ) ) {
					/* any problems and we exit */
					return new WP_Error( 'filesystem-error', __( 'Something happened with initializing WP Filesystem.', 'wps-codeable' ) );
				}

				global $wp_filesystem;
				$this->filesystem = $wp_filesystem;
			} else {
				$url = wp_nonce_url( 'plugins.php', 'wps-mu-plugin' );

				/** Let's try to setup WP_Filesystem */
				if ( false === ( $creds = request_filesystem_credentials( $url, '', false, false, null ) ) ) {
					/** A form has just been output asking the user to verify file ownership */
					return true;
				}

				/** If the user enters the credentials but the credentials can't be verified to setup WP_Filesystem, output the form again */
				if ( ! WP_Filesystem( $creds ) ) {
					/** This time produce the error that tells the user there was an error connecting */
					request_filesystem_credentials( $url, '', true, false, '' );

					return true;
				}

				if ( WP_Filesystem( $creds ) ) {
					global $wp_filesystem;
					$this->filesystem = $wp_filesystem;
				} else {
					$this->admin_notice();
				}
			}

			return true;
		}

		/**
		 * Adds an admin notice if the mu-plugins directory is not writable.
		 *
		 * @access private
		 */
		public function admin_notice() {
			if ( $this->is_muplugin_update_required() && false === $this->is_muplugin_writable() ) {
				$this->add_admin_warning_notice();
			}
		}

		/**
		 * Checks to see if the latest version of the MU plugin has been installed.
		 *
		 * If the MU plugin is not present, copy the plugin, enabling it by default.
		 * Checks the plugin's setting and 'mu_plugin_version' option to see if the MU plugin needs updating.
		 * If the MU plugin cannot be installed, a warning notice is added within the WP admin.
		 *
		 * @access private
		 */
		public function muplugin_version_check() {
			if (
				self::is_plugins_page() &&
				true === $this->is_muplugin_update_required()
			) {
				$result = $this->copy_muplugin();
				if ( is_wp_error( $result ) ) {
					$this->add_admin_warning_notice( $result );
				}
			}
		}

		/* PUBLIC API */

		/**
		 * Attempts to copy the MU plugin to the mu-plugins directory.
		 *
		 * It optionally creates the mu-plugins directory if it doesn't exist.
		 * It attempts to copy the MU plugin to the mu-plugins directory.
		 * It updates the DB setting with the latest version number.
		 *
		 * @return bool|WP_Error
		 */
		public function copy_muplugin() {
			if ( null === $this->filesystem ) {
				$this->init_wp_filesystem();
			}

			// Make the mu-plugins folder if it doesn't already exist, if the folder does exist it's left as-is.
			if ( ! $this->filesystem->is_dir( $this->mu_plugin_dir ) && ! $this->filesystem->mkdir( $this->mu_plugin_dir ) ) {
				return $this->get_mu_plugins_not_created();
			}

			// Copy the file over.
			if ( ! $this->filesystem->copy( $this->mu_plugin_source, $this->mu_plugin_dest ) ) {
				return $this->get_plugins_not_writeable();
			}

			// Check if the DB needs to be updated.
			if ( $this->is_muplugin_update_required() ) {
				$settings = (array) $this->get_settings();
				// Update version number in the database
				$settings['mu_plugin_version'] = $this->mu_plugin_version;

				update_option( $this->settings_name, $settings );
			}

			return true;
		}

		/**
		 * Attempts to remove the MU plugin from the mu-plugins folder.
		 *
		 * @return bool|WP_Error
		 */
		public function remove_muplugin() {
			if ( null === $this->filesystem ) {
				$this->init_wp_filesystem();
			}

			if ( $this->filesystem->exists( $this->mu_plugin_dest ) && ! $this->filesystem->delete( $this->mu_plugin_dest ) ) {
				return $this->get_plugins_not_writeable();
			}

			return true;
		}

		/**
		 * Checks if the compatibility mu-plugin is installed
		 *
		 * @return bool $installed
		 */
		public function is_muplugin_installed() {
			$plugins           = wp_get_mu_plugins();
			$muplugin_filename = basename( $this->mu_plugin_dest );

			foreach ( $plugins as $plugin ) {
				if ( false !== strpos( $plugin, $muplugin_filename ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Utility function to check if the mu-plugin directory and compatibility plugin are both writable.
		 *
		 * @return bool
		 */
		public function is_muplugin_writable() {
			//Assumes by default we can create the mu-plugins folder and compatibility plugin if they don't exist
			$mu_folder_writable = true;
			$mu_plugin_writable = true;

			//If the mu-plugins folder exists, make sure it's writable.
			if ( true === $this->filesystem->is_dir( $this->mu_plugin_dir ) ) {
				$mu_folder_writable = $this->filesystem->is_writable( $this->mu_plugin_dir );
			}

			//If the mu-plugins/wp-migrate-db-pro-compatibility.php file exists, make sure it's writable.
			if ( true === $this->filesystem->exists( $this->mu_plugin_dest ) ) {
				$mu_plugin_writable = $this->filesystem->is_writable( $this->mu_plugin_dest );
			}

			if ( false === $mu_folder_writable || false === $mu_plugin_writable ) {
				return false;
			}

			return true;
		}

		/**
		 * Write to the WordPress debug.log.
		 *
		 * @param mixed $log Log message to print.
		 */
		public static function write_log( $log ) {
			if ( true === WP_DEBUG ) {
				if ( is_array( $log ) || is_object( $log ) ) {
					error_log( print_r( $log, true ) );
				} else {
					error_log( $log );
				}
			}
		}

		/**
		 * Gets mu-plugins directory.
		 *
		 * @return string
		 */
		public static function get_mu_plugin_dir() {
			$wpmu_plugin_path = ( defined( 'WPMU_PLUGIN_DIR' ) && defined( 'WPMU_PLUGIN_URL' ) ) ? WPMU_PLUGIN_DIR : trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins';

			return trailingslashit( wp_normalize_path( $wpmu_plugin_path ) );
		}

		/**
		 * Determines whether the current admin page is the plugins page.
		 *
		 * @return bool
		 */
		public static function is_plugins_page() {
			global $pagenow;
			$screen = get_current_screen();

			return is_admin() && ( 'plugins.php' === $pagenow || ( null !== $screen && 'plugins.php' === $screen->base ) );
		}

		/**
		 * Removes MU plugin and removes the option from the DB setting.
		 *
		 * @param MuPluginManager $instance The MU plugin manager.
		 *
		 * @return bool|WP_Error The deactivation result.
		 * @throws Exception
		 */
		public static function on_deactivation( MuPluginManager $instance ) {
			self::$activation_result  = $instance->remove_muplugin();

			// If successfully removed, let's do some DB cleanup.
			if ( true === self::$activation_result && null !== $instance->settings ) {
				if ( isset( $instance->settings['mu_plugin_version'] ) ) {
					unset( $instance->settings['mu_plugin_version'] );
				}
				update_site_option( $instance->settings_name, $instance->settings );

				return true;
			}

			return self::on_tivation( $instance, self::$activation_result  );
		}

		/**
		 * @param MuPluginManager $instance The MU plugin manager.
		 *
		 * @return bool|WP_Error The activation result.
		 * @throws Exception
		 */
		public static function on_activation( MuPluginManager $instance ) {
			self::$activation_result = $instance->copy_muplugin();

			return self::on_tivation( $instance, self::$activation_result  );
		}

		/**
		 * Helper for on_activation & on_deactivation.
		 *
		 * @param MuPluginManager $instance The MU plugin manager.
		 * @param bool|WP_Error   $activation_result The (de)activation result.
		 *
		 * @return bool|WP_Error The (de)activation Result.
		 * @throws Exception
		 */
		public static function on_tivation( MuPluginManager $instance, $activation_result ) {
			// If copying the mu plugin failed, let's log it for the user.
			// On next page load, our admin notice should display.
			if ( is_wp_error( $activation_result ) ) {
				self::write_log( $activation_result->get_error_message() );

				// And maybe throw an exception.
				if ( $instance->throw_errs ) {
					$message = __( 'Plugin was not deactivated. ', 'wps' ) . $activation_result->get_error_message();
					throw new Exception( $message );
				}
			}

			return $activation_result;
		}
	}
}
