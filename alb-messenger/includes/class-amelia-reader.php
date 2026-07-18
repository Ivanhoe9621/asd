<?php
namespace ALBM;

defined( 'ABSPATH' ) || exit;

/**
 * Lector de Amelia (M2) — SOLO lectura, introspectivo: antes de consultar
 * verifica que la tabla exista y tenga las columnas esperadas; si Amelia no
 * está o cambió su esquema, devuelve null y el messenger degrada fail-closed
 * (M2-INTEGRATION §3). Es la única clase que conoce las tablas de Amelia.
 */
class Amelia_Reader {

	/** @var array<string,array> Columnas por tabla, cacheadas por request. */
	private $columns = array();

	/**
	 * Estado canónico de una cita, releído de Amelia (M2-INTEGRATION §2).
	 *
	 * @param string|int $ext_id
	 * @return array|null {id, service_ref, service_name, employee_ref,
	 *                     customer_ref, starts_at, ends_at, status} o null si
	 *                     la cita no existe o Amelia no está disponible.
	 *                     'missing' => true distingue "cita borrada" de
	 *                     "Amelia inaccesible" (null a secas).
	 */
	public function appointment( $ext_id ) {
		global $wpdb;

		$appointments = $this->table( 'appointments' );
		$bookings     = $this->table( 'customer_bookings' );
		if ( ! $appointments || ! $bookings ) {
			return null; // Amelia inaccesible: no decidir nada.
		}

		$services   = $this->table( 'services' );
		$name_field = $services ? 's.name AS service_name' : "'' AS service_name";
		$name_join  = $services ? "LEFT JOIN {$services} s ON s.id = a.serviceId" : '';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.id, a.serviceId AS service_ref, {$name_field},
						a.providerId AS employee_ref, a.bookingStart AS starts_at,
						a.bookingEnd AS ends_at, a.status,
						MIN(cb.customerId) AS customer_ref
				 FROM {$appointments} a
				 LEFT JOIN {$bookings} cb ON cb.appointmentId = a.id
				 {$name_join}
				 WHERE a.id = %d
				 GROUP BY a.id, a.serviceId, a.providerId, a.bookingStart, a.bookingEnd, a.status" .
				( $services ? ', s.name' : '' ),
				(int) $ext_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return array( 'missing' => true );
		}

		$row['id']           = (string) $row['id'];
		$row['service_ref']  = (string) $row['service_ref'];
		$row['employee_ref'] = (string) $row['employee_ref'];
		$row['customer_ref'] = null === $row['customer_ref'] ? null : (string) $row['customer_ref'];
		$row['missing']      = false;
		return $row;
	}

	/**
	 * Usuario WP vinculado a un cliente de Amelia (externalId, creado por el
	 * setting nativo de cuentas automáticas — decisión de producto #3).
	 *
	 * @return int 0 si no tiene usuario WP todavía.
	 */
	public function customer_wp_user( $customer_ref ) {
		global $wpdb;

		$users = $this->table( 'users' );
		if ( ! $users ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT externalId FROM {$users} WHERE id = %d AND type = 'customer'",
			(int) $customer_ref
		) );
	}

	/**
	 * IDs de citas de Amelia con inicio dentro de la banda (para el barrido
	 * incremental del cron, M2-INTEGRATION §4).
	 *
	 * @return array<string>|null null si Amelia no está disponible.
	 */
	public function appointment_ids_between( $from, $to ) {
		global $wpdb;

		$appointments = $this->table( 'appointments' );
		if ( ! $appointments ) {
			return null;
		}

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$appointments} WHERE bookingStart >= %s AND bookingStart < %s",
			$from,
			$to
		) );

		return array_map( 'strval', (array) $ids );
	}

	/** @return string|false Nombre completo de la tabla o false. */
	private function table( $key ) {
		global $wpdb;

		$expected = array(
			'appointments'      => array( 'id', 'serviceId', 'providerId', 'bookingStart', 'bookingEnd', 'status' ),
			'customer_bookings' => array( 'appointmentId', 'customerId' ),
			'users'             => array( 'id', 'type', 'externalId' ),
			'services'          => array( 'id', 'name' ),
		);

		$name = $wpdb->prefix . 'amelia_' . $key;

		if ( ! array_key_exists( $name, $this->columns ) ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
			$this->columns[ $name ] = $exists ? (array) $wpdb->get_col( "DESCRIBE {$name}", 0 ) : array();
		}

		if ( empty( $this->columns[ $name ] ) ) {
			return false;
		}
		foreach ( $expected[ $key ] as $column ) {
			if ( ! in_array( $column, $this->columns[ $name ], true ) ) {
				return false;
			}
		}
		return $name;
	}
}
