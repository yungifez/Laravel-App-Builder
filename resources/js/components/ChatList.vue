<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { show as showProject } from '@/routes/projects';
import type { ChangeItem, ChangeState } from '@/types';

// The owner's other chats beside the open one, newest first, so moving
// between them is one click. A dot says where each one stands.
defineProps<{
    projectId: number;
    chats: ChangeItem[];
    current: number | null;
}>();

const dots: Record<ChangeState, string> = {
    waiting: 'bg-amber-500',
    working: 'animate-pulse bg-sky-500',
    answered: 'bg-muted-foreground/40',
    kept: 'bg-green-600',
    stopped: 'bg-red-600',
    undone: 'bg-muted-foreground/40',
    dismissed: 'bg-muted-foreground/20',
};
</script>

<template>
    <nav
        class="flex min-h-0 flex-col border-r"
        aria-label="Chats"
        data-test="chat-list"
    >
        <Link
            :href="showProject(projectId)"
            :only="['change']"
            preserve-state
            class="m-2 flex min-h-9 items-center gap-2 rounded-md px-2 text-sm text-muted-foreground transition-colors select-none hover:bg-muted hover:text-foreground"
            data-test="chat-list-new"
        >
            <Plus class="size-4" /> New chat
        </Link>
        <ol class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-2">
            <li v-for="chat in chats" :key="chat.id">
                <Link
                    :href="
                        showProject(projectId, { query: { change: chat.id } })
                    "
                    :only="['change']"
                    preserve-state
                    preserve-scroll
                    :aria-current="chat.id === current ? 'page' : undefined"
                    :class="[
                        'flex min-h-9 items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors duration-200 select-none',
                        chat.id === current
                            ? 'bg-muted font-medium'
                            : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                    ]"
                    :data-test="`chat-list-${chat.id}`"
                >
                    <span
                        :class="[
                            'size-1.5 shrink-0 rounded-full',
                            dots[chat.state],
                        ]"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1 truncate">{{
                        chat.prompt
                    }}</span>
                </Link>
            </li>
        </ol>
    </nav>
</template>
