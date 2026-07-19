<?php
namespace ALBM;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador de Amelia (M2): registra los hooks de reservas de forma
 * defensiva y programa la reconciliación por cron. Todo desemboca en
 * Appointment_Sync::sync(). Es la frontera con Amelia a nivel de eventos;
 * la lectura la hace Amelia_Reader.
 */
class Amelia_Adapter {

	const CRON_INCREMENTAL = 'albm_reconcile_incremental';
	const CRON_DAILY       = 'albm_reconcile_daily';
	const LOCK_NAME        = 'albm_reconcile';

	/** Hooks de Amelia → todos convergen en sync() con el ID de la cita. */
	const HOOKS = array(
		'amelia_after_booking_added',
		'amelia_after_appointment_added',
		'amelia_after_appointment_updated',
		'amelia_after_appointment_status_updated',
		'amelia_after_booking_canceled',
		'amelia_after_appointment_deleted',
	);

	/** @var Appointment_Sync */
	private $sync;

	/** @var Amelia_Reader */
	private $reader;

	public function __construct( Appointment_Sync $sync, Amelia_Reader $reader ) {
		$this->sync   = $sync;
		$this->reader = $reader;

		// Registro defensivo: add_action sobre un hook inexistente es un no-op.
		foreach ( self::HOOKS as $hook ) {
			add_action( $hook, array( $this, 'on_amelia_event' ), 20, 2 );
		}

		add_action( self::CRON_INCREMENTAL, array( $this, 'reconcile_incremental' ) );
		add_action( self::CRON_DAILY, array( $this, 'reconcile_daily' ) );

		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( 'init', array( $this, 'ensure_cron' ) );
	}

	public function add_schedule( $schedules ) {
		if ( ! isset( $schedules['albm_15min'] ) ) {
			$schedules['albm_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'ALB Messenger 15 min' );
		}
		return $schedules;
	}

	public function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON_INCREMENTAL ) ) {
			wp_schedule_event( time() + 60, 'albm_15min', self::CRON_INCREMENTAL );
		}
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + 120, 'daily', self::CRON_DAILY );
		}
	}

	public static function clear_cron() {
		wp_clear_scheduled_hook( self::CRON_INCREMENTAL );
		wp_clear_scheduled_hook( self::CRON_DAILY );
	}

	/**
	 * Manejador único de todos los hooks. Extrae SOLO el ID de la cita del
	 * payload (tolerante a la forma) y delega en sync() (M2-INTEGRATION §2).
	 */
	public function on_amelia_event( $arg1 = null, $arg2 = null ) {
		$ext_id = $this->extract_appointment_id( $arg1 );
		if ( ! $ext_id ) {
			$ext_id = $this->extract_appointment_id( $arg2 );
		}
		if ( $ext_id ) {
			$this->sync->sync( $ext_id );
		}
	}

	/** Busca el ID de la cita en las claves habituales del payload de Amelia. */
	private function extract_appointment_id( $data ) {
		if ( is_numeric( $data ) ) {
			return (int) $data;
		}
		if ( is_object( $data ) ) {
			$data = (array) $data;
		}
		if ( ! is_array( $data ) ) {
			return 0;
		}
		foreach ( array( 'appointmentId', 'id', 'appointment_id' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
				return (int) $data[ $key ];
			}
		}
		// Anidado: ['appointment' => ['id' => N]] o ['booking' => ['appointmentId' => N]].
		foreach ( array( 'appointment', 'booking' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$nested = $this->extract_appointment_id( $data[ $key ] );
				if ( $nested ) {
					return $nested;
				}
			}
		}
		return 0;
	}

	/** Barrido incremental cada 15 min sobre la banda [hoy-7d, hoy+90d]. */
	public function reconcile_incremental() {
		$this->reconcile(
			gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ),
			gmdate( 'Y-m-d H:i:s', strtotime( '+90 days' ) )
		);
	}

	/** Barrido completo diario (deriva histórica). */
	public function reconcile_daily() {
		$this->reconcile( '1970-01-01 00:00:00', gmdate( 'Y-m-d H:i:s', strtotime( '+5 years' ) ) );
	}

	/**
	 * Reconciliación: compara las citas de Amelia en la banda contra nuestra
	 * proyección y converge las diferencias. Protegida por GET_LOCK de MySQL
	 * (atado a la conexión: se libera solo si el proceso muere) —
	 * M2-INTEGRATION §8. Si otro barrido corre, este se salta limpio (§9).
	 */
	public function reconcile( $from, $to ) {
		global $wpdb;

		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK_NAME ) );
		if ( '1' !== (string) $got ) {
			return; // otro barrido en curso.
		}

		try {
			$amelia_ids = $this->reader->appointment_ids_between( $from, $to );
			if ( null === $amelia_ids ) {
				return; // Amelia inaccesible.
			}

			// (a) altas/ediciones: converge cada cita que Amelia reporta en la banda.
			foreach ( $amelia_ids as $ext_id ) {
				$this->sync->sync( $ext_id );
			}

			// (c) borrados: filas nuestras en la banda cuya cita ya no está en Amelia.
			$ours = $wpdb->get_col( $wpdb->prepare(
				'SELECT ext_appointment_id FROM ' . Schema::table( 'appointments' ) . "
				 WHERE starts_at >= %s AND starts_at < %s AND status <> 'deleted'",
				$from,
				$to
			) );
			$gone = array_diff( (array) $ours, $amelia_ids );
			foreach ( $gone as $ext_id ) {
				$this->sync->sync( $ext_id ); // el reader lo verá 'missing' → handle_deleted.
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
		}
	}
}
