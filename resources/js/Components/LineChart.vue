<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Daily line chart for the admin Traffic screen. Plain SVG, no chart library.
 *
 * One y-axis only: every series passed in must share a unit (counts with
 * counts). Two measures of different scale go in two charts.
 *
 * Colours are the first three categorical slots of the validated palette,
 * stepped for the admin's dark surface (#150A1C): all checks pass — adjacent
 * CVD ΔE ≥ 9.4, normal-vision ΔE ≥ 26.5, ≥ 3:1 contrast. Assign them in this
 * fixed order; never more than three series per chart.
 *
 * Identity is never colour alone: a legend for 2+ series, plus a direct label
 * at each line's end. A hover crosshair + tooltip reads exact values, and a
 * table view sits under the plot.
 */
const SERIES_COLORS = ['#3987e5', '#d95926', '#199e70']

const props = defineProps({
    /** [{ date: 'YYYY-MM-DD', <key>: number, ... }] */
    rows: { type: Array, required: true },
    /** [{ key, label }] — colour comes from the fixed slot order. */
    series: { type: Array, required: true },
    height: { type: Number, default: 220 },
    format: { type: Function, default: (v) => Number(v).toLocaleString() },
    /** Accessible name; also the table caption. */
    title: { type: String, required: true },
})

const PAD = { top: 12, right: 72, bottom: 26, left: 44 }

const root = ref(null)
const width = ref(640)
const hover = ref(null)
let observer = null

onMounted(() => {
    observer = new ResizeObserver(([entry]) => {
        width.value = Math.max(280, Math.floor(entry.contentRect.width))
    })
    observer.observe(root.value)
})
onBeforeUnmount(() => observer?.disconnect())

const lines = computed(() =>
    props.series.slice(0, SERIES_COLORS.length).map((s, i) => ({ ...s, color: SERIES_COLORS[i] })),
)

const plotW = computed(() => width.value - PAD.left - PAD.right)
const plotH = computed(() => props.height - PAD.top - PAD.bottom)

/** A "nice" ceiling (1, 2, 2.5, 5 × 10ⁿ) so gridlines land on round values. */
const yMax = computed(() => {
    const raw = Math.max(1, ...props.rows.flatMap((r) => lines.value.map((s) => Number(r[s.key]) || 0)))
    const mag = 10 ** Math.floor(Math.log10(raw))
    const step = [1, 2, 2.5, 5, 10].find((m) => m * mag >= raw) ?? 10
    return step * mag
})

const x = (i) => PAD.left + (props.rows.length <= 1 ? plotW.value / 2 : (i / (props.rows.length - 1)) * plotW.value)
const y = (v) => PAD.top + plotH.value - ((Number(v) || 0) / yMax.value) * plotH.value

