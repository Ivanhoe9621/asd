# ALB Employee Portal — Documento técnico de arquitectura (v0.1, sin código)

> Estado: **propuesta pendiente de aprobación**. No se ha escrito código de este plugin todavía.
> Última actualización: 2026-07-08

---

## 0. Resumen ejecutivo

Nuevo plugin independiente **`alb-employee-portal`** que da a cada empleado un panel privado para gestionar su propia operación (servicios, precios, clientes, agenda, estadísticas) sin tocar el núcleo de Amelia ni el plugin `alb-catalog`. Amelia sigue siendo la única fuente de verdad para reservas, empleados y el catálogo base de servicios.

Decisión de arquitectura clave (reemplaza la propuesta inicial de "un servicio = un empleado"): **el catálogo de servicios sigue siendo único y compartido**. Lo que varía por empleado es una **capa de configuración por empleado** (precio, capacidad, y — donde Amelia no lo permite de forma nativa — descripción corta, imagen y visibilidad), guardada en tablas propias de `alb-employee-portal` y fusionada en el catálogo público a través de un punto de extensión que `alb-catalog` ya expone.

---

## 1. Decisiones de arquitectura (confirmadas por Ivanhoe)

1. **No depender de la licencia Elite.** El plugin debe funcionar sobre la licencia actual de Amelia. Si se detecta la API Elite disponible, se puede añadir como capa opcional de respaldo, nunca como requisito.
2. **Catálogo único, configuración por empleado.** Ningún servicio se duplica ni se le asigna un "dueño" exclusivo. La relación empleado↔servicio ya existe en Amelia (tabla de unión provider↔service); se le añade una capa de metadatos propios donde Amelia no cubre el campo.
3. **Proyecto independiente**, sin modificar `ameliabooking` ni `alb-catalog`. Integración solo por hooks/filtros/API pública que ya expongan.
4. **Preferencia estricta de capas seguras**, en este orden: (a) métodos internos de Amelia (Application Services / Command Handlers vía su contenedor interno) → (b) API REST pública si existe Elite → (c) SQL de solo lectura → (d) SQL de escritura acotado a una columna conocida, solo como último recurso y siempre con `$wpdb->prepare`.
5. **Diseño extensible** para: comisiones, pagos, estadísticas avanzadas, promociones, cupones, reseñas, fidelización, panel financiero, notificaciones, app móvil — sin rehacer el sistema.
6. **Aislamiento total entre empleados**, validado siempre en servidor, nunca solo en el frontend.
7. **Este documento se aprueba antes de escribir código.**

---

## 2. Qué permite Amelia hoy de forma nativa (investigado vía documentación pública de wpamelia.com)

