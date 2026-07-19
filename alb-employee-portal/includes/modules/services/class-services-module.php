<?php
namespace ALB_EP\Modules\Services;

use ALB_EP\Core;
use ALB_EP\Gateway\Booking_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Módulo Services: catálogo único del proveedor + capa de configuración por
 * empleado (descripción corta, imagen, visibilidad) + cola de propuestas de
 * servicios nuevos con aprobación del administrador.
 *
 * El precio/capacidad por empleado NO vive aquí: es funcionalidad nativa del
 * proveedor y se escribe a través del Gateway.
 */
class Services_Module implements Core\Module {

	const SCHEMA_VERSION = '1.0.0';

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';
	const STATUS_LINKED   = 'linked';

	/** Tope de la columna decimal(10,2) — un precio mayor rompería el insert. */
	const MAX_PRICE = 99999999.99;

	/** Transiciones de estado válidas para una propuesta. */
	const TRANSITIONS = array(
		self::STATUS_PENDING  => array( self::STATUS_APPROVED, self::STATUS_REJECTED ),
		self::STATUS_APPROVED => array( self::STATUS_LINKED, self::STATUS_REJECTED ),
		self::STATUS_REJECTED => array(),
		self::STATUS_LINKED   => array(),
	);

	/** @var Booking_Provider */
	private $gateway;

	/** @var Core\Identity */
	private $identity;

	/** @var Core\Audit */
	private $audit;

	/** @var Core\Event_Bus */
	private $bus;

	public function __construct( Booking_Provider $gateway, Core\Identity $identity, Core\Audit $audit, Core\Event_Bus $bus ) {
		$this->gateway  = $gateway;
		$this->identity = $identity;
		$this->audit    = $audit;
		$this->bus      = $bus;
	}

	public function key() {
		return 'services';
	}

	public function schema_version() {
		return self::SCHEMA_VERSION;
	}

	public static function meta_table() {
		global $wpdb;
		return $wpdb->prefix . 'alb_ep_service_employee_meta';
	}

	public static function requests_table() {
		global $wpdb;
		return $wpdb->prefix . 'alb_ep_service_requests';
	}

	public function migrate( $from ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$meta     = self::meta_table();
		$requests = self::requests_table();

		dbDelta( "CREATE TABLE {$meta} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(32) NOT NULL DEFAULT 'amelia',
			ext_service_id varchar(64) NOT NULL,
			ext_employee_id varchar(64) NOT NULL,
			short_description text DEFAULT NULL,
			image_id bigint(20) unsigned DEFAULT NULL,
			visible tinyint(1) NOT NULL DEFAULT 1,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY service_employee (provider,ext_service_id,ext_employee_id),
			KEY employee (provider,ext_employee_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$requests} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(32) NOT NULL DEFAULT 'amelia',
			ext_employee_id varchar(64) NOT NULL,
			proposed_name varchar(255) NOT NULL,
			proposed_description text DEFAULT NULL,
			proposed_price decimal(10,2) DEFAULT NULL,
			proposed_duration int(11) DEFAULT NULL,
			proposed_category_id varchar(64) DEFAULT NULL,
			proposed_image_id bigint(20) unsigned DEFAULT NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			ext_service_id_result varchar(64) DEFAULT NULL,
			admin_note text DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY employee (provider,ext_employee_id),
			KEY status (status)
		) {$charset};" );
	}

	public function boot( Core\Event_Bus $bus ) {
		// Sin suscripciones propias por ahora; otros módulos escucharán los eventos que este emite.
	}

	public function register_routes( Core\Rest_Kernel $rest ) {
		$rest->route( '/services', 'GET', array( $this, 'list_services' ) );
		$rest->route( '/categories', 'GET', array( $this, 'list_categories' ) );
		$rest->route( '/services/(?P<id>[\w-]+)/meta', 'PUT', array( $this, 'update_meta' ) );
		$rest->route( '/services/(?P<id>[\w-]+)/pricing', 'PUT', array( $this, 'update_pricing' ) );
		$rest->route( '/service-requests', 'GET', array( $this, 'list_requests' ) );
		$rest->route( '/service-requests', 'POST', array( $this, 'create_request' ) );
		$rest->route( '/admin/service-requests', 'GET', array( $this, 'admin_list_requests' ), Core\Rest_Kernel::ACCESS_ADMIN );
		$rest->route( '/admin/service-requests/(?P<id>\d+)', 'PUT', array( $this, 'admin_update_request' ), Core\Rest_Kernel::ACCESS_ADMIN );
	}

