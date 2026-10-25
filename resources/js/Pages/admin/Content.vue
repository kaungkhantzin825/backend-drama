<script setup>
import { onMounted, reactive, ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminApi } from '@/lib/adminApi'

/** Content management: standalone videos and their publication state. */
const api = useAdminApi()

const videos = ref([])
const categories = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const page = ref(1)
const status = ref('')
const error = ref('')
const notice = ref('')

const blank = () => ({ title: '', category_id: '', video_url: '', duration_seconds: '', cover_image: '', synopsis: '' })

const form = reactive(blank())
const creating = ref(false)

const selected = ref(null)
const editForm = reactive(blank())
const saving = ref(false)

/** Form fields → API payload; empty strings become null. */
function payload(source) {
    return {
        title: source.title,
        category_id: source.category_id || null,
        video_url: source.video_url || null,
        duration_seconds: Number(source.duration_seconds) || 0,
        cover_image: source.cover_image || null,
        synopsis: source.synopsis || null,
    }
}

async function load() {
    error.value = ''
    try {
        const query = new URLSearchParams({ page: String(page.value) })
        if (status.value) query.set('status', status.value)
        const res = await api.get(`/admin/api/dramas?${query}`)
        videos.value = res.data.data
        meta.value = res.meta
    } catch (e) {
        error.value = e.message
    }
}

async function loadCategories() {
    try {
        categories.value = (await api.get('/admin/api/categories')).data
    } catch (e) {
        error.value = e.message
    }
}

async function createVideo() {
    creating.value = true
    error.value = ''
    try {
        await api.post('/admin/api/dramas', payload(form))
        notice.value = 'Video created as a draft.'
        Object.assign(form, blank())
        await load()
    } catch (e) {
        error.value = e.message
    } finally {
        creating.value = false
    }
}

async function publish(video) {
    try {
        await api.post(`/admin/api/dramas/${video.id}/publish`)
        notice.value = `“${video.title}” published.`
        await load()
    } catch (e) {
        error.value = e.message
    }
}

function edit(video) {
    selected.value = video
    Object.assign(editForm, {
        title: video.title,
        category_id: video.category_id ?? '',
        video_url: video.video_url ?? '',
        duration_seconds: video.duration_seconds || '',
        cover_image: video.cover_image ?? '',
        synopsis: video.synopsis ?? '',
    })
}

async function saveVideo() {
    if (!selected.value) return
    saving.value = true
    error.value = ''
    try {
        await api.patch(`/admin/api/dramas/${selected.value.id}`, payload(editForm))
        notice.value = 'Video saved.'
        selected.value = null
        await load()
    } catch (e) {
        error.value = e.message
    } finally {
        saving.value = false
    }
}

async function removeVideo(video) {
    if (!window.confirm(`Delete “${video.title}”? Its watch history goes with it.`)) return
    try {
        await api.del(`/admin/api/dramas/${video.id}`)
        notice.value = 'Video deleted.'
        if (selected.value?.id === video.id) selected.value = null
        await load()
    } catch (e) {
        error.value = e.message
    }
}

function go(next) {
    page.value = next
    load()
}

onMounted(() => {
    load()
    loadCategories()
})
</script>

