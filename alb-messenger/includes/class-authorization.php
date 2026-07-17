<?php
namespace ALBM;

defined( 'ABSPATH' ) || exit;

/**
 * Autorización (M1): implementa la matriz de permisos del diseño §5 como
 * servicio puro de decisión. No consulta la base de datos: recibe los hechos
 * (rol en el hilo, admin, estado, ventana) y devuelve la decisión — así la
 * matriz completa es verificable celda por celda sin infraestructura.
 *
 * Estados: active | readonly | locked | archived (diseño §1.4).
 */
class Authorization {

	const STATUS_ACTIVE   = 'active';
	const STATUS_READONLY = 'readonly';
	const STATUS_LOCKED   = 'locked';
	const STATUS_ARCHIVED = 'archived';

	/**
	 * ¿Puede leer el hilo?
	 *
	 * @param bool        $is_admin
	 * @param string|null $participant_role 'customer'|'employee'|null.
	 * @return true|\WP_Error
	 */
	public function can_read( $is_admin, $participant_role ) {
		if ( $is_admin || null !== $participant_role ) {
			// La lectura se permite en TODO estado (locked y archived incluidos).
			return true;
		}
		return new \WP_Error(
			'albm_forbidden',
			__( 'No tienes acceso a esta conversación.', 'alb-messenger' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * ¿Puede escribir en el hilo?
	 *
	 * @param bool        $is_admin         El admin escribe SIEMPRE (como Soporte, auditado).
	 * @param string|null $participant_role Rol del actor en el hilo.
	 * @param string      $status           Estado del hilo.
	 * @param bool        $window_open      ∃ cita con writable_until > ahora ∨ override_until > ahora
	 *                                      (lo calcula el motor de ventanas, M2).
	 * @return true|\WP_Error
	 */
	public function can_write( $is_admin, $participant_role, $status, $window_open ) {
		if ( $is_admin ) {
			return true;
		}

		if ( null === $participant_role ) {
			return new \WP_Error(
				'albm_forbidden',
				__( 'No tienes acceso a esta conversación.', 'alb-messenger' ),
				array( 'status' => 403 )
			);
		}

		if ( self::STATUS_LOCKED === $status ) {
			return new \WP_Error(
				'albm_locked',
				__( 'Esta conversación está bloqueada por el administrador.', 'alb-messenger' ),
				array( 'status' => 423 )
			);
		}

		if ( self::STATUS_ACTIVE === $status && $window_open ) {
			return true;
		}

		return new \WP_Error(
			'albm_window_closed',
			__( 'El chat se abre cuando tienes una reserva activa. Contacta al salón si necesitas ayuda.', 'alb-messenger' ),
			array( 'status' => 409 )
		);
	}
}
