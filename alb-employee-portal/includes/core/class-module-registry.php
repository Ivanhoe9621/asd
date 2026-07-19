<?php
namespace ALB_EP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registro de módulos y motor de migraciones (requisito permanente 4).
 * Guarda la versión de esquema instalada de cada módulo (y del núcleo) en
 * alb_ep_modules y ejecuta migraciones incrementales idempotentes cuando el
 * código espera una versión más nueva.
 */
class Module_Registry {

	const CORE_KEY            = 'core';
	const CORE_SCHEMA_VERSION = '1.0.0';

	/** @var Module[] */
	private $modules = array();

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'alb_ep_modules';
	}

	public function register( Module $module ) {
		$this->modules[ $module->key() ] = $module;
	}

	/** @return Module[] Módulos registrados y activos. */
	public function active() {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT module_key, active FROM ' . self::table(), OBJECT_K );

		$active = array();
		foreach ( $this->modules as $key => $module ) {
			// Un módulo registrado sin fila todavía (primera carga) se trata como activo.
			if ( ! isset( $rows[ $key ] ) || (int) $rows[ $key ]->active ) {
				$active[ $key ] = $module;
			}
		}
		return $active;
	}

	/**
	 * Ejecuta las migraciones pendientes del núcleo y de cada módulo.
	 *
	 * La comparación rápida usa una opción autoload (cero consultas extra):
	 * solo cuando el mapa de versiones esperado difiere del guardado se toca
	 * la tabla de módulos y se corre dbDelta. Sin esta puerta, cada carga de
	 * página del sitio pagaría SHOW TABLES + un SELECT por módulo.
	 */
	public function migrate() {
		$expected = array( self::CORE_KEY => self::CORE_SCHEMA_VERSION );
		foreach ( $this->modules as $module ) {
			$expected[ $module->key() ] = $module->schema_version();
		}

		if ( get_option( 'alb_ep_schema_versions' ) === $expected ) {
			return;
		}

		if ( $this->core_needs_migration() ) {
			$this->migrate_core();
		}

		foreach ( $this->modules as $module ) {
			$installed = $this->installed_version( $module->key() );
			if ( $installed === $module->schema_version() ) {
				continue;
			}
			$module->migrate( $installed );
			$this->record_version( $module->key(), $module->schema_version() );
		}

		update_option( 'alb_ep_schema_versions', $expected, true );
	}

	private function core_needs_migration() {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) );
		if ( ! $exists ) {
			return true;
		}
		return $this->installed_version( self::CORE_KEY ) !== self::CORE_SCHEMA_VERSION;
	}

	private function migrate_core() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$modules = self::table();
		$map     = Identity::table();
		$audit   = Audit::table();

		// dbDelta es idempotente: crea o ajusta sin destruir datos.
		dbDelta( "CREATE TABLE {$modules} (
			module_key varchar(64) NOT NULL,
			schema_version varchar(20) NOT NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (module_key)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$map} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned NOT NULL,
			provider varchar(32) NOT NULL DEFAULT 'amelia',
			ext_employee_id varchar(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wp_user (wp_user_id),
			KEY employee (provider,ext_employee_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$audit} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_wp_user_id bigint(20) unsigned NOT NULL,
			ext_employee_id varchar(64) DEFAULT NULL,
			action varchar(64) NOT NULL,
			entity_type varchar(64) NOT NULL,
			entity_ref varchar(64) NOT NULL,
			before_state longtext DEFAULT NULL,
			after_state longtext DEFAULT NULL,
			origin varchar(16) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY action (action),
			KEY employee (ext_employee_id),
			KEY created (created_at)
		) {$charset};" );

		$this->record_version( self::CORE_KEY, self::CORE_SCHEMA_VERSION );
	}

	/** @return string|null */
	private function installed_version( $key ) {
		global $wpdb;
		$version = $wpdb->get_var(
			$wpdb->prepare( 'SELECT schema_version FROM ' . self::table() . ' WHERE module_key = %s', $key )
		);
		return $version ? (string) $version : null;
	}

	private function record_version( $key, $version ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (module_key, schema_version, active, updated_at)
				 VALUES (%s, %s, 1, %s)
				 ON DUPLICATE KEY UPDATE schema_version = VALUES(schema_version), updated_at = VALUES(updated_at)',
				$key,
				$version,
				current_time( 'mysql', true )
			)
		);
	}
}
