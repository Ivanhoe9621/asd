# ALB Messenger — Análisis de arquitectura (Etapa 1)

> Proyecto independiente. Estado: **propuesta de arquitectura en análisis — sin código, pendiente de las decisiones de Ivanhoe listadas al final.**
> Última actualización: 2026-07-16

## 0. Qué es

Plugin de WordPress **`alb-messenger`**: mensajería privada entre el cliente y el empleado asignado de una reserva de Amelia, al estilo DoorDash/Uber, con supervisión total del administrador. Amelia sigue siendo solo el sistema de reservas: **no se modifica, no se editan sus archivos, no se escriben sus tablas** — el messenger solo *lee* de Amelia para saber quién habla con quién y por qué cita.

---

## 1. El modelo de conversación (la decisión que define todo)

### Alternativas

**A. Una conversación por cita** (modelo gig-economy puro: DoorDash/Uber)
- ✅ Contexto clarísimo: cada chat trata de UNA cita; ventana de vida natural (se abre al reservar, se cierra tras el servicio).
- ✅ Permisos triviales: participantes = los de esa cita.
- ❌ Un salón NO es una gig-economy: la clienta que viene cada 3 semanas con la misma estilista tendría 15 chats fragmentados con la misma persona.
- ❌ "¿Dónde me dijiste lo del tinte?" — la información útil queda dispersa.

**B. Una conversación por par cliente↔empleado** (modelo WhatsApp)
- ✅ Historial continuo — refleja la relación real de un salón (clientela recurrente).
- ✅ Una sola entrada en la bandeja por persona.
- ❌ ¿Cuándo se puede escribir? Sin cita de por medio, un chat siempre-abierto invita a coordinar por fuera de la plataforma (pierdes reservas) y a spam.
- ❌ El contexto de "esta cita concreta" se diluye.

**C. Híbrido: conversación por par + citas como contexto y como "llave"** ⭐ recomendada
- El hilo es **uno por par cliente↔empleado** (historial continuo).
- Cada cita de Amelia se **vincula al hilo** y genera un separador de sistema ("📅 Nueva cita: Balayage — 22 jul, 10:00").
- La cita actúa de **llave de escritura**: el chat es escribible solo cuando existe una cita en ventana activa (propuesta: desde que la reserva se confirma hasta N horas después de terminar; configurable). Fuera de ventana: **solo lectura** (el historial nunca se pierde).
- ✅ Combina la relación continua del salón con la disciplina de "el chat existe porque hay una cita".
- ❌ Es el modelo con más lógica (ventanas, estados) — se paga una vez en el diseño.

**Respuestas directas a las preguntas planteadas:**
- *¿Una conversación por cita?* No: por par, con la cita como contexto y llave (modelo C).
- *¿Qué pasa si el cliente reserva varias veces?* Mismo hilo; cada nueva cita añade su separador y reabre la ventana de escritura.
- *¿Y si reserva con otro empleado?* Se crea otro hilo (par distinto). Si Amelia reasigna la cita de empleado, el hilo viejo pasa a solo lectura con nota de sistema y se abre/reactiva el del nuevo par.

## 2. Identidad: quién es quién

**Empleados:** usuario WP ↔ empleado de Amelia. Mapeo explícito administrado por el admin (tabla propia del messenger); nunca se confía en un ID enviado por el cliente — la identidad se resuelve SIEMPRE en servidor a partir de la sesión.

**Clientes — el problema real:** en Amelia un cliente puede existir sin usuario de WordPress. Sin login no hay manera segura de autenticar el chat. Alternativas:

| | A. Exigir cuenta WP ⭐ | B. Magic links por reserva (invitado) |
|---|---|---|
| Cómo | Activar la opción nativa de Amelia "Automatically create Amelia Customer user" (Settings → Roles Settings): cada cliente que reserva recibe usuario WP + email con acceso | Token firmado por cita enviado por email/SMS que abre el chat sin login |
| Seguridad | 🟢 sesión estándar de WP, nonces, todo probado | 🔴 tokens = superficie nueva: expiración, revocación, reenvío, robo del enlace |
| Esfuerzo v1 | 🟢 casi nulo (es un setting de Amelia) | 🔴 alto |
| Fricción usuario | 🟡 un login (una vez; Amelia auto-loguea al panel del cliente) | 🟢 cero |
| Recomendación | **v1** | v1.1+ si la fricción resulta problema real |

**Consecuencia:** los clientes con reservas previas a activar el setting no tendrán usuario WP → el admin podrá vincularlos manualmente o quedarán sin chat hasta su próxima reserva. Aceptable para v1.

## 3. Permisos y el rol del administrador

Diseñado desde el origen con el admin dentro:

