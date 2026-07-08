<?php
namespace ALB_EP;

defined( 'ABSPATH' ) || exit;

/**
 * Orquestador del núcleo: instancia los servicios centrales, registra los
 * módulos y ejecuta las migraciones pendientes. Ningún módulo se instancia
 * a sí mismo ni conoce a otro módulo: todo pasa por este punto de armado.
 */
final class Plugin {

	private static $instance = null;

	/** @var Core\Event_Bus */
	public $bus;

	/** @var Core\Audit */
	public $audit;

	/** @var Core\Identity */
	public $identity;

	/** @var Core\Rest_Kernel */
	public $rest;

	/** @var Core\Module_Registry */
	public $modules;

	/** @var Gateway\Booking_Provider */
	public $gateway;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain( 'alb-employee-portal', false, dirname( plugin_basename( ALB_EP_FILE ) ) . '/languages' );

		$this->bus      = new Core\Event_Bus();
		$this->audit    = new Core\Audit();
		$this->identity = new Core\Identity();

		/*
		 * Único punto del sistema donde se elige el proveedor de reservas
		 * (requisitos permanentes 1 y 2). El filtro permite sustituirlo por
		 * otro adaptador sin tocar núcleo ni módulos.
		 */
		$this->gateway = apply_filters( 'alb_ep_booking_provider', new Gateway\Amelia_Provider() );

		$this->rest    = new Core\Rest_Kernel( $this->identity );
		$this->modules = new Core\Module_Registry();

		$this->modules->register( new Modules\Services\Services_Module( $this->gateway, $this->identity, $this->audit, $this->bus ) );
		$this->modules->register( new Modules\Customers\Customers_Module( $this->gateway, $this->audit, $this->bus ) );
		$this->modules->register( new Modules\Agenda\Agenda_Module( $this->gateway ) );
		$this->modules->register( new Modules\Stats\Stats_Module( $this->gateway ) );

		$this->modules->migrate();

		new Core\Admin_Rest( $this->rest, $this->identity, $this->audit, $this->gateway );

		foreach ( $this->modules->active() as $module ) {
			$module->boot( $this->bus );
			add_action( 'rest_api_init', function () use ( $module ) {
				$module->register_routes( $this->rest );
			} );
		}
	}

	/** Alta del plugin: crea/actualiza tablas del núcleo y de los módulos. */
	public static function activate() {
		self::instance();
	}
}
