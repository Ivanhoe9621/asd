<?php
namespace ALB_EP\Modules\Customers;

use ALB_EP\Core;
use ALB_EP\Gateway\Booking_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Módulo Customers: los clientes viven en el proveedor (única fuente de
 * datos, sin duplicación); este módulo solo expone la vista filtrada por
 * empleado y el alta a través del Gateway. No tiene tablas propias.
 *
 * Aislamiento: el empleado ve únicamente clientes que reservaron con él —
 * la consulta del Gateway filtra por employee_ref resuelto en servidor.
 */
class Customers_Module implements Core\Module {

	const SCHEMA_VERSION = '1.0.0';

	/** @var Booking_Provider */
	private $gateway;

	/** @var Core\Audit */
	private $audit;

	/** @var Core\Event_Bus */
	private $bus;

	public function __construct( Booking_Provider $gateway, Core\Audit $audit, Core\Event_Bus $bus ) {
		$this->gateway = $gateway;
		$this->audit   = $audit;
		$this->bus     = $bus;
	}

	public function key() {
		return 'customers';
	}

	public function schema_version() {
		return self::SCHEMA_VERSION;
	}

	public function migrate( $from ) {
		// Sin tablas propias: los clientes son del proveedor (requisito de no duplicar datos).
	}

	public function boot( Core\Event_Bus $bus ) {
	}

	public function register_routes( Core\Rest_Kernel $rest ) {
		$rest->route( '/customers', 'GET', array( $this, 'list_customers' ) );
		$rest->route( '/customers', 'POST', array( $this, 'create_customer' ) );
	}

	public function list_customers( \WP_REST_Request $request, $employee_ref ) {
		$customers = $this->gateway->get_customers_for_employee( $employee_ref );
		if ( is_wp_error( $customers ) ) {
			return $customers;
		}

		$search = strtolower( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		if ( '' !== $search ) {
			$customers = array_values( array_filter( $customers, function ( $customer ) use ( $search ) {
				$haystack = strtolower( $customer['first_name'] . ' ' . $customer['last_name'] . ' ' . $customer['email'] . ' ' . $customer['phone'] );
				return false !== strpos( $haystack, $search );
			} ) );
		}

		return array( 'items' => $customers );
	}

	public function create_customer( \WP_REST_Request $request, $employee_ref ) {
		$first_name = sanitize_text_field( (string) $request->get_param( 'first_name' ) );
		$phone      = sanitize_text_field( (string) $request->get_param( 'phone' ) );

		if ( '' === $first_name || '' === $phone ) {
			return new \WP_Error(
				'alb_ep_invalid_request',
				__( 'Nombre y teléfono del cliente son obligatorios.', 'alb-employee-portal' ),
				array( 'status' => 400 )
			);
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( $request->get_param( 'email' ) && '' === $email ) {
			return new \WP_Error(
				'alb_ep_invalid_email',
				__( 'El email indicado no es válido.', 'alb-employee-portal' ),
				array( 'status' => 400 )
			);
		}

		$data = array(
			'first_name' => $first_name,
			'last_name'  => sanitize_text_field( (string) $request->get_param( 'last_name' ) ),
			'phone'      => $phone,
			'email'      => $email,
			'note'       => sanitize_textarea_field( (string) $request->get_param( 'note' ) ),
		);

		$customer_ref = $this->gateway->create_customer( $data );
		if ( is_wp_error( $customer_ref ) ) {
			return $customer_ref;
		}

		$this->audit->log( 'customer.create', 'customer', $customer_ref, null, $data, Core\Audit::ORIGIN_PORTAL, $employee_ref );
		$this->bus->emit( 'customer_created', array(
			'customer_ref' => $customer_ref,
			'employee_ref' => $employee_ref,
		) );

		return array( 'id' => $customer_ref );
	}
}
