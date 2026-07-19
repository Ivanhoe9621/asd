<?php
/**
 * Plugin Name:       ALB Messenger
 * Plugin URI:        https://albookings.com
 * Description:       Mensajería privada entre clientes y empleados sobre las reservas de Amelia, con supervisión del administrador. Amelia permanece intacta.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            AL Bookings LLC
 * License:           GPL-2.0-or-later
 * Text Domain:       alb-messenger
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'ALBM_VERSION', '0.1.0' );
define( 'ALBM_FILE', __FILE__ );
define( 'ALBM_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALBM_REST_NAMESPACE', 'albm/v1' );

require_once ALBM_DIR . 'includes/class-schema.php';
require_once ALBM_DIR . 'includes/class-identity.php';
require_once ALBM_DIR . 'includes/class-authorization.php';
require_once ALBM_DIR . 'includes/class-rest-kernel.php';
require_once ALBM_DIR . 'includes/class-amelia-reader.php';
require_once ALBM_DIR . 'includes/class-conversation-repository.php';
require_once ALBM_DIR . 'includes/class-appointment-sync.php';
require_once ALBM_DIR . 'includes/class-amelia-adapter.php';
require_once ALBM_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'ALBM\\Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'ALBM\\Plugin', 'instance' ) );
