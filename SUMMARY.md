# AL Bookings — Catálogo Premium (proyecto)

> Este documento resume todo el trabajo hecho hasta ahora para que puedas retomarlo desde cualquier dispositivo (incluido el teléfono) sin perder contexto. Pégaselo a Claude en un chat nuevo y dile "continúa este proyecto" para seguir donde quedó.

Última actualización: 2026-07-07

---

## 1. El negocio

- **AL Bookings LLC** — agencia de marketing y reservas de servicios de belleza en Louisville, KY. Propietario: Ivanhoe.
- Modelo de negocio: Ivanhoe promociona servicios con **Meta Ads** y **gana comisión por cada cita reservada** a través de albookings.com.
- Sitio: WordPress + tema **Kadence** + plugin de reservas **Amelia** (motor de citas, horarios, empleados, Google Calendar, pagos).
- Contacto del sitio: 502-795-8430 · info@albookings.com. Píxel de Meta instalado vía **PixelYourSite**.

## 2. El problema que se resolvió

El catálogo original de Amelia vive en `/cata` y es funcional pero visualmente básico (estilo plugin genérico, no "premium"). Se pidió un catálogo nuevo:

- **Independiente** — no debía modificar, reemplazar ni arriesgar a Amelia de ninguna forma.
- **Sin duplicar datos** — debía leer servicios, precios, categorías, empleados e imágenes directamente de Amelia, en vivo.
- **Diseño premium** — inspirado en Apple / Airbnb / Stripe / Linear / Notion.
- **Experiencia tipo app** — categoría → servicio → profesional → fecha/hora → confirmar, todo en un solo viewport, sin recargas.
- Rápido, mobile-first, accesible, con buen SEO.

### Decisión técnica

Se evaluaron alternativas (Bookly, JetBooking, FluentBooking, SureCart, WooCommerce Bookings) y todas se descartaron: cualquier plugin de reservas nuevo habría significado **cargar los datos dos veces** y mantener dos sistemas de reservas en paralelo. Se decidió construir un **plugin propio de solo lectura** que lee los datos de Amelia sin tocarlos, dejando a Amelia como único motor de reservas (fecha, hora y confirmación siguen pasando por Amelia — no tiene sentido reconstruir esa lógica).

### Requisitos añadidos por Ivanhoe (feedback de revisión del plan)

1. No depender de SQL directo a las tablas de Amelia si existe una API interna — usar SQL solo como último recurso.
2. Catálogo 100% genérico (nada de servicios hardcodeados) para poder crecer a cualquier categoría de negocio.
3. Tarjetas visuales ricas estilo Airbnb (imagen grande, precio, duración, descripción, etiquetas, profesional, espacio para reseñas).
4. Buscador y filtros (precio, duración, categoría, profesional, ubicación) desde el día uno.
5. Experiencia de una sola pantalla, como una app.
6. Microinteracciones sutiles (hover, fade, slide, skeleton de carga, ripple en botones, animaciones de 200–300ms).
7. Modo oscuro.
8. **Pensar la arquitectura como base de una futura plataforma completa**: pagos online, favoritos, cuentas de cliente, historial, cupones, fidelización, reseñas, paquetes, membresías, tarjetas regalo, multi-sucursal, multi-idioma, multi-moneda, app móvil — sin implementarlas ahora, pero sin tener que rehacer el proyecto cuando se agreguen.
9. URL del catálogo: **`/book`** (se deja `/reservar` libre para una futura landing comercial).
10. Mantener Amelia como backend — confirmado como la decisión correcta.

## 3. Qué se construyó

Plugin de WordPress: **`alb-catalog`** ("ALB Catálogo Premium"), versión actual **1.0.3**, **activo en producción** en albookings.com.

