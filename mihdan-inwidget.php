<?php
/**
 * Plugin Name: Mihdan: InWidget
 * Description: Плагин для транслирования ваших фотографий из Instagram прямо на вашем сайте
 * Version: 1.2.1
 * GitHub Plugin URI: https://github.com/mihdan/mihdan-inwidget
 *
 * @package mihdan_inwidget
 * @link http://inwidget.ru/
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

if ( ! class_exists( 'Mihdan_Inwidget' ) ) {

	/**
	 * Class Mihdan_Inwidget
	 */
	class Mihdan_Inwidget {
		/**
		 * @var string слюг плагина
		 */
		protected $slug = 'mihdan_inwidget';

		/**
		 * @var null экземпляр класса
		 */
		protected static $instance = null;

		public function __construct() {}

		public static function get_instance() {

			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		public function init() {
			$this->register_hooks();
		}

		public function register_hooks() {
			add_shortcode( $this->slug, array( $this, 'shortcode' ) );
		}

		public function shortcode( $atts ) {

			$atts = shortcode_atts( array(
				'width'   => 310,
				'height'  => 285,
				'toolbar' => 'true',
				'preview' => 'small',
				'view'    => 8,
				'inline'  => 4,
			), $atts );

			return vsprintf( '<iframe frameborder="0" scrolling="no" src="%svendor/inwidget/index.php?toolbar=%s&preview=%s&view=%d&inline=%d&width=%d" width="%d" height="%d" ></iframe>', array(
				plugin_dir_url( __FILE__ ),
				$atts['toolbar'],
				$atts['preview'],
				$atts['view'],
				$atts['inline'],
				$atts['width'],
				$atts['width'],
				$atts['height'],
			) );
		}
	}

	function mihdan_inwidget() {
		Mihdan_Inwidget::get_instance()->init();
	}

	mihdan_inwidget();
}

// eof;
