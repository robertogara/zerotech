<?php
/**
 * Plugin Name:       Chatway Live Chat
 * Contributors:      chatway, galdub, tomeraharon
 * Description:       Chatway is a live chat app. Use Chatway to chat with your website's visitors.
 * Version:           1.5.2
 * Tested up to:      7.1
 * Author:            Chatway Live Chat
 * Author URI:        https://chatway.app/
 * License:           GPL v3 or later
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       chatway
 * Domain Path:       /languages
 */

use Chatway\App\ExternalApi;

class Chatway {
    function __construct() {
        add_action( 'plugins_loaded', [$this, 'boot'] );
    }

    /**
     * @source chatway.php
     * You need to change version from 4 different places. 
     * 1. chatway.php comment section 
     * 2. chatway.php version() method 
     * 3. Gruntfile.js version property
     * 4. readme.txt Stable tag
     */ 
    public static function version() {
        return '1.5.2';
    }

    /**
     * Retrieves the plugin's base name.
     *
     * @return string The base name of the plugin.
     */
    public static function plugin_base() {
        return plugin_basename(__FILE__);
    }

    /**
     * Generates and returns the URL for the support page.
     *
     * @return string The URL of the support page.
     */
    public static function support_url() {
        return 'https://chatway.app/help';
    }

    /**
     * Checks if the WooCommerce plugin is active.
     *
     * Determines whether the WooCommerce plugin is installed, activated, and its related
     * class is loaded in the current WordPress environment.
     *
     * @return bool True if WooCommerce is active, otherwise false.
     */
    public static function is_woocomerce_active() {
        if (!function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if(is_plugin_active('woocommerce/woocommerce.php') && class_exists('WooCommerce')) {
            return 1;
        }
        return 0;
    }

    public function boot() {
        $this->add_textdomain();
        new Chatway\App\Assets();
        new Chatway\App\Front();
        new Chatway\App\View();
        new Chatway\App\User();
        new Chatway\App\ChatwayApi();

        $version = get_option('chatway_wp_plugin_version', '');
        if($version != \Chatway::version()) {
            ExternalApi::sync_wp_plugin_version();
        }
    }

    private function add_textdomain() {
        load_plugin_textdomain( 'chatway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    /**
     * Get the path or require any file
     * @param string $file_or_dir takes the path based on the root dir
     * @param boolean $path_only (optional, default: false) if you want the path in return make the value true 
     */ 
    public static function require( $file_or_dir = '', $path_only = false ) { 
        if ( ! $path_only ) {
            require trailingslashit( plugin_dir_path( __FILE__ ) ) . $file_or_dir;
        } else {
            return trailingslashit( plugin_dir_path( __FILE__ ) ) . $file_or_dir;
        }
    }

    /**
     * Include once or include once and get the path
     * @param string $file takes the path based on the root dir
     * @param boolean $no_return (optional, default: false) if you want the path in return make the value true 
     */ 
    public static function include_once( $file = '', $no_return = false ) {
        if ( ! $no_return ) {
            return include_once( self::require( $file, true ) );
        } else {
            include_once( self::require( $file, true ) );
        }
    }

    /**
     * Get the url of any assets file like css, js, images, fonts etc.
     * @param string $file - Define the file path based on the plugin root directory 
     */ 
    public static function url( $file = '' ) {
        return esc_url( trailingslashit( plugins_url( '/', __FILE__ ) ) . $file );
    }
}

/**
 * Autoloader 
 */ 
require_once( 'autoloader.php' );
require_once( 'inc/clear-all-cache.php' );
$loader = new Chatway\AutoLoader();
$loader->register();
/**
 * register the namespace
 * 
 * @param {1} will take the namespace
 * @param {2} path of the folder 
 */ 
$loader->add_namespace( 'Chatway\App', Chatway::require( 'app', true ) );

/**
 * Register the activation and deactivation hook 
 */ 
$chatwayBase = new Chatway\App\Base();
register_activation_hook( __FILE__, [ $chatwayBase, 'activate' ] );
register_deactivation_hook( __FILE__, [ $chatwayBase, 'deactivate' ] );


/**
 * Initialize the plugin 
 */ 
new Chatway();