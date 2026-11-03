/* ============================================================
   RunR Landing — turnstile.js
   Cloudflare Turnstile (bot blocker) frontend integration.

   The site key comes from index.html:
     window.RUNR_TURNSTILE_SITEKEY = '0x4AAAAA...';

   Turnstile loads on demand, not with the page: its script and the widget of a form are fetched
   when that form gets focus or input, or when its widget slot comes near the viewport. A submit
   waits for the form's token (waitToken), so it also works when the widget was not loaded yet;
   it fails with bot_check_unavailable when Turnstile cannot load.
   ============================================================ */

(function () {
  'use strict';

  // Used only when index.html sets no `window.RUNR_TURNSTILE_SITEKEY`.
  const FALLBACK_SITEKEY = '1x00000000000000000000AA'; // CF Turnstile "always passes" test key
  const API = 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=onTurnstileLoad&render=explicit';
  const LOAD_TIMEOUT = 15000;   // ms for the Turnstile script to arrive
  const TOKEN_TIMEOUT = 30000;  // ms a submit waits for the widget's token

  const widgets = new Map(); // formName → { id, slot }
  const waiting = new Map(); // formName → [{ resolve, reject }]
  let loading = null;        // Promise of the Turnstile script, null before the first use or after a failure

  function sitekey() {
    return (typeof window.RUNR_TURNSTILE_SITEKEY === 'string' && window.RUNR_TURNSTILE_SITEKEY.length > 0)
      ? window.RUNR_TURNSTILE_SITEKEY
      : FALLBACK_SITEKEY;
  }

  // The widget speaks the page's language: / is English, /tr/ Turkish.
  function locale() {
    return document.documentElement.lang === 'tr' ? 'tr' : 'en';
  }

  function slotOf(formName) {
    return document.querySelector('.turnstile-slot[data-form="' + formName + '"]');
  }

  function settle(formName, token, error) {
    const list = waiting.get(formName) || [];
    waiting.delete(formName);
    list.forEach((w) => (error ? w.reject(new Error(error)) : w.resolve(token)));
  }

  // The Turnstile script, once; a failed load can be retried by the next use.
  function load() {
    if (typeof window.turnstile !== 'undefined') return Promise.resolve();
    if (loading) return loading;
    loading = new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('bot_check_unavailable')), LOAD_TIMEOUT);
      window.onTurnstileLoad = () => { clearTimeout(timer); resolve(); };
      const s = document.createElement('script');
      s.src = API;
      s.async = true;
      s.onerror = () => { clearTimeout(timer); reject(new Error('bot_check_unavailable')); };
      document.head.appendChild(s);
    });
    loading.catch(() => { loading = null; });
    return loading;
  }

  function render(formName) {
    if (widgets.has(formName)) return;
    const slot = slotOf(formName);
    if (!slot) throw new Error('bot_check_unavailable');
    const id = window.turnstile.render(slot, {
      sitekey: sitekey(),
      theme: 'dark',
      size: 'normal',
      language: locale(),
      appearance: 'always',
      callback: (token) => { slot.dataset.token = token; settle(formName, token); },
      'error-callback':   () => { delete slot.dataset.token; settle(formName, '', 'bot_check_failed'); },
      'expired-callback': () => { delete slot.dataset.token; }
    });
    widgets.set(formName, { id, slot });
  }

  // Loads Turnstile and renders the form's widget; resolves when the widget is there.
  function ensure(formName) {
    return load().then(() => render(formName));
  }

  function getToken(formName) {
    const w = widgets.get(formName);
    if (!w) return '';
    try {
      return window.turnstile.getResponse(w.id) || w.slot.dataset.token || '';
    } catch (_) {
      return w.slot.dataset.token || '';
    }
  }

  // Public API on window.RunRTurnstile
  window.RunRTurnstile = {
    /** Returns token for a given form, or '' if not solved yet. */
    getToken,
    /** The form's token: at once when it is there, else after Turnstile loads and the widget
     *  passes. Rejects with bot_check_unavailable (no Turnstile), bot_check_failed (the widget
     *  failed) or bot_check_required (no token in time, e.g. an unsolved challenge). */
    waitToken(formName) {
      const now = getToken(formName);
      if (now) return Promise.resolve(now);
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => settle(formName, '', 'bot_check_required'), TOKEN_TIMEOUT);
        const done = (fn) => (v) => { clearTimeout(timer); fn(v); };
        const list = waiting.get(formName) || [];
        list.push({ resolve: done(resolve), reject: done(reject) });
        waiting.set(formName, list);
        ensure(formName).then(() => {
          const t = getToken(formName);
          if (t) settle(formName, t);
        }, (e) => settle(formName, '', e.message || 'bot_check_unavailable'));
      });
    },
    /** Reset widget after a submit (so a new token must be solved). */
    reset(formName) {
      const w = widgets.get(formName);
      if (!w) return;
      try { window.turnstile.reset(w.id); } catch (_) {}
      delete w.slot.dataset.token;
    },
    /** Is the bot-blocker actually available right now? */
    ready() {
      return typeof window.turnstile !== 'undefined' && widgets.size > 0;
    }
  };

  // On demand: a form's widget loads when the form gets focus or input, or when its slot comes
  // within 300px of the viewport (the enterprise form's slot only once its modal is open).
  document.querySelectorAll('.turnstile-slot[data-form]').forEach((slot) => {
    const formName = slot.dataset.form;
    const form = slot.closest('form') || (slot.parentElement && slot.parentElement.querySelector('form'));
    const go = () => { ensure(formName).catch(() => { /* reported by the submit */ }); };
    if (form) {
      form.addEventListener('focusin', go, { once: true });
      form.addEventListener('input', go, { once: true });
    }
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) { io.disconnect(); go(); }
      }, { rootMargin: '300px 0px' });
      io.observe(slot);
    }
  });
})();
