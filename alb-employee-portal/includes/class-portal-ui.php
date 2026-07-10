<?php
namespace ALB_EP;

defined( 'ABSPATH' ) || exit;

/**
 * Interfaz del portal: shortcode [alb_employee_portal] + assets. Los assets
 * solo se encolan en páginas que usan el shortcode. Toda la lógica vive en
 * el frontend contra la API REST; esta clase no toca datos.
 */
class Portal_UI {

	const SHORTCODE = 'alb_employee_portal';

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		$base = plugin_dir_url( ALB_EP_FILE );

		wp_register_style( 'alb-ep-portal', $base . 'assets/portal.css', array(), ALB_EP_VERSION );
		wp_register_script( 'alb-ep-portal', $base . 'assets/portal.js', array(), ALB_EP_VERSION, true );
	}

	public function render() {
		if ( ! is_user_logged_in() ) {
			return '<div class="alb-ep-guest">'
				. '<p>' . esc_html__( 'Inicia sesión para acceder a tu portal de empleado.', 'alb-employee-portal' ) . '</p>'
				. '<a class="alb-ep-btn alb-ep-btn--primary" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">'
				. esc_html__( 'Iniciar sesión', 'alb-employee-portal' ) . '</a>'
				. '</div>';
		}

		wp_enqueue_style( 'alb-ep-portal' );
		wp_enqueue_script( 'alb-ep-portal' );

		wp_localize_script( 'alb-ep-portal', 'ALB_EP_CONFIG', array(
			'root'  => esc_url_raw( rest_url( ALB_EP_REST_NAMESPACE ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'i18n'  => array(
				'services'        => __( 'Mis servicios', 'alb-employee-portal' ),
				'requests'        => __( 'Propuestas', 'alb-employee-portal' ),
				'customers'       => __( 'Clientes', 'alb-employee-portal' ),
				'agenda'          => __( 'Agenda', 'alb-employee-portal' ),
				'stats'           => __( 'Estadísticas', 'alb-employee-portal' ),
				'save'            => __( 'Guardar', 'alb-employee-portal' ),
				'description'     => __( 'Descripción corta', 'alb-employee-portal' ),
				'cancel'          => __( 'Cancelar', 'alb-employee-portal' ),
				'edit'            => __( 'Editar', 'alb-employee-portal' ),
				'visible'         => __( 'Visible en el catálogo', 'alb-employee-portal' ),
				'hidden'          => __( 'Oculto', 'alb-employee-portal' ),
				'empty'           => __( 'Nada por aquí todavía.', 'alb-employee-portal' ),
				'error_generic'   => __( 'Algo salió mal. Intenta de nuevo.', 'alb-employee-portal' ),
				'not_mapped'      => __( 'Tu usuario no está vinculado a ningún empleado. Contacta al administrador.', 'alb-employee-portal' ),
				'write_pending'   => __( 'Esta acción se habilitará próximamente.', 'alb-employee-portal' ),
				'saved'           => __( 'Guardado.', 'alb-employee-portal' ),
				'sent'            => __( 'Propuesta enviada. El administrador la revisará.', 'alb-employee-portal' ),
				'new_request'     => __( 'Proponer servicio nuevo', 'alb-employee-portal' ),
				'new_customer'    => __( 'Nuevo cliente', 'alb-employee-portal' ),
				'search'          => __( 'Buscar…', 'alb-employee-portal' ),
				'upcoming'        => __( 'Próximas', 'alb-employee-portal' ),
				'past'            => __( 'Pasadas', 'alb-employee-portal' ),
				'canceled'        => __( 'Canceladas', 'alb-employee-portal' ),
				'this_month'      => __( 'Este mes', 'alb-employee-portal' ),
				'appointments'    => __( 'Citas', 'alb-employee-portal' ),
				'revenue'         => __( 'Ingresos estimados', 'alb-employee-portal' ),
				'unique_clients'  => __( 'Clientes únicos', 'alb-employee-portal' ),
				'recurring'       => __( 'Recurrentes', 'alb-employee-portal' ),
				'top_services'    => __( 'Servicios más reservados', 'alb-employee-portal' ),
				'status_pending'  => __( 'Pendiente', 'alb-employee-portal' ),
				'status_approved' => __( 'Aprobada', 'alb-employee-portal' ),
				'status_rejected' => __( 'Rechazada', 'alb-employee-portal' ),
				'status_linked'   => __( 'Publicada', 'alb-employee-portal' ),
				'apt_approved'    => __( 'Confirmada', 'alb-employee-portal' ),
				'apt_pending'     => __( 'Pendiente', 'alb-employee-portal' ),
				'apt_canceled'    => __( 'Cancelada', 'alb-employee-portal' ),
				'apt_rejected'    => __( 'Rechazada', 'alb-employee-portal' ),
				'admin_requests'  => __( 'Cola', 'alb-employee-portal' ),
				'admin_team'      => __( 'Equipo', 'alb-employee-portal' ),
				'admin_audit'     => __( 'Auditoría', 'alb-employee-portal' ),
				'approve'         => __( 'Aprobar', 'alb-employee-portal' ),
				'reject'          => __( 'Rechazar', 'alb-employee-portal' ),
				'link'            => __( 'Vincular', 'alb-employee-portal' ),
				'link_hint'       => __( 'Crea el servicio en Amelia y pega aquí su ID para vincular la propuesta.', 'alb-employee-portal' ),
				'map_add'         => __( 'Vincular usuario con empleado', 'alb-employee-portal' ),
				'wp_user'         => __( 'Usuario de WordPress', 'alb-employee-portal' ),
				'employee'        => __( 'Empleado', 'alb-employee-portal' ),
				'system_status'   => __( 'Estado del sistema', 'alb-employee-portal' ),
				'writes_off'      => __( 'Escrituras hacia Amelia: deshabilitadas (pendiente verificación en servidor)', 'alb-employee-portal' ),
				'writes_on'       => __( 'Escrituras hacia Amelia: habilitadas', 'alb-employee-portal' ),
				'load_more'       => __( 'Cargar más', 'alb-employee-portal' ),
				'by'              => __( 'por', 'alb-employee-portal' ),
			),
		) );

		ob_start();
		include ALB_EP_DIR . 'templates/portal.php';
		return ob_get_clean();
	}
}
