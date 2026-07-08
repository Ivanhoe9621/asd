<?php
namespace ALB_EP\Modules\Stats;

use ALB_EP\Core;
use ALB_EP\Gateway\Booking_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Módulo Stats: estadísticas del propio empleado calculadas sobre los datos
 * del proveedor. Sin tablas propias mientras el volumen no exija caché
 * (decisión de la sección 6.2 del documento de arquitectura). Nunca expone
 * estadísticas globales a un empleado.
 */
class Stats_Module implements Core\Module {

	const SCHEMA_VERSION = '1.0.0';

	/** Estados del proveedor que cuentan como reserva efectiva. */
	const REVENUE_STATUSES = array( 'approved', 'pending' );

	/** @var Booking_Provider */
	private $gateway;

	public function __construct( Booking_Provider $gateway ) {
		$this->gateway = $gateway;
	}

	public function key() {
		return 'stats';
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
		$rest->route( '/stats/summary', 'GET', array( $this, 'summary' ) );
	}

	public function summary( \WP_REST_Request $request, $employee_ref ) {
		$month = sanitize_text_field( (string) $request->get_param( 'month' ) );
		if ( '' === $month ) {
			$month = gmdate( 'Y-m' );
		}
		$parsed = \DateTime::createFromFormat( 'Y-m', $month );
		if ( ! $parsed || $parsed->format( 'Y-m' ) !== $month ) {
			return new \WP_Error(
				'alb_ep_invalid_month',
				__( 'Formato de mes inválido: usa YYYY-MM.', 'alb-employee-portal' ),
				array( 'status' => 400 )
			);
		}

		$from = $month . '-01';
		$to   = $parsed->format( 'Y-m-t' );

		$appointments = $this->gateway->get_appointments( $employee_ref, $from, $to );
		if ( is_wp_error( $appointments ) ) {
			return $appointments;
		}

		$by_status         = array();
		$revenue           = 0.0;
		$revenue_complete  = true;
		$service_counts    = array();
		$customer_bookings = array();

		foreach ( $appointments as $appointment ) {
			$status = (string) $appointment['status'];
			$by_status[ $status ] = 1 + ( $by_status[ $status ] ?? 0 );

			if ( in_array( $status, self::REVENUE_STATUSES, true ) ) {
				if ( null === $appointment['price'] ) {
					$revenue_complete = false;
				} else {
					$revenue += (float) $appointment['price'];
				}
				$service_counts[ $appointment['service_ref'] ] = 1 + ( $service_counts[ $appointment['service_ref'] ] ?? 0 );

				if ( ! empty( $appointment['customer_ref'] ) ) {
					$customer_bookings[ $appointment['customer_ref'] ] = 1 + ( $customer_bookings[ $appointment['customer_ref'] ] ?? 0 );
				}
			}
		}

		arsort( $service_counts );
		$top_services  = $this->resolve_service_names( $employee_ref, array_slice( $service_counts, 0, 5, true ) );
		$recurring     = count( array_filter( $customer_bookings, function ( $count ) {
			return $count > 1;
		} ) );

		return array(
			'month'               => $month,
			'appointments_total'  => count( $appointments ),
			'appointments_status' => $by_status,
			'estimated_revenue'   => round( $revenue, 2 ),
			'revenue_complete'    => $revenue_complete,
			'top_services'        => $top_services,
			'unique_customers'    => count( $customer_bookings ),
			'recurring_customers' => $recurring,
		);
	}

	/** @return array[] [{service_ref, name, bookings}] */
	private function resolve_service_names( $employee_ref, array $counts ) {
		$names    = array();
		$services = $this->gateway->get_services_for_employee( $employee_ref );

		if ( ! is_wp_error( $services ) ) {
			foreach ( $services as $service ) {
				$names[ $service['id'] ] = $service['name'];
			}
		}

		$top = array();
		foreach ( $counts as $service_ref => $bookings ) {
			$top[] = array(
				'service_ref' => (string) $service_ref,
				'name'        => $names[ $service_ref ] ?? sprintf( '#%s', $service_ref ),
				'bookings'    => $bookings,
			);
		}
		return $top;
	}
}
