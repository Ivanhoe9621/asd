# ALB Employee Portal (esqueleto v0.1.0)

Núcleo + módulo Services de la plataforma de empleados de AL Bookings. La arquitectura, los requisitos permanentes y los diagramas viven en [`../ALB-EMPLOYEE-PORTAL.md`](../ALB-EMPLOYEE-PORTAL.md) — este código los implementa y no debe desviarse de ellos.

## Estado

- **Núcleo**: Identity & Permissions, REST Kernel con middleware de permisos, Event Bus, Module Registry con migraciones versionadas, Audit Service, i18n. Funcional.
- **Gateway**: interfaz neutral `Booking_Provider` + adaptador `Amelia_Provider`. Lecturas por SQL introspectivo de solo lectura (patrón validado por alb-catalog). **Escrituras deshabilitadas** (devuelven 501) hasta la verificación en servidor de la sección 7 del documento de arquitectura.
- **Módulo Services**: meta por servicio+empleado (descripción corta, imagen, visibilidad), cola de propuestas con aprobación admin. Funcional salvo `PUT pricing` (depende de escrituras del Gateway).
- **Módulo Customers**: lista de clientes del empleado (solo los que reservaron con él, agregados con conteo de reservas y última visita) con búsqueda; alta de clientes vía Gateway (501 hasta habilitar escrituras). Sin tablas propias.
- **Módulo Agenda**: citas propias por rango de fechas (máx. 1 año) con filtro por estado, incluyendo cliente y precio por cita cuando el proveedor los expone. Solo lectura, sin tablas propias.
- **Módulo Stats**: resumen mensual del propio empleado — citas por estado, ingresos estimados (con indicador `revenue_complete` si algún precio no pudo leerse), top 5 servicios, clientes únicos y recurrentes. Sin tablas propias.
- **Pendiente**: interfaz de usuario (assets), verificación en servidor (habilita escrituras).

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

Igual que alb-catalog: comprimir esta carpeta en un ZIP (cuidando que las rutas usen `/`, no `\` — ver historial de despliegue en SUMMARY.md) y subirlo en wp-admin → Plugins, o pegar archivos por el editor de plugins. Tras activar, mapear cada usuario WP a su empleado con `PUT /wp-json/alb-employee-portal/v1/admin/employee-map`.
