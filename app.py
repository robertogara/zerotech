import json
import os
import re
from contextlib import closing
from collections.abc import Mapping
from datetime import datetime, timezone
from pathlib import Path
import secrets
import sqlite3
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen
from urllib.parse import urlsplit
from xml.sax.saxutils import escape

import stripe
from dotenv import load_dotenv
from flask import Flask, Response, abort, jsonify, make_response, redirect, render_template, request, send_file, url_for, session
from werkzeug.exceptions import RequestEntityTooLarge

DOTENV_PATH = Path(__file__).resolve().with_name(".env")
load_dotenv(DOTENV_PATH, override=False)

app = Flask(__name__, static_folder=None)
app.config["MAX_CONTENT_LENGTH"] = 16 * 1024
app.secret_key = os.environ.get("FLASK_SECRET_KEY") or secrets.token_hex(32)

default_site_url = os.environ.get("RENDER_EXTERNAL_URL", "http://localhost:5000")
site_url = os.environ.get("SITE_URL", default_site_url).strip().rstrip("/")
parsed_site_url = urlsplit(site_url)
if (
    parsed_site_url.scheme not in {"http", "https"}
    or not parsed_site_url.netloc
    or parsed_site_url.path
    or parsed_site_url.query
    or parsed_site_url.fragment
    or parsed_site_url.username
    or parsed_site_url.password
):
    raise ValueError("SITE_URL debe ser un origen HTTP(S), sin credenciales ni ruta.")
app.config["SITE_URL"] = site_url

trusted_hosts = os.environ.get("TRUSTED_HOSTS")
if trusted_hosts is not None:
    app.config["TRUSTED_HOSTS"] = [
        host.strip() for host in trusted_hosts.split(",") if host.strip()
    ]
    if not app.config["TRUSTED_HOSTS"]:
        raise ValueError("TRUSTED_HOSTS debe incluir al menos un nombre de host.")

