# ALB Employee Portal — Arquitectura definitiva (v1.0, sin código)

> Estado: **arquitectura general aprobada por Ivanhoe (2026-07-08); esta versión definitiva pendiente de su visto bueno final antes de escribir código.**
> Última actualización: 2026-07-08

---

## 0. Resumen ejecutivo

Plugin independiente **`alb-employee-portal`**: panel privado por empleado (servicios, precios, clientes, agenda, estadísticas) sin tocar el núcleo de Amelia ni `alb-catalog`. Amelia sigue siendo la única fuente de verdad para reservas, empleados, clientes y el catálogo base de servicios.

Se diseña desde el día uno como **núcleo + módulos de una plataforma mayor** (sección 6): comisiones, finanzas, reportes, marketing, inventario, fidelización, reseñas, pagos y app móvil se añadirán como módulos sin rehacer el sistema.

---

## 1. Decisiones de arquitectura (confirmadas por Ivanhoe)

1. **No depender de la licencia Elite.** Funciona con la licencia actual; la API Elite, si existe, es solo capa opcional.
2. **Catálogo único de servicios.** Los empleados no crean copias del mismo servicio. Las diferencias por empleado viven en una capa de configuración por empleado.
3. **Precios por empleado = funcionalidad nativa de Amelia** (pestaña de servicios asignados del empleado). El portal solo expone una interfaz más simple sobre ese mecanismo; no inventa un sistema de precios paralelo.
4. **Creación de servicios nuevos: propuesta → revisión → aprobación del administrador.**
5. **Proyecto independiente**, integración solo por hooks/filtros/clases públicas.
6. **Aislamiento total entre empleados**, validado siempre en servidor.
7. **Tablas propias solo donde se confirme que no existe alternativa oficial segura y mantenible** — la investigación de la sección 2 resuelve esto campo por campo.
8. **Primera pieza de una plataforma mayor** — arquitectura modular (sección 6).

---

## 2. Investigación: ¿ofrece Amelia una vía oficial para extender servicios con metadatos?

Pregunta planteada por Ivanhoe antes de aprobar tablas propias. Investigado en la documentación pública de wpamelia.com (la política de red de esta sesión impidió descargar el código fuente de la versión Lite desde wordpress.org; ver 2.4 para lo que queda por confirmar en servidor).

### 2.1. Custom Fields de Amelia — NO sirven para esto

Amelia tiene un sistema oficial de "Custom Fields", pero son **campos del formulario de reserva que llena el cliente** (texto, dropdown, checkbox, archivos, fechas, dirección). Se pueden adjuntar a servicios concretos, pero el valor se guarda **en cada reserva individual**, no en el servicio. No es un mecanismo de metadatos administrativos por servicio, y menos por combinación servicio+empleado. Descartado.

### 2.2. Hooks oficiales de WordPress — SÍ existen, y cambian parte del diseño

Amelia documenta oficialmente familias de filtros/acciones de WordPress para extender sin tocar su núcleo, incluyendo para **servicios**:

- `amelia_before_service_added_filter` — modificar datos de un servicio antes de crearse
- `amelia_before_service_updated_filter` — modificar datos antes de actualizarse
- `amelia_get_service_filter` / `amelia_get_services_filter` — modificar datos de servicio(s) al recuperarse
- Familias equivalentes para citas, reservas, clientes, usuarios WP, eventos y compras de paquetes

**Qué resuelven y qué no:**

- ✅ Son la superficie de integración oficial y estable: nos permiten **reaccionar** cuando el admin crea/edita servicios en Amelia (ej. invalidar cachés, sincronizar nuestra capa de metadatos, validar propuestas ya vinculadas) y **fusionar** nuestros overrides cuando Amelia recupera servicios.
- ❌ **No proporcionan almacenamiento persistente.** Un filtro modifica datos "al vuelo" en cada request; no hay dónde guardar un dato nuestro dentro de Amelia por esta vía.

### 2.3. Columna `settings` de la entidad servicio — existe pero NO es vía segura

Las entidades de Amelia usan columnas JSON de configuración internas. Aunque técnicamente se podría inyectar claves propias ahí, **no está documentado como punto de extensión público**: una actualización de Amelia puede reescribir/normalizar ese JSON y eliminar claves desconocidas sin aviso. Guardar datos propios dentro de un esquema que es propiedad de otro plugin es exactamente el tipo de acoplamiento frágil que la decisión #5 prohíbe. Descartado.

### 2.4. Conclusión de la investigación

| Campo por empleado | Vía |
|---|---|
| Precio | **Nativo de Amelia** (override por empleado en servicios asignados) — sin tabla propia |
| Capacidad mín/máx | **Nativo de Amelia** — sin tabla propia |
| Descripción corta por servicio+empleado | Sin mecanismo oficial de persistencia → **tabla propia justificada** |
| Imagen por servicio+empleado | Sin mecanismo oficial → **tabla propia justificada** |
| Visibilidad de UN servicio para UN empleado | Sin mecanismo oficial (Amelia solo oculta al empleado entero) → **tabla propia justificada** |
| Propuestas de servicios nuevos | Concepto inexistente en Amelia → **tabla propia justificada** |

