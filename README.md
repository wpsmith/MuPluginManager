# MuPluginManager

[![Code Climate](https://codeclimate.com/github/wpsmith/MuPluginManager/badges/gpa.svg)](https://codeclimate.com/github/wpsmith/MuPluginManager)

A class to use in your WordPress plugin to install a MU Plugin auto-magically.

## Description

This class takes a file and installs the file in the `/mu-plugins/` folder. If the file already exists, it will compare the versions of the file

## Installation

This isn't a WordPress plugin on its own, so the usual instructions don't apply. Instead you can install manually or using `composer`.

### Manually install class
Copy [`MuPluginManager/src`](src) folder into your plugin for basic usage. Be sure to require the various files accordingly.

or:

### Install class via Composer
1. Tell Composer to install this class as a dependency: `composer require wpsmith/mupluginmanager`
2. Recommended: Install the Mozart package: `composer require coenjacobs/mozart --dev` and [configure it](https://github.com/coenjacobs/mozart#configuration).
3. The class then renamed to use your own prefix to prevent collisions with other plugins bundling this class.

## Implementation & Usage

Consider this basic plugin structure with the mu-plugin in its own folder for namespacing purposes:
```bash
|-- example.php
|-- includes
    |-- mu-plugin
        |-- example-mu.php
```

So then you can implement it like this:
```php
use WPS\WP\MuPlugins\MuPluginManager;

/**
 * Gets the MU plugin manager.
 */
function get_muplugin_manager() {
    // Path to the actual MU plugin located within this plugin.
    $src = plugin_dir_path( __FILE__ ) . 'includes/mu-plugin/my-mu-plugin.php';
    $dest_filename = 'my-mu-plugin.php';
    
    new MuPluginManager( $src, $dest_filename, '0.0.1', 'my-mu-plugin-settings' );
}

// Register (de)activation hooks.
register_activation_hook( __FILE__, function() {
    MuPluginManager::on_activation( get_muplugin_manager() );
} );
register_deactivation_hook( __FILE__, function() {
    try {
        MuPluginManager::on_deactivation( get_muplugin_manager() );
    } catch ( \Exception $e ) {
        MuPluginManager::write_log( 'MU Plugin threw an error' );
        MuPluginManager::write_log( $e->getMessage() );
    }
} );
```

Now, for whatever reason, you want to check for the MU plugin on more than just activation or deactivation, the class can run automagically on plugins admin page.
```php
use WPS\WP\MuPlugins\MuPluginManager;

/**
 * Gets the MU plugin manager.
 *
 * @return MuPluginManager MU plugin manager.
 */
function get_muplugin_manager() {
    static $mgr;
    
    if ( null !== $mgr ) {
        return $mgr;
    }

    // Path to the actual MU plugin located within this plugin.
    $src = plugin_dir_path( __FILE__ ) . 'mu-plugin/example-mu.php';
    $dest_filename = 'my-mu-plugin.php';
    
    $mgr = new MuPluginManager( $src, $dest_filename, '0.0.1', 'my-mu-plugin-settings' );
    
    return $mgr;
}

add_action( 'plugin_loaded', array( get_muplugin_manager(), 'add_hooks' ) );
````
## Change Log

Initial.

## License

[GPL 2.0 or later](LICENSE).

## Contributions

Contributions are welcome - fork, fix and send pull requests against the `master` branch please.

## Credits

Built by [Travis Smith](https://twitter.com/wp_smith)  
Copyright 2013-2020 [Travis Smith](https://wpsmith.net)