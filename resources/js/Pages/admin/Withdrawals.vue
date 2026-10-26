<script setup>
import { onMounted, ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminApi } from '@/lib/adminApi'

/** Payout queue: approve, reject (refunds points) or mark paid. */
const api = useAdminApi()
const rows = ref([])
const meta = ref({ current_page: 1, last_page: 1 })
const status = ref('pending')
const error = ref('')

async function load(page = 1) {
    error.value = ''
    try {
        const query = new URLSearchParams({ page: String(page) })
        if (status.value) query.set('status', status.value)
        const res = await api.get(`/admin/api/withdrawals?${query}`)
        rows.value = res.data.data
        meta.value = res.meta
    } catch (e) {
        error.value = e.message
    }
}

async function act(row, action) {
    const who = `${row.display_amount} via ${row.method_name} to ${row.account_details?.account_number ?? '—'}`
    try {
        if (action === 'reject') {
            const note = window.prompt('Why is this being rejected? (the points go back to the user)', 'Insufficient verification')
            if (!note) return
            await api.post(`/admin/api/withdrawals/${row.id}/reject`, { note })
        } else if (action === 'approve') {
            if (!window.confirm(`Confirm this withdrawal?\n${who}\n\nThe user is told it is being prepared.`)) return
            await api.post(`/admin/api/withdrawals/${row.id}/approve`, {})
        } else {
            if (!window.confirm(`Mark as paid?\n${who}\n\nOnly do this after the money has been sent.`)) return
            await api.post(`/admin/api/withdrawals/${row.id}/paid`, {})
        }
        await load(meta.value.current_page)
    } catch (e) {
        error.value = e.message
    }
}

onMounted(() => load())
</script>

<template>
    <AdminLayout>
        <h1 class="font-display text-3xl font-bold tracking-tight">Withdrawals</h1>
        <p class="mt-2 text-sm text-ink-muted">
            Points are debited on request. Confirm a request, send the money, then mark it paid — or reject it to refund the points.
            The user gets a notification at every step.
        </p>

        <p v-if="error" class="mt-4 rounded-xl bg-brand/10 px-4 py-3 text-sm text-brand-soft">{{ error }}</p>

        <div class="card mt-6 p-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="section-title">Queue</h2>
                <select v-model="status" class="field-input max-w-[180px]" @change="load(1)">
                    <option value="">All</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="paid">Paid</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="py-2">User</th>
                            <th class="py-2">Amount</th>
                            <th class="py-2">Points</th>
                            <th class="py-2">Method</th>
                            <th class="py-2">Status</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="w in rows" :key="w.id" class="border-t border-white/5">
                            <td class="py-3">
                                <p class="font-medium">{{ w.user?.name ?? '—' }}</p>
                                <p class="text-xs text-ink-faint">{{ w.user?.email ?? '—' }}</p>
                            </td>
                            <td class="py-3 font-semibold text-cash">{{ w.display_amount }}</td>
                            <td class="py-3 text-ink-muted">{{ w.points_amount.toLocaleString() }}</td>
                            <td class="py-3 text-ink-muted">
                                {{ w.method_name }}
                                <span class="block text-xs text-ink-faint">{{ w.account_details?.account_name ?? '' }}</span>
                                <span class="block text-xs font-medium text-ink">
                                    {{ w.account_details?.account_number ?? w.account_details?.email ?? '' }}
                                </span>
                            </td>
                            <td class="py-3"><span class="chip">{{ w.status_label }}</span></td>
                            <td class="py-3">
                                <div v-if="w.status === 'pending'" class="flex justify-end gap-2">
                                    <button class="btn-brand text-xs" @click="act(w, 'approve')">Confirm</button>
                                    <button class="btn-ghost text-xs" @click="act(w, 'reject')">Reject</button>
                                </div>
                                <div v-else-if="w.status === 'approved'" class="flex justify-end">
                                    <button class="btn-brand text-xs" @click="act(w, 'paid')">Mark paid</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td colspan="6" class="py-6 text-center text-ink-faint">Nothing in the queue.</td>
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
