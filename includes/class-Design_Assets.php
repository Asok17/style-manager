<?php
/**
 * This is the class that handles the overall logic for design assets.
 *
 * @see         https://pixelgrade.com
 * @author      Pixelgrade
 * @since       1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'StyleManager_Design_Assets' ) ) :

class StyleManager_Design_Assets extends StyleManager_Singleton_Registry {

	/**
	 * The current design assets config.
	 * @var     array
	 * @access  public
	 * @since   1.0.0
	 */
	protected $design_assets = null;

	/**
	 * The cloud API object used to communicate with the cloud.
	 * @var     StyleManager_Cloud_Api
	 * @access  public
	 * @since   1.0.0
	 */
	protected $cloud_api = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	protected function __construct() {
		$this->init();
	}

	/**
	 * Initialize this module.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		/**
		 * Initialize the Cloud API logic.
		 */
		require_once 'lib/class-Cloud_Api.php';
		$this->cloud_api = new StyleManager_Cloud_Api();
	}

	/**
	 * Get the design assets configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $skip_cache Optional. Whether to use the cached config or fetch a new one.
	 *
	 * @return array
	 */
	public function get( $skip_cache = false ) {
		if ( ! is_null( $this->design_assets ) && false === $skip_cache ) {
			return $this->design_assets;
		}

		$this->design_assets = $this->maybe_fetch( $skip_cache );

		// Determine if we should use the config in the theme root and skip the external config entirely.
		if ( defined('STYLE_MANAGER_LOAD_THEME_ROOT_CONFIG') && true === STYLE_MANAGER_LOAD_THEME_ROOT_CONFIG ) {
			$this->design_assets = $this->maybe_load_theme_config_from_theme_root( $this->design_assets );
		}

		return apply_filters( 'style_manager_get_design_assets', $this->design_assets );
	}

	/**
	 * Fetch the design assets data from the Pixelgrade Cloud.
	 *
	 * Caches the data for 12 hours. Use local defaults if not available.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $skip_cache Optional. Whether to use the cached data or fetch a new one.
	 *
	 * @return array|false
	 */
	protected function maybe_fetch( $skip_cache = false ) {
		// First try and get the cached data
		$data = get_option( $this->_get_cache_key() );

		// For performance reasons, we will ONLY fetch remotely when in the WP ADMIN area or via an ADMIN AJAX call, regardless of settings.
		if ( ! is_admin() ) {
			return  $data;
		}

		// Get the cache data expiration timestamp.
		$expire_timestamp = get_option( $this->_get_cache_key() . '_timestamp' );

		// We don't force skip the cache for AJAX requests for performance reasons.
		if ( ! wp_doing_ajax() && defined('CUSTOMIFY_SM_ALWAYS_FETCH_DESIGN_ASSETS' ) && true === CUSTOMIFY_SM_ALWAYS_FETCH_DESIGN_ASSETS ) {
			$skip_cache = true;
		}

		// The data isn't set, is expired or we were instructed to skip the cache; we need to fetch fresh data.
		if ( true === $skip_cache || false === $data || false === $expire_timestamp || $expire_timestamp < time() ) {
			// Fetch the design assets from the cloud.
			$fetched_data = $this->cloud_api->fetch_design_assets();
			// Bail in case of failure to retrieve data.
			// We will return the data already available.
			if ( false === $fetched_data ) {
				return $data;
			}

			$data = $fetched_data;

			// Cache the data in an option for 6 hours
			update_option( $this->_get_cache_key() , $data, true );
			update_option( $this->_get_cache_key() . '_timestamp' , time() + 6 * HOUR_IN_SECONDS, true );
		}

		return apply_filters( 'style_manager_maybe_fetch_design_assets', $data );
	}

	/**
	 * Get the design assets cache key.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function _get_cache_key() {
		return 'style_manager_design_assets';
	}

	/**
	 * Include the "external" config file in the theme root and overwrite the existing theme configs.
	 *
	 * @since 1.0.0
	 *
	 * @param array $design_assets
	 *
	 * @return array
	 */
	protected function maybe_load_theme_config_from_theme_root( $design_assets ) {
		$file_name = 'style_manager_theme_root.php';

		// First gather details about the current (parent) theme.
		$theme = wp_get_theme( get_template() );
		// Bail if for some strange reason we couldn't find the theme.
		if ( ! $theme->exists() ) {
			return $design_assets;
		}

		$file = trailingslashit( $theme->get_template_directory() ) . $file_name;
		if ( ! file_exists( $file ) ) {
			return $design_assets;
		}

		// We expect to get from the file include a $config variable with the entire Style Manager config.
		include $file;

		if ( ! isset( $config ) || ! is_array( $config ) || empty( $config['sections'] ) ) {
			// Alert the developers that things are not alright.
			_doing_it_wrong( __METHOD__, 'The Style Manager theme root config is not good - the `sections` entry is missing. Please check it! We will not apply it.', null );

			return $design_assets;
		}

		// Construct the pseudo-external theme config.
		// Start with a clean slate.
		$design_assets['theme_configs'] = array();

		$design_assets['theme_configs']['theme_root'] = array(
			'id'            => 1,
			'name'          => $theme->get( 'Name' ),
			'slug'          => $theme->get_stylesheet(),
			'txtd'          => $theme->get( 'TextDomain' ),
			'loose_match'   => true,
			'config'        => $config,
			'created'       => date('Y-m-d H:i:s'),
			'last_modified' => date('Y-m-d H:i:s'),
			'hashid'        => 'theme_root',
		);

		return $design_assets;
	}
}

endif;
