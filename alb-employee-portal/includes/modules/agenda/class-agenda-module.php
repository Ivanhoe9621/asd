<?php
namespace ALB_EP\Modules\Agenda;

use ALB_EP\Core;
use ALB_EP\Gateway\Booking_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Módulo Agenda: vista de solo lectura de las citas del empleado (futuras,
 * pasadas y canceladas). Los datos son del proveedor; sin tablas propias.
 */
class Agenda_Module implements Core\Module {

	const SCHEMA_VERSION = '1.0.0';

	/** Rango máximo consultable de una vez, para proteger la base. */
	const MAX_RANGE_DAYS = 366;

	/** @var Booking_Provider */
	private $gateway;

	public function __construct( Booking_Provider $gateway ) {
		$this->gateway = $gateway;
	}

	public function key() {
		return 'agenda';
	}

	public function schema_version() {
		return self::SCHEMA_VERSION;
	}

	public function migrate( $from ) {
		// Sin tablas propias.
	}

	public function boot( Core\Event_Bus $bus ) {
	}

	public function register_routes( Core\Rest_Kernel $rest ) {
		$rest->route( '/appointments', 'GET', array( $this, 'list_appointments' ) );
	}

	public function list_appointments( \WP_REST_Request $request, $employee_ref ) {
		$from = $this->parse_date( $request->get_param( 'from' ), gmdate( 'Y-m-d' ) );
		$to   = $this->parse_date( $request->get_param( 'to' ), gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );

		if ( is_wp_error( $from ) ) {
			return $from;
		}
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		if ( ( strtotime( $to ) - strtotime( $from ) ) > self::MAX_RANGE_DAYS * DAY_IN_SECONDS ) {
			return new \WP_Error(
				'alb_ep_range_too_wide',
				__( 'El rango máximo consultable es de un año.', 'alb-employee-portal' ),
				array( 'status' => 400 )
			);
		}

		$appointments = $this->gateway->get_appointments( $employee_ref, $from, $to );
		if ( is_wp_error( $appointments ) ) {
			return $appointments;
		}

		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		if ( '' !== $status ) {
			$appointments = array_values( array_filter( $appointments, function ( $appointment ) use ( $status ) {
				return $appointment['status'] === $status;
			} ) );
		}

		return array(
			'from'  => $from,
			'to'    => $to,
			'items' => $appointments,
		);
	}

	/** @return string|\WP_Error Fecha Y-m-d validada. */
	private function parse_date( $value, $default ) {
		if ( null === $value || '' === $value ) {
			return $default;
		}

		$value = sanitize_text_field( (string) $value );
		$parsed = \DateTime::createFromFormat( 'Y-m-d', $value );
		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
			return new \WP_Error(
				'alb_ep_invalid_date',
				__( 'Formato de fecha inválido: usa YYYY-MM-DD.', 'alb-employee-portal' ),
				array( 'status' => 400 )
			);
		}
		return $value;
	}
}
