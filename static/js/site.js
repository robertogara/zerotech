document.documentElement.classList.add("js");

const menuButton = document.querySelector(".menu-toggle");
const navigation = document.querySelector("#primary-links");

if (menuButton && navigation) {
  const setMenuOpen = (isOpen) => {
    menuButton.setAttribute("aria-expanded", String(isOpen));
    menuButton.querySelector(".sr-only").textContent = isOpen
      ? "Cerrar menú"
      : "Abrir menú";
    navigation.classList.toggle("is-open", isOpen);
  };

  menuButton.addEventListener("click", () => {
    setMenuOpen(menuButton.getAttribute("aria-expanded") !== "true");
  });

  navigation.addEventListener("click", (event) => {
    const link = event.target.closest("a");
    if (link) {
      const targetUrl = new URL(link.href, window.location.href);
      if (targetUrl.origin === window.location.origin && targetUrl.hash) {
        const section = document.getElementById(decodeURIComponent(targetUrl.hash.slice(1)));
        const disclosure = section && section.querySelector(".topic-disclosure");
        if (disclosure) {
          setTopicExpanded(disclosure, true);
        }
      }
      setMenuOpen(false);
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && menuButton.getAttribute("aria-expanded") === "true") {
      setMenuOpen(false);
      menuButton.focus();
    }
  });

  window.addEventListener("resize", () => {
    if (window.matchMedia("(min-width: 881px)").matches) {
      setMenuOpen(false);
    }
  });
}

function setTopicExpanded(disclosure, isExpanded) {
  const summary = disclosure.querySelector(".topic-toggle");
  if (!summary) {
    return;
  }

  disclosure.open = isExpanded;
  summary.setAttribute("aria-expanded", String(isExpanded));
  summary.querySelector(".topic-toggle-action").textContent = isExpanded
    ? "Ocultar contenido"
    : "Ver contenido";
  summary.querySelector(".topic-toggle-icon").textContent = isExpanded ? "−" : "+";
}

document.querySelectorAll(".topic-disclosure").forEach((disclosure) => {
  const summary = disclosure.querySelector(".topic-toggle");
  const syncTopicState = () => setTopicExpanded(disclosure, disclosure.open);
  summary.setAttribute("aria-expanded", String(disclosure.open));
  summary.addEventListener("click", () => {
    window.requestAnimationFrame(syncTopicState);
  });
  disclosure.addEventListener("toggle", syncTopicState);
});

const topicNavigation = document.querySelector(".topic-nav");
if (topicNavigation) {
  topicNavigation.addEventListener("click", (event) => {
    const link = event.target.closest("a[href^='#']");
    if (!link) {
      return;
    }

    const section = document.getElementById(link.getAttribute("href").slice(1));
    const disclosure = section && section.querySelector(".topic-disclosure");
    if (disclosure) {
      setTopicExpanded(disclosure, true);
    }
  });
}

const productButtons = Array.from(document.querySelectorAll(".add-to-cart"));
const catalogElement = document.querySelector("#product-catalog");
const catalogProducts = JSON.parse(catalogElement.textContent);
const productCatalog = new Map(
  catalogProducts.map((product) => [
    product.id,
    { name: product.name, priceCents: product.price_cents },
  ]),
);
const cartKey = "zerotech.cart.v1";
const cart = new Map();
const moneyFormat = new Intl.NumberFormat("es-ES", {
  style: "currency",
  currency: "EUR",
});
const siteStatus = document.querySelector("#site-status");
const cartDialog = document.querySelector("#cart-dialog");
const cartTrigger = document.querySelector("#cart-trigger");
const cartCount = document.querySelector("#cart-count");
const cartItems = document.querySelector("#cart-items");
const cartEmpty = document.querySelector("#cart-empty");
const cartTotals = document.querySelector("#cart-totals");
const cartStatus = document.querySelector("#cart-status");
const cartTitle = document.querySelector("#cart-dialog-title");
const cartView = document.querySelector("#cart-view");
const checkoutView = document.querySelector("#checkout-view");
const checkoutItems = document.querySelector("#checkout-items");

function announce(message, target = siteStatus) {
  if (target) {
    target.textContent = "";
    window.requestAnimationFrame(() => {
      target.textContent = message;
    });
  }
}

function formatPrice(priceCents) {
  return moneyFormat.format(priceCents / 100);
}

function normalizeCart(value) {
  const normalized = new Map();
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    return normalized;
  }

  for (const [productId, quantity] of Object.entries(value)) {
    if (
      productCatalog.has(productId)
      && Number.isSafeInteger(quantity)
      && quantity > 0
      && quantity <= 99
    ) {
      normalized.set(productId, quantity);
    }
  }
  return normalized;
}

