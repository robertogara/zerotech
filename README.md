# ZeroTech

Web educativa de ZeroTech, servida con Flask y Jinja. Separa el temario, cada lección, la tienda, el detalle de producto y el contacto en páginas propias. Incluye carrito, checkout con Stripe configurable, valoraciones guardadas en SQLite y un chat conectado a OpenAI cuando se configura su clave en el servidor.

## Contenido y navegación

- `/`: portada de la tienda, con los dos productos actuales y sus precios.
- `/temario`: índice del temario con enlaces a cuatro lecciones completas (Hardware, Redes informáticas, Sistemas operativos y Seguridad informática).
- `/temario/<tema>`: página individual de cada lección.
- `/tienda`: redirige a la portada; `/producto/<id>` conserva los detalles, valoraciones y comentarios públicos.
- `/contacto`: formulario de contacto y mapa de una ubicación de referencia en Valencia. La ubicación del mapa no es una dirección de ZeroTech ni afirma que exista allí una tienda física. `/contact` redirige a `/contacto`.
- La interfaz mantiene un aspecto claro y ordenado, inspirado en la captura de WordPress.
- Las fotografías de productos y temas se sirven localmente en WebP, con texto alternativo y procedencia/licencia documentadas en [`static/img/IMAGE_SOURCES.md`](static/img/IMAGE_SOURCES.md).
- `/login`: interfaz accesible de acceso, con validación del navegador y control para mostrar u ocultar la contraseña.
- `/robots.txt` y `/sitemap.xml` describen la portada y las páginas independientes.
- Se conserva el contenido original comprobado en `simply-static-1-1793833211.zip`. Las descripciones de los productos siguen marcadas como provisionales.

## Identidad visual y accesibilidad

La interfaz usa una paleta clara con azul marino para texto, teal para acciones principales y azul, cian y violeta para distinguir temas. El CSS centraliza los colores como variables y mantiene foco visible, etiquetas de formulario, avisos accesibles, adaptación móvil y `prefers-reduced-motion`.

## Contacto y correo

El formulario valida nombre, correo, asunto y mensaje en el navegador y en Flask. Sin configuración, `POST /api/contact` devuelve `503`; no reenvía ni persiste los datos. Con la integración habilitada, Flask envía el mensaje mediante la API de Resend y solo muestra confirmación cuando el proveedor acepta la solicitud. El texto enviado se trata como texto plano.

Para configurar Resend en el servidor:

- `RESEND_API_KEY`: clave secreta de API de Resend (`re_...`).
- `CONTACT_FROM_EMAIL`: remitente de un dominio verificado en Resend.
- `CONTACT_TO_EMAIL`: dirección administrada por ZeroTech que recibirá las consultas.
- Limita la frecuencia de solicitudes en el proxy o gateway de producción antes de publicar el formulario.

No se inventa ni se muestra una dirección de contacto. Si falta cualquier valor, el formulario indica que el envío no está configurado. No se guardan mensajes en la aplicación.

## Inicio de sesión y cuentas

No hay un proveedor de identidad configurado. La vista `/login` valida campos localmente, permite mostrar/ocultar la contraseña y, al intentar continuar, borra los campos y explica que el acceso no está disponible. Las credenciales no se envían a Flask ni se guardan en el navegador.

Para habilitar cuentas hace falta seleccionar y configurar un proveedor de identidad (por ejemplo, Auth0 o Supabase Auth): dominio/issuer del proyecto, identificador de cliente, URI de retorno HTTPS y validación de tokens/sesiones en el backend. Las claves privadas y la gestión de sesiones deben permanecer en el servidor; también deben definirse recuperación de cuenta, verificación de correo y política de privacidad antes de aceptar usuarios.

## Carrito y pagos

El carrito mantiene productos y unidades (1–99) en `localStorage` cuando el navegador lo permite. Es una comodidad de interfaz, no un pedido. Los importes se recalculan en el servidor a partir del catálogo configurado; no se aceptan precios enviados por el cliente.

