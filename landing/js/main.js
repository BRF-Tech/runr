/* ============================================================
   RunR Landing — main.js
   i18n, nav, modals, forms, FAQ, magnetic buttons, 3D tilt.
   ============================================================ */

(function () {
  'use strict';

  // ── CONFIG ──
  const API_BASE = ''; // same-origin (runr.sh/api/*)

  const META = {
    tr: {
      title: 'RunR — Açık Kaynak Serverless Mimari · Kubernetes Üzerinde',
      desc:  'Fonksiyonlar, çoklu dosya projeleri, container\'lar ve WebAssembly kendi Kubernetes cluster\'larınızda, Knative ile scale-to-zero. Sandbox API, durable workflow, GPU ve MCP dahil. MIT lisanslı açık kaynak.'
    },
    en: {
      title: 'RunR — Open Source Serverless Architecture on Kubernetes',
      desc:  'Functions, multi-file projects, containers and WebAssembly on your own Kubernetes clusters, with Knative scale-to-zero. Sandbox API, durable workflows, GPUs and MCP built in. Open source under the MIT license.'
    }
  };

  // ── I18N ──
  function getLocale() {
    const stored = localStorage.getItem('runr_locale');
    if (stored === 'tr' || stored === 'en') return stored;
    const param = new URLSearchParams(location.search).get('lang');
    if (param === 'tr' || param === 'en') return param;
    return (navigator.language || 'tr').slice(0, 2).toLowerCase() === 'tr' ? 'tr' : 'en';
  }

  function applyLocale(loc) {
    document.documentElement.lang = loc;
    document.title = META[loc].title;
    const md = document.querySelector('meta[name="description"]');
    if (md) md.setAttribute('content', META[loc].desc);

    // Swap all data-tr / data-en attributes
    document.querySelectorAll('[data-tr]').forEach(el => {
      const txt = el.getAttribute(loc === 'tr' ? 'data-tr' : 'data-en');
      if (txt == null) return;
      // For placeholder/value/aria-label
      if (el.dataset.target === 'placeholder') el.placeholder = txt;
      else if (el.dataset.target === 'aria-label') el.setAttribute('aria-label', txt);
      else el.innerHTML = txt;
    });

    // Update lang button label
    const langBtn = document.querySelector('.lang-btn');
    if (langBtn) langBtn.textContent = loc === 'tr' ? 'EN' : 'TR';

    localStorage.setItem('runr_locale', loc);
  }

  let currentLocale = getLocale();
  applyLocale(currentLocale);

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.lang-btn');
    if (!btn) return;
    currentLocale = currentLocale === 'tr' ? 'en' : 'tr';
    applyLocale(currentLocale);
  });

  // ── NAV ──
  const nav = document.querySelector('.nav');
  if (nav) {
    let lastY = 0;
    function onScroll() {
      const y = window.scrollY;
      nav.classList.toggle('is-scrolled', y > 40);
      lastY = y;
    }
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  const mobileToggle = document.querySelector('.mobile-toggle');
  const navLinks = document.querySelector('.nav-links');
  if (mobileToggle && navLinks) {
    mobileToggle.addEventListener('click', () => navLinks.classList.toggle('is-open'));
    navLinks.addEventListener('click', (e) => {
      if (e.target.tagName === 'A') navLinks.classList.remove('is-open');
    });
  }

  // ── MODAL ──
  const modals = {
    enterprise: document.getElementById('modal-enterprise'),
  };

  function openModal(name, source = '') {
    const m = modals[name];
    if (!m) return;
    m.classList.add('is-open');
    if (source) m.dataset.source = source;
    document.body.style.overflow = 'hidden';
    setTimeout(() => {
      const first = m.querySelector('input, textarea, select');
      if (first) first.focus();
    }, 50);
  }

  function closeModal(m) {
    (m ? [m] : Object.values(modals)).forEach(x => x && x.classList.remove('is-open'));
    document.body.style.overflow = '';
  }

  document.addEventListener('click', (e) => {
    // Open via [data-open-modal]
    const opener = e.target.closest('[data-open-modal]');
    if (opener) {
      e.preventDefault();
      const name = opener.dataset.openModal;
      const src = opener.dataset.source || '';
      openModal(name, src);
      return;
    }
    // Close via [data-close-modal] or backdrop click
    if (e.target.matches('[data-close-modal]') || e.target.matches('.modal-backdrop')) {
      closeModal(e.target.closest('.modal-backdrop'));
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal();
  });

  // runr.sh/#enterprise (linked from the docs and the downloads page) opens the contact form.
  function openFromHash() {
    if (location.hash === '#enterprise') openModal('enterprise', 'link');
  }
  openFromHash();
  window.addEventListener('hashchange', openFromHash);

  // ── TOAST ──
  function toast(message, type = 'success', duration = 3500) {
    let t = document.getElementById('toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'toast';
      t.className = 'toast';
      document.body.appendChild(t);
    }
    t.className = 'toast toast-' + type + ' is-show';
    t.textContent = message;
    clearTimeout(toast._t);
    toast._t = setTimeout(() => { t.classList.remove('is-show'); }, duration);
  }

  // ── FORM ──
  function emailLooksValid(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v);
  }

  function errMessage(code) {
    const map = {
      tr: { invalid_email: 'Geçerli bir e-posta girin.', disposable: 'Geçici e-posta servisleri kabul edilmiyor.', no_mx: 'Bu domain\'in mail sunucusu bulunamadı.', rate_limited: 'Çok hızlı denediniz, biraz bekleyin.', validation_failed: 'Lütfen formu kontrol edin.', bot_check_failed: 'Bot kontrolünü tamamlayın.', bot_check_required: 'Lütfen "Ben robot değilim" kutucuğunu işaretleyin.', network: 'Bağlantı hatası, tekrar deneyin.' },
      en: { invalid_email: 'Please enter a valid email.', disposable: 'Disposable email services are not allowed.', no_mx: 'This domain has no mail server.', rate_limited: 'You\'re going too fast — please wait.', validation_failed: 'Please check the form.', bot_check_failed: 'Bot check failed, try again.', bot_check_required: 'Please complete the bot check.', network: 'Network error, try again.' }
    };
    return (map[currentLocale][code]) || map[currentLocale].network;
  }

  function tsToken(formName) {
    return (window.RunRTurnstile && window.RunRTurnstile.getToken)
      ? window.RunRTurnstile.getToken(formName) : '';
  }
  function tsReset(formName) {
    if (window.RunRTurnstile && window.RunRTurnstile.reset) window.RunRTurnstile.reset(formName);
  }

  async function postJson(url, body) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    let data = null;
    try { data = await res.json(); } catch (_) {}
    return { ok: res.ok && data && data.ok, data, status: res.status };
  }

  // ── EXPOSE for forms.js ──
  window.RunR = {
    API_BASE,
    locale: () => currentLocale,
    toast, closeModal, postJson,
    errMessage, emailLooksValid, tsToken, tsReset,
    modals
  };

  // ── 3D TILT ──
  const tiltCards = document.querySelectorAll('.tilt-card');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reducedMotion && window.matchMedia('(hover: hover)').matches) {
    tiltCards.forEach(card => {
      card.addEventListener('pointermove', (e) => {
        const r = card.getBoundingClientRect();
        const mx = ((e.clientX - r.left) / r.width) * 100;
        const my = ((e.clientY - r.top) / r.height) * 100;
        const rx = ((50 - my) / 50) * 8;
        const ry = ((mx - 50) / 50) * 8;
        card.style.setProperty('--rx', rx + 'deg');
        card.style.setProperty('--ry', ry + 'deg');
        card.style.setProperty('--mx', mx + '%');
        card.style.setProperty('--my', my + '%');
      });
      card.addEventListener('pointerleave', () => {
        card.style.setProperty('--rx', '0deg');
        card.style.setProperty('--ry', '0deg');
      });
    });
  }

  // ── MAGNETIC ──
  if (!reducedMotion && window.matchMedia('(hover: hover)').matches) {
    document.querySelectorAll('.btn-magnetic').forEach(btn => {
      btn.addEventListener('pointermove', (e) => {
        const r = btn.getBoundingClientRect();
        const x = (e.clientX - r.left - r.width / 2) * 0.18;
        const y = (e.clientY - r.top - r.height / 2) * 0.25;
        btn.style.transform = `translate(${x}px, ${y}px)`;
      });
      btn.addEventListener('pointerleave', () => { btn.style.transform = ''; });
    });
  }

  // ── ANCHOR SCROLL ──
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href^="#"]');
    if (!a) return;
    const id = a.getAttribute('href');
    if (id.length < 2) return;
    const target = document.querySelector(id);
    if (!target) return;
    e.preventDefault();
    const top = target.getBoundingClientRect().top + window.scrollY - 70;
    window.scrollTo({ top, behavior: 'smooth' });
  });
})();