	// ---------------------------------------------------------------
	// Servicios del empleado (catálogo del proveedor + meta propia)
	// ---------------------------------------------------------------

	public function list_services( \WP_REST_Request $request, $employee_ref ) {
		$services = $this->gateway->get_services_for_employee( $employee_ref );
		if ( is_wp_error( $services ) ) {
			return $services;
		}

		$meta = $this->meta_for_employee( $employee_ref );

		foreach ( $services as &$service ) {
			$m = isset( $meta[ $service['id'] ] ) ? $meta[ $service['id'] ] : null;

			$service['portal_meta'] = array(
				'short_description' => $m ? $m['short_description'] : null,
				'image_id'          => $m && $m['image_id'] ? (int) $m['image_id'] : null,
				'image_url'         => $m && $m['image_id'] ? wp_get_attachment_image_url( (int) $m['image_id'], 'large' ) : null,
				'visible'           => $m ? (bool) $m['visible'] : true,
			);
		}

		return array( 'items' => $services );
	}

	public function list_categories( \WP_REST_Request $request, $employee_ref ) {
		$categories = $this->gateway->get_categories();
		if ( is_wp_error( $categories ) ) {
			return $categories;
		}
		return array( 'items' => $categories );
	}

	public function update_meta( \WP_REST_Request $request, $employee_ref ) {
		global $wpdb;

		$service_ref = (string) $request['id'];

		$owns = $this->require_owned_service( $employee_ref, $service_ref );
		if ( is_wp_error( $owns ) ) {
			return $owns;
		}

		$before = $this->meta_row( $employee_ref, $service_ref );

		$data = array(
			'short_description' => $before ? $before['short_description'] : null,
			'image_id'          => $before && $before['image_id'] ? (int) $before['image_id'] : null,
			'visible'           => $before ? (int) $before['visible'] : 1,
		);

		if ( $request->has_param( 'short_description' ) ) {
			$data['short_description'] = sanitize_textarea_field( (string) $request['short_description'] );
		}
		if ( $request->has_param( 'image_id' ) ) {
			$image_id = absint( $request['image_id'] );
			if ( $image_id ) {
				$error = $this->validate_image( $image_id );
				if ( is_wp_error( $error ) ) {
					return $error;
				}
			}
			$data['image_id'] = $image_id ? $image_id : null;
		}
		if ( $request->has_param( 'visible' ) ) {
			$data['visible'] = rest_sanitize_boolean( $request['visible'] ) ? 1 : 0;
		}

		$data['updated_at'] = current_time( 'mysql', true );

		// insert()/update() de wpdb sí escriben NULL real en las columnas
		// nullable (el upsert preparado con %d convertía NULL en 0).
		if ( $before ) {
			$written = $wpdb->update( self::meta_table(), $data, array( 'id' => $before['id'] ) );
		} else {
			$written = $wpdb->insert( self::meta_table(), $data + array(
				'provider'        => $this->gateway->key(),
				'ext_service_id'  => $service_ref,
				'ext_employee_id' => $employee_ref,
			) );
		}

		if ( false === $written ) {
			return new \WP_Error(
				'alb_ep_db_error',
				__( 'No se pudo guardar el cambio. Intenta de nuevo.', 'alb-employee-portal' ),
				array( 'status' => 500 )
			);
		}

		$after = $this->meta_row( $employee_ref, $service_ref );

		$this->audit->log( 'service_meta.update', 'service', $service_ref, $before, $after, Core\Audit::ORIGIN_PORTAL, $employee_ref );
		$this->bus->emit( 'service_meta_updated', array(
			'employee_ref' => $employee_ref,
			'service_ref'  => $service_ref,
		) );

		return array( 'meta' => $after );
	}

