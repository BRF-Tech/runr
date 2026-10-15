/* ============================================================
   RunR Landing — turnstile.js
   Cloudflare Turnstile (bot blocker) frontend integration.

   The site key comes from index.html:
     window.RUNR_TURNSTILE_SITEKEY = '0x4AAAAA...';
   ============================================================ */

(function () {
  'use strict';

  // Used only when index.html sets no `window.RUNR_TURNSTILE_SITEKEY`.
  const FALLBACK_SITEKEY = '1x00000000000000000000AA'; // CF Turnstile "always passes" test key

  const widgets = new Map(); // formName → widgetId

  function sitekey() {
    return (typeof window.RUNR_TURNSTILE_SITEKEY === 'string' && window.RUNR_TURNSTILE_SITEKEY.length > 0)
      ? window.RUNR_TURNSTILE_SITEKEY
      : FALLBACK_SITEKEY;
  }

  function locale() {
    return (localStorage.getItem('runr_locale') === 'en') ? 'en' : 'tr';
  }

  function renderAll() {
    if (typeof window.turnstile === 'undefined') return;
    const slots = document.querySelectorAll('.turnstile-slot');
    slots.forEach(slot => {
      const formName = slot.dataset.form;
      if (!formName || widgets.has(formName)) return;
      try {
        const id = window.turnstile.render(slot, {
          sitekey: sitekey(),
          theme: 'dark',
          size: 'normal',
          language: locale(),
          appearance: 'always',
          callback: (token) => { slot.dataset.token = token; },
          'error-callback':   () => { delete slot.dataset.token; },
          'expired-callback': () => { delete slot.dataset.token; }
        });
        widgets.set(formName, { id, slot });
      } catch (e) {
        console.warn('Turnstile render failed:', e);
      }
    });
  }

  // Public API on window.RunRTurnstile
  window.RunRTurnstile = {
    /** Returns token for a given form, or '' if not solved yet. */
    getToken(formName) {
      const w = widgets.get(formName);
      if (!w) return '';
      try {
        return window.turnstile.getResponse(w.id) || w.slot.dataset.token || '';
      } catch (_) {
        return w.slot.dataset.token || '';
      }
    },
    /** Reset widget after a submit (so a new token must be solved). */
    reset(formName) {
      const w = widgets.get(formName);
      if (!w) return;
      try { window.turnstile.reset(w.id); } catch (_) {}
      delete w.slot.dataset.token;
    },
    /** Force re-render (e.g. after language change). */
    refresh() {
      widgets.forEach((w) => {
        try { window.turnstile.remove(w.id); } catch (_) {}
      });
      widgets.clear();
      renderAll();
    },
    /** Is the bot-blocker actually available right now? */
    ready() {
      return typeof window.turnstile !== 'undefined' && widgets.size > 0;
    }
  };

  // The Turnstile loader script uses ?onload=onTurnstileLoad.
  window.onTurnstileLoad = function () { renderAll(); };
  // The loader is async and may finish before this deferred script runs; render now in that case.
  if (typeof window.turnstile !== 'undefined') renderAll();

  // Re-render the widgets on locale change (to update their language)
  document.addEventListener('click', (e) => {
    if (e.target.closest('.lang-btn')) {
      setTimeout(() => window.RunRTurnstile.refresh(), 50);
    }
  });
})();