TOPICS = (
    {
        "id": "hardware",
        "title": "Hardware",
        "theme": "hardware",
        "emoji": "🖥️",
        "icon": "hardware",
        "image": "img/product-course.webp",
        "image_alt": "Detalle fotográfico de los circuitos y componentes de una placa base.",
        "description": "Descubre los componentes de un ordenador, como el procesador, la memoria RAM, el almacenamiento y la placa base.",
        "lesson_intro": "El hardware es la parte física del ordenador: los componentes que puedes tocar. Cada pieza cumple una función y trabaja en equipo.",
        "steps": (
            {"title": "Sigue el recorrido de la información", "text": "El teclado o el ratón envían una acción. El procesador la interpreta, la memoria mantiene a mano lo que se está usando y la pantalla muestra el resultado."},
            {"title": "Conoce el procesador y la memoria", "text": "La CPU ejecuta instrucciones. La RAM es una mesa de trabajo rápida y temporal: al apagar el equipo, su contenido desaparece."},
            {"title": "Distingue la memoria del almacenamiento", "text": "El SSD o disco guarda archivos y programas incluso cuando el equipo está apagado. La RAM, en cambio, ayuda a trabajar con ellos mientras están abiertos."},
            {"title": "Entiende cómo se conectan", "text": "La placa base comunica los componentes; la fuente de alimentación les entrega energía. Los puertos permiten conectar periféricos como una pantalla o una impresora."},
        ),
        "concepts": ("CPU: ejecuta instrucciones.", "RAM: espacio de trabajo temporal.", "SSD o disco: conserva archivos.", "Placa base: conecta componentes."),
        "example": "Al abrir un navegador, el sistema lee el programa del SSD, lo carga en la RAM y la CPU ejecuta sus instrucciones. La tarjeta gráfica ayuda a dibujar la ventana en la pantalla.",
        "summary": "El procesador calcula, la RAM mantiene el trabajo actual, el almacenamiento conserva los datos y la placa base permite que las piezas se comuniquen.",
    },
    {
        "id": "redes",
        "title": "Redes informáticas",
        "theme": "networks",
        "emoji": "🌐",
        "icon": "networks",
        "image": "img/topic-networks.webp",
        "image_alt": "Conectores y cables de red enchufados en un panel de comunicaciones.",
        "description": "Aprende cómo se conectan los dispositivos, qué función tiene un router y cómo funciona Internet.",
        "lesson_intro": "Una red es un conjunto de dispositivos que intercambian información. Puede estar en casa o conectar equipos de todo el mundo, como hace Internet.",
        "steps": (
            {"title": "Empieza en tu red local", "text": "El móvil y el ordenador pueden conectarse al router por Wi-Fi o cable. Así comparten una red local, aunque no estén visitando Internet."},
            {"title": "Comprende el papel del router", "text": "El router comunica la red de casa con la red del proveedor de Internet y dirige el tráfico hacia su destino. También suele asignar direcciones locales a los dispositivos."},
            {"title": "Busca el destino", "text": "Cuando escribes el nombre de una web, el sistema DNS ayuda a encontrar la dirección IP asociada. La IP permite identificar el destino en la red."},
            {"title": "Envía y recibe datos", "text": "La información viaja dividida en paquetes. Los equipos de red los encaminan y el dispositivo receptor vuelve a reunirlos para mostrar la página o el archivo."},
        ),
        "concepts": ("Red local: dispositivos próximos conectados.", "Router: comunica redes y dirige tráfico.", "IP: dirección de un dispositivo o servicio.", "DNS: relaciona nombres de dominio con direcciones IP."),
        "example": "Al visitar una web desde el móvil, el router envía la solicitud al proveedor; DNS ayuda a localizar el servidor y este devuelve los datos para que el navegador dibuje la página.",
        "summary": "Los dispositivos se conectan a redes, el router dirige los datos, DNS ayuda a localizar nombres y los paquetes llevan la información hasta su destino.",
    },
    {
        "id": "sistemas-operativos",
        "title": "Sistemas operativos",
        "theme": "systems",
        "emoji": "⚙️",
        "icon": "systems",
        "image": "img/topic-systems.webp",
        "image_alt": "Ordenador portátil con una interfaz de terminal y una aplicación abierta.",
        "description": "Conoce Windows y Linux, la gestión de archivos, los usuarios y los comandos básicos.",
        "lesson_intro": "El sistema operativo coordina el hardware y ofrece una forma de abrir programas, gestionar archivos y trabajar con el ordenador.",
        "steps": (
            {"title": "Inicia sesión y abre una aplicación", "text": "Al arrancar, el sistema operativo prepara los dispositivos y carga la sesión del usuario. Después puedes abrir un programa desde el menú o el escritorio."},
            {"title": "Organiza archivos y carpetas", "text": "Una carpeta agrupa archivos y otras carpetas. Usa nombres claros y guarda los documentos en una ubicación que puedas volver a encontrar."},
            {"title": "Reconoce usuarios y permisos", "text": "Cada cuenta puede tener sus propios archivos y preferencias. Los permisos determinan quién puede ver, modificar o ejecutar ciertos recursos."},
            {"title": "Prueba la terminal con cuidado", "text": "La terminal permite escribir órdenes. `pwd` muestra la ubicación actual en muchos sistemas, `ls` lista archivos en Linux y macOS, y `dir` lo hace en Windows. Empieza con órdenes de consulta."},
        ),
        "concepts": ("Sistema operativo: coordina el equipo.", "Archivo: guarda información.", "Carpeta: organiza archivos.", "Permiso: limita las acciones permitidas a cada usuario."),
        "example": "Para crear una carpeta de apuntes, abre el explorador de archivos, elige una ubicación y crea una carpeta llamada “Informática”. Después guarda allí un documento de texto.",
        "summary": "El sistema operativo administra el equipo. Las cuentas, carpetas y permisos ayudan a organizar el trabajo; la terminal ofrece otra forma de interactuar.",
    },
    {
        "id": "seguridad",
        "title": "Seguridad informática",
        "theme": "security",
        "emoji": "🔐",
        "icon": "security",
        "image": "img/topic-security.webp",
        "image_alt": "Persona usando una tarjeta junto a un ordenador portátil, ejemplo de protección de datos en línea.",
        "description": "Aprende a crear contraseñas seguras, reconocer amenazas y proteger tus dispositivos y datos personales.",
        "lesson_intro": "La seguridad informática consiste en reducir riesgos para tus dispositivos, cuentas y datos. Unos hábitos sencillos ayudan a evitar muchos problemas.",
        "steps": (
            {"title": "Protege cada cuenta", "text": "Usa contraseñas largas y diferentes para cada servicio. Un gestor de contraseñas puede ayudarte a guardarlas sin reutilizarlas."},
            {"title": "Añade una segunda comprobación", "text": "Activa la autenticación multifactor cuando esté disponible. Además de la contraseña, puede pedir una confirmación desde otro dispositivo o una llave de seguridad."},
            {"title": "Detente ante mensajes inesperados", "text": "Desconfía de la urgencia, los enlaces extraños y las peticiones de claves o códigos. Comprueba el dominio y contacta con la organización por un canal que ya conozcas."},
            {"title": "Mantén una copia y actualiza", "text": "Instala las actualizaciones desde las opciones oficiales del dispositivo y conserva copias de seguridad separadas de los archivos importantes."},
        ),
        "concepts": ("Phishing: engaño que intenta robar datos.", "Autenticación multifactor: más de una prueba de acceso.", "Actualizaciones: corrigen fallos y mejoran protección.", "Copia de seguridad: permite recuperar archivos."),
        "example": "Recibes un correo que afirma que tu cuenta se bloqueará hoy y te pide iniciar sesión en un enlace. No lo abras: visita la web escribiendo su dirección conocida y revisa allí las notificaciones.",
        "summary": "Usa claves únicas, activa un segundo factor, verifica mensajes antes de actuar y mantén software y copias de seguridad al día.",
    },
)

