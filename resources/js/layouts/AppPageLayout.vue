<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import NotificationBell from '@/components/NotificationBell.vue';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

// Pages that belong to one app ("What I know", a change's details) open
// full screen like the workspace, with one way back to it. The pages set
// the same breadcrumbs as before: the second-to-last is where back goes,
// and the last names the page.
const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

const back = computed(() => breadcrumbs.at(-2) ?? null);
const here = computed(() => breadcrumbs.at(-1) ?? null);
</script>

<template>
    <!-- Clip, not hidden: a hidden box still scrolls when focus lands near
         the bottom, which pushed the header off screen. -->
    <div class="flex h-svh flex-col overflow-clip bg-background">
        <header
            class="flex h-14 shrink-0 items-center gap-1 border-b px-2 sm:px-3"
        >
            <Button
                v-if="back"
                variant="ghost"
                size="icon"
                class="size-11 shrink-0 sm:size-9"
                as-child
            >
                <Link
                    :href="back.href"
                    :aria-label="`Back to ${back.title}`"
                    data-test="back"
                >
                    <ArrowLeft class="size-4" />
                </Link>
            </Button>
            <p class="flex min-w-0 items-baseline gap-2 px-2">
                <span
                    v-if="back"
                    class="hidden shrink-0 truncate text-muted-foreground sm:inline sm:max-w-64"
                    >{{ back.title }}</span
                >
                <span
                    v-if="back"
                    class="hidden text-muted-foreground/60 sm:inline"
                    aria-hidden="true"
                    >/</span
                >
                <span class="truncate font-semibold">{{ here?.title }}</span>
            </p>
            <div class="ml-auto shrink-0">
                <NotificationBell />
            </div>
        </header>
        <main class="min-h-0 flex-1 overflow-y-auto">
            <slot />
        </main>
        <Toaster />
    </div>
</template>
