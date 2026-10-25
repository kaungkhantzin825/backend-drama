<script setup>
import { onMounted, ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminApi } from '@/lib/adminApi'

/** Overview screen: live counters from /admin/api/stats. */
const api = useAdminApi()
const stats = ref(null)
const error = ref('')

onMounted(async () => {
    try {
        stats.value = (await api.get('/admin/api/stats')).data
    } catch (e) {
        error.value = e.message
    }
})

const money = (cents) => `$${(cents / 100).toFixed(2)}`
</script>

<template>
    <AdminLayout>
        <h1 class="font-display text-3xl font-bold tracking-tight">Overview</h1>
        <p class="mt-2 text-sm text-ink-muted">Live numbers for the whole platform.</p>

        <p v-if="error" class="mt-4 rounded-xl bg-brand/10 px-4 py-3 text-sm text-brand-soft">{{ error }}</p>

        <div v-if="stats" class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-5">
                <p class="text-xs uppercase tracking-wide text-ink-faint">Users</p>
                <p class="mt-2 font-display text-3xl font-bold">{{ stats.users.total }}</p>
                <p class="mt-1 text-xs text-ink-muted">+{{ stats.users.new_today }} today · {{ stats.users.banned }} banned</p>
            </div>
            <div class="card p-5">
                <p class="text-xs uppercase tracking-wide text-ink-faint">Videos</p>
                <p class="mt-2 font-display text-3xl font-bold">{{ stats.content.dramas }}</p>
                <p class="mt-1 text-xs text-ink-muted">{{ stats.content.published }} published</p>
            </div>
            <div class="card p-5">
                <p class="text-xs uppercase tracking-wide text-ink-faint">Points outstanding</p>
                <p class="mt-2 font-display text-3xl font-bold text-cash">{{ stats.economy.points_outstanding.toLocaleString() }}</p>
                <p class="mt-1 text-xs text-ink-muted">+{{ stats.economy.earned_today }} / −{{ stats.economy.spent_today }} today</p>
            </div>
            <div class="card p-5">
                <p class="text-xs uppercase tracking-wide text-ink-faint">Withdrawals</p>
                <p class="mt-2 font-display text-3xl font-bold text-brand-soft">{{ stats.withdrawals.pending }}</p>
                <p class="mt-1 text-xs text-ink-muted">pending · {{ money(stats.withdrawals.paid_total_cents) }} paid</p>
            </div>
        </div>

        <div v-if="stats" class="card mt-6 p-6">
            <h2 class="section-title mb-4">Ads today</h2>
            <div class="flex flex-wrap gap-8 text-sm">
                <div>
                    <p class="text-ink-faint">Reported</p>
                    <p class="font-display text-2xl font-bold">{{ stats.ads.today }}</p>
                </div>
                <div>
                    <p class="text-ink-faint">Verified</p>
                    <p class="font-display text-2xl font-bold text-success">{{ stats.ads.verified_today }}</p>
                </div>
                <div>
                    <p class="text-ink-faint">Ledger entries</p>
                    <p class="font-display text-2xl font-bold">{{ stats.economy.ledger_entries }}</p>
                </div>
            </div>
        </div>

        <div v-else-if="!error" class="mt-6 text-sm text-ink-faint">Loading…</div>
    </AdminLayout>
</template>