function restoreCart() {
  try {
    const saved = window.localStorage.getItem(cartKey);
    if (saved) {
      const restored = normalizeCart(JSON.parse(saved));
      for (const [productId, quantity] of restored) {
        cart.set(productId, quantity);
      }
    }
  } catch (_error) {
    announce("El almacenamiento local no está disponible. El carrito solo durará mientras esta pestaña permanezca abierta.");
  }
}

function saveCart() {
  try {
    window.localStorage.setItem(
      cartKey,
      JSON.stringify(Object.fromEntries(cart)),
    );
    return true;
  } catch (_error) {
    announce("No se pudo guardar el carrito en este dispositivo; seguirá disponible hasta cerrar esta pestaña.");
    return false;
  }
}

function createTextElement(tagName, className, text) {
  const element = document.createElement(tagName);
  if (className) {
    element.className = className;
  }
  element.textContent = text;
  return element;
}

function createCartAction(label, action, productId, text, disabled = false) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = "quantity-action";
  button.dataset.cartAction = action;
  button.dataset.productId = productId;
  button.setAttribute("aria-label", label);
  button.disabled = disabled;
  button.textContent = text;
  return button;
}

function renderCart() {
  const itemCount = Array.from(cart.values()).reduce((sum, quantity) => sum + quantity, 0);
  const subtotalCents = Array.from(cart.entries()).reduce((sum, [productId, quantity]) => {
    const product = productCatalog.get(productId);
    return product ? sum + product.priceCents * quantity : sum;
  }, 0);

  cartCount.textContent = String(itemCount);
  cartTrigger.setAttribute(
    "aria-label",
    `Abrir carrito, ${itemCount} ${itemCount === 1 ? "producto" : "productos"}`,
  );
  cartEmpty.hidden = itemCount !== 0;
  cartItems.hidden = itemCount === 0;
  cartTotals.hidden = itemCount === 0;
  cartItems.replaceChildren();

  for (const [productId, quantity] of cart) {
    const product = productCatalog.get(productId);
    if (!product) {
      continue;
    }

    const row = document.createElement("li");
    row.className = "cart-row";
    const main = document.createElement("div");
    main.className = "cart-row-main";
    main.append(
      createTextElement("strong", "", product.name),
      createTextElement("span", "", `${formatPrice(product.priceCents)} por unidad`),
    );

    const actions = document.createElement("div");
    actions.className = "cart-row-actions";
    const quantityControl = document.createElement("div");
    quantityControl.className = "quantity-control";
    quantityControl.setAttribute("role", "group");
    quantityControl.setAttribute("aria-label", `Cantidad de ${product.name}`);
    quantityControl.append(
      createCartAction(`Reducir cantidad de ${product.name}`, "decrease", productId, "−", quantity <= 1),
      createTextElement("span", "quantity-value", String(quantity)),
      createCartAction(`Aumentar cantidad de ${product.name}`, "increase", productId, "+", quantity >= 99),
    );
    const removeButton = createCartAction(`Quitar ${product.name} del carrito`, "remove", productId, "Quitar");
    removeButton.className = "remove-item";
    actions.append(quantityControl, removeButton);
    row.append(main, actions);
    cartItems.append(row);
  }

  document.querySelector("#cart-subtotal").textContent = formatPrice(subtotalCents);
  document.querySelector("#cart-total").textContent = formatPrice(subtotalCents);
  renderCheckout(subtotalCents);
}

function renderCheckout(subtotalCents = 0) {
  checkoutItems.replaceChildren();
  for (const [productId, quantity] of cart) {
    const product = productCatalog.get(productId);
    if (!product) {
      continue;
    }
    const row = document.createElement("li");
    row.className = "checkout-row";
    row.append(
      createTextElement("span", "", `${product.name} × ${quantity}`),
      createTextElement("strong", "", formatPrice(product.priceCents * quantity)),
    );
    checkoutItems.append(row);
  }
  document.querySelector("#checkout-total").textContent = formatPrice(subtotalCents);
}

function addProduct(productId) {
  const product = productCatalog.get(productId);
  if (!product) {
    announce("No se ha podido añadir ese producto.");
    return;
  }
  const currentQuantity = cart.get(productId) || 0;
  if (currentQuantity >= 99) {
    announce(`Has alcanzado el límite de unidades de ${product.name}.`);
    return;
  }
  cart.set(productId, currentQuantity + 1);
  const saved = saveCart();
  renderCart();
  announce(
    saved
      ? `${product.name} añadido al carrito.`
      : `${product.name} añadido, pero no se pudo guardar el carrito para la próxima visita.`,
  );
}

