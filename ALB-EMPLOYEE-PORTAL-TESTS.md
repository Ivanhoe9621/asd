# ALB Employee Portal — Checklist de pruebas (RC → producción)

> Versión bajo prueba: **1.0.0-rc.1**. Este checklist se marca durante el despliegue en el entorno real de albookings.com. El plugin **no se considera listo para producción** hasta que todas las secciones estén completas (criterios de salida al final).
>
> Convención: marcar `[x]` al pasar; si algo falla, anotar el detalle junto al ítem y abrir la corrección antes de continuar con la sección.

---

## Cómo ejecutar los pasos 1–3 del plan (sesión con wp-admin, ~5 minutos)

> El respaldo del sitio ya está hecho (2026-07-14). Los artefactos están en `dist/` de este repositorio.

1. Descargar **`dist/alb-amelia-inspector.zip`** desde GitHub.
2. wp-admin → Plugins → Añadir nuevo → Subir plugin → ese ZIP → Instalar → **Activar**.
3. Ir a **Herramientas → ALB Inspector**, clic dentro del cuadro de texto (se autoselecciona) → copiar TODO.
4. **Pegar el informe completo a Claude** en este chat. Con eso se responden todos los ítems de la sección 0 de abajo y se implementan las escrituras (paso 4 del plan).
5. Desactivar y borrar el inspector.
6. Cuando Claude entregue la rc con escrituras: instalar **`dist/alb-employee-portal-1.0.0-rc.X.zip`** (el más reciente) igual que en el paso 2, crear la página privada de pruebas con `[alb_employee_portal]`, y seguir el checklist desde la sección 1.

## 0. Verificación del entorno real (orden acordado, pasos 1–2)

- [ ] Licencia de Amelia identificada (Amelia → Settings → License): plan ______ · ¿incluye API Elite? ______
- [ ] Versión exacta de Amelia instalada: ______
- [ ] Versión de PHP del hosting: ______ (mínimo requerido: 7.4)
- [ ] Versión de WordPress: ______ (mínimo: 6.0)
- [ ] Inspección de `wp-content/plugins/ameliabooking/src/`: clases/command handlers disponibles para **crear cliente** — anotar clase y firma: ______
- [ ] Ídem para **actualizar precio por empleado** (relación provider↔service): ______
- [ ] Ídem para **crear servicio** (para el paso 4 futuro del flujo de propuestas): ______
- [ ] Cómo se obtiene el contenedor interno de Amelia en esta versión (para cablear el filtro `alb_ep_amelia_container`): ______
- [ ] Configuración actual de Amelia → Roles & Permissions documentada (captura)
- [ ] Comportamiento real de "Allow employees to manage customers": ¿muestra todos los clientes o solo los propios? ______
- [ ] Lista de empleados de Amelia con/sin usuario de WordPress asociado: ______

## 1. Instalación y migraciones (paso 4: entorno de pruebas)

- [ ] Página de pruebas creada (borrador/privada, ej. `/portal-test`) con `[alb_employee_portal]` — NO enlazada desde el sitio público
- [ ] **Caché (auditoría R1):** URL del portal añadida a las exclusiones de página de Breeze Y de Varnish (Cloudways) — el nonce embebido no debe cachearse jamás
- [ ] **Caché (auditoría R4):** `portal.js` excluido de la minificación/combinación de JS de Breeze
- [ ] ZIP del plugin genera rutas con `/` (verificado antes de subir — lección aprendida de alb-catalog v1.0.2)
- [ ] Plugin activa sin errores fatales ni warnings en pantalla
- [ ] Las 5 tablas existen: `alb_ep_modules`, `alb_ep_employee_map`, `alb_ep_audit_log`, `alb_ep_service_employee_meta`, `alb_ep_service_requests`
- [ ] `alb_ep_modules` registra `core` + 4 módulos con sus versiones de esquema
- [ ] Desactivar y reactivar: sin errores, sin tablas duplicadas, datos intactos (migraciones idempotentes)
- [ ] **Desinstalación conservando datos (default):** borrar el plugin desde wp-admin SIN la constante → verificar en la BD que las 5 tablas `alb_ep_*` y la opción `alb_ep_schema_versions` siguen existiendo; reinstalar → mapeos, meta y propuestas intactos
- [ ] **Desinstalación con purga (opt-in):** definir `ALB_EP_UNINSTALL_DROP_DATA` en `true` en wp-config.php, borrar el plugin → verificar que NO queda ninguna tabla `alb_ep_*` ni ninguna fila `alb_ep_%`/`_transient_alb_ep_%` en wp_options, y que las tablas `wp_amelia_*` y el resto de wp_options están intactas; quitar la constante al terminar
- [ ] `GET /wp-json/alb-employee-portal/v1/admin/status` como admin: `tables` 6/6 en `true`, versión correcta
- [ ] Si alguna tabla sale `false` en el diagnóstico: anotar cuál y ajustar la introspección del adaptador ANTES de seguir

