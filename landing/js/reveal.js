/* ============================================================
   RunR Landing — reveal.js
   Scroll-triggered fade-in + counter animations.
   Vanilla, IntersectionObserver-based.
   ============================================================ */

(function () {
  'use strict';

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ── REVEAL ───────────────────────────────────────────────
  const revealEls = document.querySelectorAll('.reveal');
  if (reduced) {
    revealEls.forEach(el => el.classList.add('is-visible'));
  } else if ('IntersectionObserver' in window) {
    // Apply --i index for stagger automatically (within reveal-stagger parents)
    document.querySelectorAll('.reveal-stagger').forEach(parent => {
      Array.from(parent.children).forEach((child, i) => {
        if (!child.style.getPropertyValue('--i')) {
          child.style.setProperty('--i', i);
        }
      });
    });

    const io = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          if (!entry.target.dataset.revealRepeat) {
            io.unobserve(entry.target);
          }
        } else if (entry.target.dataset.revealRepeat) {
          entry.target.classList.remove('is-visible');
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    revealEls.forEach(el => io.observe(el));
  } else {
    revealEls.forEach(el => el.classList.add('is-visible'));
  }

  // ── COUNTERS ─────────────────────────────────────────────
  const counters = document.querySelectorAll('.counter[data-target]');
  if (counters.length === 0) return;

  function animateCounter(el) {
    const target = parseFloat(el.dataset.target);
    const suffix = el.dataset.suffix || '';
    const prefix = el.dataset.prefix || '';
    const duration = parseInt(el.dataset.duration || '1400', 10);
    const decimals = parseInt(el.dataset.decimals || '0', 10);

    if (reduced) {
      el.textContent = prefix + target.toFixed(decimals) + suffix;
      return;
    }

    const start = performance.now();
    function step(now) {
      const t = Math.min(1, (now - start) / duration);
      // easeOutCubic
      const eased = 1 - Math.pow(1 - t, 3);
      const v = target * eased;
      el.textContent = prefix + v.toFixed(decimals) + suffix;
      if (t < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  if ('IntersectionObserver' in window) {
    const cio = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          animateCounter(entry.target);
          cio.unobserve(entry.target);
        }
      });
    }, { threshold: 0.4 });
    counters.forEach(c => cio.observe(c));
  } else {
    counters.forEach(animateCounter);
  }
})();