PRODUCTS = (
    {
        "id": "curso-premium-smr",
        "name": "Curso Premium SMR",
        "price_cents": 4999,
        "icon": "book",
        "image": "img/product-course.webp",
        "image_alt": "Placa base fotografiada de cerca, relacionada con el aprendizaje de hardware del curso.",
        "description": "Temario, formato y duración pendientes de confirmación.",
    },
    {
        "id": "consultoria-tecnica-personalizada",
        "name": "Consultoría Técnica Personalizada",
        "price_cents": 2999,
        "icon": "support",
        "image": "img/product-consulting.webp",
        "image_alt": "Varias personas colaboran alrededor de ordenadores en una mesa de trabajo.",
        "description": "Alcance, duración y modalidad del servicio pendientes de confirmar.",
    },
)
PRODUCTS_BY_ID = {product["id"]: product for product in PRODUCTS}
STRIPE_PAYMENT_METHODS = {
    "card": "Tarjeta bancaria",
    "paypal": "PayPal",
    "bizum": "Bizum",
}

CONTACT_EMAIL_PATTERN = re.compile(r"[^@\s<>]+@[^@\s<>]+\.[^@\s<>]+")

LEGACY_TOPIC_ANCHORS = {
    "hardware": "hardware",
    "redes": "redes",
    "sistemas-operativos": "sistemas-operativos",
    "seguridad": "seguridad",
    "seguridad-informatica": "seguridad",
}

STATIC_ASSETS = {
    "css/site.css": ("static/css/site.css", "text/css"),
    "js/site.js": ("static/js/site.js", "application/javascript"),
    "img/favicon.svg": ("static/img/favicon.svg", "image/svg+xml"),
    "img/learning-laptop.svg": ("static/img/learning-laptop.svg", "image/svg+xml"),
    "img/product-course.svg": ("static/img/product-course.svg", "image/svg+xml"),
    "img/product-consulting.svg": ("static/img/product-consulting.svg", "image/svg+xml"),
    "img/product-course.webp": ("static/img/product-course.webp", "image/webp"),
    "img/product-consulting.webp": ("static/img/product-consulting.webp", "image/webp"),
    "img/topic-networks.webp": ("static/img/topic-networks.webp", "image/webp"),
    "img/topic-systems.webp": ("static/img/topic-systems.webp", "image/webp"),
    "img/topic-security.webp": ("static/img/topic-security.webp", "image/webp"),
}


def format_eur(price_cents):
    euros, cents = divmod(price_cents, 100)
    return f"{euros},{cents:02d} €"


def openai_api_key_is_configured():
    return bool(os.environ.get("OPENAI_API_KEY", "").strip())


def openai_http_error_message(status_code):
    if status_code == 401:
        return (
            "OpenAI respondió HTTP 401: rechazó la clave API. Comprueba que sea "
            "válida y que pertenezca al proyecto correcto."
        )
    if status_code == 403:
        return (
            "OpenAI respondió HTTP 403: el proyecto no tiene permiso para usar "
            "este modelo o endpoint."
        )
    if status_code == 429:
        return (
            "OpenAI respondió HTTP 429: revisa la cuota, los límites y la "
            "facturación de la API; la suscripción de ChatGPT no incluye uso de API."
        )
    if status_code == 400:
        return (
            "OpenAI respondió HTTP 400: revisa OPENAI_MODEL y que el modelo admita "
            "el endpoint Chat Completions."
        )
    if status_code == 404:
        return (
            "OpenAI respondió HTTP 404: el modelo no existe o el proyecto no tiene "
            "acceso a él. Comprueba OPENAI_MODEL."
        )
    if status_code >= 500:
        return f"OpenAI respondió HTTP {status_code}: el proveedor puede estar temporalmente no disponible."
    return f"OpenAI respondió HTTP {status_code}. Revisa la configuración del proyecto y vuelve a intentarlo."


@app.context_processor
def shared_template_context():
    return {
        "current_year": datetime.now(timezone.utc).year,
        "products": PRODUCTS,
        "assistant_configured": openai_api_key_is_configured(),
        "stripe_checkout_enabled": stripe_checkout_is_configured(),
        "stripe_checkout_configuration_error": stripe_checkout_configuration_error(),
        "contact_service_configured": contact_service_is_configured(),
    }


def configured_stripe_payment_methods():
    requested_methods = {
        item.strip().casefold()
        for item in os.environ.get("STRIPE_PAYMENT_METHODS", "").split(",")
        if item.strip()
    }
    invalid_methods = requested_methods - STRIPE_PAYMENT_METHODS.keys()
    if invalid_methods:
        app.logger.error(
            "Stripe payment configuration contains unsupported methods: %s",
            ", ".join(sorted(invalid_methods)),
        )
        return ()
    return tuple(
        method
        for method in STRIPE_PAYMENT_METHODS
        if method in requested_methods
    )


