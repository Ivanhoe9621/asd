<?php
namespace ALB_EP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Servicio de auditoría (requisito permanente 6). Todo cambio importante se
 * registra con actor, acción, entidad, estado antes/después y origen.
 */
class Audit {

	const ORIGIN_PORTAL = 'portal';
	const ORIGIN_ADMIN  = 'admin';
	const ORIGIN_API    = 'api';
	const ORIGIN_SYSTEM = 'system';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'alb_ep_audit_log';
	}

	/**
	 * Registra una entrada de auditoría.
	 *
	 * @param string      $action       Acción en formato entidad.verbo (ej. 'service_meta.update').
	 * @param string      $entity_type  Tipo de entidad afectada.
	 * @param string      $entity_ref   Referencia de la entidad (ID propio o externo).
	 * @param mixed       $before       Estado anterior (se serializa a JSON) o null.
	 * @param mixed       $after        Estado posterior (se serializa a JSON) o null.
	 * @param string      $origin       Uno de los ORIGIN_*.
	 * @param string|null $employee_ref Empleado asociado, si aplica.
	 */
	public function log( $action, $entity_type, $entity_ref, $before, $after, $origin, $employee_ref = null ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'actor_wp_user_id' => get_current_user_id(),
				'ext_employee_id'  => $employee_ref,
				'action'           => $action,
				'entity_type'      => $entity_type,
				'entity_ref'       => (string) $entity_ref,
				'before_state'     => null === $before ? null : wp_json_encode( $before ),
				'after_state'      => null === $after ? null : wp_json_encode( $after ),
				'origin'           => $origin,
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Lista paginada del registro, para la vista de admin.
	 *
	 * @return array{items: array, total: int}
	 */
	public function query( $page = 1, $per_page = 50, array $filters = array() ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = $filters['action'];
		}
		if ( ! empty( $filters['employee_ref'] ) ) {
			$where[]  = 'ext_employee_id = %s';
			$params[] = $filters['employee_ref'];
		}

		$where_sql = implode( ' AND ', $where );
		$table     = self::table();

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$offset   = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		$params[] = (int) $per_page;
		$params[] = $offset;

		$items = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", $params ),
			ARRAY_A
		);

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}
}
