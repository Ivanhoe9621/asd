<?php
namespace ALB_EP\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador de Amelia: único código de la plataforma que conoce tablas,
 * clases y hooks de Amelia. Cadena de acceso (documento de arquitectura,
 * sección 4): hooks oficiales → contenedor interno → API Elite (opcional)
 * → SQL de solo lectura → SQL de escritura acotado.
 *
 * ESTADO ACTUAL DEL ESQUELETO:
 * - Lecturas: SQL introspectivo de solo lectura (mismo patrón validado en
 *   producción por alb-catalog/class-amelia-db-source.php).
 * - Escrituras: devuelven 'alb_ep_provider_unsupported' hasta completar la
 *   verificación en servidor de los command handlers del contenedor
 *   interno (sección 7 del documento). El flujo v1 no las necesita para
 *   funcionar: propuestas y metadatos viven en tablas propias.
 */
class Amelia_Provider implements Booking_Provider {

	/** @var array<string,array> Cache de columnas por tabla en esta request. */
	private $columns = array();

	public function key() {
		return 'amelia';
	}

	// ---------------------------------------------------------------
	// Lecturas (nivel 4: SQL introspectivo de solo lectura)
	// ---------------------------------------------------------------

	public function get_services_for_employee( $employee_ref ) {
		global $wpdb;

		$services = $this->table( 'services' );
		$p2s      = $this->table( 'providers_to_services' );
		$cats     = $this->table( 'categories' );

		if ( ! $services || ! $p2s ) {
			return $this->unavailable();
		}

		$join_cat   = $cats ? "LEFT JOIN {$cats} c ON c.id = s.categoryId" : '';
		$cat_fields = $cats ? 'c.name AS category_name,' : "'' AS category_name,";

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.name, s.description, s.categoryId AS category_id, {$cat_fields}
						s.duration, s.price AS base_price, s.status, s.pictureFullPath AS image_url,
						ps.price AS employee_price, ps.minCapacity AS min_capacity, ps.maxCapacity AS max_capacity
				 FROM {$p2s} ps
				 INNER JOIN {$services} s ON s.id = ps.serviceId
				 {$join_cat}
				 WHERE ps.userId = %d
				 ORDER BY s.name ASC",
				(int) $employee_ref
			),
			ARRAY_A
		);

		if ( null === $rows ) {
			return $this->unavailable();
		}

		foreach ( $rows as &$row ) {
			$row['id']             = (string) $row['id'];
			$row['category_id']    = (string) $row['category_id'];
			$row['base_price']     = (float) $row['base_price'];
			$row['employee_price'] = null === $row['employee_price'] ? null : (float) $row['employee_price'];
		}

		return $rows;
	}

	public function employee_offers_service( $employee_ref, $service_ref ) {
		global $wpdb;

		$p2s = $this->table( 'providers_to_services' );
		if ( ! $p2s ) {
			return $this->unavailable();
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$p2s} WHERE userId = %d AND serviceId = %d LIMIT 1",
				(int) $employee_ref,
				(int) $service_ref
			)
		);
	}

	public function get_employees() {
		global $wpdb;

		$users = $this->table( 'users' );
		if ( ! $users ) {
			return $this->unavailable();
		}

		$rows = $wpdb->get_results(
			"SELECT id, firstName AS first_name, lastName AS last_name, email, status
			 FROM {$users} WHERE type = 'provider' ORDER BY firstName ASC",
			ARRAY_A
		);

		if ( null === $rows ) {
			return $this->unavailable();
		}

		foreach ( $rows as &$row ) {
			$row['id'] = (string) $row['id'];
		}
		return $rows;
	}

	public function get_categories() {
		global $wpdb;

		$cats = $this->table( 'categories' );
		if ( ! $cats ) {
			return $this->unavailable();
		}

		$rows = $wpdb->get_results( "SELECT id, name FROM {$cats} ORDER BY name ASC", ARRAY_A );
		if ( null === $rows ) {
			return $this->unavailable();
		}

		foreach ( $rows as &$row ) {
			$row['id'] = (string) $row['id'];
		}
		return $rows;
	}

	public function get_appointments( $employee_ref, $from, $to ) {
		global $wpdb;

		$appointments = $this->table( 'appointments' );
		if ( ! $appointments ) {
			return $this->unavailable();
		}

		$bookings = $this->table( 'customer_bookings' );
		$users    = $this->table( 'users' );

		/*
		 * El detalle de cliente y precio por cita depende de dos tablas más
		 * de Amelia; si esta versión no las expone como se espera, la
		 * agenda funciona igual sin esos campos.
		 */
		$customer_fields = "NULL AS customer_ref, NULL AS customer_name, NULL AS price";
		$customer_join   = '';
		if ( $bookings && $users ) {
			$customer_fields = "cb.customerId AS customer_ref,
						TRIM(CONCAT(COALESCE(u.firstName,''), ' ', COALESCE(u.lastName,''))) AS customer_name,
						cb.price AS price";
			$customer_join   = "LEFT JOIN {$bookings} cb ON cb.appointmentId = a.id
						LEFT JOIN {$users} u ON u.id = cb.customerId";
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.serviceId AS service_ref, a.bookingStart AS starts_at,
						a.bookingEnd AS ends_at, a.status, {$customer_fields}
				 FROM {$appointments} a
				 {$customer_join}
				 WHERE a.providerId = %d AND a.bookingStart >= %s AND a.bookingStart < %s
				 ORDER BY a.bookingStart ASC",
				(int) $employee_ref,
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			),
			ARRAY_A
		);

		if ( null === $rows ) {
			return $this->unavailable();
		}

		foreach ( $rows as &$row ) {
			$row['id']           = (string) $row['id'];
			$row['service_ref']  = (string) $row['service_ref'];
			$row['customer_ref'] = null === $row['customer_ref'] ? null : (string) $row['customer_ref'];
			$row['price']        = null === $row['price'] ? null : (float) $row['price'];
		}
		return $rows;
	}

	public function get_customers_for_employee( $employee_ref ) {
		global $wpdb;

		$appointments = $this->table( 'appointments' );
		$bookings     = $this->table( 'customer_bookings' );
		$users        = $this->table( 'users' );

		if ( ! $appointments || ! $bookings || ! $users ) {
			return $this->unavailable();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.id, u.firstName AS first_name, u.lastName AS last_name,
						u.email, u.phone,
						COUNT(cb.id) AS bookings_count,
						MAX(a.bookingStart) AS last_booking_at
				 FROM {$bookings} cb
				 INNER JOIN {$appointments} a ON a.id = cb.appointmentId
				 INNER JOIN {$users} u ON u.id = cb.customerId
				 WHERE a.providerId = %d
				 GROUP BY u.id, u.firstName, u.lastName, u.email, u.phone
				 ORDER BY last_booking_at DESC",
				(int) $employee_ref
			),
			ARRAY_A
		);

		if ( null === $rows ) {
			return $this->unavailable();
		}

		foreach ( $rows as &$row ) {
			$row['id']             = (string) $row['id'];
			$row['bookings_count'] = (int) $row['bookings_count'];
		}
		return $rows;
	}

	// ---------------------------------------------------------------
	// Escrituras — pendientes de la verificación en servidor (sección 7)
	// ---------------------------------------------------------------

	public function update_employee_service_pricing( $employee_ref, $service_ref, $price, $capacity = null ) {
		$container = $this->internal_container();
		if ( null !== $container ) {
			/*
			 * TODO(sección 7.2): implementar contra el command handler real
			 * una vez inspeccionado el código premium instalado en el
			 * servidor. No se implementa a ciegas.
			 */
			return $this->unsupported( __METHOD__ );
		}
		return $this->unsupported( __METHOD__ );
	}

	public function create_customer( array $data ) {
		return $this->unsupported( __METHOD__ );
	}

	// ---------------------------------------------------------------
	// Diagnóstico e infraestructura interna del adaptador
	// ---------------------------------------------------------------

	public function diagnostics() {
		$tables = array( 'services', 'categories', 'users', 'providers_to_services', 'appointments', 'customer_bookings' );
		$found  = array();
		foreach ( $tables as $key ) {
			$found[ $key ] = (bool) $this->table( $key );
		}

		return array(
			'provider'            => $this->key(),
			'tables'              => $found,
			'internal_container'  => null !== $this->internal_container(),
			'official_hooks'      => has_filter( 'amelia_get_services_filter' ) !== false || function_exists( 'amelia_container' ),
			'writes_enabled'      => false,
			'pending_server_work' => __( 'Escrituras deshabilitadas hasta verificar los command handlers del contenedor interno de Amelia (documento de arquitectura, sección 7).', 'alb-employee-portal' ),
		);
	}

	/**
	 * Contenedor interno de Amelia si esta instalación lo expone. Se
	 * resuelve por filtro para poder cablearlo desde el servidor real sin
	 * modificar este archivo (y para poder simularlo en pruebas).
	 *
	 * @return object|null
	 */
	private function internal_container() {
		return apply_filters( 'alb_ep_amelia_container', null );
	}

	/**
	 * Nombre completo de una tabla de Amelia si existe y tiene las columnas
	 * esperadas; false si no. Introspección cacheada por request.
	 *
	 * @return string|false
	 */
	private function table( $key ) {
		global $wpdb;

		$expected = array(
			'services'              => array( 'id', 'name', 'price', 'categoryId' ),
			'categories'            => array( 'id', 'name' ),
			'users'                 => array( 'id', 'type', 'firstName', 'lastName', 'email', 'phone' ),
			'providers_to_services' => array( 'userId', 'serviceId' ),
			'appointments'          => array( 'id', 'serviceId', 'providerId', 'bookingStart' ),
			'customer_bookings'     => array( 'id', 'appointmentId', 'customerId', 'price' ),
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

	private function unavailable() {
		return new \WP_Error(
			'alb_ep_provider_unavailable',
			__( 'No se pudo leer del proveedor de reservas. Revisa el diagnóstico en la vista de administrador.', 'alb-employee-portal' ),
			array( 'status' => 503 )
		);
	}

	private function unsupported( $method ) {
		return new \WP_Error(
			'alb_ep_provider_unsupported',
			__( 'Esta operación de escritura aún no está habilitada: falta la verificación en el servidor descrita en la sección 7 del documento de arquitectura.', 'alb-employee-portal' ),
			array(
				'status' => 501,
				'method' => $method,
			)
		);
	}
}
