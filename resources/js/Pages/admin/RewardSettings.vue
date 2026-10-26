<script setup>
import { computed, onMounted, ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminApi } from '@/lib/adminApi'

/**
 * The rewards economy: watch minutes, daily check-in, rewarded ads, cash-out,
 * payment methods and the lucky wheel. Saved values apply to the next request.
 */
const api = useAdminApi()
const s = ref(null)
const saving = ref(false)
const error = ref('')
const notice = ref('')

onMounted(async () => {
    try {
        s.value = (await api.get('/admin/api/reward-settings')).data
    } catch (e) {
        error.value = e.message
    }
})

const wheelTotal = computed(() =>
    (s.value?.lucky_wheel.prizes ?? []).reduce((sum, p) => sum + (Number(p.weight) || 0), 0),
)

function chance(prize) {
    if (!wheelTotal.value) return '0%'
    return `${((Number(prize.weight) || 0) / wheelTotal.value * 100).toFixed(1)}%`
}

/** Example payout, so the rate is easy to sanity-check. */
const rateExample = computed(() => {
    const c = s.value?.cash_out
    if (!c || !c.points_per_unit) return ''
    const value = (c.min_points / c.points_per_unit).toLocaleString(undefined, { maximumFractionDigits: 2 })
    return `${Number(c.min_points).toLocaleString()} points = ${value} ${c.currency}`
})

function addPrize() {
    s.value.lucky_wheel.prizes.push({ points: 10, weight: 10 })
}

function removePrize(index) {
    s.value.lucky_wheel.prizes.splice(index, 1)
}

function addMethod() {
    s.value.payout_methods.push({ code: '', name: '', account_label: '', account_hint: '', input: 'phone', enabled: true })
}

function removeMethod(index) {
    s.value.payout_methods.splice(index, 1)
}

/** A code from the name for new rows, e.g. "AYA Pay" → "aya_pay". */
function fillCode(method) {
    if (!method.code && method.name) {
        method.code = method.name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 16)
    }
}

