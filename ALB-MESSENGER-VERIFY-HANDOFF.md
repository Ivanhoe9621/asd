# Traspaso: verificación en sitio real de ALB Messenger (M0–M2)

> **Para pegar a otro chat de Claude que tenga la extensión de Chrome conectada a wp-admin de albookings.com.**
> Este documento es el prompt completo + runbook. El chat receptor NO necesita conocer el historial: todo el contexto está aquí.

---

## PROMPT PARA EL CHAT RECEPTOR (pega desde aquí hasta el final)

Actúa como ingeniero de despliegue de WordPress. Tienes acceso a wp-admin de **albookings.com** vía la extensión de Chrome. Vas a **verificar en el sitio real la capa de integración (M2) del plugin ALB Messenger** — NO a lanzar un chat para usuarios (ese plugin aún no tiene interfaz; solo tiene la integración con Amelia). El objetivo es confirmar que, cuando existe una reserva en Amelia, el plugin crea correctamente la conversación y sus eventos, y averiguar qué hooks de Amelia disparan de verdad en esta instalación.

### Reglas duras (no negociables)
1. **No modifiques Amelia** ni sus archivos ni sus ajustes de reservas. Solo LEES de Amelia.
2. **Trabaja en un entorno de pruebas.** Si Cloudways tiene staging, úsalo. Si no, haz todo con una **reserva de prueba** (un servicio barato, un cliente de prueba con tu propio email) que borrarás al final; nunca toques citas de clientes reales.
3. **El respaldo ya existe** (el dueño lo confirmó). No pidas crear otro.
4. Los dos plugins que subirás son **temporales y de solo diagnóstico/integración**: al terminar, se desactivan y borran.
5. **No inventes resultados.** Si un paso no se puede completar, dilo con el error exacto.
6. Reporta al final en el formato de la sección "REPORTE" — ese texto es lo que el dueño llevará de vuelta al chat de desarrollo.

### Artefactos (descárgalos del repositorio GitHub `Ivanhoe9621/asd`, rama `claude/albookings-advanced-content-4ei27c`, carpeta `dist/`)
- **`albm-diagnostics.zip`** — plugin de SOLO LECTURA que vuelca el estado (tablas, hooks, conversaciones, ajuste de clientes). Instálalo primero.
- **`alb-messenger-m2.zip`** — el plugin ALB Messenger en su estado actual (módulos M0–M2: esquema + identidad/permisos + integración con Amelia). Sin interfaz de chat todavía.

Descarga cada ZIP con el botón **Download** de GitHub; **no lo descomprimas** (WordPress lo necesita comprimido para "Subir plugin").

### Pasos

**Paso 0 — Entorno.**
- Confirma versión de WordPress, versión de PHP (Cloudways → Application Settings) y versión de Amelia (Plugins).
- Anota si hay staging disponible o si trabajarás en producción con una reserva de prueba.

