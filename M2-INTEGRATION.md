# M2-INTEGRATION — Integración ALB Messenger ↔ Amelia

> Responde las 10 preguntas de Ivanhoe previas al código del módulo M2. Ninguna línea de código se escribe hasta aprobar este documento.
> Fuentes: documentación oficial de Amelia — [WP hooks bookings](https://wpamelia.com/documentation/wp-hooks-bookings/), [WP hooks appointments](https://wpamelia.com/documentation/wp-hooks-appointments/).
> Última actualización: 2026-07-18

## Principio rector (afecta a casi todas las respuestas)

**Los hooks son un timbre, no un mensajero.** Cuando un hook de Amelia se dispara, el adaptador extrae del payload UNA sola cosa: el ID de la cita afectada. Todo lo demás (fechas, estado, empleado, cliente, servicio) se **relee del estado canónico en la base de datos de Amelia** en ese momento, con la lectura introspectiva. Consecuencias:

- Da igual que el formato del payload cambie entre versiones de Amelia: solo dependemos de que exista un ID.
- El camino del hook y el camino del cron son **la misma función**: `sync_appointment(ext_id)` — una función de *convergencia* que lee el estado real en Amelia y ajusta nuestras tablas hasta coincidir. Una sola pieza de código que probar, en vez de dos.
- Ejecutarla una vez o veinte veces con el mismo estado de origen produce exactamente las mismas filas (base de la idempotencia, pregunta 7).

---

## 1. ¿Qué hooks oficiales de Amelia se utilizarán?

Registrados defensivamente (un `add_action` sobre un hook que no exista en esa versión es un no-op inocuo):

| Hook | Cuándo dispara | Uso |
|---|---|---|
| `amelia_after_booking_added` | Reserva desde el formulario front-end (el camino principal: cliente reserva) | `sync_appointment(id)` |
| `amelia_after_appointment_added` | Cita creada desde wp-admin | `sync_appointment(id)` |
| `amelia_after_appointment_updated` | Cita editada (reprogramación, cambio de empleado) | `sync_appointment(id)` |
| `amelia_after_appointment_status_updated` | Cambio de estado (aprobada/cancelada/rechazada) | `sync_appointment(id)` |
| `amelia_after_booking_canceled` | Cancelación por el cliente | `sync_appointment(id)` |
| `amelia_after_appointment_deleted` | Borrado de la cita | `sync_appointment(id)` (la detecta ausente y desactiva su ventana) |

**Verificación en sitio real (parte de la prueba de salida de M2):** los nombres exactos varían entre versiones de Amelia. El adaptador incluirá durante las pruebas un registro temporal que anota qué hooks se disparan realmente al crear/editar/cancelar una cita de prueba en albookings.com; los nombres se ajustan con esa evidencia. Si algún hook no existe en la versión instalada, no pasa nada: el cron (pregunta 4) cubre el hueco por diseño.

## 2. ¿Qué datos se reciben en cada hook?

Amelia pasa arrays con la estructura de la cita/reserva (id, serviceId, providerId, bookingStart, bookingEnd, status, y la lista de bookings con customerId). **Deliberadamente solo usamos el ID** (con tolerancia de forma: se busca `id` en las claves habituales del array del hook — `appointment[id]`, `booking[appointmentId]`). El resto se relee de la BD de Amelia. Así el contrato con el payload es mínimo y estable, y la documentación incompleta de Amelia sobre sus payloads deja de ser un riesgo.

## 3. ¿Qué ocurre si un hook no se dispara?

Nada se pierde; solo se retrasa. El cron de reconciliación (pregunta 4) detecta la divergencia en su siguiente pasada (≤15 min) y ejecuta la misma `sync_appointment()`. Efecto visible en el peor caso: el chat de una reserva nueva aparece hasta 15 minutos tarde. **Principio fail-closed:** la ventana de escritura solo se abre cuando NUESTRA fila de cita existe — datos ausentes nunca conceden acceso, solo lo retrasan.

## 4. ¿Cómo se reconcilia el estado mediante cron?

Dos barridos, ambos bajo el mismo lock (pregunta 8):

- **Incremental, cada 15 minutos:** consulta acotada a las citas de Amelia con `bookingStart` en la banda [hoy−7 días, hoy+90 días] (una consulta indexada) y las compara contra nuestra proyección `albm_appointments` de esa misma banda. Tres diferencias posibles: (a) cita en Amelia sin fila nuestra → sync (alta perdida); (b) fila nuestra con estado/fechas/empleado distintos → sync (edición perdida); (c) fila nuestra cuya cita ya no existe en Amelia → sync (borrado). Al final, recomputa el estado de las conversaciones afectadas (active↔readonly) y el archivado a 12 meses (barrido diario).
- **Completo, diario:** misma lógica sin banda de fechas, para la deriva histórica rara (p. ej. una cita antigua editada a mano). 

El cron no confía en "qué cambió": deriva TODO del estado actual de Amelia. Por eso también repara errores nuestros, no solo hooks perdidos.

## 5. ¿Qué pasa si se cambia una cita manualmente desde wp-admin?

Las ediciones del backend de Amelia pasan por sus propios handlers → disparan los hooks de backend (fila 2–6 de la tabla). Si esa versión no dispara alguno, el cron detecta la diferencia contra el estado canónico en ≤15 min. Caso especial — **reasignación a otro empleado**: `sync_appointment()` detecta que el `providerId` actual no coincide con el par de la conversación vinculada → mueve la fila de la cita al hilo del nuevo par (find-or-create), registra `employee_changed` en el hilo viejo y `appointment_linked` en el nuevo, y recalcula ventanas de ambos. El historial de mensajes de cada hilo no se toca jamás.

## 6. ¿Cómo se evitan conversaciones duplicadas?

**Por restricción de base de datos, no por lógica de aplicación.** `albm_conversations.pair_key` es UNIQUE (`amelia:{customer_ref}:{employee_ref}`). El find-or-create no hace "consultar y si no existe insertar" (eso tiene una carrera): hace **INSERT y deja que MySQL decida** — si el INSERT falla por clave duplicada, se re-selecciona la fila que otro proceso acaba de crear. Dos hooks simultáneos del mismo par (cliente que reserva dos servicios seguidos) producen exactamente una conversación, garantizado por el motor de la BD. Lo mismo con las citas: `ext_appointment_id` UNIQUE.

## 7. ¿Cómo se garantiza la idempotencia?

Tres mecanismos, del más fuerte al más fino:

1. **Claves naturales únicas** (pregunta 6): re-crear lo ya creado es imposible.
2. **Convergencia**: `sync_appointment()` no aplica "cambios" — calcula el estado objetivo desde Amelia y lo escribe. N ejecuciones = mismo resultado.
3. **Compare-and-swap para los eventos**: un evento (`appointment_rescheduled`, `employee_changed`…) solo se registra si la actualización de la proyección **realmente cambió una fila** — el UPDATE lleva la condición del estado anterior (`... WHERE ext_appointment_id=%s AND (status<>%s OR starts_at<>%s OR ...)`) y solo si `affected_rows > 0` se inserta el evento. Re-sincronizar sin cambios reales = cero eventos duplicados, cero mensajes fantasma en la línea de tiempo.

## 8. ¿Qué estrategia de locking?

- **Unicidad**: la garantía dura contra duplicados es la restricción UNIQUE (nivel MySQL) — no depende de ningún lock.
- **Cron**: `GET_LOCK('albm_reconcile', 0)` de MySQL al empezar el barrido; si no se obtiene (otro barrido en curso), la ejecución **se salta limpia**. Se eligió GET_LOCK y no un lock por opción/transient de WordPress porque el lock de MySQL **se libera solo si el proceso muere** (está atado a la conexión) — un transient-lock huérfano tras un fatal bloquearía la reconciliación para siempre.
- **Sincronización individual**: `sync_appointment()` NO necesita lock — la convergencia (7.2) + CAS (7.3) + claves únicas (7.1) hacen seguras las ejecuciones concurrentes; la última en escribir deja el mismo estado objetivo que la primera.

## 9. ¿Qué ocurre si el cron se ejecuta dos veces?

- **En paralelo**: el segundo no obtiene `GET_LOCK` y termina inmediatamente sin tocar nada.
- **En serie** (p. ej. wp-cron reencolado): el segundo barrido encuentra todo ya convergido — por (7.2) no escribe filas y por (7.3) no emite eventos. Coste: una consulta de lectura.
- **Carrera residual hook-vs-cron** sobre la misma cita en el mismo instante: ambos convergen al mismo estado (inofensivo) y el CAS garantiza que solo uno registra el evento (el UPDATE condicional solo afecta filas en uno de los dos).

## 10. ¿Qué ocurre si WordPress falla entre dos operaciones?

El orden de escritura está diseñado para que **todo prefijo parcial sea un estado seguro y auto-reparable**:

```
1. conversación (o ya existía)        ← sin lo demás: hilo vacío inofensivo, invisible sin citas
2. participantes                      ← sin cita aún: chat fail-closed (no hay ventana → nadie escribe)
3. fila de cita (writable_until)      ← desde aquí el chat funciona
4. evento informativo                 ← lo último; si falta, solo falta una línea informativa
5. recomputar estado del hilo
```

Ningún paso depende de que un paso *posterior* haya ocurrido, y cada paso es idempotente. Si PHP muere entre el 2 y el 3, queda una conversación sin cita: nadie puede escribir en ella (fail-closed) y el siguiente disparo — el reintento del hook o el cron en ≤15 min — ejecuta la misma convergencia y completa lo que faltó. No se usan transacciones multi-sentencia como *garantía* (aunque las tablas son InnoDB) precisamente para que la corrección no dependa del entorno: la dan la convergencia, el CAS y las claves únicas. **No existe ningún orden de fallo que conceda acceso indebido o corrompa datos; todos los fallos degradan a "retraso ≤ un ciclo de cron".**

---

## Prueba de salida de M2 (recordatorio del plan, ampliada por este documento)

Simulación del ciclo completo contra fixtures: alta → hilo+ventana correctos · reprogramación → ventana recalculada + 1 solo evento · cancelación → evento + estado · reasignación de empleado → movimiento entre hilos · **sync repetido 3× → cero filas y cero eventos nuevos** (idempotencia) · **dos find-or-create concurrentes simulados → una sola conversación** (unicidad) · sync con prefijo parcial (sin fila de cita) → chat cerrado y reparación en el siguiente ciclo. En el sitio real: registro temporal de qué hooks disparan de verdad.
