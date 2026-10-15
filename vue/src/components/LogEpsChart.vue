<script setup>
import { ref, computed, onUnmounted } from 'vue'

const props = defineProps({
  data:         { type: Array,  default: () => [] },
  selectedFrom: { type: Date,   default: null },
  selectedTo:   { type: Date,   default: null },
  chartHeight:  { type: Number, default: 36 },
})

const emit = defineEmits(['range-select', 'range-clear'])

const VW    = 600
const PAD_Y = 2
const VH    = computed(() => props.chartHeight)
const gridY = computed(() => [VH.value * 0.25, VH.value * 0.5, VH.value * 0.75])

const hoverIdx    = ref(null)
const dragStart   = ref(null)
const dragCurrent = ref(null)
const isDragging  = ref(false)
const chartEl     = ref(null)   // bound to the chart div (not outer container)

const maxEps = computed(() => {
  const m = Math.max(...props.data.map(d => d.eps))
  return m > 0 ? m : 1
})

const pts = computed(() => {
  if (props.data.length < 2) return []
  const n = props.data.length
  return props.data.map((d, i) => ({
    x: (i / (n - 1)) * VW,
    y: VH.value - PAD_Y - Math.max(1, (d.eps / maxEps.value) * (VH.value - PAD_Y * 2)),
    d,
  }))
})

function curvePath(points) {
  if (points.length < 2) return ''
  let path = `M ${points[0].x.toFixed(1)} ${points[0].y.toFixed(1)}`
  for (let i = 1; i < points.length; i++) {
    const cx = ((points[i - 1].x + points[i].x) / 2).toFixed(1)
    path += ` C ${cx} ${points[i - 1].y.toFixed(1)} ${cx} ${points[i].y.toFixed(1)} ${points[i].x.toFixed(1)} ${points[i].y.toFixed(1)}`
  }
  return path
}

const linePath = computed(() => curvePath(pts.value))
const areaPath = computed(() => {
  if (!pts.value.length) return ''
  return `${curvePath(pts.value)} L ${VW} ${VH.value} L 0 ${VH.value} Z`
})

const brushRect = computed(() => {
  if (dragStart.value === null || dragCurrent.value === null || !pts.value.length) return null
  const a = Math.min(dragStart.value, dragCurrent.value)
  const b = Math.max(dragStart.value, dragCurrent.value)
  return { x: pts.value[a]?.x ?? 0, width: Math.max(1, (pts.value[b]?.x ?? VW) - (pts.value[a]?.x ?? 0)) }
})

const selectionRect = computed(() => {
  if (!props.selectedFrom && !props.selectedTo) return null
  if (!props.data.length || !pts.value.length) return null
  const tMin = props.data[0].t.getTime()
  const tMax = props.data[props.data.length - 1].t.getTime()
  const span = tMax - tMin
  if (span <= 0) return null
  const x1 = props.selectedFrom ? Math.max(0, ((props.selectedFrom.getTime() - tMin) / span) * VW) : 0
  const x2 = props.selectedTo   ? Math.min(VW, ((props.selectedTo.getTime()   - tMin) / span) * VW) : VW
  return { x: x1, width: Math.max(1, x2 - x1) }
})

const hoverPt = computed(() =>
  !isDragging.value && hoverIdx.value !== null ? pts.value[hoverIdx.value] ?? null : null
)

// X axis: 3 evenly-spaced time labels
const xLabels = computed(() => {
  if (props.data.length < 2) return []
  const n   = props.data.length
  const mid = Math.floor(n / 2)
  return [props.data[0].t, props.data[mid].t, props.data[n - 1].t].map(fmtTs)
})

// Y axis: max, mid, unit label
const yLabels = computed(() => ({
  max: fmtEps(maxEps.value),
  mid: fmtEps(maxEps.value / 2),
}))

function fmtTs(d) {
  if (!d) return ''
  return new Date(d.getTime() + 3 * 3600_000).toISOString().slice(11, 19)
}
function fmtEps(v) {
  if (v < 0.01) return v.toFixed(3)
  if (v < 1)    return v.toFixed(2)
  if (v < 10)   return v.toFixed(1)
  return Math.round(v).toString()
}

// Tooltip: keep inside chart bounds horizontally
function tipLeft(pt)  {
  const pct = (pt.x / VW) * 100
  if (pct < 12) return '0%'
  if (pct > 88) return '100%'
  return `${pct}%`
}
function tipAnchor(pt) {
  const pct = (pt.x / VW) * 100
  if (pct < 12) return 'translateX(0)'
  if (pct > 88) return 'translateX(-100%)'
  return 'translateX(-50%)'
}

function getIdx(e) {
  const el = chartEl.value
  if (!el) return 0
  const rect = el.getBoundingClientRect()
  const x = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width))
  return Math.round(x * (props.data.length - 1))
}

function onMouseDown(e) {
  if (!props.data.length) return
  isDragging.value  = true
  const idx = getIdx(e)
  dragStart.value   = idx
  dragCurrent.value = idx
  window.addEventListener('mouseup', onWindowMouseUp)
}

function onMouseMove(e) {
  if (!props.data.length) return
  const idx = getIdx(e)
  hoverIdx.value = idx
  if (isDragging.value) dragCurrent.value = idx
}

function onWindowMouseUp() {
  window.removeEventListener('mouseup', onWindowMouseUp)
  if (!isDragging.value) return
  isDragging.value = false
  const a = Math.min(dragStart.value, dragCurrent.value)
  const b = Math.max(dragStart.value, dragCurrent.value)
  dragStart.value = dragCurrent.value = null
  if (a === b) { emit('range-clear'); return }
  emit('range-select', { from: props.data[a].t, to: props.data[b].t })
}