const paths = computed(() =>
    lines.value.map((s) => ({
        ...s,
        d: props.rows.map((r, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(r[s.key]).toFixed(1)}`).join(' '),
    })),
)

const gridValues = computed(() => [0, 0.25, 0.5, 0.75, 1].map((f) => f * yMax.value))

/** Three date ticks: first, middle, last — enough to orient, never crowded. */
const xTicks = computed(() => {
    const n = props.rows.length
    if (n === 0) return []
    const idx = [...new Set([0, Math.floor((n - 1) / 2), n - 1])]
    return idx.map((i) => ({ i, label: props.rows[i].date.slice(5) }))
})

/** Line-end labels, nudged apart so two close lines never overlap. */
const endLabels = computed(() => {
    const last = props.rows.length - 1
    if (last < 0) return []
    const labels = lines.value
        .map((s) => ({ ...s, y: y(props.rows[last][s.key]) }))
        .sort((a, b) => a.y - b.y)
    for (let i = 1; i < labels.length; i++) {
        if (labels[i].y - labels[i - 1].y < 13) labels[i].y = labels[i - 1].y + 13
    }
    return labels
})

function onMove(event) {
    if (props.rows.length === 0) return
    const box = event.currentTarget.getBoundingClientRect()
    const px = ((event.clientX - box.left) / box.width) * width.value
    const ratio = (px - PAD.left) / Math.max(1, plotW.value)
    const i = Math.round(Math.min(1, Math.max(0, ratio)) * (props.rows.length - 1))
    hover.value = i
}

const tooltip = computed(() => {
    if (hover.value === null || !props.rows[hover.value]) return null
    const row = props.rows[hover.value]
    const left = x(hover.value)
    return {
        row,
        left,
        // Flip to the other side of the crosshair near the right edge.
        alignRight: left > width.value * 0.6,
    }
})
</script>

<template>
    <figure ref="root" class="relative w-full">
        <div v-if="lines.length > 1" class="mb-2 flex flex-wrap gap-4 text-xs text-ink-muted">
            <span v-for="s in lines" :key="s.key" class="inline-flex items-center gap-1.5">
                <span class="inline-block h-0.5 w-4 rounded-full" :style="{ background: s.color }" />
                {{ s.label }}
            </span>
        </div>

        <svg
            :viewBox="`0 0 ${width} ${height}`"
            :width="width"
            :height="height"
            role="img"
            :aria-label="title"
            class="block max-w-full"
            @mousemove="onMove"
            @mouseleave="hover = null"
        >
            <!-- Recessive grid and y labels -->
            <g>
                <line
                    v-for="v in gridValues"
                    :key="v"
                    :x1="PAD.left"
                    :x2="width - PAD.right"
                    :y1="y(v)"
                    :y2="y(v)"
                    stroke="#2C1738"
                    stroke-width="1"
                />
                <text
                    v-for="v in gridValues"
                    :key="`l${v}`"
                    :x="PAD.left - 8"
                    :y="y(v) + 3"
                    text-anchor="end"
                    class="fill-ink-faint"
                    font-size="10"
                >{{ format(v) }}</text>
                <text
                    v-for="t in xTicks"
                    :key="`x${t.i}`"
                    :x="x(t.i)"
                    :y="height - 8"
                    :text-anchor="t.i === 0 ? 'start' : t.i === rows.length - 1 ? 'end' : 'middle'"
                    class="fill-ink-faint"
                    font-size="10"
                >{{ t.label }}</text>
            </g>

            <!-- Series -->
            <path
                v-for="p in paths"
                :key="p.key"
                :d="p.d"
                fill="none"
                :stroke="p.color"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
            />

            <!-- Direct labels at the line ends (text in ink, not series colour) -->
            <g v-for="l in endLabels" :key="`e${l.key}`">
                <circle :cx="x(rows.length - 1)" :cy="y(rows[rows.length - 1][l.key])" r="3" :fill="l.color" />
                <text :x="width - PAD.right + 8" :y="l.y + 3" font-size="10" class="fill-ink-muted">{{ l.label }}</text>
            </g>

            <!-- Hover crosshair -->
            <g v-if="tooltip">
                <line
                    :x1="tooltip.left"
                    :x2="tooltip.left"
                    :y1="PAD.top"
                    :y2="PAD.top + plotH"
                    stroke="#A694B5"
                    stroke-width="1"
                    stroke-dasharray="3 3"
                />
                <circle
                    v-for="s in lines"
                    :key="`h${s.key}`"
                    :cx="tooltip.left"
                    :cy="y(tooltip.row[s.key])"
                    r="4"
                    :fill="s.color"
                    stroke="#150A1C"
                    stroke-width="2"
                />
            </g>

            <!-- Hit area bigger than the marks -->
            <rect :x="PAD.left" :y="0" :width="plotW" :height="height" fill="transparent" />
        </svg>

        <div
            v-if="tooltip"
            class="pointer-events-none absolute top-6 z-10 rounded-lg border border-surface-border bg-surface-overlay px-3 py-2 text-xs shadow-lg"
            :style="tooltip.alignRight
                ? { right: `${width - tooltip.left + 10}px` }
                : { left: `${tooltip.left + 10}px` }"
        >
            <p class="mb-1 font-semibold text-ink">{{ tooltip.row.date }}</p>
            <p v-for="s in lines" :key="`t${s.key}`" class="flex items-center gap-2 text-ink-muted">
                <span class="inline-block h-2 w-2 rounded-full" :style="{ background: s.color }" />
                {{ s.label }}
                <span class="ml-auto pl-3 font-semibold text-ink">{{ format(tooltip.row[s.key]) }}</span>
            </p>
        </div>

        <details class="mt-2 text-xs">
            <summary class="cursor-pointer text-ink-faint">Show as table</summary>
            <div class="mt-2 max-h-56 overflow-auto">
                <table class="w-full text-left">
                    <caption class="sr-only">{{ title }}</caption>
                    <thead class="text-ink-faint">
                        <tr>
                            <th class="py-1">Date</th>
                            <th v-for="s in lines" :key="`th${s.key}`" class="py-1 text-right">{{ s.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.date" class="border-t border-white/5">
                            <td class="py-1 text-ink-muted">{{ r.date }}</td>
                            <td v-for="s in lines" :key="`td${s.key}`" class="py-1 text-right text-ink">{{ format(r[s.key]) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </figure>
</template>
