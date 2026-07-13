# ALB Employee Portal — Auditoría final de estabilidad (pre-despliegue)

> Versión auditada: **1.0.0-rc.2** (commit `4b7a816`). Fecha: 2026-07-13.
> Alcance: solo estabilidad para producción — no se escribió funcionalidad nueva. Los 10 puntos pedidos por Ivanhoe, con el informe de riesgos al final.
> Método: relectura dirigida del código + chequeos automatizados (grep de propiedades dinámicas y funciones deprecadas, lint PHP 8.4 con E_ALL, prueba de humo, inventario de capacidades y opciones).

---

## 1. Compatibilidad con futuras actualizaciones de Amelia

**Diseño verificado:** el plugin jamás escribe en tablas de Amelia (las escrituras están deshabilitadas en esta RC) y jamás modifica sus archivos. Todas las lecturas pasan por introspección: antes de cada consulta se verifica que la tabla exista **y** que tenga las columnas esperadas (`Amelia_Provider::table()`); si una actualización de Amelia renombra una columna, el adaptador devuelve un 503 limpio (`alb_ep_provider_unavailable`) en vez de un error SQL, y `/admin/status` muestra exactamente qué tabla dejó de reconocerse.

**Límite honesto:** la compatibilidad es *detectada*, no *garantizada* — un cambio de esquema de Amelia deja la vista afectada fuera de servicio hasta ajustar el adaptador (un solo archivo). El catálogo público `/book` y las reservas de Amelia no se ven afectados en ningún caso.

**Regla operativa recomendada:** tras cada actualización de Amelia, abrir la pestaña Equipo (muestra el diagnóstico) o `GET /admin/status`. Añadido como práctica al checklist de pruebas.

## 2. Compatibilidad WordPress 6.x y PHP 8.2/8.3

- **Chequeo automatizado:** cero propiedades dinámicas sin declarar (deprecadas en 8.2, error en 9), cero funciones removidas/deprecadas (`create_function`, `each`, `utf8_encode`, `strftime`, `FILTER_SANITIZE_STRING`…).
- Lint y prueba de humo ejecutados con **PHP 8.4** y `E_ALL`: sin errores ni avisos de deprecación (8.4 es superconjunto estricto de 8.2/8.3 en deprecaciones).
- APIs de WordPress usadas: `register_rest_route`, `dbDelta`, shortcodes, `wp_localize_script`, `WP_User_Query`, opciones — todas estables desde mucho antes de WP 6.0. Sin jQuery, sin Gutenberg, sin APIs experimentales.
- Tipos SQL conservadores (`longtext` para JSON de auditoría, no el tipo `JSON`) — compatible con MariaDB de Cloudways.
- **Sin verificar en vivo:** el hosting real (se confirma en la sección 1 del checklist de pruebas con la versión exacta de PHP del servidor).

## 3. Fugas de memoria y consultas N+1

- **N+1: no hay.** Conteo de consultas por endpoint (verificado): `/services` = 2 (catálogo + meta en bloque), `/customers` = 1 agregada, `/appointments` = 1 agregada, `/stats/summary` = 2, auditoría = 2 (COUNT + página). Ningún bucle ejecuta consultas.
- **Por carga de página del sitio:** 1 consulta (`active()` sobre la tabla de módulos, PK, ~5 filas) + 1 opción autoload. Las migraciones ya no tocan la BD salvo cambio de versión (corregido en rc.2).
- **Memoria PHP:** sin estado entre requests (modelo request-scoped de WP); la única caché en memoria (`Amelia_Provider::$columns`) vive por request. Consultas sin LIMIT en agenda (acotada a 1 año) y clientes (clientes de un empleado) — a escala de salón, irrelevante; anotado como riesgo Bajo con umbral.
- **JS:** los dos listeners delegados en `el.view` se registran una sola vez; los listeners por vista mueren con sus nodos al re-renderizar (sin acumulación). La guardia de secuencia de rc.2 evita trabajo de renders obsoletos.

## 4. Concurrencia (dos empleados / dos pestañas / dos admins)

