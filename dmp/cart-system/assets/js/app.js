/**
 * @file   assets/js/app.js
 * @brief  Global JavaScript utilities for the application
 */

/**
 * app.js — Global JS utilities
 * Replaces all native alert() / confirm() with Bootstrap 5 modals/toasts
 */

// ── Escaping ───────────────────────────────────────────────

/**
 * Escape text for interpolation into an HTML string that will be injected
 * with innerHTML / insertAdjacentHTML.
 *
 * USE THIS whenever a server value ends up inside a template literal that is
 * then written to innerHTML. Server-side encoding (e(), js(), jsstr())
 * protects the ATTRIBUTE; it does not protect the DOM sink the value reaches
 * after JavaScript has decoded it. A member-set field such as a GCash or USDT
 * address reached the admin's browser exactly this way (defect C5b,
 * tmp/shop/remedy.md).
 *
 *   modalDesc.innerHTML = `Send to <strong>${escHtml(account)}</strong>`;
 *
 * Static markup around the value stays markup; the value itself becomes text.
 * For a value that needs NO markup at all, prefer textContent outright.
 */
function escHtml(value) {
  return String(value == null ? "" : value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

// ── Toast ────────────────────────────────────────────────────
function showToast(message, type = "success") {
  // 'error' is the common typo callers make; map it to Bootstrap's real 'danger'
  // so burned-in text 'text-bg-error' (not a real class → no red background)
  // never renders. Same for other near-miss synonyms.
  const aliases = { error: "danger", fail: "danger", critical: "danger" };
  type = aliases[type] || type;
  // M13: `type` lands inside a class attribute. Escape it rather than trust the
  // caller's string, but the honest fix is to not accept arbitrary classes at
  // all — an allow-list cannot be broken out of, and this value only ever
  // selects a Bootstrap contextual colour.
  const allowedTypes = ["success", "danger", "warning", "info"];
  if (!allowedTypes.includes(type)) type = "info";
  const id = "toast_" + Date.now();
  // Errors carry no leading glyph - callers pass the full sentence (e.g.
  // "Insufficient stock. ."); the dismiss ? sits in the top-right corner
  // instead of being repeated in front of the text.
  const icons = { success: "✓", warning: "⚠", info: "ℹ" };
  const icon = icons[type] || "";
  // M13: the message text reached insertAdjacentHTML unescaped, so any server
  // text that reached a toast became markup. Callers already pass text carrying
  // database content — a product name out of Cart::validateStock(), for
  // instance — so this is reachable as soon as one of those paths reports an
  // error, not merely latent.
  //
  // Note the deliberate contrast with showConfirm() below: a toast has no
  // markup contract, so its message is escaped. showConfirm's message is
  // developer-authored HTML by design and is exempted explicitly in the linter.
  const html = `
    <div id="${escHtml(id)}" class="toast text-bg-${escHtml(type)} border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex align-items-start">
        <div class="toast-body fw-semibold" style="font-size:.875rem;">
          ${icon ? icon + " " : ""}${escHtml(String(message ?? ""))}
        </div>
        <!-- Pinned to the top-right by CSS in main.css, so the position does
             not depend on how the message wraps. The spacing utility that used
             to sit on this button applied equal margins, which pulled it into
             the vertical middle of a tall toast; the margin now lives in CSS. -->
        <button type="button" class="btn-close btn-close-white flex-shrink-0" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>`;
  let container = document.getElementById("toastContainer");
  if (!container) {
    container = document.createElement("div");
    container.id = "toastContainer";
    document.body.appendChild(container);
  }
  container.insertAdjacentHTML("beforeend", html);
  const el = document.getElementById(id);
  const toast = new bootstrap.Toast(el, { delay: 3500 });
  toast.show();
  // Slow, silent exit: Bootstrap's default toast fade is 150ms. Swapping the
  // transition only once hiding starts keeps the entrance quick while the
  // fade-out glides away (CSS: #toastContainer .toast.slow-hide).
  el.addEventListener("hide.bs.toast", () => el.classList.add("slow-hide"));
  el.addEventListener("hidden.bs.toast", () => el.remove());
}

// ── Confirm Modal ────────────────────────────────────────────
// Usage: showConfirm({ title, message, confirmText, confirmClass, onConfirm })
//
// CONTRACT: `message` is trusted HTML, unlike showToast's message.
//
// The modal is written with insertAdjacentHTML because callers embed developer
// markup in it — `suspend <strong>@alice</strong>` — and escaping it wholesale
// would print those tags as literal text. Every untrusted fragment inside a
// message must therefore be escaped BY THE CALLER before it gets here. The
// inline onclick= callers use jsstr(), which escapes for the JS literal and
// then htmlspecialchars(), so they satisfy the contract; user_view.php:805 does
// it explicitly with escHtml().
//
// This is a deliberate exemption and is listed as such in tests/TemplateLintTest
// rather than being waved through by a distance heuristic. Any new caller that
// passes raw database text into `message` is a stored-XSS bug.
function showConfirm(opts) {
  const id = "confirmModal_" + Date.now();
  const {
    title = "Confirm",
    message = "Are you sure?",
    confirmText = "Confirm",
    confirmClass = "btn-primary",
    onConfirm = null,
    formId = null,
    // Dialog width. `null` keeps Bootstrap's default, which is what the ~20
    // existing callers expect. Pass "sm" for a short confirmation that only
    // needs to carry a title and one line — a default-width modal around
    // "Are you sure?" is mostly empty space.
    size = null,
  } = opts;

  // Allow-listed, then appended as a raw class. Same rule as the toast type:
  // an arbitrary string reaching a class attribute is an injection sink, and
  // there are only three widths worth having.
  const sizeClass = { sm: "modal-sm", lg: "modal-lg", xl: "modal-xl" }[size] || "";

  const html = `
    <div class="modal fade" id="${escHtml(id)}" tabindex="-1" aria-modal="true" role="dialog"
         aria-labelledby="${escHtml(id)}_title" aria-describedby="${escHtml(id)}_body">
      <div class="modal-dialog modal-dialog-centered ${escHtml(sizeClass)}">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="${escHtml(id)}_title">${title}</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="${escHtml(id)}_body" style="font-size:.9rem;line-height:1.65;">${message}</div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn ${escHtml(confirmClass)}" id="${escHtml(id)}_confirm">${escHtml(confirmText)}</button>
          </div>
        </div>
      </div>
    </div>`;

  document.body.insertAdjacentHTML("beforeend", html);
  const el = document.getElementById(id);
  const modal = new bootstrap.Modal(el);
  const btnConf = document.getElementById(id + "_confirm");

  btnConf.addEventListener("click", () => {
    modal.hide();
    if (onConfirm) onConfirm();
    if (formId) {
      const f = document.getElementById(formId);
      if (f) f.submit();
    }
  });

  el.addEventListener("hidden.bs.modal", () => el.remove());
  modal.show();
}

// ── Inline form submit with confirm ──────────────────────────
// Usage: <button onclick="confirmSubmit(this, {title,message,...})">
function confirmSubmit(btn, opts) {
  const form = btn.closest("form");
  showConfirm({ ...opts, onConfirm: () => form.submit() });
}

// ── Sidebar swipe (mobile) ───────────────────────────────────
(function () {
  let startX = 0;
  document.addEventListener(
    "touchstart",
    (e) => {
      startX = e.touches[0].clientX;
    },
    { passive: true },
  );
  document.addEventListener(
    "touchend",
    (e) => {
      const dx = e.changedTouches[0].clientX - startX;
      const offcanvasEl = document.getElementById("mobileSidebar");
      if (!offcanvasEl) return;
      const instance = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
      if (dx > 60 && startX < 50) instance.show();
      if (dx < -60) instance.hide();
    },
    { passive: true },
  );
})();

// ── Copy to clipboard with toast ─────────────────────────────
function copyText(text) {
  navigator.clipboard.writeText(text).then(() => {
    showToast("Copied: " + text, "info");
  });
}

// ── Keep the active sidebar item centered in view ────────────
// The sidebar scrolls on its own, so every fresh page load leaves
// it at scrollTop 0 — the active link can sit below the fold and
// the user has to hunt for it. Re-center it inside whichever
// element actually scrolls the nav (desktop .sidebar or the
// mobile offcanvas body).
function centerActiveNavItem(box) {
  const active = box.querySelector(".nav-item-link.active");
  if (!active || !box.clientHeight) return; // no active item / hidden box

  const boxTop = box.getBoundingClientRect().top;
  const itemTop = active.getBoundingClientRect().top - boxTop;
  const wanted =
    box.scrollTop + itemTop - (box.clientHeight - active.offsetHeight) / 2;
  const max = Math.max(0, box.scrollHeight - box.clientHeight);
  // Clamp: items near the top/bottom can't reach the center, but
  // they stay visible either way.
  box.scrollTop = Math.min(Math.max(wanted, 0), max);
}

function centerActiveSidebar() {
  document
    .querySelectorAll(".sidebar, #mobileSidebar .offcanvas-body")
    .forEach(centerActiveNavItem);
}

centerActiveSidebar();
// Fonts/images settling in late change the item heights — re-center.
window.addEventListener("load", centerActiveSidebar);
if (document.fonts && document.fonts.ready) {
  document.fonts.ready.then(centerActiveSidebar);
}

// ── Silent slow fade-out for inline flash alerts ──────────────
// render_flash() (core/helpers.php) renders "Added to cart.", validation
// warnings, etc. as static Bootstrap alerts that linger until the next page
// load. Fade them out gently after a readable delay, like the toasts above.
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".flash-alert").forEach((alert) => {
    window.setTimeout(() => {
      alert.classList.add("flash-fading");
      window.setTimeout(() => {
        if (alert.parentNode) alert.parentNode.removeChild(alert);
      }, 1700);
    }, 3200);
  });
});
