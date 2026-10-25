<script setup>
import { onMounted, ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminApi } from '@/lib/adminApi'

const api = useAdminApi()
const users = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const q = ref('')
const banned = ref('')
const error = ref('')

async function load(page = 1) {
    error.value = ''
    try {
        const query = new URLSearchParams({ page: String(page) })
        if (q.value) query.set('q', q.value)
        if (banned.value !== '') query.set('banned', banned.value)
        const res = await api.get(`/admin/api/users?${query}`)
        users.value = res.data.data
        meta.value = res.meta
    } catch (e) {
        error.value = e.message
    }
}

async function toggleBan(user) {
    try {
        if (user.is_banned) {
            await api.del(`/admin/api/users/${user.id}/ban`)
        } else {
            const reason = window.prompt('Reason for banning?', 'Suspected fraud')
            if (!reason) return
            await api.post(`/admin/api/users/${user.id}/ban`, { reason })
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
        <h1 class="font-display text-3xl font-bold tracking-tight">Users</h1>
        <p class="mt-2 text-sm text-ink-muted">Mobile accounts, balances and bans.</p>

        <p v-if="error" class="mt-4 rounded-xl bg-brand/10 px-4 py-3 text-sm text-brand-soft">{{ error }}</p>

        <div class="card mt-6 p-6">
            <div class="mb-4 flex flex-wrap gap-3">
                <input v-model="q" class="field-input max-w-xs" placeholder="Search name / email / device" @keyup.enter="load(1)" />
                <select v-model="banned" class="field-input max-w-[160px]" @change="load(1)">
                    <option value="">All</option>
                    <option value="1">Banned</option>
                    <option value="0">Active</option>
                </select>
                <button class="btn-brand text-sm" @click="load(1)">Search</button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="py-2">User</th>
                            <th class="py-2">Balance</th>
                            <th class="py-2">Earned</th>
                            <th class="py-2">Last seen</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="u in users" :key="u.id" class="border-t border-white/5">
                            <td class="py-3">
                                <p class="font-medium">{{ u.name }}</p>
                                <p class="text-xs text-ink-faint">{{ u.email ?? u.device_id ?? 'guest' }}</p>
                            </td>
                            <td class="py-3 font-semibold text-cash">{{ u.points_balance.toLocaleString() }}</td>
                            <td class="py-3 text-ink-muted">{{ u.total_points_earned.toLocaleString() }}</td>
                            <td class="py-3 text-xs text-ink-faint">{{ u.last_seen_at ?? '—' }}</td>
                            <td class="py-3 text-right">
                                <button class="btn-ghost text-xs" @click="toggleBan(u)">
                                    {{ u.is_banned ? 'Unban' : 'Ban' }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="users.length === 0">
                            <td colspan="5" class="py-6 text-center text-ink-faint">No users found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-between text-sm">
                <span class="text-ink-faint">{{ meta.total }} users · page {{ meta.current_page }}/{{ meta.last_page }}</span>
                <div class="flex gap-2">
                    <button class="btn-ghost text-xs" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">Prev</button>
                    <button class="btn-ghost text-xs" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Next</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
