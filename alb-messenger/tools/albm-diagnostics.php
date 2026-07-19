<?php
/**
 * Plugin Name: ALB Messenger Diagnostics (temporal)
 * Description: Diagnóstico de SOLO LECTURA para verificar la integración M2 de ALB Messenger con Amelia. No escribe nada. ELIMINAR tras la verificación.
 * Version:     1.0.0
 *
 * USO: subir como plugin, activar, ir a Herramientas → ALB Messenger Diag.
 * Muestra: qué hooks de Amelia están registrados, el estado de las tablas
 * albm_*, las conversaciones/eventos/citas provisionados, y el ajuste de
 * creación automática de usuario de cliente. Todo de solo lectura.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_management_page( 'ALB Messenger Diag', 'ALB Messenger Diag', 'manage_options', 'albm-diag', 'albm_diag_render' );
} );

function albm_diag_section( &$out, $title, $fn ) {
	$out[] = "\n## {$title}";
	try {
		$fn( $out );
	} catch ( \Throwable $e ) {
		$out[] = '  [ERROR aislado]: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine();
	}
}

function albm_diag_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Solo administradores.' );
	}
	global $wpdb;
	$out = array( '===== ALB MESSENGER DIAG ' . gmdate( 'Y-m-d H:i' ) . ' UTC =====' );

	albm_diag_section( $out, '1. TABLAS albm_*', function ( &$out ) use ( $wpdb ) {
		foreach ( array( 'conversations', 'participants', 'events', 'appointments', 'messages', 'reads', 'notification_state', 'audit_log', 'employee_map' ) as $t ) {
			$name   = $wpdb->prefix . 'albm_' . $t;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
			$count  = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$name}" ) : 0;
			$out[]  = sprintf( '  %-22s %s (%d filas)', $t, $exists ? 'existe' : 'NO EXISTE', $count );
		}
		$out[] = '  schema_version registrada: ' . get_option( 'albm_schema_version', 'NO' );
	} );

	albm_diag_section( $out, '2. HOOKS DE AMELIA REGISTRADOS (los que escucha el messenger deben aparecer)', function ( &$out ) {
		global $wp_filter;
		$want = array( 'amelia_after_booking_added', 'amelia_after_appointment_added', 'amelia_after_appointment_updated', 'amelia_after_appointment_status_updated', 'amelia_after_booking_canceled', 'amelia_after_appointment_deleted' );
		foreach ( $want as $hook ) {
			$out[] = sprintf( '  %-42s %s', $hook, isset( $wp_filter[ $hook ] ) ? 'REGISTRADO' : 'no registrado (aún)' );
		}
		$out[] = '  --- Todos los hooks amelia_* con listeners en esta carga: ---';
		foreach ( array_keys( (array) $wp_filter ) as $h ) {
			if ( 0 === strpos( $h, 'amelia' ) ) {
				$out[] = '    ' . $h;
			}
		}
	} );

	albm_diag_section( $out, '3. CRON DEL MESSENGER', function ( &$out ) {
		foreach ( array( 'albm_reconcile_incremental', 'albm_reconcile_daily' ) as $h ) {
			$next  = wp_next_scheduled( $h );
			$out[] = sprintf( '  %-30s %s', $h, $next ? 'programado (' . gmdate( 'Y-m-d H:i', $next ) . ' UTC)' : 'NO programado' );
		}
	} );

	albm_diag_section( $out, '4. CONVERSACIONES Y EVENTOS PROVISIONADOS (tras una reserva de prueba deben aparecer)', function ( &$out ) use ( $wpdb ) {
		$conv = $wpdb->prefix . 'albm_conversations';
		$evt  = $wpdb->prefix . 'albm_events';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conv ) ) ) {
			$out[] = '  (tablas no instaladas todavía)';
			return;
		}
		$rows = $wpdb->get_results( "SELECT id, pair_key, status, last_message_at, created_at FROM {$conv} ORDER BY id DESC LIMIT 10", ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$out[] = sprintf( '  conv #%d | %s | estado %s | creada %s', $r['id'], $r['pair_key'], $r['status'], $r['created_at'] );
		}
		$ev = $wpdb->get_results( "SELECT conversation_id, type, ext_ref, created_at FROM {$evt} ORDER BY id DESC LIMIT 15", ARRAY_A );
		$out[] = '  --- últimos eventos ---';
		foreach ( (array) $ev as $r ) {
			$out[] = sprintf( '  conv #%d | %s | ext %s | %s', $r['conversation_id'], $r['type'], $r['ext_ref'], $r['created_at'] );
		}
	} );

	albm_diag_section( $out, '5. AJUSTE: creación automática de usuario de cliente en Amelia (decisión #3)', function ( &$out ) use ( $wpdb ) {
		$users = $wpdb->prefix . 'amelia_users';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $users ) ) ) {
			$out[] = '  tabla de usuarios de Amelia no encontrada';
			return;
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$users} WHERE type = 'customer'" );
		$withwp = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$users} WHERE type = 'customer' AND externalId IS NOT NULL AND externalId > 0" );
		$out[]  = sprintf( '  clientes en Amelia: %d | con usuario WP vinculado (externalId): %d', $total, $withwp );
		$out[]  = '  (si withwp es 0 o muy bajo, el ajuste de cuentas automáticas probablemente está APAGADO)';
	} );

	echo '<div class="wrap"><h1>ALB Messenger Diagnostics</h1>';
	echo '<p>Copia todo y pégaselo a Claude. Luego desactiva y borra este plugin.</p>';
	echo '<textarea readonly style="width:100%;height:70vh;font-family:monospace;font-size:12px" onclick="this.select()">'
		. esc_textarea( implode( "\n", $out ) ) . '</textarea></div>';
}
