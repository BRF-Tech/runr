/* ============================================================
   RunR Landing — forms.js
   Release-news sign-up (POST /api/waitlist) + Enterprise contact form handlers.
   Depends on window.RunR namespace (exposed by main.js).
   ============================================================ */

(function () {
  'use strict';

  function init() {
    if (!window.RunR) { setTimeout(init, 30); return; }
    const R = window.RunR;

    const finalForm    = document.getElementById('form-final-cta');
    const entForm      = document.getElementById('form-enterprise');

    function busy(btn) { btn && (btn.disabled = true, btn.dataset._lbl = btn.textContent, btn.textContent = '…'); }
    function idle(btn) { if (btn) { btn.disabled = false; btn.textContent = btn.dataset._lbl || btn.textContent; } }

    // The form's Turnstile token; Turnstile loads on demand (js/turnstile.js), so a submit may
    // wait for it. '' after an error toast (Turnstile unavailable, failed or not solved).
    async function botToken(formName) {
      try {
        return await R.tsWait(formName);
      } catch (e) {
        R.toast(R.errMessage((e && e.message) || 'bot_check_required'), 'error');
        return '';
      }
    }

    async function submitWaitlist(email, source, btn, formName) {
      if (!R.emailLooksValid(email)) { R.toast(R.errMessage('invalid_email'), 'error'); return; }
      busy(btn);
      const token = await botToken(formName);
      if (!token) { idle(btn); return; }
      try {
        const { ok, data } = await R.postJson(`${R.API_BASE}/api/waitlist`, {
          email: email.trim().toLowerCase(),
          locale: R.locale(),
          source: source || 'unknown',
          turnstile_token: token
        });
        if (ok) {
          // Umami (its script comes from the web server) counts accepted sign-ups only.
          window.umami?.track('release-news-signup', { source: source || 'unknown' });
          R.toast(R.locale() === 'tr' ? 'Listedesiniz! Teşekkürler.' : "You're on the list. Thanks!", 'success');
          finalForm && finalForm.reset();
        } else {
          R.toast(R.errMessage((data && data.error) || 'network'), 'error');
        }
      } catch (_) {
        R.toast(R.errMessage('network'), 'error');
      } finally {
        idle(btn);
        R.tsReset(formName);
      }
    }

    if (finalForm) {
      finalForm.addEventListener('submit', (e) => {
        e.preventDefault();
        submitWaitlist(finalForm.email.value, 'final', finalForm.querySelector('button[type=submit]'), 'final');
      });
    }

    if (entForm) {
      entForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = {
          name: entForm.name.value.trim(),
          company: entForm.company.value.trim(),
          email: entForm.email.value.trim().toLowerCase(),
          team_size: entForm.team_size.value,
          message: entForm.message.value.trim(),
          locale: R.locale(),
          turnstile_token: ''
        };
        if (!body.name || body.name.length < 2)        { R.toast(R.errMessage('validation_failed'), 'error'); return; }
        if (!body.company || body.company.length < 2) { R.toast(R.errMessage('validation_failed'), 'error'); return; }
        if (!R.emailLooksValid(body.email))            { R.toast(R.errMessage('invalid_email'), 'error'); return; }
        if (!body.message || body.message.length < 10){ R.toast(R.errMessage('validation_failed'), 'error'); return; }

        const btn = entForm.querySelector('button[type=submit]');
        busy(btn);
        body.turnstile_token = await botToken('enterprise');
        if (!body.turnstile_token) { idle(btn); return; }
        try {
          const { ok, data } = await R.postJson(`${R.API_BASE}/api/enterprise-contact`, body);
          if (ok) {
            window.umami?.track('enterprise-contact-sent', { team_size: body.team_size });
            R.toast(R.locale() === 'tr' ? 'Teşekkürler, en kısa sürede dönüş yapacağız.' : "Thanks, we'll be in touch soon.", 'success');
            R.closeModal();
            entForm.reset();
          } else {
            R.toast(R.errMessage((data && data.error) || 'network'), 'error');
          }
        } catch (_) {
          R.toast(R.errMessage('network'), 'error');
        } finally {
          idle(btn);
          R.tsReset('enterprise');
        }
      });
    }
  }

  init();
})();
