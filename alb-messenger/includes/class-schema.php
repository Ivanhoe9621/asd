<?php
namespace ALBM;

defined( 'ABSPATH' ) || exit;

/**
 * Esquema y migraciones versionadas de ALB Messenger (diseño v1.1, §1.2).
 *
 * La comparación rápida usa una opción autoload: en una carga normal de
 * página no se ejecuta NINGUNA consulta de migración. Solo cuando la versión
 * de esquema del código difiere de la instalada se corre dbDelta, que es
 * idempotente y nunca destruye datos. Los estados y roles se almacenan como
 * varchar (no enum) para que dbDelta los gestione de forma fiable.
 */
class Schema {

	const VERSION = '1.0.0';
	const OPTION  = 'albm_schema_version';

	/** Nombres cortos de las 9 tablas de la 1.0 (diseño §1.2). */
	const TABLES = array(
		'conversations',
		'participants',
		'events',
		'appointments',
		'messages',
		'reads',
		'notification_state',
		'audit_log',
		'employee_map',
	);

	/** Nombre completo de una tabla propia. */
	public static function table( $key ) {
		global $wpdb;
		return $wpdb->prefix . 'albm_' . $key;
	}

	/** Ejecuta las migraciones solo si hacen falta. */
	public static function migrate() {
		if ( get_option( self::OPTION ) === self::VERSION ) {
			return;
		}
		self::install();
		update_option( self::OPTION, self::VERSION, true );
	}

	private static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta( 'CREATE TABLE ' . self::table( 'conversations' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(32) NOT NULL DEFAULT 'amelia',
			pair_key varchar(160) NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'active',
			override_until datetime DEFAULT NULL,
			last_message_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY pair_key (pair_key),
			KEY status_last (status,last_message_at)
		) {$charset};" );

		dbDelta( 'CREATE TABLE ' . self::table( 'participants' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL,
			role varchar(16) NOT NULL,
			provider_ref varchar(64) DEFAULT NULL,
			joined_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY conv_user (conversation_id,wp_user_id),
			KEY user (wp_user_id)
		) {$charset};" );

		dbDelta( 'CREATE TABLE ' . self::table( 'events' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			type varchar(40) NOT NULL,
			ext_ref varchar(64) DEFAULT NULL,
			payload longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conv_id (conversation_id,id),
			KEY type_ref (type,ext_ref)
		) {$charset};" );

		dbDelta( 'CREATE TABLE ' . self::table( 'appointments' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			ext_appointment_id varchar(64) NOT NULL,
			service_name varchar(255) NOT NULL DEFAULT '',
			starts_at datetime NOT NULL,
			ends_at datetime NOT NULL,
			status varchar(32) NOT NULL DEFAULT '',
			writable_until datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ext_appointment (ext_appointment_id),
			KEY conv_writable (conversation_id,writable_until)
		) {$charset};" );

		dbDelta( 'CREATE TABLE ' . self::table( 'messages' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			appointment_id bigint(20) unsigned DEFAULT NULL,
			sender_wp_id bigint(20) unsigned NOT NULL,
			sender_role varchar(16) NOT NULL,
			type varchar(16) NOT NULL DEFAULT 'text',
			body longtext NOT NULL,
			payload longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			deleted_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY conv_id (conversation_id,id),
			KEY appointment (appointment_id)
		) {$charset};" );

		dbDelta( 'CREATE TABLE ' . self::table( 'reads' ) . " (
			conversation_id bigint(20) unsigned NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL,
			last_read_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (conversation_id,wp_user_id)
		) {$charset};" );

		dbDelta( 'CREATE TABLE ' . self::table( 'notification_state' ) . " (
			conversation_id bigint(20) unsigned NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL,
			pending_since datetime DEFAULT NULL,
			last_email_at datetime DEFAULT NULL,
			PRIMARY KEY  (conversation_id,wp_user_id)
		) {$charset};" );

		dbDelta( 'CREATE TABLE ' . self::table( 'audit_log' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			admin_wp_id bigint(20) unsigned NOT NULL,
			conversation_id bigint(20) unsigned NOT NULL,
			action varchar(16) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation (conversation_id),
			KEY admin_time (admin_wp_id,created_at)
		) {$charset};" );

		dbDelta( 'CREATE TABLE ' . self::table( 'employee_map' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned NOT NULL,
			provider varchar(32) NOT NULL DEFAULT 'amelia',
			ext_employee_id varchar(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wp_user (wp_user_id),
			KEY employee (provider,ext_employee_id)
		) {$charset};" );
	}
}
