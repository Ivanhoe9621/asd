<?php
namespace ALBM;

defined( 'ABSPATH' ) || exit;

/**
 * Kernel REST (M1): único punto de registro de rutas bajo albm/v1. Toda
 * ruta declara su nivel de acceso; el middleware corre antes que cualquier
 * handler. Los módulos siguientes registran sus rutas a través de aquí.
 */
class Rest_Kernel {

	const ACCESS_AUTH  = 'auth';   // cualquier usuario con sesión
	const ACCESS_ADMIN = 'admin';  // manage_options

	/** @var Identity */
	private $identity;

	public function __construct( Identity $identity ) {
		$this->identity = $identity;

		add_action( 'rest_api_init', function () {
			$this->route( '/me', 'GET', array( $this, 'me' ) );
		} );
	}

	/**
	 * Registra una ruta bajo albm/v1.
	 *
	 * @param string   $route   Ruta relativa (ej. '/conversations').
	 * @param string   $methods Métodos HTTP.
	 * @param callable $handler Recibe (WP_REST_Request); las comprobaciones
	 *                          por-conversación (matriz §5) las hace el
	 *                          handler con Authorization — este middleware
	 *                          cubre autenticación y nivel admin.
	 * @param string   $access  ACCESS_AUTH | ACCESS_ADMIN.
	 * @param array    $args    Args REST estándar.
	 */
	public function route( $route, $methods, $handler, $access = self::ACCESS_AUTH, array $args = array() ) {
		register_rest_route(
			ALBM_REST_NAMESPACE,
			$route,
			array(
				'methods'             => $methods,
				'args'                => $args,
				'permission_callback' => function () use ( $access ) {
					if ( ! is_user_logged_in() ) {
						return new \WP_Error(
							'albm_unauthorized',
							__( 'Debes iniciar sesión.', 'alb-messenger' ),
							array( 'status' => 401 )
						);
					}
					if ( self::ACCESS_ADMIN === $access && ! $this->identity->is_admin() ) {
						return new \WP_Error(
							'albm_forbidden',
							__( 'Solo el administrador puede realizar esta acción.', 'alb-messenger' ),
							array( 'status' => 403 )
						);
					}
					return true;
				},
				'callback'            => function ( \WP_REST_Request $request ) use ( $handler ) {
					$result = call_user_func( $handler, $request );
					return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
				},
			)
		);
	}

	/** GET /albm/v1/me — roles efectivos del usuario autenticado. */
	public function me( \WP_REST_Request $request ) {
		$user = wp_get_current_user();

		return array(
			'wp_user_id'   => (int) $user->ID,
			'display_name' => $user->display_name,
			'is_admin'     => $this->identity->is_admin(),
			'employee_ref' => $this->identity->employee_ref(),
			'is_customer'  => $this->identity->is_customer(),
		);
	}
}