Importante: esto es lo que documenta wpamelia.com públicamente. **No pude verificar la versión exacta ni la configuración actual de Roles & Permissions instalada en albookings.com**, porque este documento se redactó sin acceso a wp-admin del sitio (ver sección 7, punto abierto #1). Lo que sigue debe confirmarse en el sitio real antes de dar por buena cualquier decisión que dependa de ello.

### 2.1. Ya soportado nativamente (reutilizar, no reconstruir)

| Función | Cómo lo hace Amelia |
|---|---|
| Precio distinto por empleado para un mismo servicio | Pestaña "Services" del perfil del empleado → precio editable por servicio; sobreescribe el precio por defecto **solo para ese empleado** |
| Precio distinto por duración, por empleado | Si el servicio tiene "custom duration pricing" activado, cada empleado tiene su propia sección de precios por duración |
| Capacidad mínima/máxima distinta por empleado | Mismo panel, override de min/max capacity que aplica solo a ese empleado |
| Auto-asignarse / desasignarse de servicios existentes | Con "Allow employees to manage their services" activado |
| Horario, días libres, días especiales propios | Nativo, por empleado |
| Ver solo sus propias citas (futuras/pasadas/canceladas) | Employee Panel ya filtra por empleado |
| Crear/gestionar clientes | Con "Allow employees to manage customers" activado en Roles & Permissions → Employee |
| Ocultar un empleado completo del front-end | "Hide" en el diálogo de edición del empleado — pero oculta **todo** el empleado, no un servicio suyo en particular |
| Roles Admin / Empleado / Cliente a nivel de sistema | Nativo en Amelia → Settings → Roles & Permissions |

### 2.2. NO encontré evidencia de que Amelia lo soporte nativamente (gaps reales)

| Función pedida | Estado |
|---|---|
| Descripción corta distinta por empleado para el mismo servicio | La descripción vive en la entidad **Servicio** (global) o en la entidad **Empleado** (bio general del profesional), no existe un campo "descripción de este servicio, según este empleado" |
| Imagen distinta por empleado para el mismo servicio | Mismo problema: la imagen es del servicio (global) o del empleado (foto de perfil), no hay combinación servicio+empleado |
| Ocultar un solo servicio para un solo empleado, manteniendo visibles sus otros servicios y sin ocultar al empleado entero | No hay un toggle de visibilidad a nivel de la relación servicio-empleado; la única visibilidad nativa es a nivel de empleado completo |
| Empleado crea un servicio nuevo desde cero (nombre, categoría, imagen, ubicación) | No existe en el flujo nativo: un empleado solo puede auto-asignarse a servicios que el admin ya creó, y al hacerlo ve **todos** los servicios existentes, no solo los suyos |
| Estadísticas por empleado (ingresos, servicios más vendidos, clientes recurrentes) | No hay un dashboard nativo de este tipo por empleado |

### 2.3. Sin confirmar (requiere inspeccionar el código real de Amelia en el servidor, no solo su documentación pública)

- Nombre exacto de las clases internas (Application Services / Command Handlers) que Amelia usa para crear/editar servicios y clientes. `alb-catalog` ya demostró que existe un contenedor interno accesible (lo usa `class-amelia-internal-source.php` para lecturas), pero no sabemos si ese mismo contenedor expone también las clases de escritura, ni sus firmas exactas.
- Si "Allow employees to manage customers" filtra la lista de clientes solo a los que reservaron con ese empleado, o muestra la lista completa de clientes del negocio.
- Qué licencia de Amelia está activa en albookings.com (Standard/Pro/Elite) — condiciona si la API REST pública es una opción de respaldo real.

Estos tres puntos solo se pueden cerrar con acceso a wp-admin / al código del plugin en el servidor (la extensión de Chrome conectada, según el flujo ya usado para `alb-catalog`).

---

## 3. Modelo de datos propuesto

Amelia sigue siendo dueña de: empleados, servicios, categorías, precio/capacidad por empleado (2.1), reservas, clientes.

`alb-employee-portal` añade **solo** lo que Amelia no cubre, en sus propias tablas (prefijo `alb_ep_`), referenciando IDs de Amelia por clave foránea lógica (sin FK real entre plugins, para no crear un acoplamiento de esquema):

- **`alb_ep_service_employee_meta`** — `(id, amelia_service_id, amelia_employee_id, short_description, image_id, visible, updated_at)`. Un registro opcional por combinación servicio+empleado. Si no existe registro, se usa la descripción/imagen del servicio global de Amelia (fallback transparente).
- **`alb_ep_service_requests`** — `(id, amelia_employee_id, proposed_name, proposed_description, proposed_price, proposed_duration, proposed_category_id, proposed_image_id, status[pending|approved|rejected|linked], amelia_service_id_result, admin_note, created_at)`. Cola de "propuestas de servicio nuevo" (ver sección 5).
- **`alb_ep_employee_wp_user`** — mapa explícito `wp_user_id ↔ amelia_employee_id`, para no asumir que coinciden (hoy en Amelia el empleado y el usuario de WP pueden o no estar vinculados 1:1 según cómo se creó cada cuenta).

Todo lo demás (precio, capacidad, agenda, clientes) se lee/escribe directamente en Amelia por las vías descritas en la sección 4 — **no se duplica**.

---

## 4. Vías de acceso a Amelia, en orden de preferencia

1. **Contenedor interno de Amelia (in-process, mismo patrón que `alb-catalog`)** — para lo ya confirmado que existe (lectura de servicios/empleados/citas, y escritura de precio/capacidad por empleado si el contenedor expone esa clase; pendiente de verificar en el servidor, ver 2.3).
2. **API REST pública de Amelia** — solo si la licencia es Elite (por confirmar). Se implementa como cliente HTTP opcional detrás de una interfaz común, nunca como dependencia obligatoria.
3. **SQL de solo lectura** — como respaldo de lectura, igual que ya hace `class-amelia-db-source.php` en `alb-catalog`.
4. **SQL de escritura acotado** — solo si 1 y 2 no exponen una vía segura para una operación puntual (ej. actualizar una sola columna de precio en la tabla de unión provider↔service), siempre con `$wpdb->prepare`, nunca para crear/borrar filas de entidades completas.

La creación de un **servicio nuevo completo** no entra en el nivel 4 bajo ningún escenario (es una operación de alto riesgo para hacerla por SQL directo: toca varias tablas relacionadas — servicio, categoría, horarios por defecto, etc.). Por eso el flujo de creación de servicios (sección 5) evita este problema por diseño en la v1.

---

## 5. Flujo de creación de servicios (propuesta → aprobación)

Siguiendo el modelo de Ivanhoe (admin crea categorías y aprueba servicios nuevos; empleado propone):

1. Empleado llena un formulario en su panel: nombre, descripción, precio, duración, categoría existente, imagen. Esto crea una fila en `alb_ep_service_requests` con `status = pending`. **No toca Amelia todavía.**
2. Admin ve la cola de propuestas pendientes (en el panel de admin del plugin, o simplemente por notificación/email).
3. Admin aprueba: en la v1, esto significa que el admin crea el servicio real **dentro de la propia pantalla de Amelia** (como ya hace hoy), y luego marca la propuesta como `linked` pegando el ID del servicio creado — cierre manual, cero riesgo, no depende de tener resuelto el punto 2.3.
4. Si en el futuro se confirma que el contenedor interno de Amelia expone una clase seria de "crear servicio" (punto 2.3), el paso 3 se puede automatizar sin cambiar el resto del flujo ni la tabla `alb_ep_service_requests`.

Esto cumple el requisito de Ivanhoe (admin controla el catálogo, evita duplicados/nombres inconsistentes) sin bloquear la v1 a una pieza de información que hoy no tenemos confirmada.

---

## 6. Flujo de permisos

- Todo endpoint del plugin vive bajo el namespace REST propio `alb-employee-portal/v1/...` (nunca se reutilizan ni se sobreescriben rutas de Amelia).
- En cada request: `amelia_employee_id` se resuelve **siempre en servidor** a partir de `get_current_user_id()` vía `alb_ep_employee_wp_user`. Nunca se acepta un `employee_id` que venga del body/query del cliente para determinar de quién son los datos — como mucho se usa para verificar que coincide con el resuelto en servidor, y si no coincide, se rechaza con 403.
- Excepción: `current_user_can('administrator')` (o el rol Amelia Manager, a confirmar) puede operar sobre cualquier `employee_id` explícito.
- Cada tabla propia (`alb_ep_*`) lleva `amelia_employee_id` y todas las consultas de lectura filtran por ese valor resuelto en servidor — mismo principio para clientes/citas leídos de Amelia (filtrar por el empleado resuelto, nunca confiar en un filtro mandado por el frontend).

---

## 7. Extensibilidad futura — cómo encaja cada función sin rehacer el sistema

| Futuro | Cómo lo absorbe esta arquitectura |
|---|---|
| Comisiones por empleado | Tabla propia `alb_ep_commission_rules` keyed por `amelia_employee_id` (+ opcionalmente `amelia_service_id`); se calcula sobre citas leídas de Amelia, no requiere tocar Amelia |
| Pagos | Módulo nuevo que se suscribe a un hook propio `alb_ep_appointment_completed` (disparado por nuestro propio polling/lectura de Amelia, no por Amelia mismo) |
| Estadísticas avanzadas | Se apoyan en los mismos datos ya leídos de Amelia (citas, servicios) + las tablas propias; sin tablas nuevas de "hechos" hasta que haga falta caché |
| Promociones / cupones | Amelia ya soporta cupones nativamente (mencionado en SUMMARY.md como idea futura para `alb-catalog`); este plugin solo necesitaría exponer un panel de gestión, reutilizando el motor de Amelia si su API lo permite, o tabla propia si no |
| Reseñas | Tabla propia `alb_ep_reviews` (`amelia_service_id`, `amelia_employee_id`, `amelia_customer_id`, rating, texto) — el campo `rating` ya está reservado en el diseño de datos de `alb-catalog` según SUMMARY.md, así que se fusiona igual que la descripción/imagen por empleado (sección 3) |
| Fidelización | Tabla propia `alb_ep_loyalty_points` keyed por `amelia_customer_id` |
| Panel financiero | Capa de agregación sobre comisiones + pagos, sin nuevo modelo de datos base |
| Notificaciones | Hooks propios (`alb_ep_*`) + integración opcional con lo que ya use el sitio (email de WordPress, o el pixel/PixelYourSite existente) |
| App móvil | Como todo pasa por `alb-employee-portal/v1/...` (REST propio), una app móvil consume la misma API sin cambios de arquitectura |

El principio común: **todo lo nuevo vive en tablas y rutas propias de `alb-employee-portal`, referenciando IDs de Amelia por valor, nunca por acoplamiento de esquema** — así una actualización de Amelia no puede romper este plugin, y viceversa.

---

## 8. Riesgos y puntos abiertos (bloquean el paso a código)

1. **Sin acceso al wp-admin / código real de Amelia en este momento** — no pude confirmar: licencia activa, configuración actual de Roles & Permissions, ni si el contenedor interno de Amelia expone clases de escritura (crear servicio, crear cliente, actualizar precio) además de las de lectura que ya usa `alb-catalog`. Esto requiere la laptop de Ivanhoe con la extensión de Chrome conectada.
2. **Vínculo WP user ↔ Amelia employee** — hay que confirmar cómo está esa relación hoy en producción (¿todos los empleados de Amelia ya tienen usuario de WordPress con rol Amelia Employee? ¿o algunos son solo registros dentro de Amelia sin login?).
3. **Alcance real de "Allow employees to manage customers"** — si ya filtra por empleado, gran parte del punto 5 del pedido original ("ver solo sus clientes") queda resuelto activando una casilla, sin código.

---

## 9. Qué sigue

Este documento no incluye código. Antes de empezar a implementar, se necesita:

1. Aprobación de Ivanhoe sobre las secciones 3 (modelo de datos), 5 (flujo de propuesta de servicios) y 6 (permisos).
2. Sesión con acceso a wp-admin de albookings.com para cerrar los 3 puntos de la sección 8.
3. Con eso resuelto, siguiente entregable: esqueleto del plugin (`alb-employee-portal.php` + estructura de `includes/`), todavía sin lógica de negocio, para revisión antes de implementar los endpoints.
