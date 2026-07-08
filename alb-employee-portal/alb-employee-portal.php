<?php
/**
 * Plugin Name:       ALB Employee Portal
 * Plugin URI:        https://albookings.com
 * Description:       Panel privado por empleado sobre el proveedor de reservas (Amelia): servicios, propuestas, clientes, agenda y estadísticas. Núcleo modular de la plataforma ALB.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            AL Bookings LLC
 * License:           GPL-2.0-or-later
 * Text Domain:       alb-employee-portal
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'ALB_EP_VERSION', '0.1.0' );
define( 'ALB_EP_FILE', __FILE__ );
define( 'ALB_EP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALB_EP_REST_NAMESPACE', 'alb-employee-portal/v1' );

require_once ALB_EP_DIR . 'includes/core/interface-module.php';
require_once ALB_EP_DIR . 'includes/core/class-event-bus.php';
require_once ALB_EP_DIR . 'includes/core/class-audit.php';
require_once ALB_EP_DIR . 'includes/core/class-identity.php';
require_once ALB_EP_DIR . 'includes/core/class-rest-kernel.php';
require_once ALB_EP_DIR . 'includes/core/class-module-registry.php';
require_once ALB_EP_DIR . 'includes/core/class-admin-rest.php';
require_once ALB_EP_DIR . 'includes/gateway/interface-booking-provider.php';
require_once ALB_EP_DIR . 'includes/gateway/class-amelia-provider.php';
require_once ALB_EP_DIR . 'includes/modules/services/class-services-module.php';
require_once ALB_EP_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'ALB_EP\\Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'ALB_EP\\Plugin', 'instance' ) );
