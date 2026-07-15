<?php
/**
 * Plugin Name: ALB Amelia Inspector (temporal)
 * Description: Diagnóstico de solo lectura para la sección 0 del checklist de ALB Employee Portal. Genera el informe que necesita el desarrollo para implementar las escrituras hacia Amelia. ELIMINAR tras la inspección.
 * Version:     1.1.0
 *
 * USO (durante la sesión con wp-admin):
 *   1. Subir este archivo único como plugin (o pegarlo vía editor de plugins).
 *   2. Activarlo. Ir a Herramientas → ALB Inspector.
 *   3. Copiar TODO el bloque de texto del informe y pegárselo a Claude.
 *   4. Desactivar y borrar este plugin.
 *
 * Si la página del panel saliera en blanco, hay DOS vías de respaldo que no
 * dependen de la interfaz de administración:
 *   - Modo texto plano:  /wp-admin/tools.php?page=alb-inspector&alb_raw=1
 *   - Archivo en disco:   se escribe una copia en uploads y su ruta se
 *                         muestra al final del informe.
 *
 * Robustez (v1.1.0): cada sección se ejecuta aislada; si una falla, se anota
 * el error en el propio informe y las demás continúan. El informe SIEMPRE se
 * genera. Solo lectura: no escribe en la base de datos ni modifica Amelia.
 * Solo administradores (manage_options).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_management_page( 'ALB Inspector', 'ALB Inspector', 'manage_options', 'alb-inspector', 'alb_inspector_render' );
} );

/**
 * Ejecuta un generador de sección de forma aislada: cualquier excepción o
 * error se convierte en una nota dentro del informe en vez de abortar la
 * página. Esto es lo que faltaba en v1.0.0.
 *
 * @param array    &$out Acumulador de líneas del informe.
 * @param string   $title Título de la sección.
 * @param callable $fn    Genera las líneas; recibe (&$out).
 */
function alb_inspector_section( &$out, $title, $fn ) {
	$out[] = '';
	$out[] = $title;
	try {
		$fn( $out );
	} catch ( \Throwable $e ) {
		$out[] = '  [ERROR en esta sección, omitida sin abortar el informe]: '
			. $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine();
	}
}

