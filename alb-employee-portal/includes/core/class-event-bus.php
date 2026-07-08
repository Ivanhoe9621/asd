<?php
namespace ALB_EP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Bus de eventos de la plataforma. Envuelve los hooks de WordPress con el
 * prefijo propio para que los eventos queden documentados en un solo lugar
 * y los módulos nunca se enganchen a internals de otro módulo.
 */
class Event_Bus {

	const PREFIX = 'alb_ep_';

	/**
	 * Emite un evento de la plataforma.
	 *
	 * @param string $event   Nombre sin prefijo (ej. 'service_meta_updated').
	 * @param array  $payload Datos del evento.
	 */
	public function emit( $event, array $payload = array() ) {
		do_action( self::PREFIX . $event, $payload );
	}

	/**
	 * Suscribe un callback a un evento de la plataforma.
	 *
	 * @param string   $event    Nombre sin prefijo.
	 * @param callable $callback Recibe el payload del evento.
	 * @param int      $priority Prioridad estándar de WordPress.
	 */
	public function on( $event, $callback, $priority = 10 ) {
		add_action( self::PREFIX . $event, $callback, $priority, 1 );
	}
}
