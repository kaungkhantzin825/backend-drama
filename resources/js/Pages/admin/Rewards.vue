<script setup>
import { onMounted, ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminApi } from '@/lib/adminApi'

/** Rewards: economy totals plus the raw immutable ledger. */
const api = useAdminApi()
const stats = ref(null)
const entries = ref([])
const meta = ref({ current_page: 1, last_page: 1 })
const type = ref('')
const error = ref('')

async function loadStats() {
    try {
        stats.value = (await api.get('/admin/api/rewards/stats')).data
    } catch (e) {
        error.value = e.message
    }
}

async function loadLedger(page = 1) {
    try {
        const query = new URLSearchParams({ page: String(page) })
        if (type.value) query.set('type', type.value)
        const res = await api.get(`/admin/api/rewards/ledger?${query}`)
        entries.value = res.data.data
        meta.value = res.meta
    } catch (e) {
        error.value = e.message
    }
}

onMounted(() => {
    loadStats()
    loadLedger()
})
</script>

<template>
    <AdminLayout>
        <h1 class="font-display text-3xl font-bold tracking-tight">Rewards</h1>
        <p class="mt-2 text-sm text-ink-muted">Append-only points ledger. Entries are never edited.</p>

        <p v-if="error" class="mt-4 rounded-xl bg-brand/10 px-4 py-3 text-sm text-brand-soft">{{ error }}</p>

        <div v-if="stats" class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-5">
                <p class="text-xs uppercase tracking-wide text-ink-faint">Outstanding</p>
                <p class="mt-2 font-display text-2xl font-bold text-cash">{{ stats.points_outstanding.toLocaleString() }}</p>
            </div>
            <div class="card p-5">
                <p class="text-xs uppercase tracking-wide text-ink-faint">Earned today</p>
                <p class="mt-2 font-display text-2xl font-bold text-success">+{{ stats.earned_today.toLocaleString() }}</p>
            </div>
            <div class="card p-5">
                <p class="text-xs uppercase tracking-wide text-ink-faint">Spent today</p>
                <p class="mt-2 font-display text-2xl font-bold text-brand-soft">−{{ stats.spent_today.toLocaleString() }}</p>
            </div>
            <div class="card p-5">
                <p class="text-xs uppercase tracking-wide text-ink-faint">Ads verified</p>
                <p class="mt-2 font-display text-2xl font-bold">{{ stats.ads_verified_today }}</p>
            </div>
        </div>

        <div v-if="stats" class="card mt-6 p-6">
            <h2 class="section-title mb-4">By type</h2>
            <div class="flex flex-wrap gap-6 text-sm">
                <div v-for="row in stats.by_type" :key="row.type">
                    <p class="text-ink-faint">{{ row.label }}</p>
                    <p class="font-semibold" :class="row.net >= 0 ? 'text-success' : 'text-brand-soft'">
                        {{ row.net > 0 ? '+' : '' }}{{ row.net.toLocaleString() }}
                        <span class="text-xs font-normal text-ink-faint">({{ row.entries }})</span>
                    </p>
                </div>
            </div>
        </div>

        <div class="card mt-6 p-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="section-title">Ledger</h2>
                <select v-model="type" class="field-input max-w-[220px]" @change="loadLedger(1)">
                    <option value="">All types</option>
                    <option v-for="row in stats?.by_type ?? []" :key="row.type" :value="row.type">{{ row.label }}</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="py-2">User</th>
                            <th class="py-2">Type</th>
                            <th class="py-2">Delta</th>
                            <th class="py-2">Balance</th>
                            <th class="py-2">Description</th>
                            <th class="py-2">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in entries" :key="e.id" class="border-t border-white/5">
                            <td class="py-2">{{ e.user?.name ?? '—' }}</td>
                            <td class="py-2 text-ink-muted">{{ e.type_label }}</td>
                            <td class="py-2 font-semibold" :class="e.delta > 0 ? 'text-success' : 'text-brand-soft'">
                                {{ e.delta > 0 ? '+' : '' }}{{ e.delta }}
                            </td>
                            <td class="py-2 text-cash">{{ e.balance_after }}</td>
                            <td class="py-2 text-xs text-ink-faint">{{ e.description }}</td>
                            <td class="py-2 text-xs text-ink-faint">{{ e.created_at }}</td>
                        </tr>
                        <tr v-if="entries.length === 0">
                            <td colspan="6" class="py-6 text-center text-ink-faint">No ledger entries yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-between text-sm">
                <span class="text-ink-faint">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <div class="flex gap-2">
                    <button class="btn-ghost text-xs" :disabled="meta.current_page <= 1" @click="loadLedger(meta.current_page - 1)">Prev</button>
                    <button class="btn-ghost text-xs" :disabled="meta.current_page >= meta.last_page" @click="loadLedger(meta.current_page + 1)">Next</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
