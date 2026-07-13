<?php
/**
 * Plugin Name: ALB Amelia Inspector (temporal)
 * Description: Diagnóstico de solo lectura para la sección 0 del checklist de ALB Employee Portal. Genera el informe que necesita el desarrollo para implementar las escrituras hacia Amelia. ELIMINAR tras la inspección.
 * Version:     1.0.0
 *
 * USO (durante la sesión con wp-admin):
 *   1. Subir este archivo único como plugin (o pegarlo vía editor de plugins).
 *   2. Activarlo. Ir a Herramientas → ALB Inspector.
 *   3. Copiar TODO el bloque de texto del informe y pegárselo a Claude.
 *   4. Desactivar y borrar este plugin.
 *
 * Solo lectura: no escribe en la base de datos ni modifica Amelia.
 * Solo visible para administradores (manage_options).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_management_page(
		'ALB Inspector',
		'ALB Inspector',
		'manage_options',
		'alb-inspector',
		'alb_inspector_render'
	);
} );

function alb_inspector_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Solo administradores.' );
	}

	global $wpdb;
	$out = array();

	$out[] = '===== ALB AMELIA INSPECTOR — ' . gmdate( 'Y-m-d H:i' ) . ' UTC =====';
	$out[] = '';

	// ---- 1. Entorno ----
	$out[] = '## 1. ENTORNO';
	$out[] = 'PHP: ' . PHP_VERSION;
	$out[] = 'WordPress: ' . get_bloginfo( 'version' );
	$out[] = 'Prefijo de tablas: ' . $wpdb->prefix;

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( get_plugins() as $file => $data ) {
		if ( false !== stripos( $file, 'amelia' ) || false !== stripos( $data['Name'], 'amelia' ) ) {
			$out[] = sprintf( 'Plugin Amelia: %s | versión %s | archivo %s | activo: %s',
				$data['Name'], $data['Version'], $file, is_plugin_active( $file ) ? 'sí' : 'no' );
		}
	}

	// ---- 2. Licencia / edición ----
	$out[] = '';
	$out[] = '## 2. LICENCIA (opciones amelia en wp_options, valores truncados)';
	$options = $wpdb->get_results(
		"SELECT option_name, LEFT(option_value, 400) AS v FROM {$wpdb->options}
		 WHERE option_name LIKE '%amelia%' AND (option_name LIKE '%licen%' OR option_name LIKE '%purchase%' OR option_name LIKE '%envato%' OR option_name LIKE '%settings%')
		 ORDER BY option_name LIMIT 20",
		ARRAY_A
	);
	foreach ( (array) $options as $row ) {
		$out[] = '- ' . $row['option_name'] . ' = ' . $row['v'];
	}

	// ---- 3. Tablas de Amelia ----
	$out[] = '';
	$out[] = '## 3. TABLAS wp_amelia_* (DESCRIBE de las relevantes)';
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'amelia_%' ) );
	$out[]  = 'Todas: ' . implode( ', ', array_map( function ( $t ) use ( $wpdb ) {
		return str_replace( $wpdb->prefix, '', $t );
	}, (array) $tables ) );

	$relevant = array( 'services', 'categories', 'users', 'providers_to_services', 'appointments', 'customer_bookings', 'providers_to_services' );
	foreach ( array_unique( $relevant ) as $key ) {
		$table = $wpdb->prefix . 'amelia_' . $key;
		if ( ! in_array( $table, (array) $tables, true ) ) {
			$out[] = "-- {$key}: NO EXISTE con ese nombre";
			continue;
		}
		$cols  = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );
		$out[] = "-- {$key}: " . implode( ', ', array_map( function ( $c ) {
			return $c['Field'] . ':' . $c['Type'];
		}, (array) $cols ) );
	}

	// ---- 4. Clases internas de Amelia (para las escrituras) ----
	$out[] = '';
	$out[] = '## 4. CLASES INTERNAS (command handlers y servicios de aplicación)';
	$src = WP_PLUGIN_DIR . '/ameliabooking/src';
	if ( ! is_dir( $src ) ) {
		$out[] = 'NO se encontró ' . $src;
	} else {
		$patterns = array(
			'crear cliente'     => array( 'Customer', 'User' ),
			'servicios'         => array( 'Service' ),
			'precio/relación'   => array( 'Provider' ),
		);
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
		$hits     = array();
		foreach ( $iterator as $file ) {
			$name = $file->getFilename();
			if ( substr( $name, -4 ) !== '.php' ) {
				continue;
			}
			$path = str_replace( $src . '/', '', $file->getPathname() );
			// Command handlers, application services y repositorios de las entidades que nos interesan.
			if ( preg_match( '#(Application/(Commands|Services)|Infrastructure/Repository).*(Service|Provider|Customer|User|Appointment)#i', $path )
				|| preg_match( '#(Add|Update|Create).*(Service|Customer|Provider).*CommandHandler#i', $name ) ) {
				$hits[] = $path;
			}
			if ( count( $hits ) >= 120 ) {
				break;
			}
		}
		sort( $hits );
		$out[] = count( $hits ) . ' archivos relevantes bajo src/:';
		foreach ( $hits as $hit ) {
			$out[] = '  ' . $hit;
		}
	}

	// ---- 5. Contenedor interno ----
	$out[] = '';
	$out[] = '## 5. CONTENEDOR / BOOTSTRAP';
	foreach ( array(
		'\AmeliaBooking\Plugin',
		'\AmeliaBooking\Infrastructure\Container',
		'\AmeliaBooking\Infrastructure\Common\Container',
	) as $class ) {
		$out[] = $class . ' → ' . ( class_exists( $class ) ? 'EXISTE' : 'no' );
	}
	foreach ( array( 'amelia_container', 'amelia_get_container' ) as $fn ) {
		$out[] = $fn . '() → ' . ( function_exists( $fn ) ? 'EXISTE' : 'no' );
	}

	// ---- 6. Hooks oficiales activos ----
	$out[] = '';
	$out[] = '## 6. HOOKS DE AMELIA REGISTRADOS EN ESTA INSTALACIÓN';
	global $wp_filter;
	$amelia_hooks = array();
	foreach ( array_keys( (array) $wp_filter ) as $hook ) {
		if ( 0 === strpos( $hook, 'amelia' ) ) {
			$amelia_hooks[] = $hook;
		}
	}
	$out[] = $amelia_hooks ? implode( ', ', $amelia_hooks ) : '(ninguno registrado por terceros todavía — normal)';

	// ---- 7. Empleados y usuarios WP ----
	$out[] = '';
	$out[] = '## 7. EMPLEADOS vs USUARIOS WP (para el mapeo inicial)';
	$users_table = $wpdb->prefix . 'amelia_users';
	if ( in_array( $users_table, (array) $tables, true ) ) {
		$providers = $wpdb->get_results(
			"SELECT id, firstName, lastName, status, externalId FROM {$users_table} WHERE type = 'provider' ORDER BY id",
			ARRAY_A
		);
		foreach ( (array) $providers as $provider ) {
			$out[] = sprintf( '- Empleado #%d %s %s | estado %s | usuario WP vinculado (externalId): %s',
				$provider['id'], $provider['firstName'], $provider['lastName'], $provider['status'],
				$provider['externalId'] ? '#' . $provider['externalId'] : 'NINGUNO' );
		}
	} else {
		$out[] = 'Tabla de usuarios de Amelia no encontrada.';
	}

	// ---- 8. Roles & permissions de Amelia ----
	$out[] = '';
	$out[] = '## 8. ROLES WP DE AMELIA';
	foreach ( array( 'wpamelia-provider', 'wpamelia-manager', 'wpamelia-customer' ) as $role_key ) {
		$role  = get_role( $role_key );
		$out[] = $role_key . ' → ' . ( $role ? 'existe, capacidades: ' . implode( ',', array_keys( array_filter( $role->capabilities ) ) ) : 'no existe' );
	}

	$out[] = '';
	$out[] = '===== FIN DEL INFORME =====';

	echo '<div class="wrap"><h1>ALB Amelia Inspector</h1>';
	echo '<p>Copia todo el bloque y pégaselo a Claude. Luego desactiva y borra este plugin.</p>';
	echo '<textarea readonly style="width:100%;height:70vh;font-family:monospace;font-size:12px" onclick="this.select()">'
		. esc_textarea( implode( "\n", $out ) )
		. '</textarea></div>';
}