## 2. Identidad y permisos (transversal)

- [ ] Usuario anónimo (sin sesión): TODOS los endpoints devuelven 401 (probar `/me`, `/services`, `/admin/status`)
- [ ] Usuario logueado sin mapeo y sin rol admin: `/services` devuelve 403 `alb_ep_not_mapped`; el portal muestra el mensaje de contactar al administrador
- [ ] Admin mapea usuario de prueba ↔ empleado de prueba desde la pestaña Equipo; el vínculo aparece en la lista y en `alb_ep_employee_map`
- [ ] Ese usuario ve el portal con sus 5 pestañas y SOLO sus datos
- [ ] Admin sin mapeo como empleado: ve solo las 3 pestañas admin
- [ ] Admin también mapeado: ve las 8 pestañas

## 3. Funcionales por módulo (paso 5: datos reales)

### 3.1 Services

- [ ] `GET /services` lista exactamente los servicios asignados a ese empleado en Amelia (comparar con wp-admin → Amelia → Employees)
- [ ] Precio mostrado = precio por empleado si existe override en Amelia; si no, el precio base
- [ ] Editar descripción corta → se guarda, sobrevive recarga, fila en `alb_ep_service_employee_meta`
- [ ] Cambiar visibilidad a Oculto → badge cambia; verificar el valor en la tabla
- [ ] `image_id` inexistente → 400 `alb_ep_invalid_image`
- [ ] Evento y auditoría: cada edición crea fila en `alb_ep_audit_log` con before/after correctos
- [ ] **Escritura pricing** (tras habilitarse en paso 3 del plan): cambiar precio propio → se refleja en Amelia (wp-admin) y en el catálogo `/book` tras el TTL de caché (10 min) o invalidación
- [ ] Pricing con valor negativo/no numérico → 400

### 3.2 Propuestas de servicios

- [ ] Empleado crea propuesta → estado Pendiente; nombre vacío → 400
- [ ] La propuesta aparece en la Cola del admin (filtro Pendiente)
- [ ] Rechazar → estado Rechazada visible para el empleado, con nota del admin si se escribió
- [ ] Aprobar → aparece campo de vinculación; admin crea el servicio en Amelia y pega el ID → estado Publicada con `ext_service_id_result` correcto
- [ ] Vincular sin ID → 400
- [ ] Toda transición queda en auditoría

### 3.3 Customers

- [ ] `GET /customers` devuelve SOLO clientes con reservas con ese empleado (verificar contra Amelia → Appointments)
- [ ] Empleado B no ve clientes exclusivos del empleado A
- [ ] Búsqueda por nombre/teléfono/email funciona
- [ ] **Crear cliente** (tras habilitarse): aparece en Amelia → Customers sin duplicar si el email ya existe; auditado
- [ ] Crear sin nombre o sin teléfono → 400; email malformado → 400

### 3.4 Agenda

- [ ] Próximas/Pasadas/Canceladas devuelven las citas correctas (comparar con el calendario de Amelia)
- [ ] Cada cita muestra cliente y precio (si alguna sale sin ellos, anotar: significa que `customer_bookings` no pasó la introspección)
- [ ] Fecha malformada → 400; rango > 1 año → 400
- [ ] Empleado no ve citas de otros (verificar con dos empleados con citas el mismo día)

### 3.5 Stats

- [ ] Mes en curso: totales de citas cuadran con Amelia
- [ ] Ingresos estimados cuadran con la suma de precios de citas aprobadas+pendientes; `revenue_complete` en `true`
- [ ] Mes sin actividad: ceros, sin errores
- [ ] Top de servicios usa nombres reales
- [ ] Un empleado no puede obtener stats de otro (probar `?employee_id=` ajeno → 403)

