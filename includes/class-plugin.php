<?php
/**
 * Plugin loader.
 *
 * @package    NHG
 * @subpackage NHG\Lookup
 * @author     Shakeeb Ahmed <me@shakeebahmed.com>
 */

namespace NHG\Lookup;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin Loader.
 */
class Plugin {

	/**
	 * Main instance
	 *
	 * Ensure only one instance is loaded or can be loaded.
	 *
	 * @return Plugin
	 */
	public static function get() {
		static $instance;

		if ( is_null( $instance ) ) {
			$instance = new Plugin();
		}

		return $instance;
	}

	/**
	 * Set plugin paths.
	 *
	 * @param  string $file File.
	 * @return Plugin
	 */
	public function set_paths( $file ) {
		$this->file = $file;
		$this->path = dirname( $file ) . '/';
		$this->url  = plugins_url( '', $file ) . '/';

		return $this;
	}

	/**
	 * Instantiate the plugin.
	 *
	 * @return Plugin
	 */
	public function setup() {
		// Define constants.
		define( 'NHG_LOOKUP_TABLE_FILE', $this->file );

		// Instantiate classes.
		new \NHG\Lookup\Installer();
		( new Catalog_Ordering() )->hooks();

		if ( \NHG::is_backend() ) {
			( new Sales_Updater() )->hooks();
		}

		return $this;
	}
}
