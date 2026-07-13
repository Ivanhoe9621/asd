<?php
namespace ALB_EP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * REST Kernel: único punto de registro de rutas de la plataforma. Toda ruta
 * declara su nivel de acceso y el middleware de permisos se ejecuta antes
 * que cualquier handler de módulo (requisito permanente 8). Los módulos no
 * llaman a register_rest_route directamente.
 */
class Rest_Kernel {

	const ACCESS_EMPLOYEE = 'employee';
	const ACCESS_ADMIN    = 'admin';

	/**
	 * Cualquier usuario autenticado, mapeado o no. Solo para rutas que
	 * toleran employee_ref null (ej. /me, que es donde el frontend descubre
	 * si el usuario es admin sin mapear — de lo contrario un admin recién
	 * instalado no podría llegar nunca a la pantalla que crea el primer
	 * mapeo).
	 */
	const ACCESS_ANY = 'any';

	/** @var Identity */
	private $identity;

	public function __construct( Identity $identity ) {
		$this->identity = $identity;
	}

	/**
	 * Registra una ruta bajo el namespace de la plataforma.
	 *
	 * @param string   $route    Ruta relativa (ej. '/services').
	 * @param string   $methods  Métodos HTTP.
	 * @param callable $handler  Handler; recibe (WP_REST_Request, string $employee_ref|null).
	 * @param string   $access   ACCESS_EMPLOYEE o ACCESS_ADMIN.
	 * @param array    $args     Definición de argumentos REST estándar.
	 */
	public function route( $route, $methods, $handler, $access = self::ACCESS_EMPLOYEE, array $args = array() ) {
		register_rest_route(
			ALB_EP_REST_NAMESPACE,
			$route,
			array(
				'methods'             => $methods,
				'args'                => $args,
				'permission_callback' => function () use ( $access ) {
					return $this->authorize( $access );
				},
				'callback'            => function ( \WP_REST_Request $request ) use ( $handler, $access ) {
					$employee_ref = null;

					if ( self::ACCESS_EMPLOYEE === $access ) {
						$employee_ref = $this->identity->resolve_for_request( $request->get_param( 'employee_id' ) );
						if ( is_wp_error( $employee_ref ) ) {
							return $employee_ref;
						}
					} elseif ( self::ACCESS_ANY === $access ) {
						$employee_ref = $this->identity->current_employee_ref();
					}

					$result = call_user_func( $handler, $request, $employee_ref );

					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return rest_ensure_response( $result );
				},
			)
		);
	}

	/** @return true|\WP_Error */
	private function authorize( $access ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'alb_ep_unauthorized',
				__( 'Debes iniciar sesión.', 'alb-employee-portal' ),
				array( 'status' => 401 )
			);
		}

		if ( self::ACCESS_ADMIN === $access && ! $this->identity->is_platform_admin() ) {
			return new \WP_Error(
				'alb_ep_forbidden',
				__( 'Solo el administrador puede realizar esta acción.', 'alb-employee-portal' ),
				array( 'status' => 403 )
			);
		}

		if ( self::ACCESS_ANY === $access ) {
			return true;
		}

		if ( self::ACCESS_EMPLOYEE === $access
			&& ! $this->identity->is_platform_admin()
			&& null === $this->identity->current_employee_ref() ) {
			return new \WP_Error(
				'alb_ep_not_mapped',
				__( 'Tu usuario no está vinculado a ningún empleado. Contacta al administrador.', 'alb-employee-portal' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