- **Participantes** (cliente, empleado): leer y escribir en SUS hilos, solo dentro de ventana activa.
- **Administrador** (`manage_options`): acceso de lectura a TODAS las conversaciones desde su panel; puede escribir en cualquier hilo (interviene como "Soporte AL Bookings", claramente etiquetado, nunca suplantando a nadie); puede cerrar/reabrir ventanas y archivar hilos.
- **Toda validación en servidor**: cada request resuelve identidad→participación→estado de ventana antes de tocar datos. El frontend jamás decide.
- **Transparencia de la supervisión (decisión de producto, no técnica):** ¿la lectura del admin es silenciosa, silenciosa pero registrada internamente, o visible para los participantes? Propuesta: **registrada internamente** (fila de auditoría por acceso: quién, qué hilo, cuándo) + los mensajes que escriba siempre visibles como Soporte. Es tu llamada — tiene implicaciones de confianza y legales.

## 4. Almacenamiento

**Descartado** usar `wp_posts`/comentarios (no escala, colisiona con todo). **Tablas propias** con esquema versionado y migraciones:

```
albm_conversations   id · provider('amelia') · customer_ref · employee_ref ·
                     customer_wp_id · employee_wp_id · status(active|readonly|archived) ·
                     window_until (datetime null) · last_message_at · created_at
                     UNIQUE (provider, customer_ref, employee_ref)

albm_messages        id · conversation_id · sender_wp_id (null = sistema) ·
                     type('text'|'system'; futuros: image/file/voice/location) ·
                     body (longtext) · payload (longtext JSON null) ·
                     created_at · deleted_at (null; borrado suave)
                     INDEX (conversation_id, id)

albm_reads           conversation_id · wp_user_id · last_read_message_id · updated_at
                     PK (conversation_id, wp_user_id)

albm_appointments    conversation_id · ext_appointment_id · starts_at · ends_at · status
                     (vincula citas de Amelia al hilo; alimenta ventana y separadores)

albm_oversight_log   id · admin_wp_id · conversation_id · action(read|write|reopen|archive) ·
                     created_at   (si se aprueba la supervisión registrada)
```

Decisiones de diseño dentro del esquema:
- **Leídos**: un puntero `last_read_message_id` por persona y conversación (O(1)), no una fila por mensaje leído (explota en volumen). "Enviado/leído" estilo doble check sale de comparar punteros.
- **`type` + `payload` JSON** en mensajes: el texto es solo el primer tipo. Imagen/archivo/voz/ubicación del roadmap = nuevos `type` + payload, **cero migración estructural**.
- **Borrado suave** (`deleted_at`): un chat entre cliente y negocio es también un registro ante disputas — nada se destruye silenciosamente.
- Referencias a Amelia **por valor** (`customer_ref`, `ext_appointment_id`), sin foreign keys físicas a sus tablas: Amelia puede actualizarse sin rompernos.

## 5. Integración con Amelia (solo lectura)

Capa adaptadora única — ningún otro componente sabe que Amelia existe:

1. **Hooks oficiales de Amelia** (ciclo de vida de reservas: creación, cancelación, reprogramación) → provisionar/actualizar el vínculo cita↔hilo y la ventana de escritura en el momento en que ocurre la reserva. Vía preferida.
2. **Lectura directa de BD introspectiva** (verificando tabla y columnas antes de consultar) como respaldo y para el barrido de consistencia (cron horario que reconcilia citas que algún hook se haya perdido).
3. Si Amelia se desactiva o cambia esquema: el messenger degrada a **solo lectura de hilos existentes** con aviso — nunca un fatal.

## 6. Tiempo real: cómo llegan los mensajes

Restricción dura: WordPress en Cloudways = PHP-FPM sin procesos persistentes. Comparativa:

| Transporte | Latencia | Riesgo en este hosting | Veredicto |
|---|---|---|---|
| **Polling corto adaptativo** (REST cada 5s activo / 30s inactivo, con backoff) | 2–10s | 🟢 ninguno; endpoint ligero (1 consulta indexada) | ⭐ **v1** |
| Long-polling | ~1s | 🔴 retiene workers PHP-FPM → tumba el sitio con pocos usuarios | No |
| SSE | ~1s | 🔴 mismo problema de workers | No |
| WebSocket propio (Node) | <1s | 🟡 daemon extra que operar en Cloudways | v2 si hiciera falta |
| Servicio hosted (Pusher/Ably/OneSignal) | <1s | 🟡 dependencia y coste externos; mensajes pasan por terceros | Candidato v1.1 |

**Diseño:** el frontend habla con una interfaz `Transport`; v1 la implementa el polling. Migrar a push más adelante = una implementación nueva de la interfaz, sin tocar dominio ni UI. Para citas de salón (coordinación con minutos de margen, no despacho en vivo), 5 segundos de latencia es correcto.

