<?php
namespace ALB_EP\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Contrato neutral del proveedor de reservas (requisitos permanentes 1 y 2).
 * El núcleo y los módulos hablan únicamente en estos términos; solo el
 * adaptador concreto (hoy Amelia_Provider) conoce el sistema real.
 *
 * Todas las referencias son strings opacos (ext_*_id): el núcleo nunca
 * asume que son IDs numéricos de una tabla concreta.
 *
 * Los métodos de escritura pueden devolver WP_Error con código
 * 'alb_ep_provider_unsupported' si el adaptador aún no implementa esa
 * operación de forma segura (ver sección 7 del documento de arquitectura).
 */
interface Booking_Provider {

	/** Clave del proveedor (ej. 'amelia'). */
	public function key();

	/**
	 * Servicios asignados a un empleado, con los datos del catálogo base.
	 *
	 * @param string $employee_ref
	 * @return array[]|\WP_Error Lista de arrays con claves:
	 *                           id, name, description, category_id, category_name,
	 *                           duration, base_price, employee_price, min_capacity,
	 *                           max_capacity, image_url, status.
	 */
	public function get_services_for_employee( $employee_ref );

	/**
	 * ¿Ofrece este empleado este servicio? Base de assert_owns() en módulos.
	 *
	 * @return bool|\WP_Error
	 */
	public function employee_offers_service( $employee_ref, $service_ref );

	/**
	 * Empleados del proveedor (para el mapeo admin). Sin datos de contacto
	 * sensibles: id, first_name, last_name, status y email SOLO para admin.
	 *
	 * @return array[]|\WP_Error
	 */
	public function get_employees();

	/** Categorías del catálogo base: id, name. @return array[]|\WP_Error */
	public function get_categories();

	/**
	 * Actualiza el precio (y opcionalmente capacidad) de un servicio PARA UN
	 * EMPLEADO, usando el mecanismo nativo del proveedor. Nunca toca el
	 * precio global del servicio.
	 *
	 * @param string     $employee_ref
	 * @param string     $service_ref
	 * @param float      $price
	 * @param array|null $capacity ['min' => int, 'max' => int] o null para no tocarla.
	 * @return true|\WP_Error
	 */
	public function update_employee_service_pricing( $employee_ref, $service_ref, $price, $capacity = null );

	/**
	 * Crea un cliente en el proveedor (sin duplicar si el email ya existe,
	 * cuando el proveedor lo permita detectar).
	 *
	 * @param array $data ['first_name','last_name','phone','email','note']
	 * @return string|\WP_Error Referencia del cliente creado.
	 */
	public function create_customer( array $data );

	/**
	 * Citas de un empleado en un rango.
	 *
	 * @param string $employee_ref
	 * @param string $from Fecha ISO (Y-m-d).
	 * @param string $to   Fecha ISO (Y-m-d).
	 * @return array[]|\WP_Error id, service_ref, customer_ref, starts_at, ends_at, status, price.
	 */
	public function get_appointments( $employee_ref, $from, $to );

	/**
	 * Diagnóstico del adaptador para el endpoint /status de admin: qué
	 * niveles de la cadena de acceso están disponibles en esta instalación.
	 *
	 * @return array
	 */
	public function diagnostics();
}