def stripe_checkout_configuration_error():
    secret_key = os.environ.get("STRIPE_SECRET_KEY", "")
    webhook_secret = os.environ.get("STRIPE_WEBHOOK_SECRET", "")
    missing_configuration = []
    if os.environ.get("STRIPE_CHECKOUT_ENABLED", "").casefold() != "true":
        missing_configuration.append("STRIPE_CHECKOUT_ENABLED=true")
    if not secret_key.startswith(("sk_test_", "sk_live_")) or len(secret_key) <= 8:
        missing_configuration.append("STRIPE_SECRET_KEY")
    if not webhook_secret.startswith("whsec_") or len(webhook_secret) <= 6:
        missing_configuration.append("STRIPE_WEBHOOK_SECRET")
    if not configured_stripe_payment_methods():
        missing_configuration.append("STRIPE_PAYMENT_METHODS")
    if (
        secret_key.startswith("sk_live_")
        and urlsplit(app.config["SITE_URL"]).scheme != "https"
    ):
        missing_configuration.append("SITE_URL (debe usar HTTPS para claves live)")
    if missing_configuration:
        return "Falta configuración de Stripe: " + ", ".join(missing_configuration) + "."
    return ""


def stripe_checkout_is_configured():
    return not stripe_checkout_configuration_error()


def stripe_object_to_dict(value):
    """Normalize Stripe SDK resources and test dictionaries to plain dicts."""
    if isinstance(value, dict):
        return value
    to_dict = getattr(value, "to_dict", None)
    if callable(to_dict):
        converted = to_dict()
        if isinstance(converted, dict):
            return converted
    return {}


def contact_service_is_configured():
    api_key = os.environ.get("RESEND_API_KEY", "")
    sender = os.environ.get("CONTACT_FROM_EMAIL", "")
    recipient = os.environ.get("CONTACT_TO_EMAIL", "")
    return (
        api_key.startswith("re_")
        and CONTACT_EMAIL_PATTERN.fullmatch(sender) is not None
        and CONTACT_EMAIL_PATTERN.fullmatch(recipient) is not None
    )


def validate_contact_payload(payload):
    if not isinstance(payload, Mapping):
        return None, "Revisa los datos del formulario e inténtalo de nuevo."
    honeypot = payload.get("website", "")
    if not isinstance(honeypot, str) or honeypot.strip():
        return None, "No se pudo procesar el formulario."

    fields = ("name", "email", "subject", "message")
    if any(not isinstance(payload.get(field), str) for field in fields):
        return None, "Completa todos los campos con un formato válido."

    values = {field: payload[field].strip() for field in fields}
    if (
        not values["name"]
        or len(values["name"]) > 100
        or any(ord(char) < 32 for char in values["name"])
    ):
        return None, "El nombre es obligatorio y no puede superar los 100 caracteres."
    if (
        len(values["email"]) > 254
        or CONTACT_EMAIL_PATTERN.fullmatch(values["email"]) is None
    ):
        return None, "Escribe un correo electrónico válido."
    if (
        not values["subject"]
        or len(values["subject"]) > 160
        or any(ord(char) < 32 for char in values["subject"])
    ):
        return None, "El asunto es obligatorio y no puede superar los 160 caracteres."
    if not values["message"] or len(values["message"]) > 5000:
        return None, "El mensaje es obligatorio y no puede superar los 5000 caracteres."
    return values, None