- **Dos empleados a la vez:** escriben filas distintas (clave única `provider+ext_service_id+ext_employee_id`) — sin interferencia posible. Las propuestas son filas independientes por empleado.
- **Mismo empleado en dos pestañas (update_meta):** patrón select-then-write; en la carrera de primera creación, el segundo INSERT choca con la clave única, `wpdb->insert` devuelve `false` y el endpoint responde 500 limpio (rc.2) — sin corrupción, el usuario reintenta. En ediciones posteriores gana el último (last-write-wins), con ambas ediciones auditadas.
- **Dos admins sobre la misma propuesta:** la validación de transiciones (rc.2) elimina los estados imposibles; queda una ventana leer-validar-escribir en la que dos escrituras *ambas válidas desde `pending`* (aprobar y rechazar a la vez) resuelven por last-write-wins. Ambas quedan en auditoría. Con un solo administrador (situación actual del negocio) el riesgo es teórico; la mitigación futura documentada es `UPDATE ... WHERE status = %s` (bloqueo optimista).

## 5. Integridad de datos ante interrupción de una escritura

- Todas las escrituras del plugin son **de una sola fila y una sola sentencia** — atómicas a nivel de MySQL. No existen escrituras multi-tabla que puedan quedar a medias.
- Orden interno: escritura → auditoría → evento. Una caída entre pasos puede dejar un cambio sin fila de auditoría o sin evento emitido (nunca lo inverso: desde rc.2 la auditoría solo se escribe si la escritura confirmó). Pérdida de rastro posible, corrupción imposible.
- **Migraciones:** `dbDelta` es idempotente y la opción de versiones se actualiza **al final**; una migración interrumpida se re-ejecuta completa en la siguiente carga. Verificado en el flujo de `Module_Registry::migrate()`.

## 6. Caché de Breeze / Cloudways (Varnish)

Tres puntos de fricción identificados — ninguno requiere código, todos son configuración del despliegue:

1. **El nonce dentro de la página del portal.** `wp_create_nonce('wp_rest')` se imprime en el HTML; si la página del portal terminara en la caché de página (Breeze o Varnish), un visitante recibiría un nonce ajeno/vencido y toda la API respondería 403. Por defecto Breeze y el Varnish de Cloudways **no cachean usuarios con sesión** (cookie `wordpress_logged_in`), y la página sin sesión solo muestra el botón de login — pero la exclusión explícita cuesta un minuto y elimina el riesgo: **añadir la URL del portal a las exclusiones de Breeze y de Varnish**.
2. **Minificación/combinación de JS de Breeze:** puede romper scripts. `portal.js` es un IIFE clásico sin dependencias (bajo riesgo), pero **excluirlo de la minificación de Breeze** es la opción segura; ya se sirve versionado (`?ver=1.0.0-rc.2`), así que las actualizaciones rompen caché correctamente.
3. **REST API:** WordPress envía `Cache-Control: no-cache` en respuestas autenticadas y las requests llevan cookies (Varnish hace pass) — sin acción necesaria; verificar en el ítem correspondiente del checklist.

Ambas exclusiones se añadieron al checklist de pruebas (sección 1).

## 7. Comportamiento con Amelia desactivado o fallando

**Verificado en la prueba de humo** (el harness no tiene ninguna tabla de Amelia): el plugin activa sin error, registra sus 19 rutas, las migraciones corren, y toda lectura del proveedor devuelve 503 `alb_ep_provider_unavailable` con mensaje traducible; el portal muestra el estado vacío y un toast, jamás un fatal. No hay `use`/`new` de clases de Amelia en ningún sitio (el contenedor llega por filtro, default `null`), así que desactivar Amelia no puede producir "class not found". Las tablas propias (meta, propuestas, auditoría, mapeo) siguen operativas sin Amelia. Reactivada Amelia, todo vuelve solo — la introspección se reevalúa por request.

## 8. Desinstalación completa

**Estado actual (hallazgo):** `uninstall.php` conserva deliberadamente las 5 tablas (decisión correcta como *default* — protege datos), pero **no ofrece la vía opt-in de purga total** que pide este punto, y deja huérfanos: las tablas `alb_ep_*` y la opción **autoload** `alb_ep_schema_versions` (esta última se carga en memoria en cada página del sitio para siempre, aunque son ~200 bytes).

**Recomendación (2 líneas + condicional, pendiente de tu OK — no se implementó por la instrucción de no escribir funcionalidad):** que `uninstall.php` borre siempre la opción, y que las tablas se eliminen solo si el administrador define `ALB_EP_UNINSTALL_DROP_DATA` en `wp-config.php` — el patrón estándar de WooCommerce y similares. Es el único punto de los 10 que deja un cambio de código propuesto.

