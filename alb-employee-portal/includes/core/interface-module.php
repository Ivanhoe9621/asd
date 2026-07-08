<?php
namespace ALB_EP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Contrato que debe cumplir todo módulo de la plataforma (requisito
 * permanente 5): los módulos solo exponen esta interfaz al núcleo y se
 * comunican entre sí únicamente a través del Event Bus.
 */
interface Module {

	/** Clave única del módulo (ej. 'services'). */
	public function key();

	/** Versión de esquema que este código espera (ej. '1.0.0'). */
	public function schema_version();

	/**
	 * Migra las tablas propias del módulo desde $from (null = instalación
	 * nueva) hasta schema_version(). Debe ser idempotente y sin pérdida de
	 * datos (requisito permanente 4).
	 *
	 * @param string|null $from Versión instalada actualmente.
	 */
	public function migrate( $from );

	/** Registra las rutas REST del módulo a través del kernel. */
	public function register_routes( Rest_Kernel $rest );

	/** Suscripciones del módulo al Event Bus. */
	public function boot( Event_Bus $bus );
}