onUnmounted(() => window.removeEventListener('mouseup', onWindowMouseUp))
</script>

<template>
  <div v-if="data.length >= 2" class="flex-1 min-w-0 select-none">

    <div class="flex items-stretch gap-1">

      <!-- Y axis labels -->
      <div class="w-8 shrink-0 relative pointer-events-none" :style="{ height: VH + 'px' }">
        <span class="absolute top-0 right-0.5 text-[9px] font-mono text-gray-400 leading-none">{{ yLabels.max }}</span>
        <span class="absolute top-1/2 right-0.5 -translate-y-1/2 text-[9px] font-mono text-gray-400 leading-none">{{ yLabels.mid }}</span>
        <span class="absolute bottom-0 right-0.5 text-[9px] font-mono text-gray-400 leading-none">0</span>
        <span class="absolute top-1/2 left-0 -translate-y-1/2 -rotate-90 text-[9px] font-mono font-semibold text-gray-400 leading-none origin-center whitespace-nowrap tracking-widest" style="left:-10px">EPS</span>
      </div>

      <!-- Chart area -->
      <div
        ref="chartEl"
        class="flex-1 relative min-w-0 rounded-sm border border-gray-800 bg-gray-950 cursor-crosshair"
        @mousedown="onMouseDown"
        @mousemove="onMouseMove"
        @mouseleave="hoverIdx = null"
      >
        <svg
          class="w-full block text-brand-500"
          :style="{ height: VH + 'px' }"
          :viewBox="`0 0 ${VW} ${VH}`"
          preserveAspectRatio="none"
        >
          <defs>
            <linearGradient id="eps-area-fill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%"   stop-color="currentColor" stop-opacity="0.25" />
              <stop offset="100%" stop-color="currentColor" stop-opacity="0.02" />
            </linearGradient>
            <linearGradient id="eps-sel-fill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%"   stop-color="currentColor" stop-opacity="0.18" />
              <stop offset="100%" stop-color="currentColor" stop-opacity="0.06" />
            </linearGradient>
          </defs>

          <line v-for="gy in gridY" :key="gy"
            x1="0" :y1="gy.toFixed(1)" :x2="VW" :y2="gy.toFixed(1)"
            stroke="#1f2937" stroke-width="0.6" />

          <rect v-if="selectionRect && !brushRect"
            :x="selectionRect.x" y="0" :width="selectionRect.width" :height="VH"
            fill="url(#eps-sel-fill)" stroke="currentColor" stroke-width="0.5" stroke-opacity="0.4" />

          <path :d="areaPath" fill="url(#eps-area-fill)" />
          <path :d="linePath" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round" />

          <rect v-if="brushRect"
            :x="brushRect.x" y="0" :width="brushRect.width" :height="VH"
            fill="currentColor" fill-opacity="0.15" stroke="currentColor" stroke-width="0.6" stroke-opacity="0.7" />

          <line v-if="hoverPt"
            :x1="hoverPt.x.toFixed(1)" y1="0" :x2="hoverPt.x.toFixed(1)" :y2="VH"
            stroke="currentColor" stroke-width="0.6" stroke-dasharray="2 2" opacity="0.5" />

          <circle v-if="hoverPt"
            :cx="hoverPt.x.toFixed(1)" :cy="hoverPt.y.toFixed(1)" r="2.5"
            fill="currentColor" stroke="#030712" stroke-width="1.2" />
        </svg>

        <!-- Hover tooltip — pinned inside chart at top -->
        <div v-if="hoverPt"
          class="absolute top-1 pointer-events-none z-20"
          :style="{ left: tipLeft(hoverPt), transform: tipAnchor(hoverPt) }"
        >
          <div class="bg-gray-900/95 border border-gray-700/80 rounded-sm shadow-lg px-2.5 py-1 text-[10px] font-mono whitespace-nowrap">
            <span class="text-gray-400">{{ fmtTs(hoverPt.d.t) }}</span>
            <span class="mx-1 text-gray-400">·</span>
            <span class="text-brand-300 font-semibold">{{ fmtEps(hoverPt.d.eps) }}</span>
            <span class="text-gray-400 ml-0.5">log/s</span>
            <span class="mx-1 text-gray-400">·</span>
            <span class="text-gray-400">{{ hoverPt.d.count }}</span>
            <span class="text-gray-400 ml-0.5">logs</span>
          </div>
        </div>

        <!-- Drag range tooltip — inside chart at top center -->
        <div v-if="brushRect && dragStart !== dragCurrent"
          class="absolute top-1 left-1/2 -translate-x-1/2 pointer-events-none z-20"
        >
          <div class="bg-gray-900/95 border border-brand-700/60 rounded-sm shadow-lg px-2.5 py-1 text-[10px] font-mono whitespace-nowrap text-brand-300">
            {{ fmtTs(data[Math.min(dragStart, dragCurrent)]?.t) }}
            <span class="text-gray-400 mx-1">→</span>
            {{ fmtTs(data[Math.max(dragStart, dragCurrent)]?.t) }}
          </div>
        </div>
      </div>
    </div>

    <!-- X axis labels -->
    <div v-if="xLabels.length" class="flex justify-between pl-9 mt-0.5 pointer-events-none">
      <span v-for="(lbl, i) in xLabels" :key="i" class="text-[9px] font-mono text-gray-400">{{ lbl }}</span>
    </div>

  </div>
  <div v-else class="flex-1" />
</template>