productButtons.forEach((button) => {
  button.addEventListener("click", () => addProduct(button.dataset.productId));
});

cartItems.addEventListener("click", (event) => {
  const actionButton = event.target.closest("[data-cart-action]");
  if (!actionButton || !cart.has(actionButton.dataset.productId)) {
    return;
  }
  const { cartAction, productId } = actionButton.dataset;
  const product = productCatalog.get(productId);
  const quantity = cart.get(productId);

  if (cartAction === "remove") {
    cart.delete(productId);
    announce(`${product.name} eliminado del carrito.`, cartStatus);
  } else if (cartAction === "decrease" && quantity > 1) {
    cart.set(productId, quantity - 1);
    announce(`Cantidad de ${product.name} actualizada a ${quantity - 1}.`, cartStatus);
  } else if (cartAction === "increase" && quantity < 99) {
    cart.set(productId, quantity + 1);
    announce(`Cantidad de ${product.name} actualizada a ${quantity + 1}.`, cartStatus);
  }

  saveCart();
  renderCart();
});

function showCartView() {
  cartView.hidden = false;
  checkoutView.hidden = true;
  cartTitle.textContent = "Tu carrito";
}

function openCart() {
  showCartView();
  cartDialog.showModal();
  document.querySelector("#cart-close").focus();
}

cartTrigger.addEventListener("click", openCart);
document.querySelector("#cart-close").addEventListener("click", () => cartDialog.close());
cartDialog.addEventListener("click", (event) => {
  if (event.target === cartDialog) {
    cartDialog.close();
  }
});
cartDialog.addEventListener("close", showCartView);

document.querySelector("#checkout-trigger").addEventListener("click", () => {
  if (cart.size === 0) {
    return;
  }
  const subtotalCents = Array.from(cart.entries()).reduce((sum, [productId, quantity]) => {
    return sum + productCatalog.get(productId).priceCents * quantity;
  }, 0);
  renderCheckout(subtotalCents);
  cartView.hidden = true;
  checkoutView.hidden = false;
  cartTitle.textContent = "Resumen de compra";
  checkoutView.querySelector("#back-to-cart").focus();
});
document.querySelector("#back-to-cart").addEventListener("click", () => {
  showCartView();
  document.querySelector("#checkout-trigger").focus();
});

const stripeCheckoutButton = document.querySelector("#stripe-checkout");
const checkoutFeedback = document.querySelector("#checkout-feedback");
stripeCheckoutButton.addEventListener("click", async () => {
  if (!cart.size || stripeCheckoutButton.disabled) {
    return;
  }

  const originalLabel = stripeCheckoutButton.textContent;
  stripeCheckoutButton.disabled = true;
  stripeCheckoutButton.textContent = "Conectando con Stripe…";
  checkoutFeedback.textContent = "";
  try {
    const response = await fetch("/api/checkout-session", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({
        items: Array.from(cart, ([id, quantity]) => ({ id, quantity })),
      }),
    });
    const contentType = response.headers.get("content-type") || "";
    if (!contentType.includes("application/json")) {
      throw new Error(
        `El servidor devolvió una respuesta inesperada (${response.status}). Revisa el terminal de Flask.`,
      );
    }
    const result = await response.json();
    if (!response.ok) {
      throw new Error(result.error || "No se pudo iniciar el pago.");
    }
    if (typeof result.url !== "string" || !result.url.startsWith("https://checkout.stripe.com/")) {
      throw new Error("No se recibió un enlace de pago seguro.");
    }
    window.location.assign(result.url);
  } catch (error) {
    checkoutFeedback.textContent = error instanceof Error
      ? error.message
      : "No se pudo iniciar el pago. No se ha confirmado ningún cobro.";
  } finally {
    stripeCheckoutButton.disabled = false;
    stripeCheckoutButton.textContent = originalLabel;
  }
});

window.addEventListener("storage", (event) => {
  if (event.key === cartKey || event.key === null) {
    cart.clear();
    try {
      if (event.newValue) {
        for (const [productId, quantity] of normalizeCart(JSON.parse(event.newValue))) {
          cart.set(productId, quantity);
        }
      }
    } catch (_error) {
      announce("No se pudo actualizar el carrito desde otra pestaña.");
    }
    renderCart();
  }
});

restoreCart();
renderCart();

const chatLauncher = document.querySelector("#chat-launcher");
const chatPanel = document.querySelector("#chat-panel");
const chatInput = document.querySelector("#chat-input");
const chatForm = document.querySelector("#chat-form");
const chatMessages = document.querySelector("#chat-messages");
const chatSend = document.querySelector(".chat-send");
const chatClose = document.querySelector("#chat-close");