```
alb-catalog/
├── alb-catalog.php                      # Bootstrap: shortcode [alb_catalogo], rutas REST, encolado de assets
├── includes/
│   ├── interface-data-source.php        # Contrato de fuente de datos
│   ├── class-amelia-internal-source.php # Fuente primaria: usa el contenedor/repositorios internos de Amelia (solo lectura)
│   ├── class-amelia-db-source.php       # Fuente de respaldo: SQL introspectivo de solo lectura sobre wp_amelia_*
│   ├── class-repository.php             # Orquesta las fuentes, normaliza datos, cachea 10 min, expone filtros de extensión
│   ├── class-rest.php                   # GET /wp-json/alb-catalog/v1/catalog (público) y /status (solo admin, diagnóstico)
│   └── class-schema.php                 # JSON-LD (BeautySalon/Offer) para SEO, solo en la página del catálogo
├── assets/
│   ├── catalog.css                      # Mobile-first, dark mode automático, paleta de Kadence, animaciones
│   └── catalog.js                       # App sin frameworks: búsqueda, filtros, categorías, flujo servicio→profesional→resumen
└── templates/catalog.php                # Marcado del shortcode
```

**Puntos de arquitectura clave:**

- El repositorio intenta primero la API interna de Amelia; si no puede armar el catálogo completo (por ejemplo, si el vínculo empleado↔servicio no viene completo en esa llamada), cae automáticamente al respaldo SQL introspectivo. **En producción, hoy el catálogo se sirve desde el respaldo SQL** porque esa versión de Amelia no expone bien ese vínculo por su API interna — es el comportamiento esperado del diseño, no un error.
- Puntos de extensión ya preparados para el futuro: filtros `alb_catalog_data_sources`, `alb_catalog_service`, `alb_catalog_employee`, `alb_catalog_data`, `alb_catalog_cache_ttl`; campos reservados en la respuesta (`rating`, `tags`, `currency`, `version`) para cuando se agreguen reseñas, multi-moneda, etc.
- Seguridad: todo el acceso a base de datos es de solo lectura con `$wpdb->prepare`; nunca se expone email/teléfono de empleados; el endpoint `/status` requiere permisos de administrador.
- Al confirmar servicio + profesional, el botón final lleva a `/cata/?ameliaCategoryId=&ameliaServiceId=&ameliaEmployeeId=` — ahí Amelia se encarga de fecha, hora, ubicación y confirmación (se quitó el paso de "elegir ubicación" del flujo propio porque Amelia valida la ubicación internamente y podía dar error "no hay empleados/servicios" si se forzaba desde fuera).

## 4. Historial de despliegue (útil para depurar)

- **v1.0.0** — instalación inicial vía automatización de navegador (subida del ZIP codificado en base64 al formulario de wp-admin).
- **v1.0.1** — se agregó el endpoint `/status` de diagnóstico y lógica de respaldo más inteligente (normaliza antes de aceptar una fuente).
- **v1.0.2** — se corrigió un ZIP corrupto (rutas con backslash rompían la extracción en el servidor Linux) y el layout para que el catálogo ocupe todo el ancho (Kadence lo encajonaba en una columna angosta).
- **v1.0.3** — se quitó el paso redundante de "ubicación" del flujo (Amelia ya lo resuelve) y se corrigió que las descripciones mostraban código HTML sin decodificar (`&#39;`, etc.) en vez de texto limpio.
- **Problema recurrente:** al subir el ZIP por el navegador automatizado, dos veces llegó al servidor con `catalog.css`/`catalog.js` en 0 bytes (corrupción en el trayecto). Solución: pegar el contenido completo del archivo directamente en **Plugins → Editor de archivos del plugin** de wp-admin en vez de resubir el ZIP. **Este es el método preferido para futuras ediciones manuales.**

## 5. Estado actual (checklist de revisión pre-publicación)

Ivanhoe pidió una revisión completa antes de publicar `/book`. Estado de cada punto:

