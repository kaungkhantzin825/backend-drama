<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import LineChart from '@/Components/LineChart.vue'
import { useAdminApi } from '@/lib/adminApi'

/**
 * Traffic monitoring: user activity, API traffic, ad waterfall performance,
 * A/B experiment comparison, and the suspicious-traffic review queue.
 */
const api = useAdminApi()

const tabs = [
    { key: 'activity', label: 'Activity' },
    { key: 'api', label: 'API' },
    { key: 'ads', label: 'Ads' },
    { key: 'ab', label: 'A/B test' },
    { key: 'flags', label: 'Suspicious' },
]
const ranges = [7, 30, 90]

const tab = ref('activity')
const days = ref(7)
const data = ref({})
const loading = ref(false)
const error = ref('')
const notice = ref('')

const exportReport = computed(() => ({ activity: 'activity', api: 'api', ads: 'waterfall', ab: 'ab' })[tab.value])

async function load() {
    if (tab.value === 'flags') return loadFlags(1)

    loading.value = true
    error.value = ''
    try {
        data.value[tab.value] = (await api.get(`/admin/api/traffic/${tab.value}?days=${days.value}`)).data
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
}

watch([tab, days], load)
onMounted(load)

const current = computed(() => data.value[tab.value])
const num = (v) => Number(v ?? 0).toLocaleString()
const pct = (v) => `${Number(v ?? 0).toFixed(1)}%`
const usd = (v) => `$${Number(v ?? 0).toFixed(2)}`

// ─── Suspicious traffic ──────────────────────────────────────────────────────

const flags = ref([])
const flagMeta = ref({ current_page: 1, last_page: 1 })
const flagStatus = ref('open')

async function loadFlags(page = 1) {
    error.value = ''
    try {
        const query = new URLSearchParams({ page: String(page) })
        if (flagStatus.value) query.set('status', flagStatus.value)
        const res = await api.get(`/admin/api/traffic/flags?${query}`)
        flags.value = res.data.data
        flagMeta.value = res.meta
    } catch (e) {
        error.value = e.message
    }
}

async function act(flag, action) {
    if (action === 'block' && !confirm(`Block ${flag.subject_type} ${flag.subject}?`)) return
    try {
        notice.value = (await api.post(`/admin/api/traffic/flags/${flag.id}/${action}`)).message
        await loadFlags(flagMeta.value.current_page)
    } catch (e) {
        error.value = e.message
    }
}

async function runScan() {
    try {
        notice.value = (await api.post('/admin/api/traffic/scan')).message
        await loadFlags(1)
    } catch (e) {
        error.value = e.message
    }
}

const severityClass = (s) => ({
    high: 'bg-brand/15 text-brand-soft',
    medium: 'bg-cash/15 text-cash-soft',
    low: 'bg-white/5 text-ink-muted',
})[s]
</script>

<template>
    <AdminLayout>
        <h1 class="font-display text-3xl font-bold tracking-tight">Traffic</h1>
        <p class="mt-2 text-sm text-ink-muted">Who is using the app, how the API is holding up, how ads perform — and who looks suspicious.</p>

        <p v-if="error" class="mt-4 rounded-xl bg-brand/10 px-4 py-3 text-sm text-brand-soft">{{ error }}</p>
        <p v-if="notice" class="mt-4 rounded-xl bg-white/5 px-4 py-3 text-sm text-ink-muted">{{ notice }}</p>

        <!-- Tabs, range and export: one row above the charts -->
        <div class="mt-6 flex flex-wrap items-center gap-2">
            <button
                v-for="t in tabs"
                :key="t.key"
                class="chip"
                :class="{ 'chip-active': tab === t.key }"
                @click="tab = t.key"
            >
                {{ t.label }}
                <span v-if="t.key === 'flags' && data.activity?.headline?.open_flags" class="ml-1 rounded-full bg-brand px-1.5 text-[10px] text-white">
                    {{ data.activity.headline.open_flags }}
                </span>
            </button>

            <div v-if="tab !== 'flags'" class="ml-auto flex items-center gap-2">
                <select v-model.number="days" class="field-input w-auto text-xs">
                    <option v-for="r in ranges" :key="r" :value="r">Last {{ r }} days</option>
                </select>
                <a
                    class="btn-ghost text-xs"
                    :href="`/admin/api/traffic/export?report=${exportReport}&days=${days}`"
                >Export CSV</a>
            </div>
        </div>

        <p v-if="loading && !current" class="mt-6 text-sm text-ink-faint">Loading…</p>

        <!-- ─── Activity ─────────────────────────────────────────────────── -->
        <template v-if="tab === 'activity' && current">
            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">DAU today</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ num(current.summary.dau_today) }}</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">MAU (30 days)</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ num(current.summary.mau) }}</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">New today</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ num(current.summary.new_users_today) }}</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">Watch min / user</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ current.summary.avg_watch_minutes_per_user }}</p>
                    <p class="mt-1 text-xs text-ink-muted">{{ current.summary.avg_sessions_per_user }} sessions / user / day</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">Next-day return</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ pct(current.summary.next_day_return_rate) }}</p>
                </div>
            </div>

            <div class="card mt-6 p-6">
                <h2 class="section-title mb-3">Daily users</h2>
                <LineChart
                    title="Daily active and new users"
                    :rows="current.series"
                    :series="[{ key: 'dau', label: 'Active users' }, { key: 'new_users', label: 'New users' }]"
                />
            </div>

            <div class="card mt-6 p-6">
                <h2 class="section-title mb-3">Watch minutes</h2>
                <LineChart title="Minutes watched per day" :rows="current.series" :series="[{ key: 'watch_minutes', label: 'Minutes' }]" />
            </div>

            <div class="mt-6 grid gap-6 md:grid-cols-2">
                <div v-for="block in [['Platforms', current.platforms], ['App versions', current.versions]]" :key="block[0]" class="card p-6">
                    <h2 class="section-title mb-3">{{ block[0] }}</h2>
                    <table class="w-full text-left text-sm">
                        <tbody>
                            <tr v-for="row in block[1]" :key="row.value" class="border-t border-white/5">
                                <td class="py-2">{{ row.value }}</td>
                                <td class="py-2 text-right font-semibold">{{ num(row.users) }} users</td>
                            </tr>
                            <tr v-if="block[1].length === 0"><td class="py-4 text-center text-ink-faint">No data yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- ─── API ──────────────────────────────────────────────────────── -->
        <template v-if="tab === 'api' && current">
            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">Requests</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ num(current.summary.requests) }}</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">Per minute now</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ current.summary.requests_per_minute_now }}</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">Error rate</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ pct(current.summary.error_rate) }}</p>
                    <p class="mt-1 text-xs text-ink-muted">{{ pct(current.summary.server_error_rate) }} server (5xx)</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">Avg response</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ current.summary.avg_ms }} ms</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">Slow requests</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ num(current.summary.slow_requests) }}</p>
                </div>
            </div>

            <div class="card mt-6 p-6">
                <h2 class="section-title mb-3">Requests per day</h2>
                <LineChart title="API requests per day" :rows="current.series" :series="[{ key: 'requests', label: 'Requests' }]" />
            </div>

            <div class="card mt-6 p-6">
                <h2 class="section-title mb-3">Errors per day</h2>
                <LineChart
                    title="API errors per day"
                    :rows="current.series"
                    :series="[{ key: 'client_errors', label: '4xx' }, { key: 'server_errors', label: '5xx' }]"
                    :height="180"
                />
            </div>

            <div class="card mt-6 p-6">
                <h2 class="section-title mb-3">Busiest endpoints</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th class="py-2">Route</th>
                                <th class="py-2 text-right">Requests</th>
                                <th class="py-2 text-right">Avg ms</th>
                                <th class="py-2 text-right">Slow</th>
                                <th class="py-2 text-right">Errors</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in current.routes" :key="r.method + r.route" class="border-t border-white/5">
                                <td class="py-2"><span class="text-ink-faint">{{ r.method }}</span> {{ r.route }}</td>
                                <td class="py-2 text-right">{{ num(r.requests) }}</td>
                                <td class="py-2 text-right">{{ r.avg_ms }}</td>
                                <td class="py-2 text-right">{{ num(r.slow) }}</td>
                                <td class="py-2 text-right">{{ pct(r.error_rate) }}</td>
                            </tr>
                            <tr v-if="current.routes.length === 0"><td colspan="5" class="py-6 text-center text-ink-faint">No traffic yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- ─── Ads ──────────────────────────────────────────────────────── -->
        <template v-if="tab === 'ads' && current">
            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">Impressions</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ num(current.summary.impressions) }}</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">Fill rate</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ pct(current.summary.fill_rate) }}</p>
                    <p class="mt-1 text-xs text-ink-muted">{{ num(current.summary.requests) }} requests</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">CTR</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ pct(current.summary.ctr) }}</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">Revenue</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ usd(current.summary.revenue) }}</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs uppercase tracking-wide text-ink-faint">eCPM</p>
                    <p class="mt-2 font-display text-3xl font-bold">{{ usd(current.summary.ecpm) }}</p>
                </div>
            </div>

            <div class="card mt-6 p-6">
                <h2 class="section-title mb-3">Ad funnel per day</h2>
                <LineChart
                    title="Ad requests, fills and impressions per day"
                    :rows="current.series"
                    :series="[
                        { key: 'requests', label: 'Requests' },
                        { key: 'fills', label: 'Fills' },
                        { key: 'impressions', label: 'Impressions' },
                    ]"
                />
            </div>

            <div class="card mt-6 p-6">
                <h2 class="section-title mb-3">Revenue per day</h2>
                <LineChart title="Ad revenue per day (USD)" :rows="current.series" :series="[{ key: 'revenue', label: 'Revenue' }]" :format="usd" />
            </div>

            <div class="card mt-6 p-6">
                <h2 class="section-title">Waterfall performance</h2>
                <p class="mb-3 mt-1 text-xs text-ink-faint">
                    Each unit in the order it is tried. A tier with a low fill rate and many timeouts is costing you
                    latency — move it down or remove it on the Ads page.
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th class="py-2">Platform</th>
                                <th class="py-2">Format</th>
                                <th class="py-2">Tier</th>
                                <th class="py-2">Unit</th>
                                <th class="py-2 text-right">Requests</th>
                                <th class="py-2 text-right">Fill rate</th>
                                <th class="py-2 text-right">Timeouts</th>
                                <th class="py-2 text-right">Impr.</th>
                                <th class="py-2 text-right">CTR</th>
                                <th class="py-2 text-right">eCPM</th>
                                <th class="py-2 text-right">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="w in current.waterfall" :key="w.platform + w.format + w.tier + w.unit_id" class="border-t border-white/5">
                                <td class="py-2">{{ w.platform }}</td>
                                <td class="py-2 capitalize">{{ w.format }}</td>
                                <td class="py-2">#{{ w.tier }}</td>
                                <td class="py-2 font-mono text-xs text-ink-muted">{{ w.unit_id }}</td>
                                <td class="py-2 text-right">{{ num(w.requests) }}</td>
                                <td class="py-2 text-right">{{ pct(w.fill_rate) }}</td>
                                <td class="py-2 text-right">{{ num(w.timeouts) }}</td>
                                <td class="py-2 text-right">{{ num(w.impressions) }}</td>
                                <td class="py-2 text-right">{{ pct(w.ctr) }}</td>
                                <td class="py-2 text-right">{{ usd(w.ecpm) }}</td>
                                <td class="py-2 text-right font-semibold text-cash">{{ usd(w.revenue) }}</td>
                            </tr>
                            <tr v-if="current.waterfall.length === 0"><td colspan="11" class="py-6 text-center text-ink-faint">No ad traffic reported yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mt-6 p-6">
                <h2 class="section-title mb-3">By placement</h2>
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="py-2">Format</th>
                            <th class="py-2">Placement</th>
                            <th class="py-2 text-right">Impressions</th>
                            <th class="py-2 text-right">Clicks</th>
                            <th class="py-2 text-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in current.placements" :key="p.format + p.placement" class="border-t border-white/5">
                            <td class="py-2 capitalize">{{ p.format }}</td>
                            <td class="py-2">{{ p.placement }}</td>
                            <td class="py-2 text-right">{{ num(p.impressions) }}</td>
                            <td class="py-2 text-right">{{ num(p.clicks) }}</td>
                            <td class="py-2 text-right">{{ usd(p.revenue) }}</td>
                        </tr>
                        <tr v-if="current.placements.length === 0"><td colspan="5" class="py-6 text-center text-ink-faint">No impressions yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ─── A/B test ─────────────────────────────────────────────────── -->
        <template v-if="tab === 'ab' && current">
            <div class="card mt-6 p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="section-title">Experiment: {{ current.experiment.name }}</h2>
                    <span class="chip" :class="{ 'chip-active': current.experiment.enabled }">
                        {{ current.experiment.enabled ? 'Running' : 'Stopped' }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-ink-faint">
                    Variants and their overrides are edited on the Ads page. Compare revenue per user (ARPDAU) against
                    engagement — a variant that earns more but loses watch time or return rate is not a win.
                </p>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th class="py-2">Variant</th>
                                <th class="py-2 text-right">Users</th>
                                <th class="py-2 text-right">Sessions / day</th>
                                <th class="py-2 text-right">Watch min / day</th>
                                <th class="py-2 text-right">Impr. / day</th>
                                <th class="py-2 text-right">Next-day return</th>
                                <th class="py-2 text-right">Fill rate</th>
                                <th class="py-2 text-right">eCPM</th>
                                <th class="py-2 text-right">ARPDAU</th>
                                <th class="py-2 text-right">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="v in current.variants" :key="v.group" class="border-t border-white/5">
                                <td class="py-2 font-semibold">{{ v.group }}</td>
                                <td class="py-2 text-right">{{ num(v.users) }}</td>
                                <td class="py-2 text-right">{{ v.sessions_per_user_day }}</td>
                                <td class="py-2 text-right">{{ v.watch_minutes_per_user_day }}</td>
                                <td class="py-2 text-right">{{ v.impressions_per_user_day }}</td>
                                <td class="py-2 text-right">{{ pct(v.next_day_return_rate) }}</td>
                                <td class="py-2 text-right">{{ pct(v.fill_rate) }}</td>
                                <td class="py-2 text-right">{{ usd(v.ecpm) }}</td>
                                <td class="py-2 text-right">${{ Number(v.arpdau).toFixed(4) }}</td>
                                <td class="py-2 text-right font-semibold text-cash">{{ usd(v.revenue) }}</td>
                            </tr>
                            <tr v-if="current.variants.length === 0">
                                <td colspan="10" class="py-6 text-center text-ink-faint">No variant traffic yet — start the experiment on the Ads page.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- ─── Suspicious ───────────────────────────────────────────────── -->
        <template v-if="tab === 'flags'">
            <div class="card mt-6 p-6">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="section-title">Flags</h2>
                    <div class="flex items-center gap-2">
                        <select v-model="flagStatus" class="field-input w-auto text-xs" @change="loadFlags(1)">
                            <option value="open">Needs review</option>
                            <option value="blocked">Blocked</option>
                            <option value="dismissed">Dismissed</option>
                            <option value="">All</option>
                        </select>
                        <button class="btn-ghost text-xs" @click="runScan">Run scan now</button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th class="py-2">Severity</th>
                                <th class="py-2">Reason</th>
                                <th class="py-2">Subject</th>
                                <th class="py-2">Details</th>
                                <th class="py-2 text-right">Hits</th>
                                <th class="py-2">Last seen</th>
                                <th class="py-2 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="f in flags" :key="f.id" class="border-t border-white/5 align-top">
                                <td class="py-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize" :class="severityClass(f.severity)">
                                        {{ f.severity }}
                                    </span>
                                </td>
                                <td class="py-2">{{ f.reason_label }}</td>
                                <td class="py-2">
                                    <span class="text-ink-faint">{{ f.subject_type }}</span>
                                    <span class="ml-1 font-mono text-xs">{{ f.subject }}</span>
                                    <span v-if="f.user" class="block text-xs text-ink-faint">{{ f.user.name }}</span>
                                </td>
                                <td class="py-2 text-xs text-ink-muted">
                                    <span v-for="(v, k) in f.details" :key="k" class="mr-2 inline-block">{{ k }}: <b class="text-ink">{{ v }}</b></span>
                                </td>
                                <td class="py-2 text-right">{{ f.hits }}</td>
                                <td class="py-2 text-xs text-ink-faint">{{ f.last_seen_at }}</td>
                                <td class="py-2 text-right">
                                    <div class="flex justify-end gap-1">
                                        <template v-if="f.status === 'open'">
                                            <button class="btn-ghost text-xs" @click="act(f, 'dismiss')">Dismiss</button>
                                            <button class="btn-brand text-xs" @click="act(f, 'block')">Block</button>
                                        </template>
                                        <button v-else-if="f.status === 'blocked'" class="btn-ghost text-xs" @click="act(f, 'unblock')">Unblock</button>
                                        <span v-else class="chip">{{ f.status_label }}</span>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="flags.length === 0">
                                <td colspan="7" class="py-6 text-center text-ink-faint">Nothing suspicious here.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex items-center justify-between text-sm">
                    <span class="text-ink-faint">Page {{ flagMeta.current_page }} of {{ flagMeta.last_page }}</span>
                    <div class="flex gap-2">
                        <button class="btn-ghost text-xs" :disabled="flagMeta.current_page <= 1" @click="loadFlags(flagMeta.current_page - 1)">Prev</button>
                        <button class="btn-ghost text-xs" :disabled="flagMeta.current_page >= flagMeta.last_page" @click="loadFlags(flagMeta.current_page + 1)">Next</button>
                    </div>
                </div>
            </div>
        </template>
    </AdminLayout>
</template>