/** Construye el informe completo como texto. Nunca lanza. */
function alb_inspector_build_report() {
	global $wpdb;
	$out = array();

	$out[] = '===== ALB AMELIA INSPECTOR v1.1.0 — ' . gmdate( 'Y-m-d H:i' ) . ' UTC =====';

	alb_inspector_section( $out, '## 1. ENTORNO', function ( &$out ) use ( $wpdb ) {
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
	} );

	alb_inspector_section( $out, '## 2. LICENCIA (opciones amelia en wp_options, truncadas)', function ( &$out ) use ( $wpdb ) {
		$options = $wpdb->get_results(
			"SELECT option_name, LEFT(option_value, 400) AS v FROM {$wpdb->options}
			 WHERE option_name LIKE '%amelia%' AND (option_name LIKE '%licen%' OR option_name LIKE '%purchase%' OR option_name LIKE '%envato%' OR option_name LIKE '%settings%')
			 ORDER BY option_name LIMIT 20",
			ARRAY_A
		);
		foreach ( (array) $options as $row ) {
			$out[] = '- ' . $row['option_name'] . ' = ' . $row['v'];
		}
	} );

	alb_inspector_section( $out, '## 3. TABLAS wp_amelia_* (DESCRIBE de las relevantes)', function ( &$out ) use ( $wpdb ) {
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'amelia_%' ) );
		$out[]  = 'Todas: ' . implode( ', ', array_map( function ( $t ) use ( $wpdb ) {
			return str_replace( $wpdb->prefix, '', $t );
		}, (array) $tables ) );
		foreach ( array( 'services', 'categories', 'users', 'providers_to_services', 'appointments', 'customer_bookings' ) as $key ) {
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
	} );

	alb_inspector_section( $out, '## 4. CLASES INTERNAS (command handlers y servicios de aplicación)', function ( &$out ) {
		$src = WP_PLUGIN_DIR . '/ameliabooking/src';
		if ( ! is_dir( $src ) ) {
			$out[] = 'NO se encontró ' . $src;
			return;
		}
		/*
		 * CATCH_GET_CHILD: si una subcarpeta no es legible, el iterador la
		 * SALTA en vez de lanzar UnexpectedValueException (la causa del blanco
		 * en v1.0.0). Aun así todo va dentro del try/catch de la sección.
		 */
		$flags    = \RecursiveDirectoryIterator::SKIP_DOTS;
		$dir      = new \RecursiveDirectoryIterator( $src, $flags );
		$iterator = new \RecursiveIteratorIterator( $dir, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD );
		$hits     = array();
		foreach ( $iterator as $file ) {
			$name = $file->getFilename();
			if ( substr( $name, -4 ) !== '.php' ) {
				continue;
			}
			$path = str_replace( $src . '/', '', $file->getPathname() );
			if ( preg_match( '#(Application/(Commands|Services)|Infrastructure/Repository).*(Service|Provider|Customer|User|Appointment)#i', $path )
				|| preg_match( '#(Add|Update|Create).*(Service|Customer|Provider).*CommandHandler#i', $name ) ) {
				$hits[] = $path;
			}
			if ( count( $hits ) >= 200 ) {
				break;
			}
		}
		sort( $hits );
		$out[] = count( $hits ) . ' archivos relevantes bajo src/:';
		foreach ( $hits as $hit ) {
			$out[] = '  ' . $hit;
		}
	} );

	alb_inspector_section( $out, '## 5. CONTENEDOR / BOOTSTRAP', function ( &$out ) {
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
	} );

	alb_inspector_section( $out, '## 6. HOOKS DE AMELIA REGISTRADOS', function ( &$out ) {
		global $wp_filter;
		$hooks = array();
		foreach ( array_keys( (array) $wp_filter ) as $hook ) {
			if ( 0 === strpos( $hook, 'amelia' ) ) {
				$hooks[] = $hook;
			}
		}
		$out[] = $hooks ? implode( ', ', $hooks ) : '(ninguno de terceros todavía — normal)';
	} );

	alb_inspector_section( $out, '## 7. EMPLEADOS vs USUARIOS WP', function ( &$out ) use ( $wpdb ) {
		$users_table = $wpdb->prefix . 'amelia_users';
		$exists      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $users_table ) );
		if ( ! $exists ) {
			$out[] = 'Tabla de usuarios de Amelia no encontrada.';
			return;
		}
		$providers = $wpdb->get_results(
			"SELECT id, firstName, lastName, status, externalId FROM {$users_table} WHERE type = 'provider' ORDER BY id",
			ARRAY_A
		);
		foreach ( (array) $providers as $p ) {
			$out[] = sprintf( '- Empleado #%d %s %s | estado %s | usuario WP (externalId): %s',
				$p['id'], $p['firstName'], $p['lastName'], $p['status'],
				$p['externalId'] ? '#' . $p['externalId'] : 'NINGUNO' );
		}
	} );

	alb_inspector_section( $out, '## 8. ROLES WP DE AMELIA', function ( &$out ) {
		foreach ( array( 'wpamelia-provider', 'wpamelia-manager', 'wpamelia-customer' ) as $role_key ) {
			$role  = get_role( $role_key );
			$out[] = $role_key . ' → ' . ( $role ? 'existe, caps: ' . implode( ',', array_keys( array_filter( $role->capabilities ) ) ) : 'no existe' );
		}
	} );

	$out[] = '';
	$out[] = '===== FIN DEL INFORME =====';
	return implode( "\n", $out );
}

/**
 * Vía 3 — escribe el informe ya construido a un archivo en uploads y
 * devuelve una nota con la ruta/URL (o el motivo del fallo). Nunca lanza.
 */
function alb_inspector_write_backup( $report ) {
	try {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) || ! wp_is_writable( $upload['basedir'] ) ) {
			return 'Copia en disco: carpeta de uploads no escribible; usa el modo texto plano.';
		}
		$file = trailingslashit( $upload['basedir'] ) . 'alb-inspector-report.txt';
		if ( false === file_put_contents( $file, $report ) ) {
			return 'Copia en disco: no se pudo escribir (permisos).';
		}
		return 'Copia en disco: ' . $file . "\n"
			. 'URL: ' . trailingslashit( $upload['baseurl'] ) . 'alb-inspector-report.txt';
	} catch ( \Throwable $e ) {
		return 'Copia en disco: error — ' . $e->getMessage();
	}
}

function alb_inspector_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Solo administradores.' );
	}

	$report  = alb_inspector_build_report();
	$report .= "\n\n" . alb_inspector_write_backup( $report );

	// Vía 2 — texto plano puro, sin CSS/JS del admin: la más robusta para copiar.
	if ( isset( $_GET['alb_raw'] ) ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $report; // phpcs:ignore WordPress.Security.EscapeOutput -- salida text/plain deliberada.
		exit;
	}

	echo '<div class="wrap"><h1>ALB Amelia Inspector v1.1.0</h1>';
	echo '<p>Copia todo el bloque y pégaselo a Claude. Luego desactiva y borra este plugin.</p>';
	echo '<p>Si el cuadro saliera vacío: <a href="' . esc_url( add_query_arg( 'alb_raw', '1' ) ) . '" target="_blank">abrir el informe en texto plano</a>.</p>';
	echo '<textarea readonly style="width:100%;height:65vh;font-family:monospace;font-size:12px" onclick="this.select()">'
		. esc_textarea( $report )
		. '</textarea></div>';
}