La integración es **Stripe Checkout alojado**. El checkout se desactiva si faltan `STRIPE_CHECKOUT_ENABLED=true`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` o `STRIPE_PAYMENT_METHODS`; una clave live también requiere `SITE_URL` HTTPS. Antes, las variables se indicaban en una sesión temporal de PowerShell y la aplicación no cargaba `.env`; además, la petición usaba un parámetro de métodos no admitido por Checkout. Esos puntos se han corregido, pero en este entorno no hay credenciales de Stripe.

Nunca se introducen ni almacenan datos de tarjeta en ZeroTech. El backend valida los identificadores y cantidades contra el catálogo del servidor, calcula el importe y crea una sesión EUR con `payment_method_types`. Solo se solicitan los métodos incluidos en `STRIPE_PAYMENT_METHODS`; Stripe además exige que cada uno esté activado y sea elegible para la cuenta, moneda, ubicación y sesión. La configuración local de ejemplo permite únicamente tarjeta. No se presupone que PayPal o Bizum estén habilitados. ZeroTech consulta el estado de la sesión a Stripe y compara moneda e importe esperado antes de mostrar aprobación. La ruta de retorno diferencia pago aprobado, pendiente, cancelado y error de verificación.

El webhook `/api/payments/webhook` verifica la firma de Stripe y reconoce `checkout.session.completed`, `checkout.session.async_payment_succeeded` y `checkout.session.async_payment_failed`. Actualmente no hay base de datos de pedidos, conciliación, reembolsos ni entrega automática del curso/consultoría: el webhook registra el resultado, pero no persiste un pedido. No actives ventas reales hasta añadir persistencia idempotente y definir contenido, prestación/entrega, términos comerciales, privacidad y soporte.

Configuración necesaria en el entorno del servidor:

- `STRIPE_SECRET_KEY`: clave secreta de Stripe (`sk_test_...` para pruebas; no usar una clave live durante la validación).
- `STRIPE_WEBHOOK_SECRET`: secreto de firma del endpoint de Stripe (`whsec_...`).
- `STRIPE_CHECKOUT_ENABLED=true`: activación deliberada del flujo; sin esta variable exacta y ambas claves válidas, el botón de pago permanece desactivado.
- `STRIPE_PAYMENT_METHODS`: lista separada por comas de métodos permitidos: `card`, `paypal`, `bizum`. Debe incluir al menos uno; los identificadores desconocidos son un error de configuración. Añade PayPal o Bizum únicamente después de activarlos y confirmar la elegibilidad de la cuenta en Stripe.
- `SITE_URL`: origen canónico de ZeroTech. En producción debe ser el dominio HTTPS real.
- En el Dashboard de Stripe, registra `https://TU_DOMINIO/api/payments/webhook` y los tres eventos de Checkout indicados arriba.

La aplicación carga `.env` desde la raíz del proyecto al iniciar; las variables ya definidas en el proceso tienen prioridad. `.env` está excluido de Git. Para desarrollo local:

1. Si aún no tienes `.env`, copia `.env.example` a `.env` y guarda allí las credenciales de prueba de Stripe (`sk_test_...`) y la clave de OpenAI (`OPENAI_API_KEY`). No compartas ni pegues claves en el chat, el navegador o el código.
2. Instala Stripe CLI e inicia `stripe listen --forward-to localhost:5000/api/payments/webhook`; guarda el `whsec_...` de esa escucha en `STRIPE_WEBHOOK_SECRET`.
3. En `.env`, establece `STRIPE_CHECKOUT_ENABLED=true`, la clave de prueba, el secreto de webhook y solo los métodos de pago activados para esa cuenta. `SITE_URL=http://localhost:5000` se configura localmente. Después inicia Flask:

   ```powershell
   python app.py
   ```

4. En el Dashboard de Stripe habilita los métodos que quieras probar. PayPal puede requerir vincular una cuenta PayPal y aprobación de Stripe; Bizum requiere elegibilidad de cuenta y de la sesión. Utiliza únicamente datos de prueba publicados por Stripe; nunca introduzcas datos de una tarjeta real.