**Paso 1 — Ajuste de identidad de clientes (decisión #3 del diseño).**
- Ve a **Amelia → Settings → Roles Settings → Customer**.
- Comprueba si **"Automatically create Amelia Customer user"** está ACTIVADO. NO lo cambies todavía — solo reporta su estado. (El messenger necesita que los clientes tengan usuario WP; este ajuste es el que se lo da. Si está apagado, se decidirá en el chat de desarrollo si se activa.)

**Paso 2 — Instalar el diagnóstico (solo lectura).**
- Plugins → Añadir nuevo → Subir plugin → `albm-diagnostics.zip` → Instalar → Activar.
- Ve a **Herramientas → ALB Messenger Diag**. Todavía dirá que las tablas albm_ "NO EXISTEN" (normal: el messenger aún no está instalado). Copia esa primera lectura — nos sirve de línea base y confirma qué hooks de Amelia existen.

**Paso 3 — Instalar ALB Messenger (M0–M2).**
- Plugins → Añadir nuevo → Subir plugin → `alb-messenger-m2.zip` → Instalar → Activar.
- Vuelve a **Herramientas → ALB Messenger Diag** y confirma en la sección 1 que las **9 tablas albm_ ahora existen** con schema_version 1.0.0, y en la sección 3 que los **dos crons quedaron programados**.
- En la sección 2, confirma que aparecen como "REGISTRADO" los hooks del messenger. Copia la lista completa de hooks `amelia_*` que muestre — es la evidencia de qué nombres de hook usa realmente esta versión de Amelia.

**Paso 4 — Prueba de provisionamiento (el corazón de la verificación).**
- Crea **una reserva de prueba** en Amelia: entra al catálogo/formulario como lo haría un cliente (o créala desde wp-admin → Amelia → Appointments), con un cliente de prueba cuyo email sea tuyo, en un servicio con un empleado asignado.
- Vuelve a **Herramientas → ALB Messenger Diag** y mira la sección 4:
  - Debe aparecer **una conversación nueva** con su `pair_key` (`amelia:{idCliente}:{idEmpleado}`).
  - Debe aparecer un evento **`appointment_linked`** para esa cita.
- Si aparecen → la integración funciona por hook en vivo. Si NO aparecen de inmediato: espera ~15 minutos (el cron de reconciliación la recogerá) y vuelve a mirar. Si tras el cron aparece, la integración funciona por reconciliación aunque el hook no dispare. **Reporta cuál de los dos casos ocurrió** — es justo el dato que el desarrollo necesita.

**Paso 5 — Prueba de cambios (si el paso 4 salió bien).**
- **Reprograma** esa cita de prueba en Amelia (cambia su hora). Revisa el diag: debe aparecer un evento `appointment_rescheduled`.
- **Cancela** la cita de prueba. Revisa el diag: debe aparecer `appointment_canceled`.
- (Ambos pueden tardar hasta 15 min si dependen del cron.)

**Paso 6 — Limpieza.**
- **Borra la reserva de prueba** en Amelia.
- Desactiva y borra **ALB Messenger** y **ALB Messenger Diagnostics** (Plugins → Desactivar → Borrar). Al borrar el messenger, sus tablas se conservan por diseño (retención) — es correcto, no las elimines a mano.
- Confirma que Amelia sigue funcionando exactamente igual (abre su calendario de citas, haz una reserva normal si quieres, verifícala).

### REPORTE (pégaselo de vuelta al dueño / chat de desarrollo)
```
ENTORNO: WordPress __ · PHP __ · Amelia __ · staging/producción __
PASO 1 - "Automatically create Amelia Customer user": [ACTIVADO / APAGADO]
PASO 2 - línea base diag: [pega la salida]
PASO 3 - 9 tablas albm creadas: [sí/no] · crons programados: [sí/no] ·
         lista de hooks amelia_* registrados: [pega la lista]
PASO 4 - tras la reserva de prueba apareció conversación+appointment_linked:
         [al instante por hook / tras ~15 min por cron / NO apareció]
PASO 5 - reprogramación → appointment_rescheduled: [sí/no/n-a]
         cancelación → appointment_canceled: [sí/no/n-a]
PASO 6 - reserva de prueba borrada: [sí] · plugins de prueba borrados: [sí] ·
         Amelia funciona igual: [sí/no]
INCIDENCIAS: [cualquier error exacto, o "ninguna"]
```

### Rollback (si algo sale mal en cualquier paso)
Desactivar y borrar los dos plugins de prueba deja el sitio exactamente como estaba (sus tablas albm_ vacías no afectan a nada, pero puedes dejarlas o pedir a phpMyAdmin de Cloudways que las borre si prefieres partir limpio). Amelia nunca se modificó, así que no hay nada que revertir de su lado.

---

## Notas para el dueño (no forman parte del prompt)

- Este traspaso verifica **solo la integración (M2)**. La interfaz de chat (paneles de cliente/empleado/admin, envío de mensajes, notificaciones) son los módulos **M3–M7**, que aún no están construidos en el chat de desarrollo.
- El dato más valioso que traerá este chat es el **resultado del Paso 4** (¿la conversación se creó por hook en vivo o hizo falta el cron?) y la **lista real de hooks** del Paso 3: eso confirma o corrige el supuesto del documento M2-INTEGRATION sobre qué hooks usa tu versión de Amelia.
- Cuando vuelvas con ese reporte, en el chat de desarrollo se ajustan los nombres de hook si hiciera falta y se continúa con M3.
