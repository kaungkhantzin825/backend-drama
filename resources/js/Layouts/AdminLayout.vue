<script setup>
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

/**
 * Shared chrome for every admin screen: sidebar navigation, signed-in account
 * and sign-out. Nav items are filtered by role so an editor never sees the
 * revenue screens they cannot open.
 */
const page = usePage()

const user = computed(() => page.props.auth?.user ?? { name: 'Staff', role: 'editor' })
const isAdmin = computed(() => user.value.role === 'admin')

const sections = computed(() => [
    { label: 'Overview', href: '/admin', icon: '▦', show: true },
    { label: 'Content', href: '/admin/content', icon: '🎞️', show: true },
    { label: 'Rewards', href: '/admin/rewards', icon: '🪙', show: isAdmin.value },
    { label: 'Reward settings', href: '/admin/reward-settings', icon: '🎛️', show: isAdmin.value },
    { label: 'Withdrawals', href: '/admin/withdrawals', icon: '💸', show: isAdmin.value },
    { label: 'Feedback', href: '/admin/feedback', icon: '💬', show: isAdmin.value },
    { label: 'Ads', href: '/admin/ads', icon: '📺', show: isAdmin.value },
    { label: 'Traffic', href: '/admin/traffic', icon: '📈', show: isAdmin.value },
    { label: 'Settings', href: '/admin/settings', icon: '⚙️', show: isAdmin.value },
    { label: 'Users', href: '/admin/users', icon: '👥', show: isAdmin.value },
])

const current = computed(() => page.url.split('?')[0])

function isActive(href) {
    return href === '/admin' ? current.value === '/admin' : current.value.startsWith(href)
}

function signOut() {
    router.post('/admin/logout')
}
</script>

<template>
    <div class="min-h-screen bg-surface text-ink">
        <div class="pointer-events-none fixed inset-0 bg-surface-gradient opacity-40"></div>

        <div class="relative flex min-h-screen">
            <!-- Sidebar -->
            <aside class="hidden w-64 shrink-0 border-r border-white/5 bg-surface-raised/70 p-5 backdrop-blur lg:block">
                <div class="mb-8 flex items-center gap-3">
                    <span class="grid h-10 w-10 place-items-center rounded-xl bg-brand/20 text-lg">🎬</span>
                    <div>
                        <p class="font-display text-sm font-bold tracking-tight">DramaLoop</p>
                        <p class="text-xs text-ink-faint">Admin panel</p>
                    </div>
                </div>

                <nav class="space-y-1">
                    <template v-for="section in sections" :key="section.href">
                        <Link
                            v-if="section.show"
                            :href="section.href"
                            class="flex items-center gap-3 rounded-2xl px-4 py-2.5 text-sm font-medium transition"
                            :class="isActive(section.href)
                                ? 'bg-brand/15 text-brand-soft'
                                : 'text-ink-muted hover:bg-white/5 hover:text-ink'"
                        >
                            <span>{{ section.icon }}</span>
                            {{ section.label }}
                        </Link>
                    </template>
                </nav>
            </aside>

            <!-- Main -->
            <div class="flex min-w-0 flex-1 flex-col">
                <header class="flex items-center justify-between border-b border-white/5 px-6 py-4">
                    <div class="flex items-center gap-2 text-sm text-ink-faint">
                        <span class="rounded-lg bg-white/5 px-2 py-1 font-mono text-xs">{{ current }}</span>
                    </div>

                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <p class="text-sm font-semibold">{{ user.name }}</p>
                            <p class="text-xs capitalize text-ink-faint">{{ user.role }}</p>
                        </div>
                        <button class="btn-ghost text-sm" @click="signOut">Sign out</button>
                    </div>
                </header>

                <main class="flex-1 p-6 lg:p-8">
                    <div class="mx-auto max-w-6xl">
                        <slot />
                    </div>
                </main>
            </div>
        </div>
    </div>
</template>