Para producción, establece las variables en el panel de entorno/secretos del alojamiento (por ejemplo, **Render → Environment**): `SITE_URL` con el origen HTTPS real, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CHECKOUT_ENABLED=true` y `STRIPE_PAYMENT_METHODS`. Nunca subas `.env` ni copies sus claves a `render.yaml`. Crea el endpoint de webhook en el Dashboard de Stripe con los eventos descritos arriba y guarda el secreto que entrega Stripe. Usa claves live solo después de completar pruebas y configurar pedidos/entrega.

Para habilitar un método, comprueba primero que la cuenta de Stripe sea apta, actívalo en el Dashboard y añade su identificador a `STRIPE_PAYMENT_METHODS`. La aplicación solo solicita esos métodos; Stripe puede rechazarlos si no están activados o no son compatibles con la cuenta o la sesión. En este entorno faltan las claves, así que el checkout permanece desactivado. No se ha comprobado qué métodos están habilitados en una cuenta Stripe.

Documentación oficial: [sesiones de Checkout](https://docs.stripe.com/api/checkout/sessions/create), [PayPal con Stripe](https://docs.stripe.com/payments/paypal) y [Bizum con Stripe](https://docs.stripe.com/payments/bizum).

Antes de usar credenciales live, valida el flujo completo con claves de prueba y configura persistencia idempotente, conciliación de eventos y entrega del producto. No se han definido impuestos, gastos, descuentos, condiciones ni la entrega de los productos; el total actual es solo la suma de los precios publicados.

## Chat

`POST /api/chat` valida la entrada y llama al endpoint Chat Completions de OpenAI desde Flask. La clave nunca se envía al navegador ni se registra. `GET /api/chat/status` devuelve únicamente `{"configured": true|false}`. Configura `OPENAI_API_KEY` en el `.env` local o en los secretos del alojamiento; `OPENAI_MODEL` es opcional y usa `gpt-4o-mini` por defecto. Los errores HTTP del proveedor aparecen con su código y orientación segura: por ejemplo, `401` para clave/permisos, `404` para modelo o acceso, y `429` para cuota, límites o facturación de la API. La suscripción de ChatGPT no incluye uso de API. El historial no se conserva entre preguntas. Antes de publicar el endpoint, configura límites de frecuencia y protección contra abuso en el proxy o gateway.

## Ejecución local

Requisitos: Python 3.9 o posterior. En PowerShell, desde la raíz:

```powershell
py -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
python app.py
```

Abre `http://127.0.0.1:5000`. Rellena `.env` con claves de prueba para recibir respuestas del chat y crear sesiones de Stripe; sin credenciales, ambos servicios indican qué configuración falta. Para probar Stripe configura el forwarding de Stripe CLI. Nunca publiques secretos en el repositorio ni en variables frontend.

## Publicación en internet (Render)

El archivo `render.yaml` prepara un servicio Flask con Gunicorn, una URL pública `onrender.com` y un disco persistente para las reseñas. El plan configurado es Starter (actualmente 7 USD/mes) y el disco de 1 GB añade actualmente 0,25 USD/mes. Revisa el importe que Render muestra antes de crear el servicio; no se debe crear sin aceptar ese coste. Un dominio propio se compra aparte y es opcional.

Para publicar, sube el proyecto a un repositorio **privado** de GitHub (no subas `.env`, claves ni `instance/`) y conéctalo a Render mediante **New → Blueprint**. Render usará `render.yaml`; el dominio `RENDER_EXTERNAL_URL` se toma automáticamente como URL canónica y `DATA_DIR=/var/data` guarda allí las reseñas. La primera publicación requiere que vincules GitHub y confirmes el plan facturable en Render.

La web puede publicarse con pagos desactivados. Para habilitar cobros en producción, añade las claves live de Stripe y el secreto de webhook como variables secretas del servicio, establece `STRIPE_CHECKOUT_ENABLED=true`, los métodos permitidos y el endpoint webhook de Stripe para la URL pública. No copies claves al repositorio ni al chat. El formulario de contacto seguirá indicando que falta configuración hasta añadir las variables de Resend documentadas arriba.

Tras modificar `app.py`, reinicia el proceso Flask para que actualice la lista de recursos estáticos permitidos; después recarga la página para usar las últimas versiones de CSS y JavaScript.

Pruebas:

```powershell
python -m unittest discover -s tests -v
```

## Despliegue

Configura `SITE_URL` y, si procede, `TRUSTED_HOSTS` con los dominios reales. En producción usa un servidor WSGI mantenido detrás de HTTPS y un proxy/CDN configurado. Aplica límites de frecuencia al formulario de contacto y a las rutas de pago. No se han realizado pruebas de carga ni se afirma capacidad para un volumen concreto de visitas.
