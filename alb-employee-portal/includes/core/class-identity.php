<?php
namespace ALB_EP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Identity & Permissions (requisito permanente 8): resuelve SIEMPRE en
 * servidor qué empleado del proveedor corresponde al usuario WordPress
 * autenticado, mediante el mapa explícito alb_ep_employee_map. Ningún
 * employee_id enviado por el cliente determina la identidad.
 */
class Identity {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'alb_ep_employee_map';
	}

	/**
	 * Referencia del empleado vinculado al usuario actual, o null si el
	 * usuario no está mapeado.
	 *
	 * @return string|null
	 */
	public function current_employee_ref() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		global $wpdb;
		$ref = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ext_employee_id FROM ' . self::table() . ' WHERE wp_user_id = %d LIMIT 1',
				$user_id
			)
		);

		return $ref ? (string) $ref : null;
	}

	/** El usuario actual administra la plataforma completa. */
	public function is_platform_admin() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Resuelve el empleado sobre el que opera la request: el propio, o el
	 * indicado explícitamente si el actor es admin.
	 *
	 * @param string|null $requested_ref Empleado pedido explícitamente (solo admin).
	 * @return string|\WP_Error
	 */
	public function resolve_for_request( $requested_ref = null ) {
		if ( null !== $requested_ref && '' !== $requested_ref ) {
			if ( $this->is_platform_admin() ) {
				return (string) $requested_ref;
			}

			$own = $this->current_employee_ref();
			if ( $own !== (string) $requested_ref ) {
				return new \WP_Error(
					'alb_ep_forbidden',
					__( 'No puedes operar sobre datos de otro empleado.', 'alb-employee-portal' ),
					array( 'status' => 403 )
				);
			}
			return $own;
		}

		$own = $this->current_employee_ref();
		if ( null === $own ) {
			return new \WP_Error(
				'alb_ep_not_mapped',
				__( 'Tu usuario no está vinculado a ningún empleado. Contacta al administrador.', 'alb-employee-portal' ),
				array( 'status' => 403 )
			);
		}
		return $own;
	}

	/** Lista completa del mapa usuario↔empleado (solo admin). */
	public function all_mappings() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT id, wp_user_id, provider, ext_employee_id, created_at FROM ' . self::table() . ' ORDER BY id ASC', ARRAY_A );
	}

	/** Crea o reemplaza el vínculo de un usuario WP con un empleado del proveedor. */
	public function map_user( $wp_user_id, $ext_employee_id, $provider = 'amelia' ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (wp_user_id, provider, ext_employee_id, created_at)
				 VALUES (%d, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE provider = VALUES(provider), ext_employee_id = VALUES(ext_employee_id)',
				$wp_user_id,
				$provider,
				(string) $ext_employee_id,
				current_time( 'mysql', true )
			)
		);
	}
}