### 3.6 Vistas admin

- [ ] Equipo: lista de empleados del proveedor completa; mapear/remapear funciona; auditado
- [ ] Auditoría: paginación con "Cargar más" correcta; filtros por acción/empleado vía API
- [ ] `/admin/status`: `writes_enabled` refleja la realidad tras implementar escrituras

## 4. Seguridad (obligatoria antes de producción)

- [ ] **Suplantación de identidad**: como empleado A, llamar endpoints con `?employee_id=` del empleado B → 403 en TODOS los endpoints de empleado
- [ ] **Escalada**: como empleado, llamar los 8 endpoints `/admin/*` → 403 en todos
- [ ] **IDOR en servicios**: `PUT /services/{id}/meta` con un ID no asignado al empleado → 403; con ID inexistente → 403
- [ ] **Nonce**: request sin `X-WP-Nonce` o con nonce inválido → 403 de WordPress
- [ ] **XSS almacenado**: guardar `<script>alert(1)</script>` como descripción corta y como nombre de propuesta → se muestra como texto plano en el portal (y en `/book` si se fusiona); nunca se ejecuta
- [ ] **SQLi**: `search`, `month`, `from/to`, ids con `'; DROP` y comillas → 400 o resultado vacío, nunca error SQL en logs
- [ ] **Privacidad**: `GET /admin/employees` como empleado → 403 (los emails de empleados solo los ve admin); respuestas de empleado no exponen datos de contacto de otros empleados
- [ ] **image_id ajeno**: apuntar a un attachment que existe pero es de otro contexto → decidir política y verificar (hoy: cualquier attachment válido pasa — revisar si conviene restringir al autor)
- [ ] **Auditoría íntegra**: cada escritura de las secciones 3.x generó su fila con actor y origen correctos; ninguna acción de escritura sin rastro
- [ ] **Enumeración**: endpoints con IDs secuenciales no revelan existencia de datos ajenos en los mensajes de error (mismo 403 genérico)

## 5. Regresión (nada existente se rompe)

- [ ] **Reserva completa en `/cata`** (flujo Amelia original): categoría → servicio → fecha → confirmación → email — igual que antes
- [ ] **Catálogo `/book`** (alb-catalog): carga, mismos servicios/precios/imágenes, API en ~0.4–0.5s como antes
- [ ] wp-admin de Amelia: editar servicio, empleado y cita funciona igual con el plugin activo
- [ ] Site Health: sin errores/avisos críticos nuevos respecto al estado previo
- [ ] `debug.log` / logs PHP del hosting: sin notices/warnings/errors nuevos del plugin en 24–48h de prueba
- [ ] Desactivar `alb-employee-portal` → `/cata`, `/book` y wp-admin siguen intactos; reactivar → datos del portal intactos
- [ ] El píxel de Meta (PixelYourSite) sigue disparando en las páginas públicas

## 6. Rendimiento y compatibilidad (paso 6)

- [ ] Portal en móvil real (390px), tablet (820px) y escritorio — sin scroll horizontal ni elementos rotos
- [ ] Modo oscuro y claro en ambos
- [ ] Chrome y Safari (iOS incluido)
- [ ] Consola del navegador limpia en las 8 vistas
- [ ] Tiempos de respuesta de la API del portal < 1s con datos reales (anotar: `/services` ___ ms, `/appointments` ___ ms, `/stats/summary` ___ ms)
- [ ] La página del portal no carga assets en páginas que no usan el shortcode (verificar en `/book` y la home)
- [ ] PageSpeed/Lighthouse de una página pública ANTES vs DESPUÉS de activar el plugin: sin regresión

## 7. Criterios de salida a producción (paso 7)

- [ ] Secciones 0–6 completas, sin ítems fallando
- [ ] Escrituras implementadas por mecanismos internos oficiales de Amelia (o decisión documentada de por qué se usó el nivel de respaldo)
- [ ] `ALB-EMPLOYEE-PORTAL.md` actualizado con los hallazgos de la sección 0 (cierra la sección 7 del documento de arquitectura)
- [ ] Versión etiquetada `1.0.0` (sin `-rc`) y copia del ZIP desplegado guardada
- [ ] Página real del portal creada (URL definitiva decidida: ______) y la de pruebas eliminada
- [ ] Riesgos Medios de la auditoría ([ALB-EMPLOYEE-PORTAL-AUDIT.md](ALB-EMPLOYEE-PORTAL-AUDIT.md)) resueltos o aceptados por escrito (R1 caché, R2 práctica post-update de Amelia, R3 purga opt-in)
- [ ] Visto bueno explícito de Ivanhoe