## 7. Notificaciones v1

- **In-app**: badge de no-leídos en los tres paneles (sale gratis del polling + punteros de lectura).
- **Email de respaldo**: si un mensaje lleva X minutos sin leerse (destinatario offline), un email "Tienes un mensaje de {nombre}" con enlace al hilo — vía `wp_mail`/cron, **máximo uno por hilo por intervalo** (anti-tormenta). SMS/push: v1.1+ (la cola de notificaciones ya nace con campo `channel`).

## 8. Componentes del plugin

```
CLIENTES: panel cliente · panel empleado · panel admin (bandeja global + supervisión)
              │  shortcodes + app JS ligera; misma app, capacidades según rol
              ▼
REST API /alb-messenger/v1  (contrato pensado también para app móvil futura)
   /me · /conversations · /conversations/{id}/messages (GET paginado, POST)
   /conversations/{id}/read · /poll?since= · /admin/...
              ▼
NÚCLEO: Permisos (identidad server-side) · Conversaciones · Mensajes ·
        Ventanas (motor de estados) · Notificaciones · Oversight ·
        Migraciones versionadas · i18n · hooks propios albm_* (extensión futura)
              ▼
ADAPTADOR AMELIA (solo lectura: hooks + BD introspectiva + cron de reconciliación)
              ▼
        Amelia (intacta)
```

Flujo de envío (resumen): POST mensaje → autenticación WP → resolver identidad → ¿participante del hilo? → ¿ventana activa (o admin)? → validar/sanear texto → insertar → actualizar `last_message_at` → hook `albm_message_sent` (aquí colgarán notificaciones, y en el futuro traducción/IA/automatizaciones, sin tocar el núcleo).

## 9. Preparación para el futuro (sin implementarlo)

| Función futura | Ya contemplado en |
|---|---|
| Imágenes/archivos/voz/ubicación | `type` + `payload` por mensaje; validación por tipo en un registro de tipos |
| "Escribiendo…" / presencia | Transport (efímero, entra con push; el polling no lo intenta) |
| Reacciones | Tabla satélite `albm_reactions` futura; el esquema de mensajes no cambia |
| Traducción / IA / autorespuestas | Consumidores del hook `albm_message_sent` |
| Push / app móvil | Interfaz Transport + la REST API ya es el contrato móvil |
| Multi-proveedor de reservas | Columna `provider` + adaptador aislado desde el día 1 |

## 10. Riesgos principales identificados en esta etapa

1. **Clientes sin usuario WP** (histórico previo al setting) — mitigado: vínculo manual por admin; se resuelve solo con el tiempo.
2. **Carga del polling** con muchos usuarios simultáneos — mitigado: endpoint de 1 consulta, intervalos adaptativos, y salto a push hosted si el negocio crece.
3. **Cachés de página** (el mismo tipo de fricción de cualquier app autenticada en este hosting): las páginas de los paneles deberán excluirse de caché — se documentará en la guía de despliegue.
4. **Privacidad/retención**: los chats contienen datos personales. Falta decidir política de retención (¿para siempre? ¿archivar a los N meses?) y responder solicitudes de borrado. Decisión de producto pendiente.
5. **Expectativa de inmediatez**: los usuarios esperan "WhatsApp"; hay que comunicar en la UI que es coordinación de citas (y el email de respaldo cubre al que no está mirando).

---

## 11. DECISIONES QUE NECESITO DE IVANHOE (bloquean la Etapa 2)

1. **Modelo de conversación**: ¿apruebas el híbrido C (hilo por par + cita como contexto y llave de escritura)?
2. **Ventana de escritura**: propuesta "desde confirmación de la reserva hasta 48h después del fin de la cita". ¿De acuerdo? ¿Otro margen?
3. **Identidad de clientes**: ¿activamos el setting nativo de Amelia (cuenta WP automática por cliente) como requisito v1, y dejamos magic links para más adelante?
4. **Supervisión del admin**: ¿lectura silenciosa pero registrada en log interno + escritura siempre visible como "Soporte"? ¿O prefieres otra política (p. ej., aviso visible "el soporte puede leer esta conversación" en el chat)?
5. **Transporte**: ¿polling adaptativo v1 (latencia 2–10s) y push como evolución, o quieres push hosted (Pusher/OneSignal) desde el día 1 asumiendo dependencia y coste externos?
6. **Notificación por email**: ¿de acuerdo con "email si no lo lee en X minutos, máximo 1 por hilo por intervalo"? ¿Qué X?
7. **Retención**: ¿los chats se conservan indefinidamente o se archivan/purgan a los N meses?

Con estas 7 respuestas cierro la arquitectura definitiva (Etapa 2: modelo de datos final + diagramas + contrato REST) y la someto a tu aprobación antes de cualquier código.