| # | Punto | Estado |
|---|---|---|
| 1 | Flujo completo sin re-preguntar información ya elegida | ✅ Verificado tras quitar el paso de ubicación redundante |
| 2 | Carga correcta en móvil / tablet / escritorio | ✅ Verificado en 390px y 820px de ancho |
| 3 | Identidad visual premium y consistente con Albookings | ⚠️ **En progreso** — se agregó un ajuste de CSS para que el fondo oscuro del catálogo no quede encajonado en la caja blanca del tema Kadence; **falta desplegar este último cambio al servidor y verificarlo en vivo** |
| 4 | Todas las imágenes cargan | ✅ Verificado (94 imágenes revisadas, 0 rotas) |
| 5 | Sin enlaces rotos | ✅ Verificado el CTA principal y el botón de WhatsApp; no se revisó exhaustivamente cada servicio individual |
| 6 | Rendimiento óptimo, sin errores de consola | ✅ Sin errores de consola observados; API responde en ~0.4–0.5s; falta correr una auditoría formal de PageSpeed/Lighthouse |
| 7 | Sin errores en Site Health ni en registros PHP | ⏳ **Pendiente de revisar** (Herramientas → Salud del sitio, y logs de PHP del hosting) |

### La página `/book`

- Existe como **BORRADOR** (page_id=842, título "Reserva tu cita") — **todavía no está publicada**.
- Vista previa (requiere sesión de wp-admin iniciada): `https://albookings.com/?page_id=842&preview=true`
- `/cata` (el catálogo original de Amelia) sigue intacto y funcionando en paralelo — nunca se tocó.

### Próximos pasos para publicar

1. Confirmar que el último ajuste de CSS (fondo del tema Kadence) quedó bien desplegado y se ve correcto en `/book`.
2. Revisar Site Health y los logs de PHP en busca de errores o advertencias.
3. Con el visto bueno de Ivanhoe, publicar la página.

## 6. Ideas de funciones futuras (discutidas, no implementadas)

En orden de impacto recomendado:

1. **Eventos del píxel de Meta en el catálogo** (ViewContent, InitiateCheckout, Schedule) — para que Meta optimice los anuncios hacia usuarios que sí reservan, no solo que visitan la página.
2. **Enlaces directos por categoría** para los anuncios (ej. `/book?cat=tattoo`) — el anuncio de un servicio cae directo en esa categoría filtrada.
3. **Reseñas y valoraciones** en las tarjetas (el campo `rating` ya está reservado en el diseño de datos).
4. **Favoritos** (corazón, sin necesidad de cuenta, guardado en el navegador).
5. **Cupones visibles** en el catálogo (Amelia ya soporta cupones).
6. **Remarketing**: exportar clientes de Amelia a públicos personalizados de Meta para campañas de "vuelve a reservar" y lookalikes.
7. **Panel simple de métricas** para Ivanhoe: reservas por servicio/profesional/categoría, para decidir qué anunciar más.
8. **Versión en inglés** del catálogo (mercado angloparlante de Louisville).
9. **Paquetes y tarjetas regalo** como productos del catálogo (Amelia ya los soporta).

Ivanhoe no ha priorizado todavía cuáles de estas construir a continuación.

## 7. Dónde vive todo

- **Sitio en producción:** https://albookings.com (hosting Cloudways)
- **Plugin en el servidor:** `wp-content/plugins/alb-catalog/`
- **Copia local del código:** `C:\Users\user\Documents\claude\alb-catalog\` (computadora de Ivanhoe)
- **Acceso:** wp-admin como usuario `ivanhoe96`, gestionado con la extensión de Chrome de Claude conectada a esa sesión.

## 8. Cómo retomar esto desde el teléfono

1. Abre un chat nuevo con Claude.
2. Pega el contenido de este archivo (o el enlace al repositorio) y dile: *"Continúa este proyecto del catálogo de AL Bookings"*.
3. Para cambios de solo **texto o planificación**, Claude puede seguir sin la laptop.
4. Para cambios que requieran **entrar a wp-admin o editar archivos del servidor**, se necesita la extensión de Chrome conectada — eso hoy solo funciona desde la laptop de Ivanhoe.
