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
		 * @var \WP_Filesystem_Base
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
		 * @var bool|null|\WP_Error
		 */
		public static $activation_result;

		/**
		 * MuPluginManager constructor.
		 *
		 * @param string $settings_name Name of the settings.
		 * @param string $src Source file to be moved to mu-plugins folder.
		 * @param string $destFilename Destination within mu-plugins to move file.
		 * @param string $version Version of the compatibility plugin, to force an update of the MU plugin, increment this value
		 * @param bool   $throw_errs Whether to throw error if activation/deactivation issues occur.
		 */
		public function __construct( $src, $destFilename, $version, $settings_name, $throw_errs = false ) {

			$this->mu_plugin_version = $version;
			$this->mu_plugin_dir     = self::get_mu_plugin_dir();
			$this->mu_plugin_source  = $src;
			$this->mu_plugin_dest    = trailingslashit( $this->mu_plugin_dir ) . $destFilename;
			$this->settings_name     = $settings_name;
			$this->settings          = get_option( $settings_name );
			$this->throw_errs        = (bool) $throw_errs;

			// Version check
			add_action( 'admin_init', array( $this, 'init_wp_filesystem' ), 0 );
			add_action( 'admin_init', array( $this, 'muplugin_version_check' ), 1 );

			// Admin notice
			add_action( 'admin_notices', array( $this, 'admin_notice' ), 1 );

		}

		/**
		 * Gets mu-plugins directory.
		 *
		 * @return string
		 */
		public static function get_mu_plugin_dir() {
			return ( defined( 'WPMU_PLUGIN_DIR' ) && defined( 'WPMU_PLUGIN_URL' ) ) ? WPMU_PLUGIN_DIR : trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins';
		}

		/**
		 * Initializes WP Filesystem.
		 *
		 * @return \WP_Error|bool
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
					return new \WP_Error( 'filesystem-error', __( 'Something happened with initializing WP Filesystem.', 'wps-codeable' ) );
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
		 * Checks to see if the latest version of the MU plugin has been installed.
		 *
		 * If the MU plugin is not present, copy the plugin, enabling it by default.
		 * Checks the plugin's setting and 'mu_plugin_version' option to see if the MU plugin needs updating.
		 * If the MU plugin cannot be installed, a warning notice is added within the WP admin.
		 *
		 */
		public function muplugin_version_check() {
			global $pagenow;
			$screen = get_current_screen();

			if (
				( 'plugins.php' === $pagenow || 'plugins.php' === $screen->base ) &&
				true === $this->is_muplugin_update_required()
			) {
				$result = $this->copy_muplugin();
				if ( is_wp_error( $result ) ) {
					$this->add_admin_warning_notice( $result );
				}
			}
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
		public function is_muplugin_update_required() {
			$settings = $this->get_settings();

			if (
				! isset( $settings['mu_plugin_version'] ) ||
				( isset( $settings['mu_plugin_version'] ) && version_compare( $this->mu_plugin_version, $settings['mu_plugin_version'], '>' ) && $this->is_muplugin_installed() )

			) {
				return true;
			}

			return false;
		}

		/**
		 * Adds an admin notice if the mu-plugins directory is not writable.
		 */
		public function admin_notice() {
			if ( $this->is_muplugin_update_required() && false === $this->is_muplugin_writable() ) {
				$this->add_admin_warning_notice();
			}
		}

		/**
		 * Outputs the admin notice.
		 *
		 * @param string $notice Admin notice.
		 */
		public function add_admin_warning_notice( $notice = '' ) {
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
		 * Attempts to copy the MU plugin to the mu-plugins directory.
		 *
		 * It optionally creates the mu-plugins directory if it doesn't exist.
		 * It attempts to copy the MU plugin to the mu-plugins directory.
		 * It updates the DB setting with the latest version number.
		 *
		 * @return bool|\WP_Error
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
				$settings = (array)$this->get_settings();
				// Update version number in the database
				$settings['mu_plugin_version'] = $this->mu_plugin_version;

				update_site_option( $this->settings_name, $settings );
			}

			return true;
		}

		/**
		 * Attempts to remove the MU plugin from the mu-plugins folder.
		 *
		 * @return bool|\WP_Error
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
		 * Returns the error that the mu-plugins directory could not be created.
		 *
		 * @return \WP_Error
		 */
		public function get_mu_plugins_not_created() {
			return new \WP_Error( 'mu-plugins-not-created', sprintf( esc_html__( 'The mu-plugins directory could not be created: %s', 'wps-codeable' ), $this->mu_plugin_dir ) );
		}

		/**
		 * Returns the error that the mu-plugins directory is not writeable.
		 *
		 * @return \WP_Error
		 */
		public function get_plugins_not_writeable() {
			return new \WP_Error( 'mu-plugins-not-writeable', sprintf( __( 'Your mu-plugin directory is currently not writable. Please update the permissions of the mu-plugins folder: %s', 'wps-codeable' ), $this->mu_plugin_dir ) );
		}

		/**
		 * Removes MU plugin and removes the option from the DB setting.
		 *
		 * @param MuPluginManager $instance
		 *
		 * @return bool|\WP_Error|null
		 * @throws \Exception
		 */
		public static function on_deactivation( MuPluginManager $instance ) {
			self::$activation_result = $instance->remove_muplugin();

			// If successfully removed, let's do some DB cleanup.
			if ( true === self::$activation_result ) {
				if ( isset( $instance->settings['mu_plugin_version'] ) ) {
					unset( $instance->settings['mu_plugin_version'] );
				}
				update_site_option( $instance->settings_name, $instance->settings );

				return true;
			}

			// If removal failed...
			if ( is_wp_error( self::$activation_result ) ) {
				// Let's log it for the user.
				self::write_log( self::$activation_result->get_error_message() );

				// And maybe throw an exception.
				if ( $instance->throw_errs ) {
					$message = __( 'Plugin was not deactivated. ', 'wps-codeable' ) . self::$activation_result->get_error_message();
					throw new \Exception( $message );
				}
			}

			return self::$activation_result;
		}

		/**
		 * Handles the adding of the MU plugin on the activation hook.
		 *
		 * @param MuPluginManager $instance
		 */
		public static function on_activation( MuPluginManager $instance ) {
			self::$activation_result = $instance->copy_muplugin();

			// If copying the mu plugin failed, let's log it for the user.
			// On next page load, our admin notice should display.
			if ( is_wp_error( self::$activation_result ) ) {
				self::write_log( self::$activation_result->get_error_message() );
			}
		}

		/**
		 * Checks if the compatibility mu-plugin is installed
		 *
		 * @return bool $installed
		 */
		public function is_muplugin_installed() {
			$plugins           = wp_get_mu_plugins();
			$muplugin_filename = basename( $this->mu_plugin_dest );
			$installed         = false;

			foreach ( $plugins as $plugin ) {
				if ( false !== strpos( $plugin, $muplugin_filename ) ) {
					$installed = true;
				}
			}

			return $installed;
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
	}
}