	public function update_pricing( \WP_REST_Request $request, $employee_ref ) {
		$service_ref = (string) $request['id'];

		$owns = $this->require_owned_service( $employee_ref, $service_ref );
		if ( is_wp_error( $owns ) ) {
			return $owns;
		}

		$price = $request->get_param( 'price' );
		if ( null === $price || ! is_numeric( $price ) || (float) $price < 0 || (float) $price > self::MAX_PRICE ) {
			return new \WP_Error(
				'alb_ep_invalid_price',
				__( 'Indica un precio válido.', 'alb-employee-portal' ),
				array( 'status' => 400 )
			);
		}

		$capacity = null;
		if ( $request->has_param( 'min_capacity' ) || $request->has_param( 'max_capacity' ) ) {
			$capacity = array(
				'min' => max( 1, absint( $request->get_param( 'min_capacity' ) ) ),
				'max' => max( 1, absint( $request->get_param( 'max_capacity' ) ) ),
			);
		}

		$result = $this->gateway->update_employee_service_pricing( $employee_ref, $service_ref, (float) $price, $capacity );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit->log(
			'service_pricing.update',
			'service',
			$service_ref,
			null,
			array( 'price' => (float) $price, 'capacity' => $capacity ),
			Core\Audit::ORIGIN_PORTAL,
			$employee_ref
		);
		$this->bus->emit( 'pricing_updated', array(
			'employee_ref' => $employee_ref,
			'service_ref'  => $service_ref,
			'price'        => (float) $price,
		) );

		return array( 'updated' => true );
	}

	// ---------------------------------------------------------------
	// Propuestas de servicios nuevos (flujo propuesta → aprobación)
	// ---------------------------------------------------------------

