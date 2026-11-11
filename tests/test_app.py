import hashlib
import hmac
import json
import time
import unittest
import xml.etree.ElementTree as ET
from html.parser import HTMLParser
from io import BytesIO
from pathlib import Path
from urllib.error import HTTPError, URLError
from unittest.mock import patch

from app import app


class VisibleTextParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.parts = []

    def handle_data(self, data):
        self.parts.append(data)


class ZeroTechPageTests(unittest.TestCase):
    def setUp(self):
        app.config["TESTING"] = True
        self.client = app.test_client()

    def test_homepage_opens_the_store_with_both_existing_products(self):
        response = self.client.get("/")
        self.assertEqual(response.status_code, 200)
        parser = VisibleTextParser()
        parser.feed(response.get_data(as_text=True))
        visible_text = " ".join(" ".join(parser.parts).split())
        for text in (
            "Tienda ZeroTech",
            "Aprende y recibe apoyo técnico",
            "Curso Premium SMR",
            "49,99 €",
            "Consultoría Técnica Personalizada",
            "29,99 €",
        ):
            with self.subTest(text=text):
                self.assertIn(text, visible_text)
        self.assertEqual(response.data.count(b"<h1"), 1)
        self.assertIn(b'rel="canonical" href="http://localhost:5000/"', response.data)
        self.assertIn(b'<a href="/temario">Temario</a>', response.data)
        self.assertIn(b'<a href="/">Tienda</a>', response.data)
        self.assertIn(b'<a href="/contacto">Contacto</a>', response.data)
        self.assertIn(b"STRIPE_SECRET_KEY", response.data)
        self.assertIn(b"STRIPE_WEBHOOK_SECRET", response.data)

    def test_syllabus_and_individual_topics_remain_separate_readable_pages(self):
        response = self.client.get("/temario")
        self.assertEqual(response.status_code, 200)
        self.assertIn(b"Aprende inform\xc3\xa1tica desde cero", response.data)
        self.assertIn(b"Objetivo", response.data)
        self.assertIn(b"Acercar la inform\xc3\xa1tica a cualquier persona", response.data)
        self.assertNotIn(b"learning-laptop.svg", response.data)

        for topic_id in ("hardware", "redes", "sistemas-operativos", "seguridad"):
            with self.subTest(topic_id=topic_id):
                topic_response = self.client.get(f"/temario/{topic_id}")
                self.assertEqual(topic_response.status_code, 200)
                self.assertIn(b'class="topic-photo"', topic_response.data)
                self.assertIn(b'alt="', topic_response.data)
                for content in (
                    "Aprende paso a paso",
                    "Conceptos clave",
                    "Ejemplo sencillo",
                    "En resumen",
                ):
                    self.assertIn(content.encode(), topic_response.data)

    def test_product_and_topic_photos_are_locally_served_webp_files(self):
        for filename in (
            "product-course.webp",
            "product-consulting.webp",
            "topic-networks.webp",
            "topic-systems.webp",
            "topic-security.webp",
        ):
            with self.subTest(filename=filename):
                response = self.client.get(f"/static/img/{filename}")
                try:
                    self.assertEqual(response.status_code, 200)
                    self.assertEqual(response.mimetype, "image/webp")
                    self.assertEqual(response.data[:4], b"RIFF")
                    self.assertEqual(response.data[8:12], b"WEBP")
                finally:
                    response.close()

    def test_store_contains_exactly_two_products_with_provisional_details(self):
        response = self.client.get("/")
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.data.count(b'class="product-card"'), 2)
        self.assertEqual(response.data.count(b'class="button button-secondary add-to-cart"'), 2)
        self.assertIn("Curso Premium SMR".encode(), response.data)
        self.assertIn("49,99 €".encode(), response.data)
        self.assertIn("Consultoría Técnica Personalizada".encode(), response.data)
        self.assertIn("29,99 €".encode(), response.data)
        self.assertIn("Temario, formato y duración pendientes de confirmación.".encode(), response.data)
        self.assertIn("Alcance, duración y modalidad del servicio pendientes de confirmar.".encode(), response.data)
        self.assertIn(b"Pago temporalmente desactivado", response.data)
        self.assertNotIn(b"type=\"password\"", response.data)
        store_alias = self.client.get("/tienda", follow_redirects=False)
        self.assertEqual(store_alias.status_code, 301)
        self.assertEqual(store_alias.headers["Location"], "/")

        for product_id, image in (
            ("curso-premium-smr", "product-course.webp"),
            ("consultoria-tecnica-personalizada", "product-consulting.webp"),
        ):
            with self.subTest(product_id=product_id):
                detail = self.client.get(f"/producto/{product_id}")
                self.assertEqual(detail.status_code, 200)
                self.assertIn(f"/static/img/{image}?v=2".encode(), detail.data)
                self.assertIn(b'id="resenas"', detail.data)
                self.assertIn(b'id="escribir-resena"', detail.data)

    def test_topic_routes_and_legacy_wordpress_paths_preserve_individual_pages(self):
        routes = (
            ("/index.php/hardware/", "/temario/hardware"),
            ("/wordpress/index.php/redes/", "/temario/redes"),
            ("/sobre", "/temario#objetivo"),
        )
        for path, target in routes:
            with self.subTest(path=path):
                response = self.client.get(path, follow_redirects=False)
                self.assertEqual(response.status_code, 301)
                self.assertEqual(response.headers["Location"], target)
        for topic_id in ("hardware", "redes", "sistemas-operativos", "seguridad"):
            with self.subTest(topic_id=topic_id):
                response = self.client.get(f"/temario/{topic_id}")
                self.assertEqual(response.status_code, 200)

    def test_login_view_is_accessible_and_discloses_unconfigured_authentication(self):
        response = self.client.get("/login")
        self.assertEqual(response.status_code, 200)
        self.assertIn(b'name="email"', response.data)
        self.assertIn(b'name="password"', response.data)
        self.assertIn(b'aria-pressed="false"', response.data)
        self.assertIn(b'id="login-submit" type="button"', response.data)
        self.assertIn(b'type="button" aria-pressed="false"', response.data)
        self.assertIn(b'content="noindex, nofollow"', response.data)
        self.assertIn(b"no enviar\xc3\xa1 ni guardar\xc3\xa1 tus credenciales", response.data)

    def test_contact_section_and_server_validation_do_not_claim_unconfigured_delivery(self):
        contact = self.client.get("/contacto")
        self.assertEqual(contact.status_code, 200)
        self.assertIn(b'href="/contacto">Contacto</a>', contact.data)
        self.assertIn("Ubicación de referencia en Valencia".encode(), contact.data)
        self.assertIn(b'Calle+Col%C3%B3n%2C+25%2C+46004+Valencia%2C+Espa%C3%B1a', contact.data)
        self.assertIn(b"no es la direcci\xc3\xb3n real de ZeroTech", contact.data)
        self.assertIn(b"ni indica que exista una tienda f\xc3\xadsica", contact.data)
        self.assertIn(b"https://www.google.com/maps/search/", contact.data)
        self.assertIn(b'id="contact-status"', contact.data)
        self.assertIn("frame-src https://www.google.com".encode(), contact.headers["Content-Security-Policy"].encode())
        self.assertNotIn("frame-src", self.client.get("/").headers["Content-Security-Policy"])
        self.assertEqual(self.client.get("/contact").headers["Location"], "/contacto")

        invalid = self.client.post("/api/contact", json={"name": "Ada"})
        self.assertEqual(invalid.status_code, 400)
        self.assertIn("formato válido", invalid.json["error"])

        payload = {
            "name": "Ada Lovelace",
            "email": "ada@example.com",
            "subject": "Consulta",
            "message": "Quiero saber más sobre los contenidos.",
            "website": "",
        }
        with patch.dict(
            "os.environ",
            {
                "RESEND_API_KEY": "",
                "CONTACT_FROM_EMAIL": "",
                "CONTACT_TO_EMAIL": "",
            },
        ):
            unavailable = self.client.post("/api/contact", json=payload)
            form_response = self.client.post("/contact", data=payload)
        self.assertEqual(unavailable.status_code, 503)
        self.assertIn(
            "no se ha enviado ni guardado",
            unavailable.json["error"].casefold(),
        )
        self.assertNotIn(b"ada@example.com", unavailable.data)
        self.assertEqual(form_response.status_code, 503)
        self.assertIn(b"El env\xc3\xado de mensajes a\xc3\xban no est\xc3\xa1 configurado", form_response.data)
        self.assertEqual(form_response.headers["Cache-Control"], "no-store")

    def test_contact_service_reports_success_only_after_provider_accepts(self):
        payload = {
            "name": "Ada Lovelace",
            "email": "ada@example.com",
            "subject": "Consulta",
            "message": "Quiero saber más sobre los contenidos.",
            "website": "",
        }
        with patch.dict(
            "os.environ",
            {
                "RESEND_API_KEY": "re_test_key",
                "CONTACT_FROM_EMAIL": "web@zerotech.example",
                "CONTACT_TO_EMAIL": "owner@zerotech.example",
            },
        ), patch("app.urlopen") as provider:
            provider.return_value.__enter__.return_value.status = 200
            response = self.client.post("/api/contact", json=payload)

        self.assertEqual(response.status_code, 200)
        self.assertIn(b"aceptado el mensaje", response.data)
        outbound_request = provider.call_args.args[0]
        self.assertEqual(outbound_request.full_url, "https://api.resend.com/emails")
        self.assertIn(b"ada@example.com", outbound_request.data)
        self.assertEqual(outbound_request.get_header("Authorization"), "Bearer re_test_key")

    def test_checkout_is_disabled_without_provider_configuration(self):
        with patch.dict(
            "os.environ",
            {
                "STRIPE_CHECKOUT_ENABLED": "",
                "STRIPE_SECRET_KEY": "",
                "STRIPE_WEBHOOK_SECRET": "",
                "STRIPE_PAYMENT_METHODS": "",
            },
        ):
            response = self.client.post(
                "/api/checkout-session",
                json={"items": [{"id": "curso-premium-smr", "quantity": 1}]},
            )
        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.json["code"], "payment_not_configured")
        self.assertIn("no se ha creado una sesión".casefold(), response.json["error"].casefold())
        self.assertIn("STRIPE_SECRET_KEY", response.json["error"])
        self.assertIn("STRIPE_WEBHOOK_SECRET", response.json["error"])
        self.assertIn("STRIPE_CHECKOUT_ENABLED=true", response.json["error"])

    def test_environment_example_has_no_secret_values_and_disables_checkout(self):
        example_path = Path(app.root_path, ".env.example")
        lines = example_path.read_text(encoding="utf-8").splitlines()
        configured_values = {
            line.partition("=")[0]: line.partition("=")[2].strip()
            for line in lines
            if line.partition("=")[0]
            in {
                "OPENAI_API_KEY",
                "STRIPE_SECRET_KEY",
                "STRIPE_WEBHOOK_SECRET",
            }
        }

        self.assertEqual(
            set(configured_values),
            {"OPENAI_API_KEY", "STRIPE_SECRET_KEY", "STRIPE_WEBHOOK_SECRET"},
        )
        self.assertFalse(any(configured_values.values()), "secret values must be blank")
        self.assertIn("STRIPE_CHECKOUT_ENABLED=false", lines)

    def test_unknown_payment_method_disables_checkout_and_logs_configuration_error(self):
        payment_environment = {
            "STRIPE_CHECKOUT_ENABLED": "true",
            "STRIPE_SECRET_KEY": "sk_test_example",
            "STRIPE_WEBHOOK_SECRET": "whsec_example",
            "STRIPE_PAYMENT_METHODS": "card,unknown",
        }
        with patch.dict("os.environ", payment_environment), self.assertLogs(
            "app", level="ERROR"
        ) as logs:
            response = self.client.post(
                "/api/checkout-session",
                json={"items": [{"id": "curso-premium-smr", "quantity": 1}]},
            )

        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.json["code"], "payment_not_configured")
        self.assertIn("unknown", logs.output[0])

    def test_live_stripe_checkout_requires_https_site_url(self):
        payment_environment = {
            "STRIPE_CHECKOUT_ENABLED": "true",
            "STRIPE_SECRET_KEY": "sk_live_example",
            "STRIPE_WEBHOOK_SECRET": "whsec_example",
            "STRIPE_PAYMENT_METHODS": "card",
        }
        with patch.dict("os.environ", payment_environment), patch.dict(
            app.config, {"SITE_URL": "http://localhost:5000"}
        ):
            response = self.client.post(
                "/api/checkout-session",
                json={"items": [{"id": "curso-premium-smr", "quantity": 1}]},
            )

        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.json["code"], "payment_not_configured")

    def test_checkout_uses_server_catalog_and_redirects_to_hosted_stripe(self):
        payment_environment = {
            "STRIPE_CHECKOUT_ENABLED": "true",
            "STRIPE_SECRET_KEY": "sk_test_example",
            "STRIPE_WEBHOOK_SECRET": "whsec_example",
            "STRIPE_PAYMENT_METHODS": "card,paypal,bizum",
        }
        with patch.dict("os.environ", payment_environment        ), patch("app.stripe.StripeClient") as stripe_client:
            create_session = stripe_client.return_value.v1.checkout.sessions.create
            create_session.return_value = {
                "url": "https://checkout.stripe.com/c/pay/cs_test_example"
            }
            invalid = self.client.post(
                "/api/checkout-session",
                json={"items": [{"id": "curso-premium-smr", "quantity": 0}]},
            )
            response = self.client.post(
                "/api/checkout-session",
                json={
                    "items": [
                        {
                            "id": "curso-premium-smr",
                            "quantity": 2,
                            "price_cents": 1,
                        }
                    ]
                },
            )

        self.assertEqual(invalid.status_code, 400)
        self.assertEqual(response.status_code, 200)
        self.assertEqual(
            response.json["url"],
            "https://checkout.stripe.com/c/pay/cs_test_example",
        )
        create_session.assert_called_once()
        line_items = create_session.call_args.args[0]["line_items"]
        self.assertEqual(line_items[0]["price_data"]["unit_amount"], 4999)
        self.assertEqual(line_items[0]["quantity"], 2)
        self.assertEqual(create_session.call_args.args[0]["mode"], "payment")
        self.assertEqual(
            create_session.call_args.args[0]["payment_method_types"],
            ["card", "paypal", "bizum"],
        )
        self.assertEqual(
            create_session.call_args.args[0]["metadata"],
            {"site": "zerotech", "expected_amount_cents": "9998"},
        )
        stripe_client.assert_called_once_with("sk_test_example")

    def test_checkout_result_only_confirms_a_paid_zerotech_stripe_session(self):
        payment_environment = {
            "STRIPE_CHECKOUT_ENABLED": "true",
            "STRIPE_SECRET_KEY": "sk_test_example",
            "STRIPE_WEBHOOK_SECRET": "whsec_example",
            "STRIPE_PAYMENT_METHODS": "card,paypal,bizum",
        }
        with patch.dict("os.environ", payment_environment), patch(
            "app.stripe.StripeClient"
        ) as stripe_client:
            stripe_client.return_value.v1.checkout.sessions.retrieve.return_value = {
                "mode": "payment",
                "payment_status": "paid",
                "status": "complete",
                "currency": "eur",
                "amount_total": 4999,
                "metadata": {
                    "site": "zerotech",
                    "expected_amount_cents": "4999",
                },
            }
            confirmed = self.client.get("/compra/resultado?session_id=cs_test_12345678")
        self.assertEqual(confirmed.status_code, 200)
        self.assertIn(b"El proveedor confirm\xc3\xb3 el pago", confirmed.data)
        self.assertEqual(confirmed.headers["Cache-Control"], "no-store")

        with patch.dict("os.environ", payment_environment), patch(
            "app.stripe.StripeClient"
        ) as stripe_client:
            stripe_client.return_value.v1.checkout.sessions.retrieve.return_value = {
                "metadata": {
                    "site": "zerotech",
                    "expected_amount_cents": "4999",
                },
                "mode": "payment",
                "payment_status": "paid",
                "status": "complete",
                "currency": "eur",
                "amount_total": 1,
            }
            unconfirmed = self.client.get("/compra/resultado?session_id=cs_test_12345678")
        self.assertIn(b"No podemos verificar el estado del pago", unconfirmed.data)

        with patch.dict("os.environ", payment_environment), patch(
            "app.stripe.StripeClient"
        ) as stripe_client:
            stripe_client.return_value.v1.checkout.sessions.retrieve.return_value = {
                "metadata": {
                    "site": "zerotech",
                    "expected_amount_cents": "4999",
                },
                "mode": "payment",
                "payment_status": "unpaid",
                "status": "complete",
                "currency": "eur",
                "amount_total": 4999,
            }
            pending = self.client.get("/compra/resultado?session_id=cs_test_12345678")
        self.assertIn(b"Pago pendiente", pending.data)

    def test_stripe_checkout_cancellation_and_missing_configuration_are_not_paid(self):
        cancelled = self.client.get("/compra/cancelada")
        self.assertIn(b"El proceso de pago se cancel\xc3\xb3", cancelled.data)
        self.assertNotIn(b"Pago confirmado por Stripe", cancelled.data)

        result = self.client.get("/compra/resultado?session_id=invalid")
        self.assertIn(b"No podemos verificar el estado del pago", result.data)

    def test_stripe_webhook_requires_and_verifies_provider_signature(self):
        event = {
            "type": "checkout.session.completed",
            "data": {
                "object": {
                    "metadata": {
                        "site": "zerotech",
                        "expected_amount_cents": "4999",
                    },
                    "mode": "payment",
                    "currency": "eur",
                    "amount_total": 4999,
                    "payment_status": "paid",
                }
            },
        }
        with patch.dict("os.environ", {"STRIPE_WEBHOOK_SECRET": "whsec_example"}):
            missing_signature = self.client.post(
                "/api/payments/webhook",
                data=b"{}",
                content_type="application/json",
            )
            with patch(
                "app.stripe.Webhook.construct_event",
                return_value=event,
            ) as verify_event:
                accepted = self.client.post(
                    "/api/payments/webhook",
                    data=b'{"type":"checkout.session.completed"}',
                    content_type="application/json",
                    headers={"Stripe-Signature": "test-signature"},
                )

        self.assertEqual(missing_signature.status_code, 400)
        self.assertEqual(accepted.status_code, 200)
        self.assertEqual(accepted.json, {"received": True})
        self.assertEqual(accepted.headers["Cache-Control"], "no-store")
        verify_event.assert_called_once_with(
            b'{"type":"checkout.session.completed"}',
            "test-signature",
            "whsec_example",
        )

    def test_stripe_webhook_validates_a_real_test_signature_without_network_access(self):
        secret = "whsec_local_test_secret"
        payload = json.dumps(
            {
                "type": "checkout.session.completed",
                "data": {"object": {}},
            },
            separators=(",", ":"),
        ).encode("utf-8")
        timestamp = int(time.time())
        signed_payload = f"{timestamp}.".encode("ascii") + payload
        signature = hmac.new(
            secret.encode("ascii"), signed_payload, hashlib.sha256
        ).hexdigest()

        with patch.dict("os.environ", {"STRIPE_WEBHOOK_SECRET": secret}):
            accepted = self.client.post(
                "/api/payments/webhook",
                data=payload,
                content_type="application/json",
                headers={"Stripe-Signature": f"t={timestamp},v1={signature}"},
            )
            rejected = self.client.post(
                "/api/payments/webhook",
                data=payload,
                content_type="application/json",
                headers={"Stripe-Signature": f"t={timestamp},v1=invalid"},
            )

        self.assertEqual(accepted.status_code, 200)
        self.assertEqual(accepted.json, {"received": True})
        self.assertEqual(rejected.status_code, 400)

    def test_nonexistent_page_has_accessible_custom_404(self):
        response = self.client.get("/temario/not-a-topic")
        self.assertEqual(response.status_code, 404)
        self.assertIn("No encontramos esa página".encode(), response.data)

    def test_chat_api_validates_input_and_names_the_missing_provider_key(self):
        wrong_content_type = self.client.post("/api/chat", data="question")
        self.assertEqual(wrong_content_type.status_code, 415)

        missing_message = self.client.post("/api/chat", json={"message": " "})
        self.assertEqual(missing_message.status_code, 400)

        long_message = self.client.post("/api/chat", json={"message": "x" * 1001})
        self.assertEqual(long_message.status_code, 400)

        with patch.dict("os.environ", {"OPENAI_API_KEY": ""}):
            unavailable = self.client.post(
                "/api/chat", json={"message": "¿Qué es la RAM?"}
            )
        self.assertEqual(unavailable.status_code, 503)
        self.assertEqual(unavailable.json["code"], "assistant_not_configured")
        self.assertIn("OPENAI_API_KEY", unavailable.json["error"])
        self.assertIn("no se ha enviado", unavailable.json["error"])

    def test_chat_status_exposes_only_whether_the_api_key_is_configured(self):
        with patch.dict("os.environ", {"OPENAI_API_KEY": ""}):
            unconfigured = self.client.get("/api/chat/status")
        self.assertEqual(unconfigured.status_code, 200)
        self.assertEqual(unconfigured.json, {"configured": False})

        with patch.dict("os.environ", {"OPENAI_API_KEY": "test-api-key"}):
            configured = self.client.get("/api/chat/status")
        self.assertEqual(configured.status_code, 200)
        self.assertEqual(configured.json, {"configured": True})

    def test_chat_widget_discloses_provider_and_unconfigured_state(self):
        with patch.dict("os.environ", {"OPENAI_API_KEY": ""}):
            unconfigured = self.client.get("/")
        self.assertIn(b"Falta OPENAI_API_KEY en el servidor", unconfigured.data)
        self.assertIn(b"No se enviar\xc3\xa1 tu pregunta", unconfigured.data)

        with patch.dict("os.environ", {"OPENAI_API_KEY": "test-api-key"}):
            configured = self.client.get("/")
        self.assertIn(b"Las preguntas se env\xc3\xadan a OpenAI", configured.data)
        self.assertNotIn(b"test-api-key", configured.data)

    def test_chat_api_calls_openai_on_the_server_and_returns_its_answer(self):
        provider_response = {
            "choices": [{"message": {"content": "La RAM es memoria temporal."}}]
        }
        with patch.dict(
            "os.environ",
            {"OPENAI_API_KEY": "test-api-key", "OPENAI_MODEL": "test-model"},
        ), patch("app.urlopen") as provider:
            provider.return_value.__enter__.return_value.read.return_value = json.dumps(
                provider_response
            ).encode("utf-8")
            response = self.client.post(
                "/api/chat", json={"message": "¿Qué es la RAM?"}
            )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json, {"reply": "La RAM es memoria temporal."})
        outgoing = provider.call_args.args[0]
        self.assertEqual(outgoing.full_url, "https://api.openai.com/v1/chat/completions")
        self.assertEqual(outgoing.get_header("Authorization"), "Bearer test-api-key")
        self.assertEqual(
            json.loads(outgoing.data)["messages"][-1]["content"],
            "¿Qué es la RAM?",
        )
        self.assertEqual(json.loads(outgoing.data)["model"], "test-model")
        self.assertNotIn("test-api-key", response.get_data(as_text=True))

    def test_chat_api_does_not_disclose_provider_errors(self):
        with patch.dict("os.environ", {"OPENAI_API_KEY": "test-api-key"}), patch(
            "app.urlopen", side_effect=URLError("test-api-key was rejected")
        ):
            response = self.client.post(
                "/api/chat", json={"message": "¿Qué es la RAM?"}
            )

        self.assertEqual(response.status_code, 502)
        self.assertEqual(response.json["code"], "openai_connection_error")
        self.assertNotIn("test-api-key", response.get_data(as_text=True))

    def test_chat_api_reports_safe_http_status_and_guidance_without_provider_body(self):
        cases = (
            (401, "openai_http_401", "clave API"),
            (403, "openai_http_403", "permiso"),
            (429, "openai_http_429", "facturación de la API"),
            (404, "openai_http_404", "OPENAI_MODEL"),
        )
        for status, code, guidance in cases:
            with self.subTest(status=status):
                provider_error = HTTPError(
                    "https://api.openai.com/v1/chat/completions",
                    status,
                    "provider error",
                    {},
                    BytesIO(b'{"error":{"message":"private provider detail"}}'),
                )
                with patch.dict(
                    "os.environ", {"OPENAI_API_KEY": "test-api-key"}
                ), patch("app.urlopen", side_effect=provider_error):
                    with self.assertLogs("app", level="WARNING") as logs:
                        response = self.client.post(
                            "/api/chat", json={"message": "¿Qué es la RAM?"}
                        )

                self.assertEqual(response.status_code, 502)
                self.assertEqual(response.json["code"], code)
                self.assertIn(guidance, response.json["error"])
                response_text = response.get_data(as_text=True)
                log_text = "\n".join(logs.output)
                self.assertNotIn("test-api-key", response_text)
                self.assertNotIn("private provider detail", response_text)
                self.assertNotIn("test-api-key", log_text)
                self.assertNotIn("private provider detail", log_text)

    def test_chat_api_rejects_oversized_requests(self):
        response = self.client.post(
            "/api/chat",
            data='{"message":"' + ("x" * 17000) + '"}',
            content_type="application/json",
        )
        self.assertEqual(response.status_code, 413)
        self.assertIn("tamaño permitido", response.json["error"])

    def test_robots_and_sitemap_include_the_store_and_separate_pages(self):
        robots = self.client.get("/robots.txt")
        self.assertEqual(robots.status_code, 200)
        self.assertIn(b"Sitemap: http://localhost:5000/sitemap.xml", robots.data)

        sitemap = self.client.get("/sitemap.xml")
        self.assertEqual(sitemap.status_code, 200)
        root = ET.fromstring(sitemap.data)
        locations = {entry[0].text for entry in root}
        self.assertEqual(
            locations,
            {
                "http://localhost:5000/",
                "http://localhost:5000/temario",
                "http://localhost:5000/contacto",
                "http://localhost:5000/temario/hardware",
                "http://localhost:5000/temario/redes",
                "http://localhost:5000/temario/sistemas-operativos",
                "http://localhost:5000/temario/seguridad",
                "http://localhost:5000/producto/curso-premium-smr",
                "http://localhost:5000/producto/consultoria-tecnica-personalizada",
            },
        )

    def test_html_has_cache_and_security_headers(self):
        response = self.client.get("/")
        self.assertTrue(response.headers["Cache-Control"].startswith("public, max-age=300"))
        self.assertEqual(response.headers["X-Content-Type-Options"], "nosniff")
        self.assertEqual(response.headers["Referrer-Policy"], "strict-origin-when-cross-origin")
        self.assertIn("Content-Security-Policy", response.headers)

    def test_frontend_assets_are_served(self):
        for path in (
            "/static/css/site.css",
            "/static/js/site.js",
            "/static/img/favicon.svg",
            "/static/img/learning-laptop.svg",
            "/static/img/product-course.webp",
            "/static/img/product-consulting.webp",
            "/static/img/topic-networks.webp",
            "/static/img/topic-systems.webp",
            "/static/img/topic-security.webp",
        ):
            with self.subTest(path=path):
                response = self.client.get(path)
                try:
                    self.assertEqual(response.status_code, 200)
                finally:
                    response.close()

    def test_only_site_assets_are_publicly_served(self):
        response = self.client.get(
            "/static/wp-content/plugins/chatway-live-chat/assets/js/frontend.js"
        )
        self.assertEqual(response.status_code, 404)


if __name__ == "__main__":
    unittest.main()
