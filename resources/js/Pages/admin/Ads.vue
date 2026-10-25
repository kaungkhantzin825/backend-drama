<script setup>
import { onMounted, ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminApi } from '@/lib/adminApi'

/** Ad impressions — the fraud-monitoring surface. */
const api = useAdminApi()
const rows = ref([])
const meta = ref({ current_page: 1, last_page: 1 })
const status = ref('')
const error = ref('')

async function load(page = 1) {
    error.value = ''
    try {
        const query = new URLSearchParams({ page: String(page) })
        if (status.value) query.set('status', status.value)
        const res = await api.get(`/admin/api/ad-events?${query}`)
        rows.value = res.data.data
        meta.value = res.meta
    } catch (e) {
        error.value = e.message
    }
}

// ─── Remote ad config ────────────────────────────────────────────────────────
// What the app reads from GET /api/v1/app-config. Each format is a waterfall:
// the app tries the units top to bottom and keeps the first that fills.

const platforms = ['android', 'ios']
const formats = ['interstitial', 'rewarded', 'banner']

const config = ref(null)
const platform = ref('android')
const saving = ref(false)
const notice = ref('')

async function loadConfig() {
    try {
        config.value = normalise((await api.get('/admin/api/app-config')).data)
    } catch (e) {
        error.value = e.message
    }
}

/**
 * PHP encodes an empty overrides map as `[]`; an array would silently drop any
 * key the editor sets on it, so turn it back into an object.
 */
function normalise(c) {
    for (const v of c.ad_experiment.variants) {
        if (Array.isArray(v.overrides)) v.overrides = {}
    }
    return c
}

// ─── A/B experiment ──────────────────────────────────────────────────────────

function addVariant() {
    const used = config.value.ad_experiment.variants.map((v) => v.key)
    const key = ['A', 'B', 'C', 'D'].find((k) => !used.includes(k))
    if (key) config.value.ad_experiment.variants.push({ key, weight: 50, overrides: {} })
}

function removeVariant(index) {
    config.value.ad_experiment.variants.splice(index, 1)
}

/** Drop blank override fields so "inherit the base config" is sent as absent. */
function cleanExperiment(exp) {
    return {
        ...exp,
        variants: exp.variants.map((v) => {
            const overrides = { ...v.overrides }
            for (const [k, val] of Object.entries(overrides)) {
                if (val === '' || val === null || val === undefined) delete overrides[k]
            }
            if ('ad_interval_seconds' in overrides) overrides.ad_interval_seconds = Number(overrides.ad_interval_seconds)
            return { key: v.key, weight: Number(v.weight), overrides }
        }),
    }
}

function addUnit(format) {
    config.value.ad_units[platform.value][format].push({ unit_id: '', label: '' })
}

function removeUnit(format, index) {
    config.value.ad_units[platform.value][format].splice(index, 1)
}

function moveUnit(format, index, step) {
    const list = config.value.ad_units[platform.value][format]
    const target = index + step
    if (target < 0 || target >= list.length) return
    ;[list[index], list[target]] = [list[target], list[index]]
}

async function saveConfig() {
    saving.value = true
    error.value = ''
    notice.value = ''
    try {
        const c = config.value
        const res = await api.put('/admin/api/app-config', {
            is_ads_enabled: c.is_ads_enabled,
            ad_provider: c.ad_provider,
            ad_interval_seconds: Number(c.ad_interval_seconds),
            ad_load_timeout_seconds: Number(c.ad_load_timeout_seconds),
            ad_units: c.ad_units,
            ad_rules: c.ad_rules,
            ad_experiment: cleanExperiment(c.ad_experiment),
        })
        config.value = normalise(res.data)
        notice.value = res.message
    } catch (e) {
        error.value = e.message
    } finally {
        saving.value = false
    }
}

onMounted(() => {
    load()
    loadConfig()
})
</script>