<template>
    <AdminLayout>
        <h1 class="font-display text-3xl font-bold tracking-tight">Content</h1>
        <p class="mt-2 text-sm text-ink-muted">Videos shown in the app's swipe feed, and their publication state.</p>

        <p v-if="error" class="mt-4 rounded-xl bg-brand/10 px-4 py-3 text-sm text-brand-soft">{{ error }}</p>
        <p v-if="notice" class="mt-4 rounded-xl bg-success/10 px-4 py-3 text-sm text-success">{{ notice }}</p>

        <!-- Create -->
        <div class="card mt-6 p-6">
            <h2 class="section-title mb-4">New video</h2>
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="createVideo">
                <label class="block">
                    <span class="field-label">Title</span>
                    <input v-model="form.title" class="field-input" required placeholder="Married to My Billionaire Boss" />
                </label>
                <label class="block">
                    <span class="field-label">Category</span>
                    <select v-model="form.category_id" class="field-input">
                        <option value="">— none —</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="field-label">Video URL</span>
                    <input v-model="form.video_url" class="field-input" placeholder=".m3u8 / .mp4 / YouTube link" />
                </label>
                <label class="block">
                    <span class="field-label">Duration (seconds)</span>
                    <input v-model="form.duration_seconds" type="number" min="0" max="7200" class="field-input" placeholder="120" />
                </label>
                <label class="block sm:col-span-2">
                    <span class="field-label">Cover image URL</span>
                    <input v-model="form.cover_image" class="field-input" placeholder="https://…/cover.jpg" />
                </label>
                <label class="block sm:col-span-2">
                    <span class="field-label">Synopsis</span>
                    <textarea v-model="form.synopsis" class="field-input" rows="2"></textarea>
                </label>
                <div class="sm:col-span-2">
                    <button class="btn-brand" :disabled="creating">{{ creating ? 'Creating…' : 'Create draft' }}</button>
                </div>
            </form>
        </div>

        <!-- List -->
        <div class="card mt-6 p-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="section-title">Videos <span class="text-ink-faint">({{ meta.total }})</span></h2>
                <select v-model="status" class="field-input max-w-[180px]" @change="page = 1; load()">
                    <option value="">All statuses</option>
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                    <option value="archived">Archived</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="py-2">Title</th>
                            <th class="py-2">Category</th>
                            <th class="py-2">Status</th>
                            <th class="py-2">Length</th>
                            <th class="py-2">Views</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="v in videos" :key="v.id" class="border-t border-white/5">
                            <td class="py-3">
                                <p class="font-medium">{{ v.title }}</p>
                                <p class="text-xs text-ink-faint">{{ v.slug }}</p>
                                <p v-if="!v.video_url" class="text-xs text-brand-soft">No video URL yet</p>
                            </td>
                            <td class="py-3 text-ink-muted">{{ v.category?.name ?? '—' }}</td>
                            <td class="py-3">
                                <span class="chip" :class="v.status === 'published' ? 'chip-active' : ''">{{ v.status }}</span>
                            </td>
                            <td class="py-3 text-ink-muted">{{ v.duration_label }}</td>
                            <td class="py-3 text-ink-muted">{{ v.view_count.toLocaleString() }}</td>
                            <td class="py-3">
                                <div class="flex justify-end gap-2">
                                    <button class="btn-ghost text-xs" @click="edit(v)">Edit</button>
                                    <button v-if="v.status !== 'published'" class="btn-brand text-xs" @click="publish(v)">
                                        Publish
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="videos.length === 0">
                            <td colspan="6" class="py-6 text-center text-ink-faint">No videos yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center justify-between text-sm">
                <span class="text-ink-faint">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <div class="flex gap-2">
                    <button class="btn-ghost text-xs" :disabled="meta.current_page <= 1" @click="go(meta.current_page - 1)">Prev</button>
                    <button class="btn-ghost text-xs" :disabled="meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">Next</button>
                </div>
            </div>
        </div>

        <!-- Edit -->
        <div v-if="selected" class="card mt-6 p-6">
            <h2 class="section-title mb-4">Edit — {{ selected.title }}</h2>

            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveVideo">
                <label class="block">
                    <span class="field-label">Title</span>
                    <input v-model="editForm.title" class="field-input" required />
                </label>
                <label class="block">
                    <span class="field-label">Category</span>
                    <select v-model="editForm.category_id" class="field-input">
                        <option value="">— none —</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="field-label">Video URL</span>
                    <input v-model="editForm.video_url" class="field-input" placeholder=".m3u8 / .mp4 / YouTube link" />
                </label>
                <label class="block">
                    <span class="field-label">Duration (seconds)</span>
                    <input v-model="editForm.duration_seconds" type="number" min="0" max="7200" class="field-input" />
                </label>
                <label class="block sm:col-span-2">
                    <span class="field-label">Cover image URL</span>
                    <input v-model="editForm.cover_image" class="field-input" />
                </label>
                <label class="block sm:col-span-2">
                    <span class="field-label">Synopsis</span>
                    <textarea v-model="editForm.synopsis" class="field-input" rows="3"></textarea>
                </label>
                <div class="flex flex-wrap gap-2 sm:col-span-2">
                    <button class="btn-brand text-sm" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                    <button type="button" class="btn-ghost text-sm" @click="selected = null">Cancel</button>
                    <button type="button" class="btn-ghost ml-auto text-sm text-brand-soft" @click="removeVideo(selected)">Delete video</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
