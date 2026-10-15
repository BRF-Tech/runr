/* ============================================================
   RunR Landing — forms.js
   Waitlist + Enterprise contact form handlers.
   Depends on window.RunR namespace (exposed by main.js).
   ============================================================ */

(function () {
  'use strict';

  function init() {
    if (!window.RunR) { setTimeout(init, 30); return; }
    const R = window.RunR;

    const waitlistForm = document.getElementById('form-waitlist');
    const finalForm    = document.getElementById('form-final-cta');
    const entForm      = document.getElementById('form-enterprise');

    async function submitWaitlist(email, source, btn, formName) {
      if (!R.emailLooksValid(email)) { R.toast(R.errMessage('invalid_email'), 'error'); return; }
      const token = R.tsToken(formName);
      if (!token) { R.toast(R.errMessage('bot_check_required'), 'error'); return; }
      btn && (btn.disabled = true, btn.dataset._lbl = btn.textContent, btn.textContent = '…');
      try {
        const { ok, data } = await R.postJson(`${R.API_BASE}/api/waitlist`, {
          email: email.trim().toLowerCase(),
          locale: R.locale(),
          source: source || 'unknown',
          turnstile_token: token
        });
        if (ok) {
          R.toast(R.locale() === 'tr' ? 'Listedesiniz! Teşekkürler.' : "You're on the list. Thanks!", 'success');
          R.closeModal();
          waitlistForm && waitlistForm.reset();
          finalForm && finalForm.reset();
        } else {
          R.toast(R.errMessage((data && data.error) || 'network'), 'error');
        }
      } catch (_) {
        R.toast(R.errMessage('network'), 'error');
      } finally {
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset._lbl || btn.textContent; }
        R.tsReset(formName);
      }
    }

    if (waitlistForm) {
      waitlistForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const email = waitlistForm.email.value;
        const src = (R.modals.waitlist && R.modals.waitlist.dataset.source) || 'modal';
        submitWaitlist(email, src, waitlistForm.querySelector('button[type=submit]'), 'waitlist');
      });
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
          turnstile_token: R.tsToken('enterprise')
        };
        if (!body.name || body.name.length < 2)        { R.toast(R.errMessage('validation_failed'), 'error'); return; }
        if (!body.company || body.company.length < 2) { R.toast(R.errMessage('validation_failed'), 'error'); return; }
        if (!R.emailLooksValid(body.email))            { R.toast(R.errMessage('invalid_email'), 'error'); return; }
        if (!body.message || body.message.length < 10){ R.toast(R.errMessage('validation_failed'), 'error'); return; }
        if (!body.turnstile_token)                     { R.toast(R.errMessage('bot_check_required'), 'error'); return; }

        const btn = entForm.querySelector('button[type=submit]');
        btn && (btn.disabled = true, btn.dataset._lbl = btn.textContent, btn.textContent = '…');
        try {
          const { ok, data } = await R.postJson(`${R.API_BASE}/api/enterprise-contact`, body);
          if (ok) {
            R.toast(R.locale() === 'tr' ? 'Teşekkürler, en kısa sürede dönüş yapacağız.' : "Thanks, we'll be in touch soon.", 'success');
            R.closeModal();
            entForm.reset();
          } else {
            R.toast(R.errMessage((data && data.error) || 'network'), 'error');
          }
        } catch (_) {
          R.toast(R.errMessage('network'), 'error');
        } finally {
          if (btn) { btn.disabled = false; btn.textContent = btn.dataset._lbl || btn.textContent; }
          R.tsReset('enterprise');
        }
      });
    }
  }

  init();
})();
