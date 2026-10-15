/* ============================================================
   RunR Landing — bg.js  (canvas 2D, fixed full-viewport)
   ────────────────────────────────────────────────────────────
   "DATA STREAM" background — aggressive, network-throughput feel:

     • Bright horizontal data packets (speed + brightness vary by layer)
     • Thin scan-line grid (vertical sweep)
     • Periodic horizontal "burst" — a large beam bar passes (every ~4s)
     • Subtle radial color orbs (depth)
     • Reduced-motion + offscreen-pause respected
   ============================================================ */

(function () {
  'use strict';

  const canvas = document.getElementById('bg-canvas');
  if (!canvas) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const ctx = canvas.getContext('2d', { alpha: true });
  const DPR = Math.min(window.devicePixelRatio || 1, 1.5);

  let W = 0, H = 0;
  function resize() {
    W = window.innerWidth; H = window.innerHeight;
    canvas.width  = W * DPR; canvas.height = H * DPR;
    canvas.style.width = W + 'px'; canvas.style.height = H + 'px';
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
  }
  resize();
  window.addEventListener('resize', resize);

  // ── DATA PACKETS (horizontal flowing lines) ─────────────
  const PCOUNT = window.innerWidth < 700 ? 30 : 55;
  const packets = [];
  function spawnPacket(p) {
    const layer = Math.random();                    // 0..1 depth
    const dir   = Math.random() < 0.5 ? 1 : -1;
    p.dir    = dir;
    p.x      = dir > 0 ? -200 : W + 200;
    p.y      = Math.random() * H;
    p.layer  = layer;
    p.len    = 40 + Math.random() * 220 * (1 - layer * 0.6);
    p.speed  = (2 + Math.random() * 6) * (1.2 - layer * 0.85) * dir;
    p.hue    = Math.random() < 0.8 ? (15 + Math.random() * 22) : (350 + Math.random() * 10);
    p.alpha  = (1 - layer * 0.7) * (0.5 + Math.random() * 0.5);
    p.thick  = (1 - layer * 0.55) * (0.8 + Math.random() * 1.4);
  }
  for (let i = 0; i < PCOUNT; i++) {
    const p = {};
    spawnPacket(p);
    p.x = Math.random() * W; // initial spread across screen
    packets.push(p);
  }

  // ── BURST BAR (occasional dramatic flash) ───────────────
  let burst = { active: false, y: 0, x: 0, speed: 0, alpha: 0, thick: 0 };
  let nextBurst = performance.now() + 2500 + Math.random() * 3000;
  function triggerBurst() {
    burst.active = true;
    burst.y = 60 + Math.random() * (H - 120);
    const fromLeft = Math.random() < 0.5;
    burst.x = fromLeft ? -300 : W + 300;
    burst.speed = (fromLeft ? 1 : -1) * (18 + Math.random() * 12);
    burst.alpha = 0.55 + Math.random() * 0.3;
    burst.thick = 2 + Math.random() * 3;
    burst.len = 350 + Math.random() * 400;
  }

  // ── ORBS (3 huge soft gradient bulbs, mouse-parallax) ───
  const orbs = [
    { x: W * 0.18, y: H * 0.25, r: 340, hue: 22, alpha: 0.30, ph: 0,   sx: 0, sy: 0 },
    { x: W * 0.82, y: H * 0.75, r: 380, hue: 5,  alpha: 0.24, ph: 1.6, sx: 0, sy: 0 },
    { x: W * 0.5,  y: H * 0.55, r: 240, hue: 30, alpha: 0.18, ph: 3.1, sx: 0, sy: 0 }
  ];
  let tmx = W/2, tmy = H/2, mx = tmx, my = tmy;
  window.addEventListener('pointermove', (e) => { tmx = e.clientX; tmy = e.clientY; }, { passive: true });

  let scrollY = 0;
  window.addEventListener('scroll', () => { scrollY = window.scrollY; }, { passive: true });

  let visible = true;
  document.addEventListener('visibilitychange', () => { visible = !document.hidden; });

  // ── LOOP ────────────────────────────────────────────────
  let last = performance.now();
  function frame(now) {
    requestAnimationFrame(frame);
    if (!visible) return;
    const delta = Math.min(50, now - last); last = now;
    const t = now * 0.001;

    ctx.clearRect(0, 0, W, H);

    // ── Orbs (additive blending via composite) ─────────
    mx += (tmx - mx) * 0.04; my += (tmy - my) * 0.04;
    const scrollOff = scrollY * 0.03;
    ctx.globalCompositeOperation = 'lighter';
    for (const o of orbs) {
      const ox = o.x + Math.cos(t * 0.35 + o.ph) * 100 + (mx - W/2) * 0.04;
      const oy = o.y + Math.sin(t * 0.28 + o.ph) * 70 + (my - H/2) * 0.03 - scrollOff;
      const g = ctx.createRadialGradient(ox, oy, 0, ox, oy, o.r);
      g.addColorStop(0,   `hsla(${o.hue}, 95%, 58%, ${o.alpha})`);
      g.addColorStop(0.5, `hsla(${o.hue}, 95%, 58%, ${o.alpha * 0.25})`);
      g.addColorStop(1,   'hsla(0,0%,0%,0)');
      ctx.fillStyle = g;
      ctx.fillRect(ox - o.r, oy - o.r, o.r * 2, o.r * 2);
    }
    ctx.globalCompositeOperation = 'source-over';

    // ── Scan-line grid (very subtle) ───────────────────
    ctx.strokeStyle = 'rgba(255, 140, 66, 0.04)';
    ctx.lineWidth = 1;
    const gridShift = (t * 8) % 40;
    for (let y = -40 + gridShift; y < H; y += 40) {
      ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke();
    }

    // ── Data packets ────────────────────────────────────
    ctx.globalCompositeOperation = 'lighter';
    for (const p of packets) {
      p.x += p.speed * (delta / 16.67);
      // wrap or respawn
      if ((p.dir > 0 && p.x - p.len > W) || (p.dir < 0 && p.x + p.len < 0)) {
        spawnPacket(p);
        continue;
      }
      // draw packet as gradient line (head bright, tail fade)
      const headX = p.x;
      const tailX = p.x - p.dir * p.len;
      const grd = ctx.createLinearGradient(tailX, p.y, headX, p.y);
      grd.addColorStop(0, `hsla(${p.hue}, 95%, 60%, 0)`);
      grd.addColorStop(0.7, `hsla(${p.hue}, 95%, 60%, ${p.alpha * 0.4})`);
      grd.addColorStop(1,   `hsla(${p.hue}, 95%, 68%, ${p.alpha})`);
      ctx.strokeStyle = grd;
      ctx.lineWidth = p.thick;
      ctx.beginPath();
      ctx.moveTo(tailX, p.y);
      ctx.lineTo(headX, p.y);
      ctx.stroke();
      // bright head dot
      ctx.fillStyle = `hsla(${p.hue}, 100%, 75%, ${p.alpha})`;
      ctx.beginPath();
      ctx.arc(headX, p.y, p.thick * 0.9, 0, Math.PI * 2);
      ctx.fill();
    }

    // ── Periodic burst bar ──────────────────────────────
    if (!burst.active && now >= nextBurst) {
      triggerBurst();
      nextBurst = now + 3500 + Math.random() * 3500;
    }
    if (burst.active) {
      burst.x += burst.speed * (delta / 16.67);
      const head = burst.x;
      const tail = burst.x - Math.sign(burst.speed) * burst.len;
      const grd = ctx.createLinearGradient(tail, burst.y, head, burst.y);
      const dir = Math.sign(burst.speed);
      grd.addColorStop(0,   `hsla(20, 100%, 60%, 0)`);
      grd.addColorStop(0.6, `hsla(20, 100%, 65%, ${burst.alpha * 0.5})`);
      grd.addColorStop(0.95,`hsla(35, 100%, 72%, ${burst.alpha})`);
      grd.addColorStop(1,   `hsla(40, 100%, 88%, ${burst.alpha})`);
      ctx.strokeStyle = grd;
      ctx.lineWidth = burst.thick;
      ctx.beginPath();
      ctx.moveTo(tail, burst.y);
      ctx.lineTo(head, burst.y);
      ctx.stroke();
      // glow halo around head
      const halo = ctx.createRadialGradient(head, burst.y, 0, head, burst.y, 30);
      halo.addColorStop(0, `hsla(40, 100%, 80%, ${burst.alpha})`);
      halo.addColorStop(1, 'hsla(0,0%,0%,0)');
      ctx.fillStyle = halo;
      ctx.fillRect(head - 30, burst.y - 30, 60, 60);
      // off-screen check
      if ((dir > 0 && head - burst.len > W + 50) || (dir < 0 && head + burst.len < -50)) burst.active = false;
    }
    ctx.globalCompositeOperation = 'source-over';
  }
  requestAnimationFrame(frame);
})();
