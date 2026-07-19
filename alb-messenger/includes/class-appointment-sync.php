<?php
namespace ALBM;

defined( 'ABSPATH' ) || exit;

/**
 * Sincronizador de citas (M2) — la función de convergencia sync() del
 * M2-INTEGRATION. Único punto por el que hooks y cron ajustan nuestras
 * tablas al estado canónico de Amelia. Idempotente por diseño: releer el
 * mismo estado de Amelia produce las mismas filas y cero eventos nuevos.
 */
class Appointment_Sync {

	const WINDOW_HOURS = 48; // ventana de escritura tras el fin de la cita (decisión #2).

	/** @var Amelia_Reader */
	private $reader;

	/** @var Conversation_Repository */
	private $repo;

	public function __construct( Amelia_Reader $reader, Conversation_Repository $repo ) {
		$this->reader = $reader;
		$this->repo   = $repo;
	}

	/**
	 * Converge el estado de UNA cita. Segura ante ejecuciones concurrentes o
	 * repetidas (M2-INTEGRATION §7, §9). No necesita lock.
	 *
	 * @param string|int $ext_id ID de la cita en Amelia.
	 * @return string Resultado, para diagnóstico/pruebas.
	 */
	public function sync( $ext_id ) {
		$appt = $this->reader->appointment( $ext_id );

		if ( null === $appt ) {
			return 'amelia_unavailable'; // no decidir nada (fail-closed).
		}

		if ( ! empty( $appt['missing'] ) ) {
			return $this->handle_deleted( $ext_id );
		}

		if ( empty( $appt['customer_ref'] ) ) {
			return 'no_customer'; // cita sin cliente aún: nada que vincular.
		}

		$customer_wp = $this->reader->customer_wp_user( $appt['customer_ref'] );
		$employee_wp = $this->employee_wp_user( $appt['employee_ref'] );

		// Conversación destino del par actual (crea si no existe).
		$conversation_id = $this->repo->find_or_create(
			'amelia',
			$appt['customer_ref'],
			$appt['employee_ref'],
			$customer_wp,
			$employee_wp
		);

		// ¿La cita ya estaba vinculada a OTRA conversación? → reasignación de empleado (§5).
		$previous_conv = $this->repo->conversation_of_appointment( $ext_id );
		$reassigned    = $previous_conv && $previous_conv !== $conversation_id;

		$writable_until = gmdate( 'Y-m-d H:i:s', strtotime( $appt['ends_at'] ) + self::WINDOW_HOURS * HOUR_IN_SECONDS );

		$result = $this->upsert_appointment( $conversation_id, $ext_id, $appt, $writable_until );

		if ( $reassigned ) {
			$this->repo->record_event_if_changed( true, $previous_conv, 'employee_changed',
				$ext_id, array( 'moved_to' => $conversation_id ) );
			$this->repo->record_event_if_changed( true, $conversation_id, 'appointment_linked',
				$ext_id, array( 'service' => $appt['service_name'], 'reassigned_from' => $previous_conv ) );
			$this->repo->recompute_status( $previous_conv );
		}

		$this->repo->recompute_status( $conversation_id );
		return $result;
	}

	/**
	 * Inserta o actualiza la proyección de la cita con compare-and-swap: el
	 * UPDATE solo toca la fila si algún campo relevante cambió, y su
	 * affected_rows decide si se registra un evento (M2-INTEGRATION §7.3).
	 *
	 * @return string 'linked' | 'updated' | 'unchanged'
	 */
	private function upsert_appointment( $conversation_id, $ext_id, array $appt, $writable_until ) {
		global $wpdb;
		$table = Schema::table( 'appointments' );

		$exists = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE ext_appointment_id = %s",
			(string) $ext_id
		) );

		if ( ! $exists ) {
			// INSERT IGNORE: si dos procesos entran a la vez, uno gana y el otro no duplica.
			$inserted = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$table}
					(conversation_id, ext_appointment_id, service_name, starts_at, ends_at, status, writable_until)
				 VALUES (%d, %s, %s, %s, %s, %s, %s)",
				$conversation_id,
				(string) $ext_id,
				$appt['service_name'],
				$appt['starts_at'],
				$appt['ends_at'],
				$appt['status'],
				$writable_until
			) );
			$this->repo->record_event_if_changed( (bool) $inserted, $conversation_id, 'appointment_linked',
				$ext_id, array( 'service' => $appt['service_name'], 'starts_at' => $appt['starts_at'] ) );
			return $inserted ? 'linked' : 'unchanged';
		}

		// Compare-and-swap: solo actualiza si algo relevante difiere.
		$affected = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET conversation_id = %d, service_name = %s, starts_at = %s, ends_at = %s,
				 status = %s, writable_until = %s
			 WHERE ext_appointment_id = %s
			   AND (conversation_id <> %d OR starts_at <> %s OR ends_at <> %s OR status <> %s)",
			$conversation_id, $appt['service_name'], $appt['starts_at'], $appt['ends_at'],
			$appt['status'], $writable_until,
			(string) $ext_id,
			$conversation_id, $appt['starts_at'], $appt['ends_at'], $appt['status']
		) );

		if ( $affected ) {
			$type = ( 'canceled' === $appt['status'] || 'rejected' === $appt['status'] )
				? 'appointment_canceled' : 'appointment_rescheduled';
			$this->repo->record_event_if_changed( true, $conversation_id, $type,
				$ext_id, array( 'status' => $appt['status'], 'starts_at' => $appt['starts_at'] ) );
			return 'updated';
		}
		return 'unchanged';
	}

	/** Cita borrada en Amelia: cierra su ventana, deja el historial intacto. */
	private function handle_deleted( $ext_id ) {
		global $wpdb;
		$table = Schema::table( 'appointments' );

		$conversation_id = $this->repo->conversation_of_appointment( $ext_id );
		if ( ! $conversation_id ) {
			return 'unchanged';
		}

		$now      = current_time( 'mysql', true );
		$affected = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'deleted', writable_until = %s
			 WHERE ext_appointment_id = %s AND status <> 'deleted'",
			$now,
			(string) $ext_id
		) );

		$this->repo->record_event_if_changed( (bool) $affected, $conversation_id, 'appointment_canceled',
			$ext_id, array( 'reason' => 'deleted_in_amelia' ) );
		$this->repo->recompute_status( $conversation_id );
		return $affected ? 'deleted' : 'unchanged';
	}

	/** Usuario WP del empleado desde nuestro mapeo (M1). 0 si no está mapeado. */
	private function employee_wp_user( $employee_ref ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT wp_user_id FROM ' . Schema::table( 'employee_map' ) . ' WHERE ext_employee_id = %s LIMIT 1',
			(string) $employee_ref
		) );
	}
}
