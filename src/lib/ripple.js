// ─── Ripple effect ala Material Design untuk sentuhan khas Android ─────────
const RIPPLE_SELECTOR = 'button, a.btn-outline';

function createRipple(e) {
  const el = e.currentTarget;
  if (el.disabled) return;
  const rect = el.getBoundingClientRect();
  const d = Math.max(rect.width, rect.height) * 2.2;
  const ink = document.createElement('span');
  ink.className = 'ripple-ink';
  ink.style.width = `${d}px`;
  ink.style.height = `${d}px`;
  ink.style.left = `${e.clientX - rect.left - d / 2}px`;
  ink.style.top = `${e.clientY - rect.top - d / 2}px`;
  el.appendChild(ink);
  window.setTimeout(() => ink.remove(), 650);
}

export function initRipple(root = document) {
  const attach = (el) => {
    if (!el || el.dataset.ripple) return;
    el.dataset.ripple = '1';
    el.addEventListener('pointerdown', createRipple);
  };

  root.querySelectorAll(RIPPLE_SELECTOR).forEach(attach);

  // Amati elemen baru (tab, modal, dsb.)
  const mo = new MutationObserver((mutations) => {
    mutations.forEach((m) => {
      m.addedNodes.forEach((node) => {
        if (!(node instanceof Element)) return;
        if (node.matches(RIPPLE_SELECTOR)) attach(node);
        node.querySelectorAll?.(RIPPLE_SELECTOR).forEach(attach);
      });
    });
  });
  mo.observe(root, { childList: true, subtree: true });
}