## 9. Capacidades y permisos de WordPress

Inventario completo (verificado por grep, no hay más):

| Chequeo | Dónde | Uso |
|---|---|---|
| `is_user_logged_in()` | Rest_Kernel, Portal_UI | Piso de toda la API y del shortcode |
| mapeo en `alb_ep_employee_map` | Identity | Identidad de empleado — resuelto SIEMPRE en servidor |
| `current_user_can('manage_options')` | Identity (único punto) | Todo lo admin: 8 rutas, inspector |

- **Mínimo privilegio:** un empleado NO necesita ninguna capability especial — basta un usuario con sesión (rol `subscriber` o el `wpamelia-provider` de Amelia) + su fila de mapeo. El plugin no otorga, crea ni modifica roles/capabilities.
- No se usa `upload_files` (la imagen se valida por autoría del attachment + tipo imagen, decisión de rc.2), ni `edit_posts`, ni capabilities de Amelia.
- **Nota consciente:** el rol "Amelia Manager" NO es admin del portal (solo `manage_options`). Si algún día un manager debe gestionar la cola, será una decisión explícita, no un accidente.
- El nonce expuesto por `wp_localize_script` es el estándar `wp_rest`; no se expone ningún otro secreto al frontend.

## 10. Informe de riesgos finales

Ordenado por severidad. **No queda ningún riesgo Alto conocido en el código.**

| # | Riesgo | Severidad | Mitigación |
|---|---|---|---|
| R1 | Página del portal cacheada con nonce embebido (Breeze/Varnish mal configurados) → API entera responde 403 a usuarios reales | **Media** | Operativa: excluir la URL del portal de Breeze y Varnish antes de publicar (checklist §1); el default de ambos ya no cachea sesiones |
| R2 | Actualización futura de Amelia renombra columnas → vistas del proveedor en 503 hasta ajustar el adaptador (1 archivo) | **Media** | Diseño degrada limpio + diagnóstico en Equipo//admin/status; revisar tras cada update de Amelia (checklist) |
| R3 | Desinstalación deja tablas y una opción autoload huérfanas; no existe purga opt-in | **Media** | Propuesta de 2 líneas en §8, pendiente de OK de Ivanhoe (único cambio de código sugerido) |
| R4 | Minificación de JS de Breeze podría romper portal.js | **Media-Baja** | Excluir de minificación (checklist §1); el script es IIFE sin dependencias |
| R5 | Carrera admin-admin en la misma propuesta (aprobar vs rechazar simultáneos) → last-write-wins auditado | **Baja** | Solo hay un admin hoy; transiciones inválidas ya bloqueadas (rc.2); bloqueo optimista documentado como mejora futura |
| R6 | Interrupción entre escritura y auditoría → cambio sin rastro (nunca corrupción) | **Baja** | Escrituras monofila atómicas; orden escritura→auditoría garantiza que la auditoría nunca miente |
| R7 | Consultas sin LIMIT en agenda (máx. 1 año) y clientes del empleado | **Baja** | Irrelevante a escala actual; umbral: revisar si un empleado supera ~5.000 citas/año |
| R8 | `GROUP_CONCAT` trunca a 1.024 caracteres los nombres de una cita grupal enorme (~40+ asistentes) | **Baja** | Solo afecta el texto mostrado, nunca conteos ni dinero (COUNT/SUM no se truncan) |
| R9 | Mismo empleado en dos pestañas: primera edición simultánea → un 500 limpio y reintento | **Baja** | Clave única garantiza integridad; UX de reintento aceptable |
| R10 | PHP del hosting sin confirmar (auditado contra 8.4 local) | **Baja** | Se confirma en checklist §0 antes de activar |

### Veredicto

La rc.2 está **apta para el despliegue en el entorno de pruebas** (paso 4 del plan) sin cambios de código adicionales, con dos condiciones operativas: las exclusiones de caché (R1/R4) configuradas antes de la primera prueba, y tu decisión sobre la purga opt-in de desinstalación (R3) — puede entrar en rc.3 o posponerse documentada. Los riesgos Bajos quedan aceptados y monitoreados por el checklist.
