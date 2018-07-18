<?php
/**
 * This is the class that handles the logic for Font Palettes.
 *
 * @see         https://pixelgrade.com
 * @author      Pixelgrade
 * @since       1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'StyleManager_Font_Palettes' ) ) :

class StyleManager_Font_Palettes extends StyleManager_Singleton_Registry {

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
		// Hook up.
		$this->add_hooks();
	}

	/**
	 * Initiate our hooks
	 *
	 * @since 1.0.0
	 */
	public function add_hooks() {
		/*
		 * Handle the font palettes preprocessing.
		 */
		add_filter( 'style_manager_get_font_palettes', array( $this, 'preprocess_config' ), 5, 1 );

		/*
		 * Handle the Customizer Style Manager section config.
		 */
		add_filter( 'style_manager_customizer_config', array( $this, 'add_style_manager_section_master_fonts_config' ), 12, 1 );
		// This needs to come after the external theme config has been applied
//		add_filter( 'style_manager_customizer_config', array( $this, 'add_current_palette_control' ), 110, 1 );
		add_filter( 'style_manager_customizer_final_config', array( $this, 'standardize_connected_fields' ), 10, 1 );

		/*
		 * Scripts enqueued in the Customizer.
		 */
		add_action( 'customize_controls_init', array( $this, 'register_admin_customizer_scripts' ), 10 );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_admin_customizer_scripts' ), 10 );

		/*
		 * Handle the logic on settings update/save.
		 */
		add_action( 'customize_save_after', array( $this, 'update_custom_palette_in_use' ), 10, 1 );

		/**
		 * Add font palettes usage to site data.
		 */
		add_filter( 'style_manager_get_site_data', array( $this, 'add_palettes_to_site_data' ), 10, 1 );
	}

	/**
	 * Register Customizer admin scripts
	 */
	public function register_admin_customizer_scripts() {
		wp_register_script( sm_prefix( 'font-swap-values' ), plugins_url( 'assets/js/customizer/font-swap-values.js', StyleManager_Plugin()->get_file() ), array( 'jquery' ), StyleManager_Plugin()->get_version() );
		wp_register_script( sm_prefix( 'font-palettes-variations' ), plugins_url( 'assets/js/customizer/font-palettes-variations.js', StyleManager_Plugin()->get_file() ), array( 'jquery' ), StyleManager_Plugin()->get_version() );
		wp_register_script( sm_prefix( 'font-palettes' ), plugins_url( 'assets/js/customizer/font-palettes.js', StyleManager_Plugin()->get_file() ), array(
			'jquery',
			sm_prefix( 'font-palettes-variations' ),
			sm_prefix( 'swap-values' ),
			sm_prefix( 'fontselectfields' ),
		), StyleManager_Plugin()->get_version() );
	}

	/**
	 * Enqueue Customizer admin scripts
	 */
	public function enqueue_admin_customizer_scripts() {
		// If there is no font palettes support, bail early.
		if ( ! $this->is_supported() ) {
			return;
		}

		wp_enqueue_script( sm_prefix( 'font-palettes' ) );
	}

	/**
	 * Preprocess the font palettes configuration.
	 *
	 * Things like transforming font_size_line_height_points to a polynomial function for easy use client side,
	 * or processing the styles intervals and making sure that we get to a state where there are no overlaps and the order is right.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config
	 *
	 * @return array
	 */
	public function preprocess_config( $config ) {
		if ( empty( $config ) ) {
			return $config;
		}

		foreach ( $config as $palette_id => $palette_config ) {
			$config[ $palette_id ] = $this->preprocess_palette_config( $palette_config );
		}

		return $config;
	}

	/**
	 * Preprocess a font palette config before using it.
	 *
	 * @since 1.0.0
	 *
	 * @param array $palette_config
	 *
	 * @return array
	 */
	private function preprocess_palette_config( $palette_config ) {
		if ( empty( $palette_config ) ) {
			return $palette_config;
		}

		global $wp_customize;
		// We only need to do the fonts logic preprocess when we are in the Customizer.
		if ( ! empty( $wp_customize ) && $wp_customize instanceof WP_Customize_Manager && ! empty( $palette_config['fonts_logic'] ) ) {
			$palette_config['fonts_logic'] = $this->preprocess_fonts_logic_config( $palette_config['fonts_logic'] );
		}

		return $palette_config;
	}

	/**
	 * Before using a font logic config, preprocess it to allow for standardization, fill up of missing info, etc.
	 *
	 * @since 1.0.0
	 *
	 * @param array $fonts_logic_config
	 *
	 * @return array
	 */
	private function preprocess_fonts_logic_config( $fonts_logic_config ) {
		if ( empty( $fonts_logic_config ) ) {
			return $fonts_logic_config;
		}

		foreach ( $fonts_logic_config as $font_setting_id => $font_logic ) {
			if ( empty( $font_logic['font_family'] ) ) {
				// If we don't have a font family we can't do much with this config - remove it.
				unset( $fonts_logic_config[ $font_setting_id ] );
				continue;
			}

			if ( empty( $font_logic['type'] ) ) {
				// Default to 'google'
				$fonts_logic_config[ $font_setting_id ]['type'] = 'google';
			}

			// Process the font_styles_intervals and make sure that they are in the right order and not overlapping.
			if ( ! empty( $font_logic['font_styles_intervals'] ) && is_array( $font_logic['font_styles_intervals'] ) ) {
				$font_styles = array( array_shift( $font_logic['font_styles_intervals'] ) );
				// Make sure that the interval has a start
				if ( ! isset( $font_styles[0]['start'] ) ) {
					$font_styles[0]['start'] = 0;
				}

				foreach ( $font_logic['font_styles_intervals'] as $font_styles_interval ) {
					// Make sure that the interval has a start
					if ( ! isset( $font_styles_interval['start'] ) ) {
						$font_styles_interval['start'] = 0;
					}
					// Go through the current font_styles and determine the place where this interval should fit in.
					for ( $i = 0; $i < count( $font_styles ); $i++ ) {
						// Determine if the new interval overlaps with this existing one.
						if ( ! isset( $font_styles[$i]['end'] ) ) {
							// Since this interval is without end, there is nothing after it.
							// We need to adjust the old interval end.
							if ( $font_styles[ $i ]['start'] < $font_styles_interval['start'] ) {
								$font_styles[ $i ]['end'] = $font_styles_interval['start'];
							} else {
								if ( ! isset( $font_styles_interval['end'] ) ) {
									// We need to delete the old interval altogether.
									unset($font_styles[ $i ]);
									$i--;
									continue;
								} else {
									// Adjust the old interval and insert in front of it.
									$font_styles[ $i ]['end'] = $font_styles_interval['end'];
									$font_styles = array_slice( $font_styles, 0, $i ) + array( $font_styles_interval );
									break;
								}
							}
						} else {
							if ( $font_styles[ $i ]['end'] > $font_styles_interval['start'] ) {
								// We need to shrink this interval and make room for the new interval.
								$font_styles[ $i ]['end'] = $font_styles_interval['start'];
							} else {
								// There is not overlap. Move to the next one.
								continue;
							}

							if ( ! isset( $font_styles_interval['end'] ) ) {
								// Everything after the existing interval is gone and the new one takes precedence.
								array_splice( $font_styles, $i + 1, count( $font_styles ), array( $font_styles_interval ) );
								break;
							} else {
								// Now go forward and see where the end of the new interval fits in.
								for ( $j = $i + 1; $j < count( $font_styles ); $j ++ ) {
									if ( $font_styles[ $j ]['start'] < $font_styles_interval['end'] ) {
										// We have an overlapping after-interval.
										if ( ! isset( $font_styles[ $j ]['end'] ) ) {
											// Since this interval is without end, there is nothing after it.
											$font_styles[ $j ]['start'] = $font_styles_interval['end'];
											break;
										} elseif ( $font_styles[ $j ]['end'] <= $font_styles_interval['end'] ) {
											// We need to delete this interval since it is completely overwritten by the new one.
											unset( $font_styles[ $j ] );
											$j --;
											continue;
										} else {
											// The new interval partially overlaps with the old one. Adjust.
											$font_styles[ $j ]['end'] = $font_styles_interval['end'];
											break;
										}
									} else {
										// We can insert the new interval since this interval is after it
										break;
									}
								}

								// Insert the new interval.
								array_splice( $font_styles, $j, 0, array( $font_styles_interval ) );
								break;
							}
						}
					}

					// If we have reached the end of the list, we will insert it at the end.
					if (  $i === count( $font_styles ) ) {
						array_push( $font_styles, $font_styles_interval );
					}
				}

				// We need to do a last pass and ensure no breaks in the intervals. We need them to be continuous.
				// We will extend intervals to their next (right-hand) neighbour to achieve continuity.
				if ( count( $font_styles ) > 1 ) {
					// The first interval should start at zero, just in case.
					$font_styles[0]['start'] = 0;
					for( $i = 1; $i < count( $font_styles ); $i++ ) {
						// Extend the previous interval, just in case.
						$font_styles[ $i-1 ]['end'] = $font_styles[ $i ]['start'];
					}
				}

				// The last interval should not have an end.
				unset( $font_styles[ count( $font_styles )-1 ]['end'] );

				// Finally, go through each font style and standardize it.
				foreach( $font_styles as $key => $value ) {
					if ( isset( $value['letter_spacing'] ) ) {
						$font_styles[ $key ]['letter_spacing'] = $this->maybe_standardize_value( $value['letter_spacing'] );
					}
				}

				$fonts_logic_config[ $font_setting_id ]['font_styles'] = $font_styles;
			}
		}

		return $fonts_logic_config;
	}

	/**
	 * Get the font palettes configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $skip_cache Optional. Whether to use the cached config or fetch a new one.
	 *
	 * @return array
	 */
	public function get_palettes( $skip_cache = false ) {
		// Make sure that the Design Assets class is loaded.
		require_once 'lib/class-Design_Assets.php';

		// Get the design assets data.
		$design_assets = StyleManager_Design_Assets::instance()->get( $skip_cache );
		if ( false === $design_assets || empty( $design_assets['font_palettes'] ) ) {
			$config = $this->get_default_config();
		} else {
			$config = $design_assets['font_palettes'];
		}

		return apply_filters( 'style_manager_get_font_palettes', $config );
	}

	/**
	 * Determine if Font Palettes are supported.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_supported() {
		// For now we will only use the fact that Style Manager is supported.
		return apply_filters( 'style_manager_font_palettes_are_supported', StyleManager::getInstance()->is_supported() );
	}

	/**
	 * Setup the Style Manager Customizer section master fonts config.
	 *
	 * This handles the base configuration for the controls in the Style Manager section. We expect other parties (e.g. the theme),
	 * to come and fill up the missing details (e.g. connected fields).
	 *
	 * @since 1.0.0
	 *
	 * @param array $config This holds required keys for the plugin config like 'opt-name', 'panels', 'settings'.
	 *
	 * @return array
	 */
	public function add_style_manager_section_master_fonts_config( $config ) {
		// If there is no style manager support, bail early.
		if ( ! $this->is_supported() ) {
			return $config;
		}

		if ( ! isset( $config['sections']['style_manager_section'] ) ) {
			$config['sections']['style_manager_section'] = array();
		}

		// The section might be already defined, thus we merge, not replace the entire section config.
		$config['sections']['style_manager_section'] = array_replace_recursive( $config['sections']['style_manager_section'], array(
			'options' => array(
				'sm_font_palette' => array(
					'type'         => 'preset',
					// We will bypass the plugin setting regarding where to store - we will store it cross-theme in wp_options
					'setting_type' => 'option',
					// We will force this setting id preventing prefixing and other regular processing.
					'setting_id'   => 'sm_font_palette',
					// We don't want to refresh the preview window, even though we have no direct effect on it through this field.
					'live'         => true,
					'priority'     => 5,
					'label'        => esc_html__( 'Select a font palette:', 'style-manager' ),
					'desc'         => esc_html__( 'Conveniently change the design of your site with font palettes. Easy as pie.', 'style-manager' ),
					'default'      => 'julia',
					'choices_type' => 'font_palette',
					'choices'      => $this->get_palettes(),
				),
				'sm_font_palette_variation' => array(
					'type'         => 'radio',
					'setting_type' => 'option',
					'setting_id'   => 'sm_font_palette_variation',
					'label'        => esc_html__( 'Palette Variation', 'style-manager' ),
					'default'      => 'regular',
					'live'         => true,
					'priority'     => 5.5,
					'choices'      => array(
						'light'     => esc_html__( 'not light', 'style-manager' ),
						'regular'     => esc_html__( 'not regular', 'style-manager' ),
						'big' => esc_html__( 'not big', 'style-manager' ),
					),
				),
				'sm_font_primary'              => array(
					'type'             => 'font',
					// We will bypass the plugin setting regarding where to store - we will store it cross-theme in wp_options
					'setting_type'     => 'option',
					// We will force this setting id preventing prefixing and other regular processing.
					'setting_id'       => 'sm_font_primary',
					// We don't want to refresh the preview window, even though we have no direct effect on it through this field.
					'live'             => true,
					'priority'         => 7,
					'label'            => esc_html__( 'Font Primary', 'style-manager' ),
					'default'  => array(
						'font-family'    => 'Montserrat',
						'font-weight'    => '400',
						'font-size'      => 20,
						'line-height'    => 1.25,
						'letter-spacing' => 0.029,
						'text-transform' => 'uppercase'
					),
					// Sub Fields Configuration
					'fields'   => array(
						// These subfields are disabled because they are calculated through the font palette logic.
						'font-size'       => false,
						'font-weight'     => false,
						'line-height'     => false,
						'letter-spacing'  => false,
						'text-align'      => false,
						'text-transform'  => false,
						'text-decoration' => false,
					),
					'connected_fields' => array(),
				),
				'sm_font_secondary'              => array(
					'type'             => 'font',
					'setting_type'     => 'option',
					'setting_id'       => 'sm_font_secondary',
					'live'             => true,
					'priority'         => 7.1,
					'label'            => esc_html__( 'Font Secondary', 'style-manager' ),
					'default'  => array(
						'font-family'    => 'Montserrat',
						'font-weight'    => '300',
						'font-size'      => 10,
						'line-height'    => 1.625,
						'letter-spacing' => 0.029,
						'text-transform' => 'uppercase'
					),
					// Sub Fields Configuration
					'fields'   => array(
						// These subfields are disabled because they are calculated through the font palette logic.
						'font-size'       => false,
						'font-weight'     => false,
						'line-height'     => false,
						'letter-spacing'  => false,
						'text-align'      => false,
						'text-transform'  => false,
						'text-decoration' => false,
					),
					'connected_fields' => array(),
				),
				'sm_font_body'              => array(
					'type'             => 'font',
					'setting_type'     => 'option',
					'setting_id'       => 'sm_font_body',
					'live'             => true,
					'priority'         => 7.2,
					'label'            => esc_html__( 'Font Body', 'style-manager' ),
					'default'  => array(
						'font-family'    => 'Montserrat',
						'font-weight'    => '300',
						'font-size'      => 14,
						'line-height'    => 1.6,
						'letter-spacing' => 0.029,
						'text-transform' => 'uppercase'
					),
					// Sub Fields Configuration
					'fields'   => array(
						// These subfields are disabled because they are calculated through the font palette logic.
						'font-size'       => false,
						'font-weight'     => false,
						'line-height'     => false,
						'letter-spacing'  => false,
						'text-align'      => false,
						'text-transform'  => false,
						'text-decoration' => false,
					),
					'connected_fields' => array(),
				),
				'sm_swap_fonts'                => array(
					'type'         => 'button',
					'setting_type' => 'option',
					'setting_id'   => 'sm_swap_fonts',
					'priority'     => 9,
					'label'        => esc_html__( 'Swap Fonts', 'style-manager' ),
					'action'       => 'sm_swap_fonts',
				),
				'sm_swap_primary_secondary_fonts'            => array(
					'type'         => 'button',
					'setting_type' => 'option',
					'setting_id'   => 'sm_swap_primary_secondary_fonts',
					'priority'     => 9.1,
					'label'        => esc_html__( 'Swap Primary ⇆ Secondary', 'style-manager' ),
					'action'       => 'sm_swap_dark_light',
				),
			),
		) );

		return $config;
	}

	/**
	 * Add the current font palette control to the Style Manager section.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config
	 *
	 * @return array
	 */
	public function add_current_palette_control( $config ) {
		// If there is no style manager support, bail early.
		if ( ! $this->is_supported() ) {
			return $config;
		}

		if ( ! isset( $config['sections']['style_manager_section'] ) ) {
			$config['sections']['style_manager_section'] = array();
		}

		$current_palette = '';
		$current_palette_sets = array( 'current', 'next' );

		$master_font_controls_ids = $this->get_all_master_font_controls_ids( $config['sections']['style_manager_section']['options'] );

		foreach ( $current_palette_sets as $set ) {
			$current_palette .= '<div class="fonts ' . $set . '">';
			foreach ( $master_font_controls_ids as $setting_id ) {
				if ( ! empty( $config['sections']['style_manager_section']['options'][ $setting_id ]['connected_fields'] ) ) {
					$current_palette .=
						'<div class="font ' . $setting_id . '" data-setting="' . $setting_id . '">' . PHP_EOL .
						'<div class="fill"></div>' . PHP_EOL .
						'<div class="picker"><i></i></div>' . PHP_EOL .
						'</div>' . PHP_EOL;
				}
			}
			$current_palette .= '</div>';
		}

		// The section might be already defined, thus we merge, not replace the entire section config.
		$config['sections']['style_manager_section']['options'] = array(
              'sm_current_font_palette' => array(
                  'type' => 'html',
                  'html' =>
                      '<div class="font-palette-container">' . PHP_EOL .
                      '<span class="customize-control-title">Current Font Palette:</span>' . PHP_EOL .
                      '<span class="description customize-control-description">Choose a font palette to start with. Adjust its style using the variation buttons below.</span>' . PHP_EOL .
                      '<div class="c-font-palette">' . PHP_EOL .
                      $current_palette .
                      '<div class="c-font-palette__overlay">' . PHP_EOL .
                      '<div class="c-font-palette__label">' .
                      '<div class="c-font-palette__name">' . 'Original Style' . '</div>' .
                      '<div class="c-font-palette__control variation-light active" data-target="#_customize-input-sm_font_palette_variation_control-radio-light">' .
                      '<span class="dashicons dashicons-image-rotate"></span>' .
                      '<div class="c-font-palette__tooltip">Light</div>' .
                      '</div>' .
                      '<div class="c-font-palette__control variation-dark" data-target="#_customize-input-sm_font_palette_variation_control-radio-dark">' .
                      '<span class="dashicons dashicons-image-filter"></span>'.
                      '<div class="c-font-palette__tooltip">Dark</div>' .
                      '</div>' .
                      '<div class="c-font-palette__control variation-fontful" data-target="#_customize-input-sm_font_palette_variation_control-radio-fontful">' .
                      '<span class="dashicons dashicons-admin-appearance"></span>' .
                      '<div class="c-font-palette__tooltip">Fontful</div>' .
                      '</div>' .
                      '</div>' . PHP_EOL .
                      '</div>' . PHP_EOL .
                      '</div>' . PHP_EOL .
                      '</div>' . PHP_EOL .
                      '<svg class="c-font-palette__blur" width="15em" height="15em" viewBox="0 0 15 15" xmlns="http://www.w3.org/2000/svg" version="1.1">' . PHP_EOL .
                      '<defs>' . PHP_EOL .
                      '<filter id="goo">' . PHP_EOL .
                      '<feGaussianBlur in="SourceGraphic" stdDeviation="10" result="blur" />' . PHP_EOL .
                      '<feFontMatrix in="blur" mode="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 50 -20" result="goo" />' . PHP_EOL .
                      '<feBlend in="SourceGraphic" in2="goo" />' . PHP_EOL .
                      '</filter>' . PHP_EOL .
                      '</defs>' . PHP_EOL .
                      '</svg>',
              ),
          ) + $config['sections']['style_manager_section']['options'];

		return $config;
	}

	/**
	 * Process any configured connected fields that relate to fonts and standardize their config.
	 *
	 * Think things like filling up the default font_size if not present.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config
	 *
	 * @return array
	 */
	public function standardize_connected_fields( $config ) {
		// If there is no style manager support, bail early.
		if ( ! $this->is_supported() ) {
			return $config;
		}

		$style_manager_options = $config['sections']['style_manager_section']['options'];
		$master_font_controls_ids = $this->get_all_master_font_controls_ids( $style_manager_options );
		if ( empty( $master_font_controls_ids ) ) {
			return $config;
		}

		foreach ( $master_font_controls_ids as $id ) {
			if ( ! empty( $style_manager_options[ $id ]['connected_fields'] ) ) {
				$connected_fields_config = array();
				foreach ( $style_manager_options[ $id ]['connected_fields'] as $key => $value ) {
					// If we have a shorthand connected field config, change it to a standard one.
					if ( ! is_array( $value ) ) {
						$key = $value;
						$value = array();
					}

					$option_config = StyleManager_Customizer::getInstance()->get_option_config( $key, $config );
					if ( empty( $option_config ) ) {
						continue;
					}

					// If we didn't get a font_size we will try and grab the default value for the connected field.
					if ( ! isset( $value['font_size'] ) ) {
						if ( isset( $option_config['default']['font-size'] ) ) {
							$value['font_size'] = array( 'value' => $option_config['default']['font-size'] );
						} else {
							$value['font_size'] = false;
						}
					}

					// Handle the case when the received font_size value is a number with a unit - split them.
					$value['font_size'] = $this->maybe_standardize_value( $value['font_size'] );

					// If we don't have an unit, maybe we can make an educated guess.
					// If the value is bellow 9, then probably we are talking about ems, else pxs.
					if ( ! empty( $value['font_size'] ) && ! empty( $value['font_size']['value'] ) && ! isset( $value['font_size']['unit'] ) ) {
						if ( $value['font_size']['value'] < 9 ) {
							$value['font_size']['unit'] = 'em';
						} else {
							$value['font_size']['unit'] = 'px';
						}
					}

					$connected_fields_config[ $key ] = $value;
				}

				$config['sections']['style_manager_section']['options'][ $id ]['connected_fields'] = $connected_fields_config;
			}
		}

		return $config;
	}

	/**
	 * Standardize a numerical value for a font CSS property.
	 *
	 * The standard format is an associative array with the following entries:
	 *  - 'value': holds the actual numerical value (int or float)
	 *  - 'unit : optional; it holds the unit that should be used for the value
	 *
	 * @param mixed $value
	 *
	 * @return array|bool
	 */
	private function maybe_standardize_value( $value ) {
		$new_value = false;

		if ( false === $value ) {
			return $new_value;
		}

		if ( is_array( $value ) ) {
			$new_value = $value;
		}

		if ( is_string( $value ) ) {
			if ( is_numeric( $value ) ) {
				$new_value = array( 'value' => (float) $value );
			} else {
				// We will get everything in front that is a valid part of a number (float including).
				preg_match("/^([\d.\-+]+)/i", $value, $match);

				if ( ! empty( $match ) && isset( $match[0] ) ) {
					$new_value = array(
						'value' => (float) $match[0],
						'unit' => substr( $value, strlen( $match[0] ) ),
					);
				}
			}
		}

		if ( is_numeric( $value ) ) {
			$new_value = array( 'value' => $value );
		}

		return $new_value;
	}

	/**
	 * Get the default (hard-coded) font palettes configuration.
	 *
	 * This is only a fallback config in case we can't communicate with the cloud, the first time.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function get_default_config() {
		$default_config = array(
			'gema' => array(
				'label'   => esc_html__( 'Gema', 'style-manager' ),
				'preview' => array(
					// Font Palette Name
					'title'            => esc_html__( 'Gema', 'style-manager' ),
					'description'      => esc_html__( 'A graceful nature, truly tasteful and polished.', 'style-manager' ),
					'background_image_url' => 'http://pxgcdn.com/images/style-manager/color-palettes/gema-theme-palette.jpg',

					// Use the following options to style the preview card fonts
					// Including font-family, size, line-height, weight, letter-spacing and text transform
					'title_font'       => array(
						'font' => 'font_primary',
						'size' => 32,
					),
					'description_font' => array(
						'font' => 'font_body',
						'size' => 16,
					),
				),

				'fonts_logic' => array(
					// Primary is used for main headings [Display, H1, H2, H3]
					'sm_font_primary' => array(
						// Define the font type ('google' or 'theme_font'). By default it's 'google'.
						'type' => 'google',
						// Font loaded when a palette is selected
						'font_family'      => 'Montserrat',
						// Load all these fonts weights.
						'font_weights'     => array( 100, 300, 700 ),
						// "Generate" the graph to be used for font-size and line-height.
						'font_size_to_line_height_points' => array(
							array( 17, 1.7 ),
							array( 20, 1.3 ),
							array( 32, 1.3 ),
							array( 48, 1.2 ),
						),

						// Define how fonts will look based on the font size.
						'font_styles_intervals'      => array(
							array(
								'start'          => 10,
								'font_weight'    => 300,
								'letter_spacing' => '0.03em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 12,
								'font_weight'    => 700,
								'letter_spacing' => '0em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 18,
								'font_weight'    => 100,
								'letter_spacing' => '0.03em',
								'text_transform' => 'uppercase',
							),
						),
					),

					// Secondary font is used for smaller headings [H4, H5, H6], including meta details
					'sm_font_secondary' => array(
						'font_family'      => 'Montserrat',
						'font_weights'     => array( 200, 400 ),
						'font_size_to_line_height_points' => array(
							array( 10, 1.6 ),
							array( 12, 1.5 ),
							array( 18, 1.5 )
						),
						'font_styles_intervals'      => array(
							array(
								'start'          => 0,
								'font_weight'    => 200,
								'letter_spacing' => '0.03em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 13,
								'font_weight'    => 400,
								'letter_spacing' => '0.015em',
								'text_transform' => 'uppercase',
							),
						),
					),

					// Used for Body Font [eg. entry-content]
					'sm_font_body' => array(
						'font_family'      => 'Montserrat',
						'font_weights'     => array( 200, '200italic', 700, '700italic' ),
						'font_size_to_line_height_points' => array(
							array( 15, 1.7 ),
							array( 16, 1.8 ),
							array( 18, 1.7 ),
						),

						// Define how fonts will look based on their size
						'font_styles_intervals'      => array(
							array(
								'font_weight'    => 200,
								'letter_spacing' => 0,
								'text_transform' => 'none',
							),
						),
					),
				),
			),
			'julia' => array(
				'label'   => esc_html__( 'Julia', 'style-manager' ),
				'preview' => array(
					// Font Palette Name
					'title'            => esc_html__( 'Julia', 'style-manager' ),
					'description'      => esc_html__( 'A graceful nature, truly tasteful and polished.', 'style-manager' ),
					'background_image_url' => 'http://pxgcdn.com/images/style-manager/color-palettes/julia-theme-palette.jpg',

					// Use the following options to style the preview card fonts
					// Including font-family, size, line-height, weight, letter-spacing and text transform
					'title_font'       => array(
						'font' => 'font_primary',
						'size' => 30,
					),
					'description_font' => array(
						'font' => 'font_body',
						'size' => 17,
					),
				),

				'fonts_logic' => array(
					// Primary is used for main headings [Display, H1, H2, H3]
					'sm_font_primary' => array(
						// Define the font type ('google' or 'theme_font'). By default it's 'google'.
						'type' => 'google',
						// Font loaded when a palette is selected
						'font_family'      => 'Lora',
						// Load all these fonts weights.
						'font_weights'     => array( 700 ),
						// "Generate" the graph to be used for font-size and line-height.
						'font_size_to_line_height_points' => array(
							array( 24, 1.25 ),
							array( 44, 1.2 ),
							array( 66, 1.15 ),
						),

						// Define how fonts will look based on the font size.
						'font_styles_intervals'      => array(
							array(
								'start'          => 0,
								'font_weight'    => 700,
								'letter_spacing' => '0em',
								'text_transform' => 'none',
							),
						),
					),

					// Secondary font is used for smaller headings [H4, H5, H6], including meta details
					'sm_font_secondary' => array(
						'font_family'      => 'Montserrat',
						'font_weights'     => array( 'regular', 600 ),
						'font_size_to_line_height_points' => array(
							array( 14, 1.2 ),
							array( 16, 1.2 )
						),
						'font_styles_intervals'      => array(
							array(
								'start'          => 0,
								'font_weight'    => 600,
								'letter_spacing' => '0.154em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 13,
								'font_weight'    => 'regular',
								'letter_spacing' => '0em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 14,
								'font_weight'    => 'regular',
								'letter_spacing' => '0.1em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 16,
								'font_weight'    => 'regular',
								'letter_spacing' => '0em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 17,
								'font_weight'    => 'regular',
								'letter_spacing' => '0em',
								'text_transform' => 'none',
							),
						),
					),

					// Used for Body Font [eg. entry-content]
					'sm_font_body' => array(
						'font_family'      => 'PT Serif',
						'font_weights'     => array( 'regular', '400italic', 700, '700italic' ),
						'font_size_to_line_height_points' => array(
							array( 15, 1.7 ),
							array( 17, 1.6 ),
							array( 18, 1.5 ),
						),

						// Define how fonts will look based on their size
						'font_styles_intervals'      => array(
							array(
								'start'          => 0,
								'font_weight'    => 'regular',
								'letter_spacing' => 0,
								'text_transform' => 'none',
							),
						),
					),
				),
			),
			'patch' => array(
				'label'   => esc_html__( 'Patch', 'style-manager' ),
				'preview' => array(
					// Font Palette Name
					'title'            => esc_html__( 'Patch', 'style-manager' ),
					'description'      => esc_html__( 'A graceful nature, truly tasteful and polished.', 'style-manager' ),
					'background_image_url' => 'http://pxgcdn.com/images/style-manager/color-palettes/patch-theme-palette.jpg',

					// Use the following options to style the preview card fonts
					// Including font-family, size, line-height, weight, letter-spacing and text transform
					'title_font'       => array(
						'font' => 'font_primary',
						'size' => 26,
					),
					'description_font' => array(
						'font' => 'font_body',
						'size' => 16,
					),
				),

				'fonts_logic' => array(
					// Primary is used for main headings [Display, H1, H2, H3]
					'sm_font_primary' => array(
						// Define the font type ('google' or 'theme_font'). By default it's 'google'.
						'type' => 'google',
						// Font loaded when a palette is selected
						'font_family'      => 'Oswald',
						// Load all these fonts weights.
						'font_weights'     => array( 300, 400, 500 ),
						// "Generate" the graph to be used for font-size and line-height.
						'font_size_to_line_height_points' => array(
							array( 20, 1.15 ),
							array( 26, 1.45 ),
							array( 30, 1.25 ),
							array( 56, 1.25 ),
						),

						// Define how fonts will look based on the font size.
						'font_styles_intervals'      => array(
							array(
								'start'          => 0,
								'font_weight'    => 500,
								'letter_spacing' => '0.04em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 24,
								'font_weight'    => 300,
								'letter_spacing' => '0.06em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 25,
								'font_weight'    => 400,
								'letter_spacing' => '0.04em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 26,
								'font_weight'    => 500,
								'letter_spacing' => '0.04em',
								'text_transform' => 'uppercase',
							),
						),
					),

					// Secondary font is used for smaller headings [H4, H5, H6], including meta details
					'sm_font_secondary' => array(
						'font_family'      => 'Oswald',
						'font_weights'     => array( 200, '200italic', 500, '500italic' ),
						'font_size_to_line_height_points' => array(
							array( 14, 1.625 ),
							array( 22, 1.55 ),
							array( 24, 1.625 ),
						),
						'font_styles_intervals'      => array(
							array(
								'start'          => 0,
								'font_weight'    => 500,
								'letter_spacing' => '0.01em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 20,
								'font_weight'    => 500,
								'letter_spacing' => '0em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 24,
								'font_weight'    => 200,
								'letter_spacing' => '0em',
								'text_transform' => 'none',
							),
						),
					),

					// Used for Body Font [eg. entry-content]
					'sm_font_body' => array(
						'font_family'      => 'Roboto',
						'font_weights'     => array( 300, '300italic', 400, '400italic', 500, '500italic' ),
						'font_size_to_line_height_points' => array(
							array( 10, 1.6 ),
							array( 16, 1.625 ),
							array( 18, 1.75 ),
						),

						// Define how fonts will look based on their size
						'font_styles_intervals'      => array(
							array(
								'start'          => 0,
								'end'            => 10.9,
								'font_weight'    => 500,
								'letter_spacing' => '0.03em',
								'text_transform' => 'none',
							),
							array(
								'start'          => 10.9,
								'end'            => 12,
								'font_weight'    => 500,
								'letter_spacing' => '0.02em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 12,
								'font_weight'    => 300,
								'letter_spacing' => 0,
								'text_transform' => 'none',
							),
						),
					),
				),
			),
			'hive' => array(
				'label'   => esc_html__( 'Hive', 'style-manager' ),
				'preview' => array(
					// Font Palette Name
					'title'            => esc_html__( 'Hive', 'style-manager' ),
					'description'      => esc_html__( 'A graceful nature, truly tasteful and polished.', 'style-manager' ),
					'background_image_url' => 'http://pxgcdn.com/images/style-manager/color-palettes/hive-theme-palette.jpg',

					// Use the following options to style the preview card fonts
					// Including font-family, size, line-height, weight, letter-spacing and text transform
					'title_font'       => array(
						'font' => 'font_primary',
						'size' => 36,
					),
					'description_font' => array(
						'font' => 'font_body',
						'size' => 18,
					),
				),

				'fonts_logic' => array(
					// Primary is used for main headings [Display, H1, H2, H3]
					'sm_font_primary' => array(
						// Define the font type ('google' or 'theme_font'). By default it's 'google'.
						'type' => 'google',
						// Font loaded when a palette is selected
						'font_family'      => 'Playfair Display',
						// Load all these fonts weights.
						'font_weights'     => array( 400, '400italic', 700, '700italic', 900, '900italic' ),
						// "Generate" the graph to be used for font-size and line-height.
						'font_size_to_line_height_points' => array(
							array( 20, 1.55 ),
							array( 28, 1.5 ),
							array( 40, 1.35 ),
							array( 65, 1.15 ),
						),

						// Define how fonts will look based on the font size.
						'font_styles_intervals'      => array(
							array(
								'start'          => 0,
								'font_weight'    => 400,
								'letter_spacing' => '0em',
								'text_transform' => 'none',
							),
						),
					),

					// Secondary font is used for smaller headings [H4, H5, H6], including meta details
					'sm_font_secondary' => array(
						'font_family'      => 'Noto Serif',
						'font_weights'     => array( 400, '400italic', 700, '700italic' ),
						'font_size_to_line_height_points' => array(
							array( 13, 1.33 ),
							array( 18, 1.5 ),
						),
						'font_styles_intervals'      => array(
							array(
								'start'          => 0,
								'font_weight'    => 400,
								'letter_spacing' => '0em',
								'text_transform' => 'none',
							),
						),
					),

					// Used for Body Font [eg. entry-content]
					'sm_font_body' => array(
						'font_family'      => 'Noto Serif',
						'font_weights'     => array( 400, '400italic', 700, '700italic' ),
						'font_size_to_line_height_points' => array(
							array( 13, 1.4 ),
							array( 18, 1.5 ),
						),

						// Define how fonts will look based on their size
						'font_styles_intervals'      => array(
							array(
								'start'          => 0,
								'font_weight'    => 400,
								'letter_spacing' => 0,
								'text_transform' => 'none',
							),
						),
					),
				),
			),
			'vasco' => array(
				'label'   => esc_html__( 'Not Vasco', 'style-manager' ),
				'preview' => array(
					// Font Palette Name
					'title'            => esc_html__( 'Not Vasco', 'style-manager' ),
					'description'      => esc_html__( 'Just awesome.', 'style-manager' ),
					'background_image_url' => 'http://pxgcdn.com/images/style-manager/color-palettes/vasco-theme-palette.jpg',

					// Use the following options to style the preview card fonts
					// Including font-family, size, line-height, weight, letter-spacing and text transform
					'title_font'       => array(
						'font' => 'font_primary',
						'size' => 26,
					),
					'description_font' => array(
						'font' => 'font_body',
						'size' => 14,
					),
				),

				'fonts_logic' => array(
					// Primary is used for main headings [Display, H1, H2, H3]
					'sm_font_primary' => array(
						// Define the font type ('google', 'theme_font', 'system'). By default it's 'google'.
						'type' => 'google',
						// Font loaded when a palette is selected
						'font_family'      => 'Playfair Display',
						// Load all these fonts weights.
						'font_weights'     => array( 'regular',700,900 ),
						// "Generate" the graph to be used for font-size and line-height.
						'font_size_to_line_height_points' => array(
							array( 14, 2 ),
							array( 40, 1.6 ),
							array( 60, 1.1 ),
						),

						// These are not used right now.
//						'font_size_min' => 30,
//						'font_size_max' => 100,

						// Define how fonts will look based on the font size.
						// The current logic is as follows:
						// - for an interval, if the start is missing, it is assumed to be 0;
						// - for an interval, if the end is missing, it is assumed to be infinity;
						// - later intervals overwrite earlier ones and apply their own styles; so the order in which you define intervals might influence things;
						// - if there are gaps between intervals, we will "extend" the first interval to the start of it's next neighbour;
						// - neighbouring intervals will have, in the end, the same end and start, and on the border, the first interval will apply
						//   i.e. end takes precedence over start.
						'font_styles_intervals'      => array(
							array(
								'start'          => 0,
								'end'            => 20,
								'font_weight'    => 'regular',
								'letter_spacing' => '0em',
								'text_transform' => 'none',
							),
							array(
								'start'          => 20,
								'end'            => 50,
								'font_weight'    => 700,
								'letter_spacing' => '0em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 30,
								'font_weight'    => 900,
								'letter_spacing' => '0em',
								'text_transform' => 'uppercase',
							),
						),
					),

					// Secondary font is used for smaller headings [H4, H5, H6], including meta details
					'sm_font_secondary' => array(
						'font_family'      => 'Noto Serif',
						'font_weights'     => array( 400, 500, 700 ),
						'font_size_to_line_height_points' => array(
							array( 14, 1.7 ),
							array( 50, 1.3 ),
							array( 80, 1 ),
						),
						'font_styles_intervals'      => array(
							array(
								'end'            => 14,
								'font_weight'    => 400,
								'letter_spacing' => '0.08em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 14,
								'end'            => 19,
								'font_weight'    => 700,
								'letter_spacing' => '0.07em',
								'text_transform' => 'uppercase',
							),
							array(
								'start'          => 19,
								'font_weight'    => 500,
								'letter_spacing' => 0,
								'text_transform' => 'none',
							),
						),
					),

					// Used for Body Font [eg. entry-content]
					'sm_font_body' => array(
						'type' => 'google',
						'font_family'      => 'Roboto Slab',
						'font_weights'     => array( 400, '400italic', 700, '700italic' ),
						'font_size_to_line_height_points' => array(
							array( 15, 1.7 ),
							array( 17, 1.6 ),
							array( 18, 1.5 ),
						),

						// Define how fonts will look based on their size
						'font_styles_intervals'      => array(
							array(
								'start'          => 0,
								'font_weight'    => '400italic',
								'letter_spacing' => 0,
								'text_transform' => 'none',
							),
						),
					),
				),
			),
		);

		return apply_filters( 'style_manager_default_font_palettes', $default_config );
	}



	/**
	 * Get the current font palette ID or false if none is selected.
	 *
	 * @since 1.0.0
	 *
	 * @return string|false
	 */
	protected function get_current_palette() {
		return get_option( 'sm_font_palette', false );
	}

	/**
	 * Get the current font palette variation ID or false if none is selected.
	 *
	 * @since 1.0.0
	 *
	 * @return string|false
	 */
	protected function get_current_palette_variation() {
		return get_option( 'sm_font_palette_variation', false );
	}

	/**
	 * Determine if the selected font palette has been customized and remember this in an option.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function update_custom_palette_in_use() {
		// If there is no style manager support, bail early.
		if ( ! $this->is_supported() ) {
			return false;
		}

		$current_palette = $this->get_current_palette();
		if ( empty( $current_palette ) ) {
			return false;
		}

		$font_palettes = $this->get_palettes();
		if ( ! isset( $font_palettes[ $current_palette ] ) || empty( $font_palettes[ $current_palette ]['options'] ) ) {
			return false;
		}

		$is_custom_palette = false;
		// If any of the current master fonts has a different value than the one provided by the font palette,
		// it means a custom font palette is in use.
		$current_palette_options = $font_palettes[ $current_palette ]['options'];
		foreach ( $current_palette_options as $setting_id => $value ) {
			if ( $value != get_option( $setting_id ) ) {
				$is_custom_palette = true;
				break;
			}
		}

		update_option( 'sm_is_custom_font_palette', $is_custom_palette, true );

		do_action( 'style_manager_updated_custom_palette_in_use', $is_custom_palette );

		return true;
	}

	/**
	 * Determine if a custom font palette is in use.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	protected function is_using_custom_palette(){
		return (bool) get_option( 'sm_is_custom_font_palette', false );
	}

	/**
	 * Get all the defined Style Manager master font field ids.
	 *
	 * @since 1.0.0
	 *
	 * @param array $options
	 *
	 * @return array
	 */
	public function get_all_master_font_controls_ids( $options ) {
		$master_font_controls = array();

		if ( empty( $options ) ) {
			return $master_font_controls;
		}

		foreach ( $options as $option_id => $option_settings ) {
			if ( ! empty( $option_settings['type'] ) && 'font' === $option_settings['type'] ) {
				$master_font_controls[] = $option_id;
			}
		}

		return $master_font_controls;
	}

	/**
	 * Add font palettes usage data to the site data sent to the cloud.
	 *
	 * @since 1.0.0
	 *
	 * @param array $site_data
	 *
	 * @return array
	 */
	public function add_palettes_to_site_data( $site_data ) {
		if ( empty( $site_data['font_palettes'] ) ) {
			$site_data['font_palettes'] = array();
		}

		// If others have added data before us, we will merge with it.
		$site_data['font_palettes'] = array_merge( $site_data['font_palettes'], array(
			'current' => $this->get_current_palette(),
			'variation' => $this->get_current_palette_variation(),
			'custom' => $this->is_using_custom_palette(),
		) );

		return $site_data;
	}
}

endif;
