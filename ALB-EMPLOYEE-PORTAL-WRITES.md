# ALB Employee Portal — Plan de escrituras hacia Amelia (pre-análisis)

> **Estado: BORRADOR DE ANÁLISIS — ningún código escrito, ninguna vía elegida.** Este documento se completa y se convierte en la propuesta formal cuando llegue el informe del inspector. Sigue el protocolo obligatorio de la fase RC (documento de arquitectura): analizar → explicar → enumerar alternativas → justificar → **esperar aprobación explícita de Ivanhoe antes de escribir código**.
>
> Preparado por adelantado el 2026-07-14 con la documentación pública de Amelia, para acelerar la sesión. Los contratos del nivel 1 (API pública) están investigados; los niveles 2–5 dependen del código real de tu instalación (los revela el inspector).

---

## Las dos escrituras dentro del alcance de la 1.0.0

Solo estas dos. Nada más se habilita (la creación de servicios sigue siendo propuesta→aprobación manual; ver alcance congelado).

| # | Operación | Endpoint del portal (ya existe, hoy devuelve 501) | Método del gateway a implementar |
|---|---|---|---|
| W1 | Actualizar precio (y capacidad) de un servicio **para un empleado** | `PUT /services/{id}/pricing` | `Amelia_Provider::update_employee_service_pricing()` |
| W2 | Crear un cliente | `POST /customers` | `Amelia_Provider::create_customer()` |

---

## Escala de prioridad (regla de Ivanhoe) y estado de cada nivel

Nunca elegir un nivel inferior si uno superior es suficientemente estable.

### Nivel 1 — API pública oficial (REST de Amelia) · **investigado**

Solo existe con **licencia Elite** — el inspector confirma si la instalación la tiene. Autenticación por header `Amelia: <API key>`. Base: `wp-admin/admin-ajax.php?action=wpamelia_api&call=/api/v1/...`.

- **W1 (precio por empleado):** `PUT .../users/providers/{id}` con el `serviceList` del empleado, cada entrada `{ id, price, minCapacity, maxCapacity }`. **Riesgo crítico detectado en la doc:** el PUT de provider recibe al empleado **completo** (status, firstName, email, serviceList, weekDayList…). Enviar un `serviceList` parcial podría **desasignar servicios no incluidos** o pisar el horario. Para W1 habría que **leer el provider completo, modificar solo el precio del servicio objetivo y reenviarlo íntegro** — un ciclo read-modify-write con su propia ventana de carrera. Esto pesa en la evaluación: una API que obliga a reenviar todo el agregado es *menos* segura para un cambio quirúrgico que un servicio interno que actualice una sola fila.
- **W2 (crear cliente):** `POST .../users/customers` con `{ firstName, lastName, phone, email?, note?, externalId? }`. Email, si se envía, **debe ser único** — Amelia rechaza duplicados (por eso el portal permite email opcional). Devuelve el cliente creado con su id. Encaja bien con W2.

Fuentes: [API Customers](https://wpamelia.com/amelia-api-customers/), [API Employees](https://wpamelia.com/amelia-api-employees/), [API general](https://wpamelia.com/documentation/api/).

**El inspector debe confirmar:** ¿licencia Elite? ¿hay API key generada (o hay que crearla)? Si no es Elite, este nivel **no existe** y se pasa al 2.

### Nivel 2 — Servicios internos documentados (Application Services) · **pendiente del inspector**

Amelia es DDD: `AmeliaBooking\Application\Services\*`. El inspector lista los archivos reales bajo `src/Application/Services/`. A confirmar: ¿existe un `ProviderApplicationService`/`ServiceApplicationService` con un método de actualización de precio por empleado utilizable en proceso? ¿un `CustomerApplicationService::add()` o equivalente para W2? Ventaja sobre el nivel 1: opera en proceso (sin HTTP, sin API key), con las validaciones de Amelia, y puede permitir un cambio más quirúrgico.

### Nivel 3 — Command Handlers internos · **pendiente del inspector**

`AmeliaBooking\Application\Commands\**\*CommandHandler`. El inspector busca `Add*/Update*` sobre `Customer`, `Provider`, `Service`. Un command handler ejecuta la misma lógica que el endpoint AJAX de wp-admin (validación + persistencia + eventos). A confirmar: firma, dependencias que requiere del contenedor, y si es invocable sin el ciclo HTTP completo de Amelia.

### Nivel 4 — Contenedor interno · **pendiente del inspector**

El filtro `alb_ep_amelia_container` ya está cableado en el adaptador (hoy devuelve `null`). El inspector confirma qué clase/función expone el contenedor (`\AmeliaBooking\Infrastructure\Container`, `amelia_container()`…) para poder resolver repositorios y servicios de los niveles 2–3.

### Nivel 5 — SQL directo replicando la lógica de Amelia · **último recurso**

Solo si 1–4 no ofrecen una vía estable. Implicaría replicar exactamente lo que Amelia hace en esas tablas (`wp_amelia_users` para W2; `wp_amelia_providers_to_services` para W1), incluyendo cualquier columna calculada o normalización. **Riesgo alto de divergencia** ante actualizaciones de Amelia; requeriría el inspector volcando el esquema exacto y la lógica de escritura de la versión instalada. Se documentaría como deuda con vigilancia por versión.

---

## Lo que se debe demostrar antes de habilitar (regla de validación de Ivanhoe)

Para la vía elegida, la propuesta formal incluirá, con evidencia del código real:

- **Clases y métodos exactos** que se invocarán.
- **Validaciones que ejecuta Amelia** (unicidad de email en W2; pertenencia servicio↔empleado y rangos de capacidad en W1).
- **Eventos que dispara Amelia** (notificaciones, sincronización con Google Calendar, webhooks de PixelYourSite/Meta si aplican) — para no producir efectos secundarios inesperados desde el portal.
- **Efectos secundarios** (¿W2 crea también un usuario de WordPress? ¿toca caché de Amelia?).
- **Comportamiento ante error** — y cómo se garantiza consistencia.

## Consistencia (regla innegociable)

- **W2** es naturalmente atómica (una entidad cliente). Si falla, no se creó nada — el portal reporta error y no escribe su auditoría (patrón ya vigente desde rc.2).
- **W1** es el punto de cuidado: si la vía elegida obliga a read-modify-write del provider completo (nivel 1), **cualquier fallo debe abortar sin dejar el provider a medias**. Criterio de aceptación: si no se puede garantizar que W1 sea atómica o completamente reversible, esa vía se descarta y se baja al siguiente nivel que sí lo garantice — aunque sea inferior en la escala. La consistencia manda sobre la posición en la escala.

## Qué NO cambia con las escrituras

- Cero tablas nuevas (W1 y W2 escriben en Amelia, no en tablas propias).
- El endpoint, los permisos, la validación de entrada y la auditoría del portal ya existen desde rc.2 — solo se reemplaza el cuerpo de los dos métodos del gateway que hoy devuelven 501.
- `/admin/status` pasará a reportar `writes_enabled: true` y la insignia de la pestaña Equipo cambiará a verde.

## Verificaciones antes de entregar la RC con escrituras (regla de despliegue)

- Pruebas de W1 y W2 (éxito, validación, error, aborto consistente).
- Cero warnings nuevos (lint + E_ALL).
- Conteo de consultas: documentar el de cada escritura; no debe subir el de las **lecturas** ya medidas en la auditoría.
- Regresión: Amelia crea citas/clientes/servicios exactamente igual con el portal activo.
