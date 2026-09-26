<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { show } from '@/routes/projects';
import { show as showEditor } from '@/routes/projects/editor';
import { show as showUnderstanding } from '@/routes/projects/understanding';

defineProps<{
    projectId: number;
    current: 'changes' | 'looks' | 'knows';
}>();
</script>

<template>
    <!-- The three things an owner does with an app, one tab each. The
         current tab is raised by weight and a rule, never by the accent. -->
    <nav class="-mx-4 overflow-x-auto border-b px-4" aria-label="Your app">
        <ul class="flex gap-6 text-sm">
            <li
                v-for="tab in [
                    { key: 'changes', label: 'Changes', href: show(projectId) },
                    {
                        key: 'looks',
                        label: 'How it looks',
                        href: showEditor(projectId),
                    },
                    {
                        key: 'knows',
                        label: 'What I know',
                        href: showUnderstanding(projectId),
                    },
                ]"
                :key="tab.key"
            >
                <Link
                    :href="tab.href"
                    :data-test="`tab-${tab.key}`"
                    :aria-current="current === tab.key ? 'page' : undefined"
                    :class="[
                        '-mb-px flex min-h-11 items-center border-b-2 whitespace-nowrap select-none',
                        current === tab.key
                            ? 'border-foreground font-medium text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    ]"
                >
                    {{ tab.label }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
