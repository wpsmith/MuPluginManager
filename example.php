<?php
/**
 * Plugin Name:     WPS Sample Plugin
 * Plugin URI:      https://wpsmith.net
 * Description:     Sample plugin written.
 * Author:          Travis Smith <t@wpsmith.net>
 * Author URI:      https://wpsmith.net
 * Text Domain:     my-plugin
 * Domain Path:     /languages
 * Version:         0.0.6
 *
 * The plugin bootstrap file.
 *
 * You may copy, distribute and modify the software as long as you track
 * changes/dates in source files. Any modifications to or software including
 * (via compiler) GPL-licensed code must also be made available under the GPL
 * along with build & install instructions.
 *
 * PHP Version 7.2
 *
 * @category   WPS\WP\Plugins\MyPlugin
 * @package    WPS\WP\Plugins\MyPlugin
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2020 Travis Smith
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @link       https://wpsmith.net/
 * @since      0.0.1
 */

namespace My\WP\Plugins\MyPlugin;

use WPS\WP\MuPlugins\MuPluginManager;

if ( ! class_exists( __NAMESPACE__ . '\Plugin' ) ) {
	/**
	 * Class Plugin
	 *
	 * @package \WPS\WP\Plugins\Codeable
	 */
	class Plugin {

		/**
		 * Plugin Version Number
		 */
		const VERSION = '0.0.1';

		/**
		 * The unique identifier of this plugin.
		 *
		 * @var string $plugin_name The string used to uniquely identify this plugin.
		 */
		protected static $plugin_name = 'my-plugin';

		/**
		 * MU Plugin Manager.
		 *
		 * @var MuPluginManager
		 */
		protected static $manager;

		/**
		 * MU Plugin file.
		 *
		 * @var string
		 */
		protected static $mu_plugin_file = 'my-mu-plugin.php';

		/* MANY OTHER METHODS, etc. */

		/**
		 * The name of the plugin used to uniquely identify it within the context of
		 * WordPress and to define internationalization functionality.
		 *
		 * @return string The name of the plugin.
		 */
		public static function get_plugin_name() {
			return self::$plugin_name;
		}

		/**
		 * Activation function.
		 */
		public static function on_deactivation() {
			try {
				MuPluginManager::on_deactivation( self::get_manager() );
			} catch ( \Exception $e ) {
				MuPluginManager::write_log( 'MU Plugin threw an error' );
				MuPluginManager::write_log( $e->getMessage() );
			}
		}

		/**
		 * Activation function.
		 */
		public static function on_activation() {
			MuPluginManager::on_activation( self::get_manager() );
		}

		/**
		 * Gets the MU Plugin Manager.
		 *
		 * @param string Full file path.
		 *
		 * @return MuPluginManager
		 */
		public static function get_manager( $plugin_dir_path = null ) {
			if ( null !== self::$manager ) {
				return self::$manager;
			}

			// Make sure we have the plugin directory path.
			if ( null === $plugin_dir_path ) {
				$plugin_dir_path = plugin_dir_path( __FILE__ );
			}

			// Path to the actual MU plugin located within this plugin.
			$src = $plugin_dir_path . 'includes/mu-plugin/' . self::$mu_plugin_file;

			// Version here is the MU plugin version.
			self::$manager = new MuPluginManager( $src, self::$mu_plugin_file, '0.0.1', self::get_plugin_name() . '-mu' );

			return self::$manager;
		}
	}
}

// Register (de)activation hooks.
register_activation_hook( __FILE__, array( __NAMESPACE__ . '\Plugin', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\Plugin', 'on_deactivation' ) );
