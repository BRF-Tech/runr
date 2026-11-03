/* ============================================================
   RunR Landing — main.js
   Language link, nav, modals, forms, FAQ, magnetic buttons, 3D tilt.
   ============================================================ */

(function () {
  'use strict';

  // ── CONFIG ──
  const API_BASE = ''; // same-origin (runr.sh/api/*)

  // ── LANGUAGE ──
  // Each language is its own static page: / is English, /tr/ Turkish (ci/runr-sh/build.py writes
  // it from the data-tr texts of index.html); the page's language is its <html lang>. The EN/TR
  // link opens the other page and stores the choice; the script in the <head> of / sends a stored
  // Turkish choice, or a Turkish browser without one, to /tr/. /tr/ never switches to English.
  const currentLocale = document.documentElement.lang === 'tr' ? 'tr' : 'en';

  document.addEventListener('click', (e) => {
    const link = e.target.closest('.lang-btn');
    if (!link) return;
    const to = link.getAttribute('hreflang');
    try { if (to === 'tr' || to === 'en') localStorage.setItem('runr_locale', to); } catch (_) {}
  });

  // ── INSTALL SNIPPETS ──
  // A copy from an install snippet (<pre data-snippet>) is counted in Umami, whose script the
  // web server adds to every page.
  document.addEventListener('copy', () => {
    const sel = document.getSelection();
    const node = sel && sel.anchorNode;
    const el = node && (node.nodeType === 1 ? node : node.parentElement);
    const pre = el && el.closest('pre[data-snippet]');
    if (pre) window.umami?.track('install-snippet-copy', { snippet: pre.dataset.snippet });
  });

  // ── SCREENSHOTS ──
  // A console scene that is not published yet (shots/<name>-dark.png missing) takes its
  // frame with it, so the section reads as text only instead of showing a broken image.
  document.querySelectorAll('[data-shot] img').forEach(img => {
    const hide = () => { const w = img.closest('[data-shot]'); if (w) w.classList.add('is-missing'); };
    if (img.complete && img.naturalWidth === 0 && img.currentSrc) hide();
    img.addEventListener('error', hide);
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

  // runr.sh/#enterprise opens the contact form (a link that can be shared or used in the docs).
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
      tr: { invalid_email: 'Geçerli bir e-posta girin.', disposable: 'Geçici e-posta servisleri kabul edilmiyor.', no_mx: 'Bu domain\'in mail sunucusu bulunamadı.', rate_limited: 'Çok hızlı denediniz, biraz bekleyin.', validation_failed: 'Lütfen formu kontrol edin.', bot_check_failed: 'Bot kontrolünü tamamlayın.', bot_check_required: 'Lütfen "Ben robot değilim" kutucuğunu işaretleyin.', bot_check_unavailable: 'Bot kontrolü yüklenemedi. Bağlantınızı ya da reklam engelleyicinizi kontrol edip tekrar deneyin.', network: 'Bağlantı hatası, tekrar deneyin.' },
      en: { invalid_email: 'Please enter a valid email.', disposable: 'Disposable email services are not allowed.', no_mx: 'This domain has no mail server.', rate_limited: 'You\'re going too fast — please wait.', validation_failed: 'Please check the form.', bot_check_failed: 'Bot check failed, try again.', bot_check_required: 'Please complete the bot check.', bot_check_unavailable: 'The bot check could not load. Check your connection or ad blocker and try again.', network: 'Network error, try again.' }
    };
    return (map[currentLocale][code]) || map[currentLocale].network;
  }

  function tsToken(formName) {
    return (window.RunRTurnstile && window.RunRTurnstile.getToken)
      ? window.RunRTurnstile.getToken(formName) : '';
  }
  // Waits for the form's token: Turnstile loads on demand (js/turnstile.js).
  function tsWait(formName) {
    return (window.RunRTurnstile && window.RunRTurnstile.waitToken)
      ? window.RunRTurnstile.waitToken(formName) : Promise.reject(new Error('bot_check_unavailable'));
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
    errMessage, emailLooksValid, tsToken, tsWait, tsReset,
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