async function save() {
    saving.value = true
    error.value = ''
    notice.value = ''
    try {
        const res = await api.put('/admin/api/reward-settings', s.value)
        s.value = res.data
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
        <h1 class="font-display text-3xl font-bold tracking-tight">Reward settings</h1>
        <p class="mt-2 text-sm text-ink-muted">
            How users earn points and cash them out. Changes apply in the app straight away.
        </p>

        <p v-if="error" class="mt-4 rounded-xl bg-brand/10 px-4 py-3 text-sm text-brand-soft">{{ error }}</p>
        <p v-if="notice" class="mt-4 rounded-xl bg-success/10 px-4 py-3 text-sm text-success">{{ notice }}</p>

        <template v-if="s">
            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <!-- Watch minutes -->
                <div class="card p-6">
                    <div class="flex items-center justify-between">
                        <h2 class="section-title">Watch to earn</h2>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="s.watch_reward.enabled" type="checkbox" class="h-4 w-4" /> On
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-ink-faint">The reward ring in the video player. Watch time adds up across videos.</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <label class="block">
                            <span class="field-label">Seconds per reward</span>
                            <input v-model.number="s.watch_reward.interval_seconds" type="number" min="10" max="3600" class="field-input" />
                        </label>
                        <label class="block">
                            <span class="field-label">Points per reward</span>
                            <input v-model.number="s.watch_reward.points" type="number" min="1" class="field-input" />
                        </label>
                        <label class="block">
                            <span class="field-label">Max points / day</span>
                            <input v-model.number="s.watch_reward.daily_cap" type="number" min="0" class="field-input" />
                        </label>
                    </div>
                </div>

                <!-- Check-in & ads -->
                <div class="card p-6">
                    <div class="flex items-center justify-between">
                        <h2 class="section-title">Daily check-in</h2>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="s.check_in.enabled" type="checkbox" class="h-4 w-4" /> On
                        </label>
                    </div>
                    <label class="mt-4 block max-w-[200px]">
                        <span class="field-label">Points per check-in</span>
                        <input v-model.number="s.check_in.points" type="number" min="0" class="field-input" />
                    </label>

                    <div class="mt-6 flex items-center justify-between border-t border-white/5 pt-5">
                        <h2 class="section-title">Watch an ad</h2>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="s.ad_reward.enabled" type="checkbox" class="h-4 w-4" /> On
                        </label>
                    </div>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="field-label">Points per ad</span>
                            <input v-model.number="s.ad_reward.points" type="number" min="0" class="field-input" />
                        </label>
                        <label class="block">
                            <span class="field-label">Ads per day</span>
                            <input v-model.number="s.ad_reward.daily_limit" type="number" min="0" max="1000" class="field-input" />
                        </label>
                    </div>
                </div>
            </div>

            <!-- Cash out -->
            <div class="card mt-6 p-6">
                <h2 class="section-title">Points &amp; cash out</h2>
                <p class="mt-1 text-xs text-ink-faint">{{ rateExample }}</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-4">
                    <label class="block">
                        <span class="field-label">Currency</span>
                        <input v-model="s.cash_out.currency" maxlength="3" class="field-input uppercase" placeholder="MMK" />
                    </label>
                    <label class="block">
                        <span class="field-label">Points for 1 {{ s.cash_out.currency }}</span>
                        <input v-model.number="s.cash_out.points_per_unit" type="number" min="1" class="field-input" />
                    </label>
                    <label class="block">
                        <span class="field-label">Minimum points to withdraw</span>
                        <input v-model.number="s.cash_out.min_points" type="number" min="0" class="field-input" />
                    </label>
                    <label class="block">
                        <span class="field-label">Withdrawals per day</span>
                        <input v-model.number="s.cash_out.daily_limit" type="number" min="1" max="100" class="field-input" />
                    </label>
                </div>
            </div>

            <!-- Payment methods -->
            <div class="card mt-6 p-6">
                <div class="flex items-center justify-between">
                    <h2 class="section-title">Payment methods</h2>
                    <button class="btn-ghost text-xs" @click="addMethod">+ Add method</button>
                </div>
                <p class="mt-1 text-xs text-ink-faint">
                    Users pick one of the switched-on methods and enter the account holder name plus the account field below.
                </p>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th class="py-2 pr-2">On</th>
                                <th class="py-2 pr-2">Name</th>
                                <th class="py-2 pr-2">Code</th>
                                <th class="py-2 pr-2">Account field label</th>
                                <th class="py-2 pr-2">Example</th>
                                <th class="py-2 pr-2">Type</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(m, i) in s.payout_methods" :key="i" class="border-t border-white/5">
                                <td class="py-2 pr-2"><input v-model="m.enabled" type="checkbox" class="h-4 w-4" /></td>
                                <td class="py-2 pr-2"><input v-model="m.name" class="field-input" placeholder="KBZPay" @blur="fillCode(m)" /></td>
                                <td class="py-2 pr-2"><input v-model="m.code" class="field-input w-28" placeholder="kbzpay" /></td>
                                <td class="py-2 pr-2"><input v-model="m.account_label" class="field-input" placeholder="KBZPay phone number" /></td>
                                <td class="py-2 pr-2"><input v-model="m.account_hint" class="field-input" placeholder="09xxxxxxxxx" /></td>
                                <td class="py-2 pr-2">
                                    <select v-model="m.input" class="field-input">
                                        <option value="phone">Phone</option>
                                        <option value="email">Email</option>
                                        <option value="text">Text</option>
                                    </select>
                                </td>
                                <td class="py-2 text-right">
                                    <button class="btn-ghost text-xs" @click="removeMethod(i)">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Lucky wheel -->
            <div class="card mt-6 p-6">
                <div class="flex items-center justify-between">
                    <h2 class="section-title">Lucky wheel</h2>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="s.lucky_wheel.enabled" type="checkbox" class="h-4 w-4" /> On
                    </label>
                </div>
                <p class="mt-1 text-xs text-ink-faint">
                    Each prize is one slice. A bigger weight means a bigger chance of winning that slice (2–12 slices).
                </p>

                <label class="mt-4 block max-w-[200px]">
                    <span class="field-label">Spins per day</span>
                    <input v-model.number="s.lucky_wheel.daily_spins" type="number" min="0" max="100" class="field-input" />
                </label>

                <table class="mt-4 w-full max-w-xl text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="py-2">#</th>
                            <th class="py-2 pr-2">Points</th>
                            <th class="py-2 pr-2">Weight</th>
                            <th class="py-2">Chance</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(p, i) in s.lucky_wheel.prizes" :key="i" class="border-t border-white/5">
                            <td class="py-2 text-ink-faint">{{ i + 1 }}</td>
                            <td class="py-2 pr-2"><input v-model.number="p.points" type="number" min="0" class="field-input w-28" /></td>
                            <td class="py-2 pr-2"><input v-model.number="p.weight" type="number" min="1" class="field-input w-28" /></td>
                            <td class="py-2 text-ink-muted">{{ chance(p) }}</td>
                            <td class="py-2 text-right">
                                <button class="btn-ghost text-xs" :disabled="s.lucky_wheel.prizes.length <= 2" @click="removePrize(i)">Remove</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <button class="btn-ghost mt-3 text-xs" :disabled="s.lucky_wheel.prizes.length >= 12" @click="addPrize">+ Add prize</button>
            </div>

            <div class="mt-6 flex justify-end">
                <button class="btn-brand" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save reward settings' }}</button>
            </div>
        </template>
    </AdminLayout>
</template>