def send_contact_message(values):
    if not contact_service_is_configured():
        return (
            "El envío de mensajes aún no está configurado. No se ha enviado ni guardado tu mensaje.",
            503,
        )

    message_data = {
        "from": os.environ["CONTACT_FROM_EMAIL"],
        "to": [os.environ["CONTACT_TO_EMAIL"]],
        "reply_to": values["email"],
        "subject": f"[ZeroTech] {values['subject']}",
        "text": (
            f"Nombre: {values['name']}\n"
            f"Correo: {values['email']}\n\n"
            f"{values['message']}"
        ),
    }
    outbound_request = Request(
        "https://api.resend.com/emails",
        data=json.dumps(message_data, ensure_ascii=False).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {os.environ['RESEND_API_KEY']}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    try:
        with urlopen(outbound_request, timeout=10) as provider_response:
            if not 200 <= provider_response.status < 300:
                return "El proveedor no confirmó el envío del mensaje. Inténtalo más tarde.", 502
    except (URLError, TimeoutError, OSError):
        app.logger.warning("No se pudo confirmar el envío de un mensaje de contacto.")
        return "No se pudo confirmar el envío. Inténtalo de nuevo más tarde.", 502

    return "El proveedor ha aceptado el mensaje para su envío.", 200


def shop_template_response():
    return render_template(
        "shop.html",
        format_eur=format_eur,
        page_title="Tienda — ZeroTech",
        meta_description="Cursos y servicios de ZeroTech.",
        canonical_url=f"{app.config['SITE_URL']}{url_for('index')}",
    )


@app.after_request
def add_response_headers(response):
    response.headers.setdefault("X-Content-Type-Options", "nosniff")
    response.headers.setdefault("Referrer-Policy", "strict-origin-when-cross-origin")
    response.headers.setdefault(
        "Permissions-Policy", "camera=(), microphone=(), geolocation=()"
    )
    content_security_policy = (
        "default-src 'self'; base-uri 'self'; object-src 'none'; "
        "frame-ancestors 'none'; form-action 'self'; img-src 'self'; "
        "style-src 'self'; script-src 'self'"
    )
    if request.path in {"/contact", "/contacto"}:
        content_security_policy += "; frame-src https://www.google.com"
    response.headers.setdefault("Content-Security-Policy", content_security_policy)
    if request.path.startswith("/api/") or (
        request.path == "/contact" and request.method == "POST"
    ):
        response.headers.setdefault("Cache-Control", "no-store")
    if response.mimetype == "text/html" and response.status_code == 200:
        response.headers.setdefault(
            "Cache-Control",
            "public, max-age=300, s-maxage=3600, stale-while-revalidate=86400",
        )
    return response


@app.get("/static/<path:filename>", endpoint="static")
def static_asset(filename):
    asset = STATIC_ASSETS.get(filename)
    if asset is None:
        abort(404)
    file_path, mime_type = asset
    return send_file(
        os.path.join(app.root_path, file_path),
        mimetype=mime_type,
        max_age=31536000,
        conditional=True,
    )


@app.get("/")
def index():
    return shop_template_response()


def review_db():
    review_data_dir = os.environ.get("DATA_DIR", app.instance_path)
    os.makedirs(review_data_dir, exist_ok=True)
    connection = sqlite3.connect(os.path.join(review_data_dir, "zerotech.sqlite3"))
    connection.row_factory = sqlite3.Row
    connection.execute("""CREATE TABLE IF NOT EXISTS product_reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id TEXT NOT NULL,
        name TEXT NOT NULL,
        rating INTEGER NOT NULL CHECK(rating BETWEEN 1 AND 5),
        comment TEXT NOT NULL,
        created_at TEXT NOT NULL
    )""")
    return connection


@app.get("/temario")
def syllabus():
    return render_template(
        "home.html",
        topics=TOPICS,
        page_title="Temario — ZeroTech",
        meta_description=(
            "Aprende los fundamentos de la informática con el temario de ZeroTech."
        ),
        canonical_url=f"{app.config['SITE_URL']}{url_for('syllabus')}",
    )


@app.get("/temario/<slug>")
def topic_page(slug):
    topic = next((item for item in TOPICS if item["id"] == slug), None)
    if topic is None:
        abort(404)
    return render_template("topic.html", topic=topic, topics=TOPICS, page_title=f"{topic['title']} — ZeroTech",
        meta_description=topic["description"], canonical_url=f"{app.config['SITE_URL']}{url_for('topic_page', slug=slug)}")


@app.get("/tienda")
def shop_page():
    return redirect(url_for("index"), code=301)


@app.get("/producto/<product_id>")
def product_page(product_id):
    product = PRODUCTS_BY_ID.get(product_id)
    if product is None:
        abort(404)
    with closing(review_db()) as db:
        reviews = db.execute("SELECT name, rating, comment, created_at FROM product_reviews WHERE product_id = ? ORDER BY id DESC LIMIT 100", (product_id,)).fetchall()
    average = round(sum(row["rating"] for row in reviews) / len(reviews), 1) if reviews else None
    token = session.setdefault("review_csrf", secrets.token_urlsafe(24))
    return render_template("product.html", product=product, reviews=reviews, average=average, csrf_token=token,
        review_error=session.pop("review_error", None),
        page_title=f"{product['name']} — ZeroTech", meta_description=product["description"],
        canonical_url=f"{app.config['SITE_URL']}{url_for('product_page', product_id=product_id)}", format_eur=format_eur)


@app.post("/producto/<product_id>/valoraciones")
def submit_review(product_id):
    product = PRODUCTS_BY_ID.get(product_id)
    if product is None:
        abort(404)
    name = request.form.get("name", "").strip()
    comment = request.form.get("comment", "").strip()
    rating = request.form.get("rating", "")
    if request.form.get("csrf_token") != session.get("review_csrf"):
        abort(400)
    if not name or len(name) > 80 or not rating.isdigit() or int(rating) not in range(1, 6) or not comment or len(comment) > 2000:
        session["review_error"] = "Revisa el nombre, la puntuación y el comentario (máximo 2.000 caracteres)."
        return redirect(url_for("product_page", product_id=product_id) + "#escribir-resena", code=303)
    with closing(review_db()) as db:
        db.execute("INSERT INTO product_reviews(product_id, name, rating, comment, created_at) VALUES (?, ?, ?, ?, ?)",
            (product_id, name, int(rating), comment, datetime.now(timezone.utc).isoformat()))
        db.commit()
    return redirect(url_for("product_page", product_id=product_id) + "#resenas", code=303)


@app.get("/login")
def login():
    return render_template(
        "login.html",
        page_title="Iniciar sesión — ZeroTech",
        meta_description="Acceso a cuentas de ZeroTech. El servicio de autenticación aún no está configurado.",
        canonical_url=f"{app.config['SITE_URL']}{url_for('login')}",
        robots_content="noindex, nofollow",
    )


@app.get("/contact")
def contact_page():
    return redirect(url_for("contact_page_es"), code=301)


@app.get("/contacto")
def contact_page_es():
    return render_template("contact.html", contact_feedback=None, page_title="Contacto — ZeroTech",
        meta_description="Contacta con ZeroTech para resolver tus dudas.", canonical_url=f"{app.config['SITE_URL']}{url_for('contact_page_es')}")


@app.get("/contacto/")
def contact_page_trailing_slash():
    return redirect(url_for("contact_page_es"), code=301)


@app.post("/contact")
def contact_form():
    values, error = validate_contact_payload(request.form)
    if error:
        return render_template("contact.html", contact_feedback={"kind": "error", "message": error},
            page_title="Contacto — ZeroTech", meta_description="Contacta con ZeroTech.", canonical_url=f"{app.config['SITE_URL']}{url_for('contact_page_es')}"), 400
    message, status_code = send_contact_message(values)
    return render_template("contact.html", contact_feedback={"kind": "success" if status_code == 200 else "error", "message": message},
        page_title="Contacto — ZeroTech", meta_description="Contacta con ZeroTech.", canonical_url=f"{app.config['SITE_URL']}{url_for('contact_page_es')}"), status_code


@app.post("/api/contact")
def contact_api():
    if not request.is_json:
        return jsonify(error="Envía el formulario en formato JSON."), 415
    payload = request.get_json(silent=True)
    values, error = validate_contact_payload(payload)
    if error:
        return jsonify(error=error), 400
    message, status_code = send_contact_message(values)
    if status_code != 200:
        return jsonify(error=message), status_code
    return jsonify(message=message), 200


@app.post("/api/checkout-session")
def create_checkout_session():
    if not stripe_checkout_is_configured():
        return (
            jsonify(
                error=(
                    "El pago aún no está configurado. No se ha creado una sesión "
                    "ni realizado ningún cobro. "
                    f"{stripe_checkout_configuration_error()}"
                ),
                code="payment_not_configured",
            ),
            503,
        )
    if not request.is_json:
        return jsonify(error="Envía el carrito en formato JSON."), 415

    payload = request.get_json(silent=True)
    items = payload.get("items") if isinstance(payload, dict) else None
    if not isinstance(items, list) or not items or len(items) > len(PRODUCTS):
        return jsonify(error="El carrito no contiene productos válidos."), 400

    line_items = []
    seen_product_ids = set()
    expected_amount_cents = 0
    for item in items:
        if not isinstance(item, dict):
            return jsonify(error="El carrito contiene un producto no válido."), 400
        product_id = item.get("id")
        quantity = item.get("quantity")
        product = PRODUCTS_BY_ID.get(product_id) if isinstance(product_id, str) else None
        if (
            product is None
            or product_id in seen_product_ids
            or type(quantity) is not int
            or not 1 <= quantity <= 99
        ):
            return jsonify(error="El carrito contiene un producto o cantidad no válida."), 400
        seen_product_ids.add(product_id)
        expected_amount_cents += product["price_cents"] * quantity
        line_items.append(
            {
                "price_data": {
                    "currency": "eur",
                    "product_data": {"name": product["name"]},
                    "unit_amount": product["price_cents"],
                },
                "quantity": quantity,
            }
        )
    try:
        stripe_client = stripe.StripeClient(os.environ["STRIPE_SECRET_KEY"])
        session = stripe_client.v1.checkout.sessions.create(
            {
                "mode": "payment",
                "line_items": line_items,
                "payment_method_types": list(configured_stripe_payment_methods()),
                "metadata": {
                    "site": "zerotech",
                    "expected_amount_cents": str(expected_amount_cents),
                },
                "success_url": (
                    f"{app.config['SITE_URL']}{url_for('checkout_result')}"
                    "?session_id={CHECKOUT_SESSION_ID}"
                ),
                "cancel_url": f"{app.config['SITE_URL']}{url_for('checkout_cancelled')}",
            }
        )
    except stripe.StripeError:
        app.logger.warning("Stripe no pudo crear una sesión de Checkout.")
        return (
            jsonify(error="No se pudo iniciar el pago. No se ha confirmado ningún cobro."),
            502,
        )

    session_data = stripe_object_to_dict(session)
    checkout_url = session_data.get("url")
    if not isinstance(checkout_url, str) or not checkout_url.startswith(
        "https://checkout.stripe.com/"
    ):
        app.logger.error("Stripe devolvió una URL de Checkout no válida.")
        return jsonify(error="Stripe no devolvió un enlace de pago seguro."), 502
    return jsonify(url=checkout_url), 200


@app.get("/compra/resultado")
def checkout_result():
    payment_state = "error"
    if stripe_checkout_is_configured():
        session_id = request.args.get("session_id", "")
        if re.fullmatch(r"cs_[A-Za-z0-9_]{8,250}", session_id):
            try:
                stripe_client = stripe.StripeClient(os.environ["STRIPE_SECRET_KEY"])
                session = stripe_client.v1.checkout.sessions.retrieve(session_id)
                session_data = stripe_object_to_dict(session)
                metadata = session_data.get("metadata") or {}
                expected_amount = metadata.get("expected_amount_cents")
                checkout_belongs_to_site = (
                    metadata.get("site") == "zerotech"
                    and session_data.get("mode") == "payment"
                    and session_data.get("currency") == "eur"
                    and isinstance(expected_amount, str)
                    and expected_amount.isdigit()
                    and session_data.get("amount_total") == int(expected_amount)
                )
                if checkout_belongs_to_site and session_data.get("payment_status") == "paid":
                    payment_state = "approved"
                elif checkout_belongs_to_site and session_data.get("status") == "expired":
                    payment_state = "cancelled"
                elif checkout_belongs_to_site and session_data.get("status") in {"open", "complete"}:
                    payment_state = "pending"
            except stripe.StripeError:
                app.logger.warning("No se pudo verificar el estado de una sesión de Stripe.")

    response = make_response(
        render_template(
            "payment_result.html",
            payment_state=payment_state,
            page_title="Estado del pago — ZeroTech",
            meta_description="Estado de una sesión de pago de ZeroTech.",
            canonical_url=None,
            robots_content="noindex, nofollow",
        )
    )
    response.headers["Cache-Control"] = "no-store"
    return response


@app.get("/compra/cancelada")
def checkout_cancelled():
    response = make_response(
        render_template(
            "payment_result.html",
            payment_state="cancelled",
            page_title="Pago cancelado — ZeroTech",
            meta_description="El pago de ZeroTech no se completó.",
            canonical_url=None,
            robots_content="noindex, nofollow",
        )
    )
    response.headers["Cache-Control"] = "no-store"
    return response


@app.post("/api/payments/webhook")
def stripe_webhook():
    webhook_secret = os.environ.get("STRIPE_WEBHOOK_SECRET", "")
    if not webhook_secret.startswith("whsec_"):
        return jsonify(error="La recepción de eventos de pago no está configurada."), 503
    signature = request.headers.get("Stripe-Signature", "")
    if not signature:
        return jsonify(error="Falta la firma del proveedor de pagos."), 400
    try:
        event = stripe.Webhook.construct_event(
            request.get_data(cache=False),
            signature,
            webhook_secret,
        )
    except ValueError:
        return jsonify(error="El evento de pago no es válido."), 400
    except stripe.SignatureVerificationError:
        return jsonify(error="La firma del evento de pago no es válida."), 400

    event_data = stripe_object_to_dict(event)
    event_type = event_data.get("type")
    if event_type in {
        "checkout.session.completed",
        "checkout.session.async_payment_succeeded",
        "checkout.session.async_payment_failed",
    }:
        session_data = stripe_object_to_dict(
            event_data.get("data", {}).get("object", {})
        )
        metadata = session_data.get("metadata") or {}
        expected_amount = metadata.get("expected_amount_cents")
        is_zerotech_checkout = (
            metadata.get("site") == "zerotech"
            and session_data.get("mode") == "payment"
            and session_data.get("currency") == "eur"
            and isinstance(expected_amount, str)
            and expected_amount.isdigit()
            and session_data.get("amount_total") == int(expected_amount)
        )
        if is_zerotech_checkout:
            if event_type in {
                "checkout.session.completed",
                "checkout.session.async_payment_succeeded",
            } and session_data.get("payment_status") == "paid":
                app.logger.info(
                    "Stripe confirmó un pago de ZeroTech; la entrega automática no está configurada."
                )
            elif event_type == "checkout.session.async_payment_failed":
                app.logger.warning("Stripe notificó un pago de ZeroTech no completado.")
    return jsonify(received=True), 200


@app.get("/sobre")
def legacy_about():
    return redirect(url_for("syllabus") + "#objetivo", code=301)


@app.post("/api/chat")
def chat_api():
    if not request.is_json:
        return jsonify(error="Envía la pregunta en formato JSON."), 415

    payload = request.get_json(silent=True)
    if not isinstance(payload, dict):
        return jsonify(error="No se pudo leer la pregunta. Inténtalo de nuevo."), 400

    message = payload.get("message")
    if not isinstance(message, str) or not message.strip():
        return jsonify(error="Escribe una pregunta antes de enviarla."), 400
    if len(message) > 1000:
        return jsonify(error="La pregunta no puede superar los 1000 caracteres."), 400

    api_key = os.environ.get("OPENAI_API_KEY", "").strip()
    if not openai_api_key_is_configured():
        return (
            jsonify(
                error=(
                    "Falta OPENAI_API_KEY en la configuración del servidor. "
                    "La pregunta no se ha enviado; añade la clave de OpenAI en el "
                    "archivo .env local o en los secretos del alojamiento."
                ),
                code="assistant_not_configured",
            ),
            503,
        )

    provider_request = Request(
        "https://api.openai.com/v1/chat/completions",
        data=json.dumps(
            {
                "model": os.environ.get("OPENAI_MODEL", "gpt-4o-mini"),
                "messages": [
                    {
                        "role": "system",
                        "content": (
                            "Eres el asistente educativo de ZeroTech. Responde en "
                            "español con claridad y de forma útil para quien está "
                            "aprendiendo informática. No inventes características, "
                            "precios ni disponibilidad de productos o servicios."
                        ),
                    },
                    {"role": "user", "content": message.strip()},
                ],
                "max_tokens": 500,
            }
        ).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
        method="POST",
    )
    try:
        with urlopen(provider_request, timeout=20) as provider_response:
            response_data = json.loads(provider_response.read())
    except HTTPError as error:
        app.logger.warning("OpenAI chat request failed with HTTP %d.", error.code)
        return (
            jsonify(
                error=openai_http_error_message(error.code),
                code=f"openai_http_{error.code}",
            ),
            502,
        )
    except TimeoutError:
        app.logger.warning("OpenAI chat request timed out.")
        return (
            jsonify(
                error="Se agotó el tiempo de espera al conectar con OpenAI. Inténtalo de nuevo.",
                code="openai_timeout",
            ),
            502,
        )
    except URLError:
        app.logger.warning("OpenAI chat request failed with a connection error.")
        return (
            jsonify(
                error=(
                    "No se pudo conectar con OpenAI. Comprueba la conexión del "
                    "servidor e inténtalo de nuevo."
                ),
                code="openai_connection_error",
            ),
            502,
        )
    except (json.JSONDecodeError, UnicodeDecodeError):
        app.logger.warning("El proveedor de IA devolvió una respuesta no válida.")
        return (
            jsonify(
                error=(
                    "El proveedor de IA devolvió una respuesta no válida. "
                    "Inténtalo de nuevo más tarde."
                ),
                code="assistant_invalid_response",
            ),
            502,
        )

    reply = None
    if isinstance(response_data, dict):
        choices = response_data.get("choices")
        if isinstance(choices, list) and choices and isinstance(choices[0], dict):
            assistant_message = choices[0].get("message")
            if isinstance(assistant_message, dict):
                reply = assistant_message.get("content")
    if not isinstance(reply, str) or not reply.strip():
        app.logger.warning("El proveedor de IA devolvió una respuesta vacía.")
        return (
            jsonify(
                error=(
                    "El proveedor de IA no generó una respuesta. "
                    "Inténtalo de nuevo más tarde."
                ),
                code="assistant_empty_response",
            ),
            502,
        )
    return jsonify(reply=reply.strip()), 200


@app.get("/api/chat/status")
def chat_status():
    return jsonify(configured=openai_api_key_is_configured()), 200


@app.errorhandler(RequestEntityTooLarge)
def request_too_large(_error):
    if request.path.startswith("/api/"):
        return jsonify(error="La solicitud supera el tamaño permitido."), 413
    return "Solicitud demasiado grande.", 413


@app.get("/robots.txt")
def robots():
    sitemap_url = f"{app.config['SITE_URL']}{url_for('sitemap')}"
    return Response(
        f"User-agent: *\nAllow: /\nSitemap: {sitemap_url}\n",
        mimetype="text/plain",
    )


@app.get("/sitemap.xml")
def sitemap():
    paths = [
        url_for("index"),
        url_for("syllabus"),
        url_for("contact_page_es"),
    ]
    paths.extend(url_for("topic_page", slug=topic["id"]) for topic in TOPICS)
    paths.extend(url_for("product_page", product_id=product["id"]) for product in PRODUCTS)
    entries = "\n".join(f"  <url><loc>{escape(app.config['SITE_URL'] + path)}</loc></url>" for path in paths)
    xml = f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n{entries}\n</urlset>\n'
    return Response(xml, mimetype="application/xml")


@app.route("/<path:legacy_path>")
def redirect_legacy_wordpress_pages(legacy_path):
    path = legacy_path.strip("/")
    if path.casefold() in {"index.html", "index.php", "wordpress/index.php"}:
        return redirect(url_for("index"), code=301)
    if path.casefold().startswith("wordpress/"):
        path = path[len("wordpress/") :]
    if path.casefold().startswith("index.php/"):
        path = path[len("index.php/") :]
    path = path.strip("/").casefold()
    if path.endswith(".html"):
        path = path[:-5]

    anchor = LEGACY_TOPIC_ANCHORS.get(path)
    if anchor is not None:
        return redirect(url_for("topic_page", slug=anchor), code=301)
    abort(404)


@app.errorhandler(404)
def page_not_found(_error):
    return (
        render_template(
            "404.html",
            page_title="Página no encontrada — ZeroTech",
            meta_description="La página que buscas no está disponible.",
            canonical_url=None,
        ),
        404,
    )


if __name__ == "__main__":
    app.run(
        host=os.environ.get("HOST", "127.0.0.1"),
        port=int(os.environ.get("PORT", "5000")),
        debug=os.environ.get("FLASK_DEBUG") == "1",
    )
