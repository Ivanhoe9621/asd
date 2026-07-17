<?php
namespace ALBM;

defined( 'ABSPATH' ) || exit;

/**
 * Identidad (M1): resuelve SIEMPRE en servidor quién es el usuario actual
 * frente al messenger. Ningún dato del cliente decide identidad ni rol.
 *
 * Roles efectivos (no excluyentes):
 * - admin:    capability manage_options.
 * - employee: fila en albm_employee_map.
 * - customer: es participante con rol customer en algún hilo (el vínculo lo
 *             crea el provisionamiento del adaptador, M2).
 */
class Identity {

	/** @return int ID del usuario WP actual (0 si anónimo). */
	public function user_id() {
		return get_current_user_id();
	}

	public function is_admin() {
		return current_user_can( 'manage_options' );
	}

	/** @return string|null Referencia del empleado en el proveedor, o null. */
	public function employee_ref( $wp_user_id = null ) {
		global $wpdb;

		$wp_user_id = null === $wp_user_id ? $this->user_id() : (int) $wp_user_id;
		if ( ! $wp_user_id ) {
			return null;
		}

		$ref = $wpdb->get_var( $wpdb->prepare(
			'SELECT ext_employee_id FROM ' . Schema::table( 'employee_map' ) . ' WHERE wp_user_id = %d LIMIT 1',
			$wp_user_id
		) );

		return $ref ? (string) $ref : null;
	}

	/**
	 * Rol del usuario dentro de una conversación concreta.
	 *
	 * @return string|null 'customer' | 'employee' | null si no es participante.
	 */
	public function participant_role( $conversation_id, $wp_user_id = null ) {
		global $wpdb;

		$wp_user_id = null === $wp_user_id ? $this->user_id() : (int) $wp_user_id;
		if ( ! $wp_user_id ) {
			return null;
		}

		$role = $wpdb->get_var( $wpdb->prepare(
			'SELECT role FROM ' . Schema::table( 'participants' ) . ' WHERE conversation_id = %d AND wp_user_id = %d LIMIT 1',
			(int) $conversation_id,
			$wp_user_id
		) );

		return $role ? (string) $role : null;
	}

	/** ¿Tiene el usuario al menos un hilo como cliente? (para /me). */
	public function is_customer( $wp_user_id = null ) {
		global $wpdb;

		$wp_user_id = null === $wp_user_id ? $this->user_id() : (int) $wp_user_id;
		if ( ! $wp_user_id ) {
			return false;
		}

		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . Schema::table( 'participants' ) . " WHERE wp_user_id = %d AND role = 'customer' LIMIT 1",
			$wp_user_id
		) );
	}
}