Queda un único punto que solo puede confirmarse con acceso al servidor (código de la versión premium instalada): si el contenedor interno expone command handlers de **escritura** utilizables (crear cliente, actualizar precio por empleado) además de las lecturas que ya usa `alb-catalog`. No bloquea esta arquitectura: la cadena de acceso (sección 4) ya contempla ambos resultados.

---

## 3. Modelo de datos

Amelia es dueña de: empleados, servicios, categorías, precio/capacidad por empleado, reservas, clientes, cupones.

`alb-employee-portal` añade solo lo justificado en 2.4, en tablas propias (prefijo `alb_ep_`), referenciando IDs de Amelia **por valor** (sin foreign keys físicas hacia tablas de Amelia — el acoplamiento de esquema entre plugins queda prohibido):

- **`alb_ep_service_employee_meta`** — `(id, amelia_service_id, amelia_employee_id, short_description, image_id, visible, updated_at)`. Registro opcional por combinación servicio+empleado; sin registro, el catálogo usa los datos globales del servicio de Amelia (fallback transparente).
- **`alb_ep_service_requests`** — `(id, amelia_employee_id, proposed_name, proposed_description, proposed_price, proposed_duration, proposed_category_id, proposed_image_id, status[pending|approved|rejected|linked], amelia_service_id_result, admin_note, created_at)`.
- **`alb_ep_employee_wp_user`** — mapa explícito `wp_user_id ↔ amelia_employee_id` (no se asume vínculo 1:1 automático).
- **`alb_ep_modules`** — registro de módulos instalados/activos y su versión de esquema (ver sección 6); permite activar/desactivar módulos futuros sin migraciones globales.

Las imágenes suben a la **biblioteca de medios de WordPress** (attachment IDs), no a almacenamiento propio.

---

## 4. Vías de acceso a Amelia (cadena, en orden de preferencia)

Detrás de una interfaz única del núcleo (`Amelia Gateway`), misma filosofía que la cadena de fuentes de `alb-catalog`:

1. **Hooks/filtros oficiales de Amelia** (2.2) — para reaccionar a cambios y fusionar datos. Es la capa más estable; se prefiere siempre que cubra el caso.
2. **Contenedor interno de Amelia (in-process)** — lecturas confirmadas (patrón `alb-catalog`); escrituras pendientes de verificar en servidor (2.4).
3. **API REST pública de Amelia** — solo si la licencia resulta ser Elite; cliente opcional detrás de la misma interfaz, jamás requisito.
4. **SQL de solo lectura** — respaldo de lectura (patrón `class-amelia-db-source.php`).
5. **SQL de escritura acotado** — último recurso, solo columnas puntuales de tablas de unión ya conocidas (ej. precio por empleado en provider↔service si 2 y 3 no lo exponen), siempre `$wpdb->prepare`, nunca creación/borrado de entidades completas.

La **creación de servicios completos jamás pasa por el nivel 5**: el flujo de propuesta→aprobación (sección 5) lo evita por diseño.

---

## 5. Flujo de creación de servicios (propuesta → revisión → aprobación)

1. Empleado llena el formulario de propuesta en su panel → fila en `alb_ep_service_requests` (`pending`). No toca Amelia.
2. Admin ve la cola de propuestas y decide.
3. **v1:** al aprobar, el admin crea el servicio en la pantalla nativa de Amelia (como hoy) y vincula la propuesta (`linked` + ID del servicio). Cero riesgo, no depende del punto pendiente de 2.4.
4. **Futuro:** si el contenedor interno expone un command handler seguro de creación, el paso 3 se automatiza con un clic — mismo flujo, misma tabla, sin migración. El filtro `amelia_before_service_added_filter` permite además etiquetar/validar el alta en ese caso.

---

## 6. Arquitectura de plataforma: núcleo + módulos

`alb-employee-portal` se estructura como un **núcleo** estable y **módulos** que se registran contra él. Cada módulo declara sus rutas REST, sus tablas y sus permisos a través del núcleo; puede activarse/desactivarse individualmente (registro en `alb_ep_modules`).

### 6.1. Núcleo (no negociable, se construye primero)