**Práctica permanente (auditoría R2):** tras cada actualización de Amelia, abrir la pestaña Equipo del portal (o `GET /admin/status`) y confirmar tablas 6/6 antes de dar el update por bueno.

---

*Preparado el 2026-07-10 sobre la RC 1.0.0-rc.1. Si durante las pruebas se corrige código, re-ejecutar la sección afectada completa, no solo el ítem que falló.*

---

## Apéndice A — Comandos listos para la sección 4 (seguridad)

Abrir la **página del portal** con la sesión indicada en cada bloque, F12 → Consola, pegar el preámbulo una vez y luego cada prueba. Cada línea dice el resultado esperado; cualquier otro resultado es un fallo que se anota en el checklist.

```js
// PREÁMBULO (pegar primero, sirve para todos los bloques)
const call = (p, m = 'GET', b) =>
  fetch('/wp-json/alb-employee-portal/v1' + p, {
    method: m, credentials: 'same-origin',
    headers: { 'X-WP-Nonce': ALB_EP_CONFIG.nonce, 'Content-Type': 'application/json' },
    body: b ? JSON.stringify(b) : undefined
  }).then(r => r.json().then(j => ({ http: r.status, ...j }))).then(console.log);
```

**Bloque 1 — con sesión de EMPLEADO (por ej. María). `OTRO` = un employee_id ajeno real; `SUYO`/`AJENO` = IDs de servicio reales:**

```js
call('/services?employee_id=OTRO')                      // → 403 alb_ep_forbidden (suplantación)
call('/stats/summary?employee_id=OTRO')                 // → 403 alb_ep_forbidden
call('/admin/status')                                   // → 403 (escalada)
call('/admin/audit')                                    // → 403
call('/admin/employee-map', 'PUT', {wp_user_id: 1, ext_employee_id: '1'}) // → 403
call('/services/AJENO/meta', 'PUT', {visible: false})   // → 403 (IDOR)
call('/services/12abc/meta', 'PUT', {visible: false})   // → 403 (ref no canónico)
call('/services/SUYO/meta', 'PUT', {short_description: '<script>alert(1)</script>'}) // → 200; verificar que en el portal se VE el texto literal, nunca ejecuta
call('/services/SUYO/meta', 'PUT', {image_id: 999999})  // → 400 alb_ep_invalid_image
call('/customers?search=%27%3B%20DROP%20TABLE')         // → 200 lista vacía, jamás error SQL
call('/service-requests', 'POST', {name: 'x', price: -5})          // → 400 alb_ep_invalid_price
call('/service-requests', 'POST', {name: 'x', price: 99999999999}) // → 400 alb_ep_invalid_price
call('/appointments?from=31-12-2026')                   // → 400 alb_ep_invalid_date
```

**Bloque 2 — SIN sesión (ventana de incógnito, en la página del portal se ve el botón de login; usar la home):**

```js
fetch('/wp-json/alb-employee-portal/v1/me', {credentials:'same-origin'})
  .then(r => console.log(r.status))                     // → 401
```

**Bloque 3 — con sesión de ADMIN:**

```js
call('/me')                                             // → 200, is_admin: true
call('/admin/service-requests/ID_LINKED', 'PUT', {status: 'approved'}) // → 409 alb_ep_invalid_transition (no se reabre un estado final)
call('/admin/audit?per_page=999')                       // → 200 con máx. 100 filas
```

**Bloque 4 — nonce inválido (cualquier sesión):**

```js
fetch('/wp-json/alb-employee-portal/v1/me', {credentials:'same-origin',
  headers: {'X-WP-Nonce': 'nonce-falso'}}).then(r => console.log(r.status)) // → 403
```

## Apéndice B — Nivel 1 (API Elite) pre-documentado para la fase de análisis

Investigado en la documentación pública de Amelia por si el inspector confirma licencia Elite (fuentes: [API Customers](https://wpamelia.com/documentation/api-customers/), [API Employees](https://w
