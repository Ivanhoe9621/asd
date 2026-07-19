<?php
/**
 * Desinstalación de ALB Employee Portal.
 *
 * Comportamiento (patrón WooCommerce):
 *
 * - POR DEFECTO no se elimina NINGÚN dato: las tablas alb_ep_* (metadatos
 *   de servicios, propuestas, auditoría, mapeo de empleados) y las opciones
 *   del plugin sobreviven a la desinstalación, de modo que reinstalar
 *   recupera todo tal cual estaba.
 *
 * - Si el administrador define en wp-config.php:
 *
 *       define( 'ALB_EP_UNINSTALL_DROP_DATA', true );
 *
 *   la desinstalación elimina TODO lo propio del plugin: sus tablas, sus
 *   opciones, sus transients y sus eventos programados.
 *
 * Nunca se toca nada de Amelia, de WordPress ni de otros plugins: las
 * tablas se eliminan por lista explícita (no por patrón), y las opciones/
 * transients por el prefijo propio alb_ep_. Las imágenes que los empleados
 * hayan subido a la biblioteca de medios son contenido de WordPress y no se
 * eliminan.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'ALB_EP_UNINSTALL_DROP_DATA' ) || true !== ALB_EP_UNINSTALL_DROP_DATA ) {
	// Default seguro: conservar todos los datos.
	return;
}

global $wpdb;

/*
 * 1. Tablas propias — lista explícita y cerrada. Si un módulo futuro añade
 *    una tabla, debe añadirla también aquí.
 */
$alb_ep_tables = array(
	$wpdb->prefix . 'alb_ep_modules',
	$wpdb->prefix . 'alb_ep_employee_map',
	$wpdb->prefix . 'alb_ep_audit_log',
	$wpdb->prefix . 'alb_ep_service_employee_meta',
	$wpdb->prefix . 'alb_ep_service_requests',
);

foreach ( $alb_ep_tables as $alb_ep_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$alb_ep_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL -- nombre de lista cerrada propia.
}

/*
 * 2. Opciones y transients propios. El prefijo alb_ep_ es exclusivo de este
 *    plugin; el guion bajo va escapado para que LIKE no haga comodín.
 */
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'alb\\_ep\\_%'
	    OR option_name LIKE '\\_transient\\_alb\\_ep\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_alb\\_ep\\_%'
	    OR option_name LIKE '\\_site\\_transient\\_alb\\_ep\\_%'
	    OR option_name LIKE '\\_site\\_transient\\_timeout\\_alb\\_ep\\_%'"
);

/*
 * 3. Eventos programados propios (hoy el plugin no registra ninguno; esto
 *    cubre los que añadan módulos futuros bajo el prefijo alb_ep_).
 */
$alb_ep_cron = _get_cron_array();
if ( is_array( $alb_ep_cron ) ) {
	foreach ( $alb_ep_cron as $alb_ep_timestamp => $alb_ep_hooks ) {
		foreach ( array_keys( (array) $alb_ep_hooks ) as $alb_ep_hook ) {
			if ( 0 === strpos( (string) $alb_ep_hook, 'alb_ep_' ) ) {
				wp_unschedule_hook( $alb_ep_hook );
			}
		}
	}
}

wp_cache_flush();
