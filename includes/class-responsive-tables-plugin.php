<?php
/**
 * Main plugin orchestrator: owns the settings and boots every part.
 *
 * @package Responsive_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Responsive_Tables_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'responsive_tables_settings';

	/**
	 * Single shared instance.
	 *
	 * @var Responsive_Tables_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings, read once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $settings = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Responsive_Tables_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		Responsive_Tables_Render::init();
		Responsive_Tables_Block::init();
		Responsive_Tables_Library::init();
		Responsive_Tables_TablePress::init();

		if ( is_admin() ) {
			new Responsive_Tables_Admin( $this );
		}

		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_settings' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush_settings' ) );
	}

	/**
	 * Default value for every setting.
	 *
	 * The layout and look settings are what every table uses unless it picks
	 * its own. The feature settings are what new tables start with.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'mode'       => 'stack',
			'breakpoint' => 600,
			'style'      => 'clean',
			'header'     => 'light',
			'accent'     => '#2563eb',
			'shortcodes' => 1,
			'sort'       => 0,
			'search'     => 0,
			'paginate'   => 0,
			'per_page'   => 10,
			'tablepress' => 0,
		);
	}

	/**
	 * Allowed values for the choice settings, also used to clean block attributes.
	 *
	 * @return array<string,string[]>
	 */
	public static function choices() {
		return array(
			'mode'   => array( 'stack', 'collapse', 'scroll' ),
			'style'  => array( 'clean', 'striped', 'bordered', 'minimal' ),
			'header' => array( 'light', 'solid', 'plain' ),
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Forget the cached settings after they change.
	 *
	 * @return void
	 */
	public function flush_settings() {
		$this->settings = null;
	}

	/**
	 * Clean a full settings array (used by the settings form).
	 *
	 * @param mixed $input Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$choices  = self::choices();
		$clean    = array();

		foreach ( $choices as $key => $allowed ) {
			$value         = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';
			$clean[ $key ] = in_array( $value, $allowed, true ) ? $value : $defaults[ $key ];
		}

		$clean['breakpoint'] = self::clamp( isset( $input['breakpoint'] ) ? $input['breakpoint'] : 0, Responsive_Tables_Render::MIN_BREAKPOINT, Responsive_Tables_Render::MAX_BREAKPOINT, $defaults['breakpoint'] );
		$clean['per_page']   = self::clamp( isset( $input['per_page'] ) ? $input['per_page'] : 0, 1, Responsive_Tables_Render::MAX_PER_PAGE, $defaults['per_page'] );

		$accent          = isset( $input['accent'] ) ? sanitize_hex_color( (string) $input['accent'] ) : '';
		$clean['accent'] = $accent ? strtolower( $accent ) : $defaults['accent'];

		foreach ( array( 'shortcodes', 'sort', 'search', 'paginate', 'tablepress' ) as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		return $clean;
	}

	/**
	 * Whole number within a range, or the fallback when it's missing.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Smallest allowed.
	 * @param int   $max      Largest allowed.
	 * @param int   $fallback Used when the value isn't a number.
	 * @return int
	 */
	public static function clamp( $value, $min, $max, $fallback ) {
		if ( ! is_numeric( $value ) ) {
			return (int) $fallback;
		}

		return max( (int) $min, min( (int) $max, (int) round( (float) $value ) ) );
	}
}