| Componente | Responsabilidad |
|---|---|
| **Identity & Permissions** | Resuelve `wp_user → amelia_employee_id` en servidor (vía `alb_ep_employee_wp_user`); expone `current_employee_id()` y `assert_owns(resource)`; el rol admin puede operar sobre cualquier empleado |
| **Amelia Gateway** | Única puerta hacia Amelia, implementa la cadena de la sección 4; ningún módulo habla con Amelia directamente |
| **REST Kernel** | Namespace `alb-employee-portal/v1/...`; middleware de autenticación/autorización que se ejecuta antes que cualquier handler de módulo |
| **Event Bus** | Hooks propios `alb_ep_*` (ej. `alb_ep_service_meta_updated`, `alb_ep_request_approved`, `alb_ep_appointment_observed`) — los módulos futuros se suscriben aquí, nunca directamente a internals de otros módulos |
| **Module Registry** | Alta/baja de módulos, versión de esquema por módulo, migraciones aisladas |

### 6.2. Módulos v1 (el pedido actual)

- **Services** — capa de metadatos por servicio+empleado + cola de propuestas (secciones 3 y 5); interfaz simple sobre el precio/capacidad nativos de Amelia.
- **Customers** — clientes del empleado (crear vía Amelia Gateway; listar filtrado por empleado resuelto en servidor).
- **Agenda** — citas propias (futuras/pasadas/canceladas), solo lectura desde Amelia.
- **Stats** — reservas del mes, ingresos estimados, servicios más vendidos, clientes recurrentes; calculado sobre datos de Amelia, sin tablas de hechos hasta que el volumen exija caché.

### 6.3. Módulos futuros (cómo encajan sin rehacer nada)

| Módulo futuro | Encaje |
|---|---|
| Comisiones | Tabla propia de reglas por empleado(+servicio); escucha `alb_ep_appointment_observed` |
| Finanzas / panel financiero | Agregación sobre comisiones+pagos; solo lectura de otros módulos vía sus APIs |
| Reportes | Consume el mismo Amelia Gateway + tablas de módulos; exporta |
| Marketing / promociones | Los cupones ya existen nativos en Amelia → interfaz de gestión, no motor propio |
| Inventario | Tablas propias keyed por `amelia_service_id`/`amelia_employee_id`; descuenta stock escuchando eventos de cita |
| Fidelización | Tabla de puntos keyed por `amelia_customer_id` |
| Reseñas | Tabla propia (servicio, empleado, cliente, rating, texto); se fusiona al catálogo por el filtro `alb_catalog_service` que `alb-catalog` ya expone (campo `rating` ya reservado allí) |
| Pagos | Módulo que escucha eventos del bus; el cobro de la reserva sigue siendo de Amelia |
| App móvil | Consume `alb-employee-portal/v1/...` tal cual — la API REST propia ES el contrato de la app |

Principio común: **todo lo nuevo vive en tablas y rutas propias, referenciando IDs de Amelia por valor; los módulos se comunican por el Event Bus y las APIs del núcleo, nunca por acceso directo cruzado.**

---

## 7. Flujo de permisos

- Todo endpoint bajo `alb-employee-portal/v1/...`; jamás se reutilizan rutas de Amelia.
- En cada request, el REST Kernel resuelve `amelia_employee_id` desde `get_current_user_id()` **en servidor**. Un `employee_id` enviado por el cliente nunca determina la identidad: como mucho se compara con el resuelto y, si difiere, 403.
- Admin (y rol equivalente de Amelia, a confirmar) puede operar sobre cualquier empleado explícito.
- Toda lectura (propia o vía Amelia Gateway) filtra por el empleado resuelto en servidor. Los módulos no pueden saltarse el middleware: el Module Registry solo registra rutas a través del REST Kernel.
- Subida de imágenes: capacidad WP mínima necesaria + validación de tipo/tamaño en servidor; el attachment queda asociado al empleado que lo subió.

---

## 8. Puntos pendientes de verificación en servidor (no bloquean la aprobación, sí el código de escritura)

Requieren la laptop de Ivanhoe con la extensión de Chrome conectada a wp-admin:

1. **Licencia de Amelia activa** (Amelia → Settings → License) — decide si el nivel 3 de la cadena existe.
2. **Command handlers de escritura en el contenedor interno** (inspección del código premium instalado en `wp-content/plugins/ameliabooking/src/`) — decide si crear clientes / actualizar precio por empleado va por nivel 2 o cae a 3/5.
3. **Configuración actual de Roles & Permissions** y comportamiento real de "Allow employees to manage customers" (¿filtra por empleado o muestra todos?) — decide cuánto del módulo Customers es solo activar casillas.
4. **Estado del vínculo WP user ↔ empleado Amelia en producción** (¿todos los empleados tienen login WP?) — decide el contenido inicial de `alb_ep_employee_wp_user`.

---

## 9. Qué sigue

1. Visto bueno final de Ivanhoe a esta versión (en particular secciones 4, 6 y 7).
2. Sesión con acceso a wp-admin para cerrar los 4 puntos de la sección 8.
3. Primer entregable de código: **núcleo** (sección 6.1) + esqueleto del módulo Services, para revisión antes de implementar lógica de negocio.
