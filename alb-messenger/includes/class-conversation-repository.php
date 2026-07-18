<?php
namespace ALBM;

defined( 'ABSPATH' ) || exit;

/**
 * Repositorio de conversaciones (M2). Encapsula el find-or-create seguro
 * ante concurrencia (M2-INTEGRATION §6) y el registro de eventos con
 * compare-and-swap (§7). No conoce Amelia — recibe datos ya resueltos.
 */
class Conversation_Repository {

	/** Construye la pair_key canónica de un par cliente↔empleado. */
	public function pair_key( $provider, $customer_ref, $employee_ref ) {
		return $provider . ':' . $customer_ref . ':' . $employee_ref;
	}

	/**
	 * Devuelve el id de la conversación del par, creándola si no existe.
	 *
	 * No hace "SELECT y si no existe INSERT" (tiene carrera): intenta
	 * INSERT IGNORE y luego SELECT — la restricción UNIQUE de pair_key
	 * garantiza una sola fila aunque dos procesos entren a la vez.
	 *
	 * @return int id de la conversación.
	 */
	public function find_or_create( $provider, $customer_ref, $employee_ref, $customer_wp_id, $employee_wp_id ) {
		global $wpdb;

		$pair_key = $this->pair_key( $provider, $customer_ref, $employee_ref );
		$table    = Schema::table( 'conversations' );

		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (provider, pair_key, status, created_at)
			 VALUES (%s, %s, 'active', %s)",
			$provider,
			$pair_key,
			current_time( 'mysql', true )
		) );

		$conversation_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE pair_key = %s",
			$pair_key
		) );

		// Participantes: idempotente por UNIQUE (conversation_id, wp_user_id).
		$this->ensure_participant( $conversation_id, $customer_wp_id, 'customer', $customer_ref );
		$this->ensure_participant( $conversation_id, $employee_wp_id, 'employee', $employee_ref );

		return $conversation_id;
	}

	private function ensure_participant( $conversation_id, $wp_user_id, $role, $provider_ref ) {
		global $wpdb;

		if ( ! $wp_user_id ) {
			return; // cliente sin usuario WP todavía: se completa en un sync posterior.
		}

		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . Schema::table( 'participants' ) . '
				(conversation_id, wp_user_id, role, provider_ref, joined_at)
			 VALUES (%d, %d, %s, %s, %s)',
			$conversation_id,
			$wp_user_id,
			$role,
			(string) $provider_ref,
			current_time( 'mysql', true )
		) );
	}

	/** @return array|null Fila de la conversación. */
	public function get( $conversation_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . Schema::table( 'conversations' ) . ' WHERE id = %d',
			(int) $conversation_id
		), ARRAY_A );
		return $row ? $row : null;
	}

	/** Conversación que hoy contiene una cita concreta (para reasignaciones). */
	public function conversation_of_appointment( $ext_appointment_id ) {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare(
			'SELECT conversation_id FROM ' . Schema::table( 'appointments' ) . ' WHERE ext_appointment_id = %s',
			(string) $ext_appointment_id
		) );
		return $id ? (int) $id : 0;
	}

	/**
	 * Registra un evento del sistema SOLO si `$changed` es true (el llamador
	 * lo obtiene del affected_rows de su UPDATE con compare-and-swap). Así
	 * re-sincronizar sin cambios reales no duplica eventos (M2-INTEGRATION §7).
	 */
	public function record_event_if_changed( $changed, $conversation_id, $type, $ext_ref = null, array $payload = array() ) {
		if ( ! $changed ) {
			return;
		}
		global $wpdb;
		$wpdb->insert( Schema::table( 'events' ), array(
			'conversation_id' => (int) $conversation_id,
			'type'            => $type,
			'ext_ref'         => null === $ext_ref ? null : (string) $ext_ref,
			'payload'         => $payload ? wp_json_encode( $payload ) : null,
			'created_at'      => current_time( 'mysql', true ),
		) );
	}

	/** Recalcula active↔readonly según las ventanas de sus citas. */
	public function recompute_status( $conversation_id ) {
		global $wpdb;

		$conv = $this->get( $conversation_id );
		if ( ! $conv || in_array( $conv['status'], array( 'locked', 'archived' ), true ) ) {
			return; // locked/archived solo los cambia el admin (o el barrido de archivado).
		}

		$now       = current_time( 'mysql', true );
		$has_open  = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . Schema::table( 'appointments' ) . '
			 WHERE conversation_id = %d AND writable_until > %s LIMIT 1',
			(int) $conversation_id,
			$now
		) );
		$override  = ! empty( $conv['override_until'] ) && $conv['override_until'] > $now;
		$target    = ( $has_open || $override ) ? 'active' : 'readonly';

		if ( $target !== $conv['status'] ) {
			$wpdb->update( Schema::table( 'conversations' ),
				array( 'status' => $target ),
				array( 'id' => (int) $conversation_id )
			);
		}
	}
}
