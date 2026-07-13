<?php
namespace ALB_EP\Core;

use ALB_EP\Gateway\Booking_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Rutas REST del propio núcleo: perfil del empleado autenticado (/me) y
 * administración de la plataforma (mapeo usuario↔empleado, auditoría,
 * diagnóstico del proveedor).
 */
class Admin_Rest {

	/** @var Identity */
	private $identity;

	/** @var Audit */
	private $audit;

	/** @var Booking_Provider */
	private $gateway;

	public function __construct( Rest_Kernel $rest, Identity $identity, Audit $audit, Booking_Provider $gateway ) {
		$this->identity = $identity;
		$this->audit    = $audit;
		$this->gateway  = $gateway;

		add_action( 'rest_api_init', function () use ( $rest ) {
			// ACCESS_ANY: /me debe responder también al admin sin mapear,
			// que es quien crea el primer vínculo usuario↔empleado.
			$rest->route( '/me', 'GET', array( $this, 'me' ), Rest_Kernel::ACCESS_ANY );
			$rest->route( '/admin/employee-map', 'GET', array( $this, 'list_map' ), Rest_Kernel::ACCESS_ADMIN );
			$rest->route( '/admin/employee-map', 'PUT', array( $this, 'update_map' ), Rest_Kernel::ACCESS_ADMIN );
			$rest->route( '/admin/employees', 'GET', array( $this, 'list_provider_employees' ), Rest_Kernel::ACCESS_ADMIN );
			$rest->route( '/admin/wp-users', 'GET', array( $this, 'list_wp_users' ), Rest_Kernel::ACCESS_ADMIN );
			$rest->route( '/admin/audit', 'GET', array( $this, 'list_audit' ), Rest_Kernel::ACCESS_ADMIN );
			$rest->route( '/admin/status', 'GET', array( $this, 'status' ), Rest_Kernel::ACCESS_ADMIN );
		} );
	}

	public function me( \WP_REST_Request $request, $employee_ref ) {
		$user = wp_get_current_user();

		return array(
			'wp_user_id'   => $user->ID,
			'display_name' => $user->display_name,
			'employee_ref' => $employee_ref,
			'is_admin'     => $this->identity->is_platform_admin(),
			'provider'     => $this->gateway->key(),
		);
	}

	public function list_map( \WP_REST_Request $request, $employee_ref ) {
		return array( 'items' => $this->identity->all_mappings() );
	}

	public function update_map( \WP_REST_Request $request, $employee_ref ) {
		$wp_user_id      = absint( $request->get_param( 'wp_user_id' ) );
		$ext_employee_id = sanitize_text_field( (string) $request->get_param( 'ext_employee_id' ) );

		if ( ! $wp_user_id || ! get_user_by( 'id', $wp_user_id ) ) {
			return new \WP_Error(
				'alb_ep_invalid_user',
				__( 'El usuario de WordPress indicado no existe.', 'alb-employee-portal' ),
				array( 'status' => 400 )
			);
		}
		if ( '' === $ext_employee_id ) {
			return new \WP_Error(
				'alb_ep_invalid_request',
				__( 'Indica el ID del empleado en el proveedor (ext_employee_id).', 'alb-employee-portal' ),
				array( 'status' => 400 )
			);
		}

		$this->identity->map_user( $wp_user_id, $ext_employee_id, $this->gateway->key() );

		$this->audit->log(
			'employee_map.update',
			'employee_map',
			$wp_user_id,
			null,
			array( 'wp_user_id' => $wp_user_id, 'ext_employee_id' => $ext_employee_id ),
			Audit::ORIGIN_ADMIN,
			$ext_employee_id
		);

		return array( 'items' => $this->identity->all_mappings() );
	}

	public function list_provider_employees( \WP_REST_Request $request, $employee_ref ) {
		$employees = $this->gateway->get_employees();
		if ( is_wp_error( $employees ) ) {
			return $employees;
		}
		return array( 'items' => $employees );
	}

	public function list_wp_users( \WP_REST_Request $request, $employee_ref ) {
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );

		$query = new \WP_User_Query( array(
			'number'         => 20,
			'search'         => '' !== $search ? '*' . $search . '*' : '',
			'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
			'fields'         => array( 'ID', 'display_name', 'user_login' ),
		) );

		$items = array();
		foreach ( $query->get_results() as $user ) {
			$items[] = array(
				'id'           => (int) $user->ID,
				'display_name' => $user->display_name,
				'login'        => $user->user_login,
			);
		}

		return array( 'items' => $items );
	}

	public function list_audit( \WP_REST_Request $request, $employee_ref ) {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = absint( $request->get_param( 'per_page' ) );
		$per_page = min( 100, $per_page > 0 ? $per_page : 50 );

		$filters = array();
		if ( $request->get_param( 'action' ) ) {
			$filters['action'] = sanitize_text_field( (string) $request->get_param( 'action' ) );
		}
		if ( $request->get_param( 'employee_ref' ) ) {
			$filters['employee_ref'] = sanitize_text_field( (string) $request->get_param( 'employee_ref' ) );
		}

		return $this->audit->query( $page, $per_page, $filters );
	}

	public function status( \WP_REST_Request $request, $employee_ref ) {
		return array(
			'version'     => ALB_EP_VERSION,
			'diagnostics' => $this->gateway->diagnostics(),
		);
	}
}