	public function list_requests( \WP_REST_Request $request, $employee_ref ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::requests_table() . ' WHERE provider = %s AND ext_employee_id = %s ORDER BY id DESC',
				$this->gateway->key(),
				$employee_ref
			),
			ARRAY_A
		);

		return array( 'items' => $rows ? $rows : array() );
	}

	public function create_request( \WP_REST_Request $request, $employee_ref ) {
		global $wpdb;

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return new \WP_Error(
				'alb_ep_invalid_request',
				__( 'El nombre del servicio propuesto es obligatorio.', 'alb-employee-portal' ),
				array( 'status' => 400 )
			);
		}

		$price = $request->get_param( 'price' );
		if ( null !== $price && '' !== $price ) {
			if ( ! is_numeric( $price ) || (float) $price < 0 || (float) $price > self::MAX_PRICE ) {
				return new \WP_Error(
					'alb_ep_invalid_price',
					__( 'Indica un precio válido.', 'alb-employee-portal' ),
					array( 'status' => 400 )
				);
			}
			$price = (float) $price;
		} else {
			$price = null;
		}

		$row = array(
			'provider'             => $this->gateway->key(),
			'ext_employee_id'      => $employee_ref,
			'proposed_name'        => $name,
			'proposed_description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			'proposed_price'       => $price,
			'proposed_duration'    => absint( $request->get_param( 'duration' ) ) ?: null,
			'proposed_category_id' => sanitize_text_field( (string) $request->get_param( 'category_id' ) ),
			'proposed_image_id'    => absint( $request->get_param( 'image_id' ) ) ?: null,
			'status'               => self::STATUS_PENDING,
			'created_at'           => current_time( 'mysql', true ),
		);

		if ( false === $wpdb->insert( self::requests_table(), $row ) ) {
			return new \WP_Error(
				'alb_ep_db_error',
				__( 'No se pudo guardar la propuesta. Intenta de nuevo.', 'alb-employee-portal' ),
				array( 'status' => 500 )
			);
		}
		$id = (int) $wpdb->insert_id;

		$this->audit->log( 'service_request.create', 'service_request', $id, null, $row, Core\Audit::ORIGIN_PORTAL, $employee_ref );
		$this->bus->emit( 'service_request_created', array(
			'request_id'   => $id,
			'employee_ref' => $employee_ref,
		) );

		return array( 'id' => $id, 'status' => self::STATUS_PENDING );
	}

	public function admin_list_requests( \WP_REST_Request $request, $employee_ref ) {
		global $wpdb;

		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$table  = self::requests_table();

		if ( '' !== $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC", $status ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
		}

		return array( 'items' => $rows ? $rows : array() );
	}

	public function admin_update_request( \WP_REST_Request $request, $employee_ref ) {
		global $wpdb;

		$id     = absint( $request['id'] );
		$before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::requests_table() . ' WHERE id = %d', $id ), ARRAY_A );

		if ( ! $before ) {
			return new \WP_Error(
				'alb_ep_not_found',
				__( 'La propuesta no existe.', 'alb-employee-portal' ),
				array( 'status' => 404 )
			);
		}

		$status  = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$allowed = isset( self::TRANSITIONS[ $before['status'] ] ) ? self::TRANSITIONS[ $before['status'] ] : array();
		if ( ! in_array( $status, $allowed, true ) ) {
			return new \WP_Error(
				'alb_ep_invalid_transition',
				sprintf(
					/* translators: 1: estado actual, 2: estados permitidos */
					__( 'Transición no válida desde "%1$s". Permitidas: %2$s.', 'alb-employee-portal' ),
					$before['status'],
					$allowed ? implode( ', ', $allowed ) : __( 'ninguna (estado final)', 'alb-employee-portal' )
				),
				array( 'status' => 409 )
			);
		}

		$update = array( 'status' => $status );

		if ( $request->has_param( 'admin_note' ) ) {
			$update['admin_note'] = sanitize_textarea_field( (string) $request['admin_note'] );
		}
		if ( self::STATUS_LINKED === $status ) {
			$result_ref = sanitize_text_field( (string) $request->get_param( 'ext_service_id' ) );
			if ( '' === $result_ref ) {
				return new \WP_Error(
					'alb_ep_invalid_request',
					__( 'Para vincular indica el ID del servicio creado en el proveedor (ext_service_id).', 'alb-employee-portal' ),
					array( 'status' => 400 )
				);
			}
			$update['ext_service_id_result'] = $result_ref;
		}

		if ( false === $wpdb->update( self::requests_table(), $update, array( 'id' => $id ) ) ) {
			return new \WP_Error(
				'alb_ep_db_error',
				__( 'No se pudo actualizar la propuesta. Intenta de nuevo.', 'alb-employee-portal' ),
				array( 'status' => 500 )
			);
		}
		$after = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::requests_table() . ' WHERE id = %d', $id ), ARRAY_A );

		$this->audit->log( 'service_request.' . $status, 'service_request', $id, $before, $after, Core\Audit::ORIGIN_ADMIN, $before['ext_employee_id'] );
		$this->bus->emit( 'service_request_' . $status, array(
			'request_id'   => $id,
			'employee_ref' => $before['ext_employee_id'],
		) );

		return array( 'request' => $after );
	}

	// ---------------------------------------------------------------

	/**
	 * Verifica en el proveedor que el servicio esté asignado al empleado.
	 * Único punto de esta autorización — la comparten meta y pricing.
	 *
	 * @return true|\WP_Error
	 */
	private function require_owned_service( $employee_ref, $service_ref ) {
		$owns = $this->gateway->employee_offers_service( $employee_ref, $service_ref );
		if ( is_wp_error( $owns ) ) {
			return $owns;
		}
		if ( ! $owns ) {
			return new \WP_Error(
				'alb_ep_forbidden',
				__( 'Este servicio no está asignado a tu perfil.', 'alb-employee-portal' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Un attachment solo es utilizable como imagen del portal si es una
	 * imagen real y pertenece al usuario actual (o el actor es admin):
	 * sin este control, un empleado podría publicar —y así descubrir la
	 * URL de— cualquier archivo de la biblioteca de medios.
	 *
	 * @return true|\WP_Error
	 */
	private function validate_image( $image_id ) {
		if ( 'attachment' !== get_post_type( $image_id ) || ! wp_attachment_is_image( $image_id ) ) {
			return new \WP_Error(
				'alb_ep_invalid_image',
				__( 'La imagen indicada no existe en la biblioteca de medios o no es una imagen.', 'alb-employee-portal' ),
				array( 'status' => 400 )
			);
		}

		$owner = (int) get_post_field( 'post_author', $image_id );
		if ( $owner !== get_current_user_id() && ! $this->identity->is_platform_admin() ) {
			return new \WP_Error(
				'alb_ep_forbidden',
				__( 'Solo puedes usar imágenes subidas por ti.', 'alb-employee-portal' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/** @return array<string,array> Meta indexada por ext_service_id. */
	private function meta_for_employee( $employee_ref ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::meta_table() . ' WHERE provider = %s AND ext_employee_id = %s',
				$this->gateway->key(),
				$employee_ref
			),
			ARRAY_A
		);

		$indexed = array();
		foreach ( (array) $rows as $row ) {
			$indexed[ $row['ext_service_id'] ] = $row;
		}
		return $indexed;
	}

	/** @return array|null */
	private function meta_row( $employee_ref, $service_ref ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::meta_table() . ' WHERE provider = %s AND ext_employee_id = %s AND ext_service_id = %s',
				$this->gateway->key(),
				$employee_ref,
				$service_ref
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}
}
