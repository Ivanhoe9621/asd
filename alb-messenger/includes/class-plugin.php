<?php
namespace ALBM;

defined( 'ABSPATH' ) || exit;

/**
 * Orquestador de ALB Messenger. En M0 solo arma lo mínimo: i18n y
 * migraciones. Los módulos siguientes (identidad, conversaciones,
 * mensajes, polling, notificaciones, paneles) se registran aquí a medida
 * que se implementen — uno por uno, según el plan de la Etapa 3.
 */
final class Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @var Identity */
	public $identity;

	/** @var Authorization */
	public $authorization;

	/** @var Rest_Kernel */
	public $rest;

	private function __construct() {
		load_plugin_textdomain( 'alb-messenger', false, dirname( plugin_basename( ALBM_FILE ) ) . '/languages' );
		Schema::migrate();

		$this->identity      = new Identity();
		$this->authorization = new Authorization();
		$this->rest          = new Rest_Kernel( $this->identity );
	}

	public static function activate() {
		self::instance();
	}
}