function toggleChat(isOpen, returnFocus = false) {
  chatPanel.hidden = !isOpen;
  chatLauncher.setAttribute("aria-expanded", String(isOpen));
  chatLauncher.setAttribute(
    "aria-label",
    isOpen ? "Minimizar chat de ZeroTech" : "Abrir chat de ZeroTech",
  );
  if (isOpen) {
    chatInput.focus();
  } else if (returnFocus) {
    chatLauncher.focus();
  }
}

function addChatMessage(message, type) {
  const bubble = createTextElement("p", `chat-message ${type}-message`, message);
  chatMessages.append(bubble);
  chatMessages.scrollTop = chatMessages.scrollHeight;
  return bubble;
}

chatLauncher.addEventListener("click", () => {
  toggleChat(chatPanel.hidden);
});
chatClose.addEventListener("click", () => toggleChat(false, true));

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && !chatPanel.hidden) {
    toggleChat(false, true);
  }
});

chatForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const message = chatInput.value.trim();
  if (!message || chatSend.disabled) {
    return;
  }

  addChatMessage(message, "user");
  chatInput.value = "";
  chatInput.disabled = true;
  chatSend.disabled = true;
  const pending = addChatMessage("Comprobando disponibilidad del asistente…", "pending");

  try {
    const response = await fetch("/api/chat", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ message }),
    });
    const result = await response.json();
    pending.remove();
    if (!response.ok) {
      const errorCode = typeof result.code === "string"
        ? ` Código: ${result.code}.`
        : "";
      addChatMessage(
        `${result.error || "No se pudo procesar la pregunta."}${errorCode}`,
        "error",
      );
    } else {
      addChatMessage(result.reply, "assistant");
    }
  } catch (_error) {
    pending.remove();
    addChatMessage(
      "No se pudo contactar con ZeroTech. El asistente no está disponible en este momento.",
      "error",
    );
  } finally {
    chatInput.disabled = false;
    chatSend.disabled = false;
    chatInput.focus();
  }
});

const contactForm = document.querySelector("#contact-form");
if (contactForm) {
  const contactStatus = document.querySelector("#contact-status");
  const contactSubmit = contactForm.querySelector('button[type="submit"]');
  contactForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!contactForm.reportValidity() || contactSubmit.disabled) {
      return;
    }
    contactSubmit.disabled = true;
    contactStatus.className = "form-feedback";
    contactStatus.textContent = "Comprobando el servicio de envío…";
    try {
      const response = await fetch("/api/contact", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(Object.fromEntries(new FormData(contactForm).entries())),
      });
      const result = await response.json();
      if (!response.ok) {
        throw new Error(result.error || "El mensaje no se pudo enviar.");
      }
      contactStatus.classList.add("success");
      contactStatus.textContent = result.message;
      contactForm.reset();
    } catch (error) {
      contactStatus.classList.add("error");
      contactStatus.textContent = error instanceof Error
        ? error.message
        : "No se pudo confirmar el envío. Inténtalo más tarde.";
    } finally {
      contactSubmit.disabled = false;
    }
  });
}

const loginForm = document.querySelector("#login-form");
if (loginForm) {
  const loginPassword = document.querySelector("#login-password");
  const passwordToggle = document.querySelector("#password-toggle");
  const loginStatus = document.querySelector("#login-status");
  const loginSubmit = document.querySelector("#login-submit");

  passwordToggle.addEventListener("click", () => {
    const showPassword = loginPassword.type === "password";
    loginPassword.type = showPassword ? "text" : "password";
    passwordToggle.setAttribute("aria-pressed", String(showPassword));
    passwordToggle.textContent = showPassword ? "Ocultar" : "Mostrar";
    passwordToggle.setAttribute(
      "aria-label",
      showPassword ? "Ocultar contraseña" : "Mostrar contraseña",
    );
  });

  const attemptLogin = () => {
    if (!loginForm.reportValidity()) {
      return;
    }
    loginForm.reset();
    loginPassword.type = "password";
    passwordToggle.textContent = "Mostrar";
    passwordToggle.setAttribute("aria-pressed", "false");
    passwordToggle.setAttribute("aria-label", "Mostrar contraseña");
    loginStatus.textContent = "El servicio de autenticación aún no está configurado. No se han enviado ni guardado tus credenciales.";
  };

  loginSubmit.addEventListener("click", attemptLogin);
  loginForm.addEventListener("submit", (event) => {
    event.preventDefault();
    attemptLogin();
  });
  loginForm.addEventListener("keydown", (event) => {
    if (event.key === "Enter" && event.target instanceof HTMLInputElement) {
      event.preventDefault();
      attemptLogin();
    }
  });
}
