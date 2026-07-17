<?php
/**
 * Desinstalación de ALB Messenger.
 *
 * Las conversaciones son historial del cliente y evidencia ante disputas
 * (decisión de retención: no borrar). La desinstalación NO elimina las
 * tablas albm_*. Una purga opt-in podrá diseñarse cuando el proyecto la
 * necesite; hoy el default seguro es conservar.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
