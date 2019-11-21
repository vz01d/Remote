<?php
/*phpcs:disable*/
/**
 * Remote
 *  
 * @category Multisite
 * @package  Remote
 * @author   Sebo <sebo@42geeks.gg>
 * @license  GPLv2 https://opensource.org/licenses/gpl-2.0.php
 * @link     https://42geeks.gg/
 */
namespace remote;

/**
 * Class Remote - main class for the Plugin
 * 
 * @category Multisite
 * @package  Remote
 * @author   Sebo <sebo@42geeks.gg>
 * @license  GPLv2 https://opensource.org/licenses/gpl-2.0.php
 * @link     https://42geeks.gg/
 */
class Remote
{
    public static $pluginRootPath  = null;
    public static $pluginRootUri   = null;
    public static $pluginAssetUri  = null;
    public static $pluginAssetPath = null;
    public static $pluginClasses   = [];

    public static $pluginRef = 0x0000;

    /**
     * Initialize plugin with root path and uri provided
     * 
     * @param string $rootPath - the abs path to the plugin directory
     * @param string $rootUri  - the uri to the plugin directory
     */
    public function __construct($rootPath = '/', $rootUri = '/')
    {
        if (null === self::$pluginRootPath) {
            self::$pluginRootPath  = $rootPath;
            self::$pluginAssetPath = $rootPath . 'assets';
        }

        if (null === self::$pluginRootUri) {
            self::$pluginRootUri  = $rootUri;
            self::$pluginAssetUri = $rootUri . 'assets';
        }

        // add hooks
        add_action('plugins_loaded', [$this, 'loadCore']);
        
        new core\RemoteObjects([
            'remote\objects\Settings',
            'remote\objects\Site'
        ]);
	}

    /**
     * Init the cpt once and flush rewrites
     * 
     * @return void
     */
    public function activatePlugin(): void
    {
        flush_rewrite_rules();
    }

    /**
     * Deactivate plugin should flush rewrites
     * 
     * @return void
     */
    public function deactivatePlugin(): void
    {
        flush_rewrite_rules();
    }

    /**
     * Load the plugins core
     * 
     * @return void
     */
    public function loadCore(): void
    {
        self::$pluginClasses[self::$pluginRef] = $this;
    }

    /**
     * Reference to the app object
     * 
     * @return mixed - return plugin reference for global namespaced access
     */
    public function app()
    {
        return self::$pluginClasses[self::$pluginRef];
    }
}