<script setup>
import { onMounted, ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminApi } from '@/lib/adminApi'

/**
 * Release control: forced / optional updates and maintenance mode.
 * Saved into the same remote config the app reads on every launch.
 */
const api = useAdminApi()
const config = ref(null)
const saving = ref(false)
const error = ref('')
const notice = ref('')

onMounted(async () => {
    try {
        config.value = (await api.get('/admin/api/app-config')).data
    } catch (e) {
        error.value = e.message
    }
})

async function save() {
    const m = config.value.maintenance
    if (m.enabled && !confirm('Turn maintenance mode ON? Every app will stop working until you turn it off.')) return

    saving.value = true
    error.value = ''
    notice.value = ''
    try {
        const res = await api.put('/admin/api/app-config', {
            app_version: config.value.app_version,
            maintenance: m,
        })
        config.value = res.data
        notice.value = res.message
    } catch (e) {
        error.value = e.message
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <AdminLayout>
        <h1 class="font-display text-3xl font-bold tracking-tight">App settings</h1>
        <p class="mt-2 text-sm text-ink-muted">Control updates and maintenance without shipping a new build.</p>

        <p v-if="error" class="mt-4 rounded-xl bg-brand/10 px-4 py-3 text-sm text-brand-soft">{{ error }}</p>
        <p v-if="notice" class="mt-4 rounded-xl bg-white/5 px-4 py-3 text-sm text-ink-muted">{{ notice }}</p>

        <template v-if="config">
            <div class="card mt-6 p-6">
                <h2 class="section-title">App version</h2>
                <p class="mt-1 text-xs text-ink-faint">
                    Below the <b>minimum</b>, the app shows a blocking “Update required” screen and the API answers 426.
                    Below the <b>latest</b>, it offers an optional update once per launch.
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="field-label">Minimum supported version</span>
                        <input v-model="config.app_version.min_supported" class="field-input" placeholder="1.0.0" />
                    </label>
                    <label class="block">
                        <span class="field-label">Latest version</span>
                        <input v-model="config.app_version.latest" class="field-input" placeholder="1.2.0" />
                    </label>
                    <label class="block">
                        <span class="field-label">Play Store link</span>
                        <input v-model="config.app_version.update_url_android" class="field-input" placeholder="https://play.google.com/store/apps/details?id=…" />
                    </label>
                    <label class="block">
                        <span class="field-label">App Store link</span>
                        <input v-model="config.app_version.update_url_ios" class="field-input" placeholder="https://apps.apple.com/app/…" />
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="field-label">Update message</span>
                        <input v-model="config.app_version.update_message" class="field-input" maxlength="255" />
                    </label>
                </div>
            </div>

            <div class="card mt-6 p-6">
                <h2 class="section-title">Maintenance mode</h2>
                <p class="mt-1 text-xs text-ink-faint">
                    While on, every app shows this message and the API answers 503 (except the config endpoint).
                </p>

                <label class="mt-4 flex items-center gap-3">
                    <input v-model="config.maintenance.enabled" type="checkbox" class="h-4 w-4" />
                    <span class="text-sm" :class="config.maintenance.enabled ? 'font-semibold text-brand-soft' : ''">
                        {{ config.maintenance.enabled ? 'Maintenance is ON' : 'Maintenance is off' }}
                    </span>
                </label>
                <label class="mt-4 block">
                    <span class="field-label">Message shown in the app</span>
                    <input v-model="config.maintenance.message" class="field-input" maxlength="255" />
                </label>
            </div>

            <div class="mt-6 flex justify-end">
                <button class="btn-brand" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save settings' }}</button>
            </div>
        </template>
    </AdminLayout>
</template>