<template>
    <AdminLayout>
        <h1 class="font-display text-3xl font-bold tracking-tight">Ads</h1>
        <p class="mt-2 text-sm text-ink-muted">Remote ad setup for the app, and every rewarded impression it reported.</p>

        <p v-if="error" class="mt-4 rounded-xl bg-brand/10 px-4 py-3 text-sm text-brand-soft">{{ error }}</p>
        <p v-if="notice" class="mt-4 rounded-xl bg-white/5 px-4 py-3 text-sm text-ink-muted">{{ notice }}</p>

        <div v-if="config" class="card mt-6 p-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="section-title">Ad configuration</h2>
                <span class="text-xs text-ink-faint">version {{ config.config_version }}</span>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <label class="flex items-center gap-3">
                    <input v-model="config.is_ads_enabled" type="checkbox" class="h-4 w-4" />
                    <span class="text-sm">Ads enabled</span>
                </label>
                <label class="block">
                    <span class="field-label">Seconds between interstitials</span>
                    <input v-model="config.ad_interval_seconds" type="number" min="0" max="3600" class="field-input" />
                </label>
                <label class="block">
                    <span class="field-label">Waterfall step timeout (s)</span>
                    <input v-model="config.ad_load_timeout_seconds" type="number" min="1" max="30" class="field-input" />
                </label>
            </div>

            <div class="mt-6 flex gap-2">
                <button
                    v-for="p in platforms"
                    :key="p"
                    class="chip"
                    :class="{ 'chip-active': platform === p }"
                    @click="platform = p"
                >
                    {{ p === 'ios' ? 'iOS' : 'Android' }}
                </button>
            </div>

            <div v-for="format in formats" :key="format" class="mt-5">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-sm font-semibold capitalize">{{ format }} waterfall</h3>
                    <button class="btn-ghost text-xs" :disabled="config.ad_units[platform][format].length >= 5" @click="addUnit(format)">
                        + Add unit
                    </button>
                </div>
                <p class="mb-2 text-xs text-ink-faint">Tried top to bottom — put the highest eCPM floor first.</p>

                <div
                    v-for="(unit, i) in config.ad_units[platform][format]"
                    :key="i"
                    class="mb-2 flex flex-wrap items-center gap-2"
                >
                    <span class="w-6 text-xs text-ink-faint">{{ i + 1 }}.</span>
                    <input v-model="unit.unit_id" class="field-input flex-1 font-mono text-xs" placeholder="ca-app-pub-XXXXXXXXXXXXXXXX/YYYYYYYYYY" />
                    <input v-model="unit.label" class="field-input max-w-[200px] text-xs" placeholder="Label (e.g. $5 floor)" />
                    <button class="btn-ghost text-xs" :disabled="i === 0" @click="moveUnit(format, i, -1)">↑</button>
                    <button class="btn-ghost text-xs" :disabled="i === config.ad_units[platform][format].length - 1" @click="moveUnit(format, i, 1)">↓</button>
                    <button class="btn-ghost text-xs" @click="removeUnit(format, i)">Remove</button>
                </div>
                <p v-if="config.ad_units[platform][format].length === 0" class="text-xs text-ink-faint">
                    No units — this format will not show on {{ platform }}.
                </p>
            </div>

            <!-- ─── Per-placement rules ─────────────────────────────────── -->
            <div class="mt-8 border-t border-white/5 pt-6">
                <h3 class="text-sm font-semibold">Placement rules</h3>
                <p class="mb-3 mt-1 text-xs text-ink-faint">Enforced by the app on top of the interval. 0 = no limit.</p>

                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="block">
                        <span class="field-label">First interstitial after N videos</span>
                        <input v-model.number="config.ad_rules.interstitial.first_after_episodes" type="number" min="0" max="50" class="field-input" />
                    </label>
                    <label class="block">
                        <span class="field-label">Interstitials per session (max)</span>
                        <input v-model.number="config.ad_rules.interstitial.max_per_session" type="number" min="0" class="field-input" />
                    </label>
                    <label class="block">
                        <span class="field-label">Interstitials per day (max)</span>
                        <input v-model.number="config.ad_rules.interstitial.max_per_day" type="number" min="0" class="field-input" />
                    </label>
                    <label class="block">
                        <span class="field-label">Rewarded ads per day (max)</span>
                        <input v-model.number="config.ad_rules.rewarded.max_per_day" type="number" min="0" class="field-input" />
                    </label>
                    <label class="flex items-center gap-3 sm:pt-6">
                        <input v-model="config.ad_rules.app_open.enabled" type="checkbox" class="h-4 w-4" />
                        <span class="text-sm">App-open ad</span>
                    </label>
                    <label class="flex items-center gap-3 sm:pt-6">
                        <input v-model="config.ad_rules.banner.enabled" type="checkbox" class="h-4 w-4" />
                        <span class="text-sm">Banner above tab bar</span>
                    </label>
                </div>
            </div>

            <!-- ─── A/B experiment ──────────────────────────────────────── -->
            <div class="mt-8 border-t border-white/5 pt-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">A/B experiment</h3>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="config.ad_experiment.enabled" type="checkbox" class="h-4 w-4" />
                        {{ config.ad_experiment.enabled ? 'Running' : 'Stopped' }}
                    </label>
                </div>
                <p class="mb-3 mt-1 text-xs text-ink-faint">
                    Each device is hashed into one variant and keeps it for the whole experiment. Leave a field blank to
                    use the base setting. Renaming the experiment reshuffles everyone. Results: Traffic → A/B test.
                </p>

                <label class="block max-w-xs">
                    <span class="field-label">Experiment name</span>
                    <input v-model="config.ad_experiment.name" class="field-input" placeholder="interval_test" />
                </label>

                <div v-for="(v, i) in config.ad_experiment.variants" :key="i" class="mt-3 flex flex-wrap items-end gap-3 rounded-xl bg-white/5 p-3">
                    <label class="block w-20">
                        <span class="field-label">Variant</span>
                        <input v-model="v.key" class="field-input" maxlength="16" />
                    </label>
                    <label class="block w-24">
                        <span class="field-label">Weight</span>
                        <input v-model.number="v.weight" type="number" min="1" max="100" class="field-input" />
                    </label>
                    <label class="block w-44">
                        <span class="field-label">Interval (s) override</span>
                        <input v-model="v.overrides.ad_interval_seconds" type="number" min="0" class="field-input" :placeholder="`base: ${config.ad_interval_seconds}`" />
                    </label>
                    <label class="block w-40">
                        <span class="field-label">Ads override</span>
                        <select v-model="v.overrides.is_ads_enabled" class="field-input">
                            <option :value="undefined">base</option>
                            <option :value="true">on</option>
                            <option :value="false">off</option>
                        </select>
                    </label>
                    <button class="btn-ghost text-xs" :disabled="config.ad_experiment.variants.length <= 2" @click="removeVariant(i)">Remove</button>
                </div>
                <button class="btn-ghost mt-3 text-xs" :disabled="config.ad_experiment.variants.length >= 4" @click="addVariant">+ Add variant</button>
            </div>

            <div class="mt-6 flex justify-end">
                <button class="btn-brand" :disabled="saving" @click="saveConfig">
                    {{ saving ? 'Saving…' : 'Save ad config' }}
                </button>
            </div>
        </div>

        <div class="card mt-6 p-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="section-title">Impressions</h2>
                <select v-model="status" class="field-input max-w-[180px]" @change="load(1)">
                    <option value="">All</option>
                    <option value="verified">Verified</option>
                    <option value="pending">Pending</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="py-2">User</th>
                            <th class="py-2">Placement</th>
                            <th class="py-2">Points</th>
                            <th class="py-2">Status</th>
                            <th class="py-2">IP</th>
                            <th class="py-2">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in rows" :key="e.id" class="border-t border-white/5">
                            <td class="py-2">
                                {{ e.user?.name ?? '—' }}
                                <span class="block text-xs text-ink-faint">{{ e.device_id ?? '' }}</span>
                            </td>
                            <td class="py-2 text-ink-muted">{{ e.placement }}</td>
                            <td class="py-2 font-semibold text-cash">+{{ e.reward_points }}</td>
                            <td class="py-2"><span class="chip">{{ e.status_label }}</span></td>
                            <td class="py-2 text-xs text-ink-faint">{{ e.ip_address }}</td>
                            <td class="py-2 text-xs text-ink-faint">{{ e.created_at }}</td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td colspan="6" class="py-6 text-center text-ink-faint">No ad events yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-between text-sm">
                <span class="text-ink-faint">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <div class="flex gap-2">
                    <button class="btn-ghost text-xs" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">Prev</button>
                    <button class="btn-ghost text-xs" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Next</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
