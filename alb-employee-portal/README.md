# ALB Employee Portal (1.0.0-rc.1 — Release Candidate)

> **RC, no versión final.** Las escrituras hacia Amelia (crear clientes, modificar precios) están pendientes de la verificación en el entorno real y forman parte del alcance de la v1.0. Plan de cierre y checklist de pruebas: [`../ALB-EMPLOYEE-PORTAL-TESTS.md`](../ALB-EMPLOYEE-PORTAL-TESTS.md).

Núcleo + módulo Services de la plataforma de empleados de AL Bookings. La arquitectura, los requisitos permanentes y los diagramas viven en [`../ALB-EMPLOYEE-PORTAL.md`](../ALB-EMPLOYEE-PORTAL.md) — este código los implementa y no debe desviarse de ellos.

## Estado

- **Núcleo**: Identity & Permissions, REST Kernel con middleware de permisos, Event Bus, Module Registry con migraciones versionadas, Audit Service, i18n. Funcional.
- **Gateway**: interfaz neutral `Booking_Provider` + adaptador `Amelia_Provider`. Lecturas por SQL introspectivo de solo lectura (patrón validado por alb-catalog). **Escrituras deshabilitadas** (devuelven 501) hasta la verificación en servidor de la sección 7 del documento de arquitectura.
- **Módulo Services**: meta por servicio+empleado (descripción corta, imagen, visibilidad), cola de propuestas con aprobación admin. Funcional salvo `PUT pricing` (depende de escrituras del Gateway).
- **Módulo Customers**: lista de clientes del empleado (solo los que reservaron con él, agregados con conteo de reservas y última visita) con búsqueda; alta de clientes vía Gateway (501 hasta habilitar escrituras). Sin tablas propias.
- **Módulo Agenda**: citas propias por rango de fechas (máx. 1 año) con filtro por estado, incluyendo cliente y precio por cita cuando el proveedor los expone. Solo lectura, sin tablas propias.
- **Módulo Stats**: resumen mensual del propio empleado — citas por estado, ingresos estimados (con indicador `revenue_complete` si algún precio no pudo leerse), top 5 servicios, clientes únicos y recurrentes. Sin tablas propias.
- **Interfaz**: shortcode `[alb_employee_portal]` + app vanilla JS (`assets/portal.js`, `assets/portal.css`) con las 5 vistas (servicios con edición de meta, propuestas, clientes con búsqueda y alta, agenda con filtros, estadísticas mensuales), mobile-first, modo oscuro automático, skeletons de carga y toasts. Verificada con Chromium (móvil 390px y escritorio, claro y oscuro, sin errores de consola).
- **Pendiente**: verificación en servidor (habilita escrituras: pricing y alta de clientes).

## Estructura

```
alb-employee-portal.php              # Bootstrap
uninstall.php                        # No destruye datos
includes/
├── class-plugin.php                 # Armado del núcleo y registro de módulos
├── core/
│   ├── interface-module.php         # Contrato de módulo
│   ├── class-identity.php           # wp_user → employee_ref (siempre en servidor)
│   ├── class-rest-kernel.php        # Registro de rutas + middleware de permisos
│   ├── class-event-bus.php          # Eventos alb_ep_*
│   ├── class-module-registry.php    # Migraciones versionadas por módulo
│   ├── class-audit.php              # Registro de auditoría
│   └── class-admin-rest.php         # /me, mapeo, auditoría, /admin/status
├── gateway/
│   ├── interface-booking-provider.php
│   └── class-amelia-provider.php    # Único código que conoce Amelia
└── modules/
    ├── services/class-services-module.php
    ├── customers/class-customers-module.php
    ├── agenda/class-agenda-module.php
    └── stats/class-stats-module.php
```

## Instalación en el servidor

Igual que alb-catalog: comprimir esta carpeta en un ZIP (cuidando que las rutas usen `/`, no `\` — ver historial de despliegue en SUMMARY.md) y subirlo en wp-admin → Plugins, o pegar archivos por el editor de plugins. Tras activar, mapear cada usuario WP a su empleado con `PUT /wp-json/alb-employee-portal/v1/admin/employee-map` o desde la pestaña Equipo del portal.

## Desinstalación

Patrón WooCommerce, en dos niveles:

- **Por defecto (desinstalar desde wp-admin → Plugins → Borrar): NO se elimina ningún dato.** Las tablas `alb_ep_*` (metadatos de servicios, propuestas, auditoría, mapeo) y las opciones del plugin se conservan; reinstalar el plugin recupera todo tal cual estaba.
- **Purga total (opt-in explícito):** añadir a `wp-config.php` **antes** de borrar el plugin:

  ```php
  define( 'ALB_EP_UNINSTALL_DROP_DATA', true );
  ```

  Con la constante en `true`, la desinstalación elimina todo lo propio del plugin: sus 5 tablas (lista explícita, no por patrón), sus opciones, sus transients y sus eventos programados con prefijo `alb_ep_`.

**Qué NO se elimina jamás, con o sin constante:** nada de Amelia, de WordPress ni de otros plugins. Las imágenes que los empleados subieron a la biblioteca de medios son contenido de WordPress y tampoco se tocan (eliminarlas, si se quisiera, es una decisión manual en Medios).

Recordar quitar la constante de `wp-config.php` después de la purga.
