<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { ref } from 'vue';
import { Input } from '@/components/ui/input';
import { stamp } from '@/lib/operations';
import { attention } from '@/routes/operations';
import { index, show } from '@/routes/operations/people';
import type { Paginated } from '@/types';

// Everyone with an account, the newest first.
type Row = {
    id: number;
    name: string;
    email: string;
    verified: boolean;
    joined_at: string | null;
    apps: number;
    plan: string;
    percent: number | null;
};

const props = defineProps<{
    search: string;
    totals: { people: number; joined_this_week: number; paying: number };
    people: Paginated<Row>;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Operations', href: attention().url },
        { title: 'People', href: index().url },
    ],
});

const query = ref(props.search);
let waiting: ReturnType<typeof setTimeout> | undefined;

// Search as they type, once they pause.
function find(): void {
    clearTimeout(waiting);
    waiting = setTimeout(
        () =>
            router.get(
                index().url,
                query.value.trim() === '' ? {} : { search: query.value.trim() },
                { preserveState: true, preserveScroll: true, replace: true },
            ),
        250,
    );
}
</script>

<template>
    <Head title="People" />

    <div class="mx-auto w-full max-w-4xl space-y-6 px-4 py-6 sm:px-6">
        <p class="text-muted-foreground" data-test="people-totals">
            {{ totals.people }}
            {{ totals.people === 1 ? 'person' : 'people' }} ·
            {{ totals.joined_this_week }} joined this week ·
            {{ totals.paying }} paying
        </p>

        <Input
            v-model="query"
            type="search"
            placeholder="Find by name or email"
            aria-label="Find by name or email"
            @input="find"
        />

        <p
            v-if="people.data.length === 0"
            class="text-muted-foreground"
            data-test="no-people"
        >
            Nobody matches.
        </p>

        <ul v-else class="divide-y border-y">
            <li v-for="person in people.data" :key="person.id">
                <Link
                    :href="show(person.id).url"
                    class="flex min-h-11 items-center gap-3 py-3 hover:bg-muted/40"
                    data-test="person-row"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{
                            person.name
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                        >
                            {{ person.email
                            }}{{ person.verified ? '' : ' · not verified' }} ·
                            joined {{ stamp(person.joined_at) }} ·
                            {{ person.apps }}
                            {{ person.apps === 1 ? 'app' : 'apps' }}
                        </span>
                    </span>
                    <span
                        class="shrink-0 text-right text-xs text-muted-foreground"
                    >
                        <span class="block font-medium text-foreground">{{
                            person.plan
                        }}</span>
                        <span
                            :class="
                                person.percent !== null &&
                                person.percent >= 100 &&
                                'font-medium text-amber-600'
                            "
                            >{{
                                person.percent === null
                                    ? 'No limit'
                                    : `${person.percent}% used`
                            }}</span
                        >
                    </span>
                    <ChevronRight
                        class="size-4 shrink-0 text-muted-foreground"
                    />
                </Link>
            </li>
        </ul>

        <nav
            v-if="people.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <Link
                v-if="people.prev_page_url"
                :href="people.prev_page_url"
                class="min-h-11 content-center text-primary sm:min-h-9"
                >Newer</Link
            >
            <span class="text-muted-foreground"
                >Page {{ people.current_page }} of {{ people.last_page }}</span
            >
            <Link
                v-if="people.next_page_url"
                :href="people.next_page_url"
                class="min-h-11 content-center text-primary sm:min-h-9"
                >Older</Link
            >
        </nav>
    </div>
</template>
