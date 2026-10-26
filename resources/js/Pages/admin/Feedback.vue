<script setup>
import { onMounted, ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminApi } from '@/lib/adminApi'

/** Feedback sent from the app, and the address each message is emailed to. */
const api = useAdminApi()
const rows = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const unreadOnly = ref(false)
const email = ref('')
const unread = ref(0)
const error = ref('')
const notice = ref('')

async function loadSettings() {
    try {
        const data = (await api.get('/admin/api/feedback/settings')).data
        email.value = data.email ?? ''
        unread.value = data.unread
    } catch (e) {
        error.value = e.message
    }
}

async function load(page = 1) {
    error.value = ''
    try {
        const query = new URLSearchParams({ page: String(page) })
        if (unreadOnly.value) query.set('unread', '1')
        const res = await api.get(`/admin/api/feedback?${query}`)
        rows.value = res.data.data
        meta.value = res.meta
    } catch (e) {
        error.value = e.message
    }
}

async function saveEmail() {
    error.value = ''
    notice.value = ''
    try {
        const res = await api.put('/admin/api/feedback/settings', { email: email.value || null })
        email.value = res.data.email ?? ''
        notice.value = res.message
    } catch (e) {
        error.value = e.message
    }
}

async function markRead(row) {
    try {
        await api.post(`/admin/api/feedback/${row.id}/read`, {})
        row.is_read = true
        unread.value = Math.max(0, unread.value - 1)
    } catch (e) {
        error.value = e.message
    }
}

onMounted(() => {
    loadSettings()
    load()
})
</script>

<template>
    <AdminLayout>
        <h1 class="font-display text-3xl font-bold tracking-tight">Feedback</h1>
        <p class="mt-2 text-sm text-ink-muted">Messages from the app's “Send feedback” screen. {{ unread }} unread.</p>

        <p v-if="error" class="mt-4 rounded-xl bg-brand/10 px-4 py-3 text-sm text-brand-soft">{{ error }}</p>
        <p v-if="notice" class="mt-4 rounded-xl bg-success/10 px-4 py-3 text-sm text-success">{{ notice }}</p>

        <div class="card mt-6 p-6">
            <h2 class="section-title">Email notifications</h2>
            <p class="mt-1 text-xs text-ink-faint">Every new message is emailed here. Leave empty to only keep them in this page.</p>
            <form class="mt-4 flex flex-wrap gap-3" @submit.prevent="saveEmail">
                <input v-model="email" type="email" class="field-input max-w-sm" placeholder="support@yourdomain.com" />
                <button class="btn-brand text-sm">Save</button>
            </form>
        </div>

        <div class="card mt-6 p-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="section-title">Inbox <span class="text-ink-faint">({{ meta.total }})</span></h2>
                <label class="flex items-center gap-2 text-sm text-ink-muted">
                    <input v-model="unreadOnly" type="checkbox" class="h-4 w-4" @change="load(1)" /> Unread only
                </label>
            </div>

            <div class="space-y-3">
                <div
                    v-for="f in rows"
                    :key="f.id"
                    class="rounded-xl border border-white/5 p-4"
                    :class="f.is_read ? 'opacity-70' : 'bg-white/[0.03]'"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="chip" :class="f.is_read ? '' : 'chip-active'">{{ f.category_label }}</span>
                            <span class="text-xs text-ink-faint">{{ new Date(f.created_at).toLocaleString() }}</span>
                        </div>
                        <button v-if="!f.is_read" class="btn-ghost text-xs" @click="markRead(f)">Mark read</button>
                    </div>
                    <p class="mt-3 whitespace-pre-wrap text-sm">{{ f.message }}</p>
                    <p class="mt-3 text-xs text-ink-faint">
                        {{ f.user?.name ?? 'Guest' }} · contact: {{ f.contact || '—' }} ·
                        {{ f.platform || '—' }} {{ f.app_version || '' }} ·
                        {{ f.emailed ? 'emailed' : 'not emailed' }}
                    </p>
                </div>
                <p v-if="rows.length === 0" class="py-6 text-center text-sm text-ink-faint">No feedback yet.</p>
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
