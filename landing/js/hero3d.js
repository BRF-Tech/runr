/* ============================================================
   RunR Landing — hero3d.js  (Three.js)
   ────────────────────────────────────────────────────────────
   "POD SKYLINE" — a storytelling serverless animation:

     Request → cold start → pod spawn → execution glow →
     response → scale-to-zero → pod sinks back into the floor.

   - Floor grid (Knative cluster floor)
   - 120 "pod slots" — invisible by default (scale-to-zero)
   - Continuous random "request" events: an ingress particle lands
     on a slot, the slot rises and glows while executing, a response
     particle returns from its top toward the camera, then the slot fades.
   - Gradual scale-out: under heavy load, neighbouring slots wake up too.
   ============================================================ */

(function () {
  'use strict';

  const canvas = document.getElementById('hero3d');
  if (!canvas) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  let retries = 0;
  function start() {
    if (typeof THREE === 'undefined') {
      if (++retries > 80) { console.warn('[RunR] Three.js did not load — CSS hero fallback active.'); return; }
      setTimeout(start, 50); return;
    }
    console.info('[RunR] hero3d: Pod Skyline starting (Three.js r' + THREE.REVISION + ')');

    const W = () => canvas.clientWidth  || canvas.offsetWidth  || window.innerWidth;
    const H = () => canvas.clientHeight || canvas.offsetHeight || window.innerHeight;

    const scene = new THREE.Scene();
    scene.fog = new THREE.Fog(0x0a0e1a, 16, 55);

    const camera = new THREE.PerspectiveCamera(48, W() / H(), 0.1, 200);
    camera.position.set(0, 9, 18);
    camera.lookAt(0, 1, 0);

    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, window.innerWidth < 700 ? 1 : 1.6));
    renderer.setSize(W(), H(), false);
    renderer.setClearColor(0x000000, 0);
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.6;

    // ── LIGHTS ───────────────────────────────────────────
    scene.add(new THREE.AmbientLight(0x6a4a2a, 1.1));
    const key  = new THREE.DirectionalLight(0xffaa55, 2.2);
    key.position.set(8, 14, 6); scene.add(key);
    const rim  = new THREE.DirectionalLight(0xff3946, 1.6);
    rim.position.set(-8, 6, -8); scene.add(rim);
    const core = new THREE.PointLight(0xffb347, 1.8, 28);
    core.position.set(0, 4, 0); scene.add(core);

    // ── FLOOR GRID (cluster surface) ────────────────────
    const gridHelper = new THREE.GridHelper(60, 30, 0xff8c42, 0x331b0c);
    gridHelper.position.y = 0;
    gridHelper.material.transparent = true;
    gridHelper.material.opacity = 0.18;
    scene.add(gridHelper);

    // ── POD SLOTS ────────────────────────────────────────
    const COLS = 14, ROWS = 9, SP_X = 1.5, SP_Y = 1.5;
    const total = COLS * ROWS;
    const podGeo = new THREE.BoxGeometry(0.55, 1, 0.55);
    podGeo.translate(0, 0.5, 0); // pivot at bottom (so scale grows up from floor)
    const podMat = new THREE.MeshStandardMaterial({
      color: 0xff8c42, emissive: 0xff5722, emissiveIntensity: 0.7,
      roughness: 0.35, metalness: 0.5
    });
    const pods = new THREE.InstancedMesh(podGeo, podMat, total);
    pods.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
    pods.instanceColor = new THREE.InstancedBufferAttribute(new Float32Array(total * 3), 3);
    scene.add(pods);

    // Slot data
    const slots = [];
    const tmpMat = new THREE.Matrix4();
    const tmpQ   = new THREE.Quaternion();
    const tmpV   = new THREE.Vector3();
    const tmpS   = new THREE.Vector3();
    let idx = 0;
    for (let r = 0; r < ROWS; r++) {
      for (let c = 0; c < COLS; c++) {
        const x = (c - (COLS-1)/2) * SP_X + (r % 2 ? SP_X*0.5 : 0);
        const z = (r - (ROWS-1)/2) * SP_Y - 2;
        slots.push({
          x, z, life: 0,         // 0 = dormant, 1 = peak, 0 again = dead
          state: 'idle',         // idle | spawn | live | dying
          height: 0,
          peakHeight: 0,
          peakLifeMs: 0,
          spawnedAt: 0
        });
        // start at scale 0
        tmpV.set(x, 0, z); tmpS.set(0.001, 0.001, 0.001);
        tmpMat.compose(tmpV, tmpQ, tmpS);
        pods.setMatrixAt(idx, tmpMat);
        // default color
        pods.instanceColor.array[idx*3]   = 1.0;
        pods.instanceColor.array[idx*3+1] = 0.55;
        pods.instanceColor.array[idx*3+2] = 0.26;
        idx++;
      }
    }
    pods.instanceMatrix.needsUpdate = true;
    pods.instanceColor.needsUpdate = true;

    // ── PARTICLE STREAMS (ingress request + egress response) ──
    const PCOUNT = 220;
    const pGeo = new THREE.BufferGeometry();
    const pPos = new Float32Array(PCOUNT * 3);
    const pData = [];
    for (let i = 0; i < PCOUNT; i++) {
      pData.push({ alive: false, kind: 'in', sx:0, sy:0, sz:0, tx:0, ty:0, tz:0, t:0, sp:0 });
      pPos[i*3] = pPos[i*3+1] = pPos[i*3+2] = -9999;
    }
    pGeo.setAttribute('position', new THREE.BufferAttribute(pPos, 3));
    const pMat = new THREE.PointsMaterial({
      color: 0xffd47a, size: 0.22, sizeAttenuation: true,
      transparent: true, opacity: 1.0, blending: THREE.AdditiveBlending, depthWrite: false
    });
    const particles = new THREE.Points(pGeo, pMat);
    scene.add(particles);

    function spawnParticle(kind, slot) {
      // find a dead particle slot
      for (let i = 0; i < PCOUNT; i++) {
        const p = pData[i];
        if (!p.alive) {
          p.alive = true; p.kind = kind; p.t = 0;
          if (kind === 'in') {
            // request comes from off-camera (high above + side)
            const a = Math.random() * Math.PI * 2;
            p.sx = Math.cos(a) * 14;
            p.sy = 8 + Math.random() * 4;
            p.sz = Math.sin(a) * 14;
            p.tx = slot.x; p.ty = slot.peakHeight + 0.3; p.tz = slot.z;
            p.sp = 0.015 + Math.random() * 0.01;
          } else {
            // response leaves slot top → toward camera-ish
            p.sx = slot.x; p.sy = slot.peakHeight + 0.4; p.sz = slot.z;
            p.tx = slot.x * 0.3 + (Math.random() - 0.5) * 4;
            p.ty = 6 + Math.random() * 3;
            p.tz = slot.z * 0.3 + 8 + Math.random() * 4;
            p.sp = 0.018 + Math.random() * 0.012;
          }
          return;
        }
      }
    }

    // ── SPAWN A POD ──────────────────────────────────────
    function triggerPod(now, opts) {
      opts = opts || {};
      const dormant = [];
      for (let i = 0; i < slots.length; i++) if (slots[i].state === 'idle') dormant.push(i);
      if (!dormant.length) return;
      const s = dormant[(Math.random() * dormant.length) | 0];
      const slot = slots[s];
      slot.state = 'spawn';
      slot.spawnedAt = now;
      slot.peakHeight = 1.4 + Math.random() * 2.4;
      slot.peakLifeMs = 1100 + Math.random() * 1600;
      // ingress request particle
      spawnParticle('in', slot);
      slot.__idx = s;

      // 30% chance to also trigger 1-2 neighbours (scale-out)
      if (!opts.noCascade && Math.random() < 0.35) {
        for (let n = 0; n < slots.length; n++) {
          if (slots[n].state !== 'idle') continue;
          const dx = slot.x - slots[n].x, dz = slot.z - slots[n].z;
          if (Math.sqrt(dx*dx + dz*dz) < 2.2 && Math.random() < 0.4) {
            setTimeout(() => triggerPod(performance.now(), { noCascade: true }), 80 + Math.random() * 240);
          }
        }
      }
    }

    // ── MOUSE PARALLAX ──────────────────────────────────
    let tmx = 0, tmy = 0, mx = 0, my = 0;
    window.addEventListener('pointermove', (e) => {
      tmx = e.clientX / window.innerWidth - 0.5;
      tmy = e.clientY / window.innerHeight - 0.5;
    }, { passive: true });

    // ── RESIZE ──────────────────────────────────────────
    function resize() { camera.aspect = W()/H(); camera.updateProjectionMatrix(); renderer.setSize(W(), H(), false); }
    window.addEventListener('resize', resize); resize();

    // ── VISIBILITY PAUSE ─────────────────────────────────
    let running = true;
    if ('IntersectionObserver' in window) {
      new IntersectionObserver((e) => { for (const x of e) running = x.isIntersecting; }, { threshold: 0 }).observe(canvas);
    }

    // ── LOOP ─────────────────────────────────────────────
    let nextSpawn = 0;
    function tick(now) {
      requestAnimationFrame(tick);
      if (!running) return;
      const t = now * 0.001;

      // continuous spawn events
      if (t > nextSpawn) {
        // 1-3 pods per spawn tick → looks like real traffic
        const burst = 1 + ((Math.random() * 3) | 0);
        for (let b = 0; b < burst; b++) triggerPod(now);
        nextSpawn = t + 0.18 + Math.random() * 0.42;
      }

      // smooth mouse
      mx += (tmx - mx) * 0.04;
      my += (tmy - my) * 0.04;

      // Orbital camera — wide & dynamic
      camera.position.x = Math.sin(t * 0.16) * 4 + mx * 4;
      camera.position.y = 8.5 + Math.sin(t * 0.22) * 1.6 - my * 2;
      camera.position.z = 16 + Math.cos(t * 0.12) * 2.5;
      camera.lookAt(Math.sin(t * 0.08) * 0.8, 1.6, Math.cos(t * 0.08) * 0.4);

      // ── POD LIFECYCLE ───────────────────────────────
      const colors = pods.instanceColor.array;
      for (let i = 0; i < slots.length; i++) {
        const s = slots[i];
        let h = s.height;
        let act = 0;

        if (s.state === 'spawn') {
          // ramp up over ~400ms
          const elapsed = now - s.spawnedAt;
          const k = Math.min(1, elapsed / 420);
          h = s.peakHeight * easeOutBack(k);
          if (k >= 1) { s.state = 'live'; s.liveStart = now; }
          act = k * 0.8;
        } else if (s.state === 'live') {
          // pulse + execution glow
          const liveT = now - s.liveStart;
          h = s.peakHeight * (1 + Math.sin(liveT * 0.012) * 0.05);
          act = 1.0;
          if (liveT > s.peakLifeMs) {
            s.state = 'dying'; s.dyingStart = now;
            // egress response particle
            spawnParticle('out', s);
          }
        } else if (s.state === 'dying') {
          // shrink back to floor over ~500ms
          const elapsed = now - s.dyingStart;
          const k = Math.min(1, elapsed / 520);
          h = s.peakHeight * (1 - easeInQuad(k));
          act = 1 - k;
          if (k >= 1) { s.state = 'idle'; s.peakHeight = 0; h = 0; act = 0; }
        }
        s.height = h;

        // write instance matrix (scale Y = height)
        tmpV.set(s.x, 0, s.z);
        tmpS.set(1, Math.max(0.001, h), 1);
        tmpMat.compose(tmpV, tmpQ, tmpS);
        pods.setMatrixAt(i, tmpMat);

        // color: idle dim → live bright yellow
        colors[i*3]   = 1.0;
        colors[i*3+1] = 0.45 + act * 0.5;
        colors[i*3+2] = 0.20 + act * 0.5;
      }
      pods.instanceMatrix.needsUpdate = true;
      pods.instanceColor.needsUpdate = true;

      // ── PARTICLES ─────────────────────────────────────
      for (let i = 0; i < PCOUNT; i++) {
        const p = pData[i];
        if (!p.alive) { pPos[i*3] = pPos[i*3+1] = pPos[i*3+2] = -9999; continue; }
        p.t += p.sp;
        if (p.t >= 1) { p.alive = false; continue; }
        // ease the trajectory (arc up)
        const k = p.t;
        const arc = Math.sin(k * Math.PI) * 1.5;
        pPos[i*3]   = p.sx + (p.tx - p.sx) * k;
        pPos[i*3+1] = p.sy + (p.ty - p.sy) * k + arc;
        pPos[i*3+2] = p.sz + (p.tz - p.sz) * k;
      }
      pGeo.attributes.position.needsUpdate = true;

      renderer.render(scene, camera);
    }

    function easeOutBack(x) { const c1 = 1.70158, c3 = c1 + 1; return 1 + c3 * Math.pow(x - 1, 3) + c1 * Math.pow(x - 1, 2); }
    function easeInQuad(x) { return x * x; }

    requestAnimationFrame(tick);
  }

  start();
})();
