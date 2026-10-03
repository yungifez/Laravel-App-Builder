<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { stamp } from '@/lib/operations';
import { attention } from '@/routes/operations';
import { index, update } from '@/routes/operations/messages';
import { show as person } from '@/routes/operations/people';
import type { Paginated } from '@/types';

// What people wrote through the contact form, the ones nobody handled
// first.
type Row = {
    id: number;
    name: string;
    email: string;
    person: number | null;
    message: string;
    sent_at: string | null;
    handled: boolean;
};

defineProps<{ messages: Paginated<Row> }>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Operations', href: attention().url },
        { title: 'Messages', href: index().url },
    ],
});

function mark(message: Row): void {
    router.put(
        update(message.id).url,
        { handled: !message.handled },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Messages" />

    <div class="mx-auto w-full max-w-4xl space-y-6 px-4 py-6 sm:px-6">
        <p
            v-if="messages.data.length === 0"
            class="text-muted-foreground"
            data-test="no-messages"
        >
            Nobody has written yet.
        </p>

        <ul v-else class="divide-y border-y">
            <li
                v-for="message in messages.data"
                :key="message.id"
                class="space-y-2 py-4"
                :class="message.handled && 'text-muted-foreground'"
                data-test="message-row"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <p class="text-sm">
                        <Link
                            v-if="message.person"
                            :href="person(message.person).url"
                            class="font-medium text-primary hover:underline"
                            >{{ message.name }}</Link
                        >
                        <span v-else class="font-medium">{{
                            message.name
                        }}</span>
                        <a
                            :href="`mailto:${message.email}`"
                            class="ml-1 text-primary hover:underline"
                            >{{ message.email }}</a
                        >
                        <span class="text-muted-foreground">
                            · {{ stamp(message.sent_at) }}</span
                        >
                    </p>
                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center rounded-md border bg-background px-3 text-sm select-none hover:bg-muted sm:min-h-8"
                        @click="mark(message)"
                    >
                        {{ message.handled ? 'Not handled' : 'Handled' }}
                    </button>
                </div>
                <p class="whitespace-pre-line">{{ message.message }}</p>
            </li>
        </ul>

        <nav
            v-if="messages.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <Link
                v-if="messages.prev_page_url"
                :href="messages.prev_page_url"
                class="min-h-11 content-center text-primary sm:min-h-9"
                >Newer</Link
            >
            <span class="text-muted-foreground"
                >Page {{ messages.current_page }} of
                {{ messages.last_page }}</span
            >
            <Link
                v-if="messages.next_page_url"
                :href="messages.next_page_url"
                class="min-h-11 content-center text-primary sm:min-h-9"
                >Older</Link
            >
        </nav>
    </div>
</template>
