<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { duration, stamp, usd, words } from '@/lib/operations';
import { attention } from '@/routes/operations';
import { index, show } from '@/routes/operations/changes';
import type {
    ChangeFilterOptions,
    ChangeFilters,
    ChangeRow,
    Paginated,
} from '@/types';

const props = defineProps<{
    changes: Paginated<ChangeRow>;
    filters: ChangeFilters;
    options: ChangeFilterOptions;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Operations', href: attention().url },
        { title: 'Changes', href: index().url },
    ],
});

const form = reactive<Record<keyof ChangeFilters, string>>({
    project: props.filters.project?.toString() ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    outcome: props.filters.outcome ?? '',
    verification: props.filters.verification ?? '',
    driver: props.filters.driver ?? '',
    provider: props.filters.provider ?? '',
    model: props.filters.model ?? '',
    reason: props.filters.reason ?? '',
});

const verifications = ['passed', 'unverified', 'failed', 'errored'];

function apply(): void {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== ''),
    );

    router.get(index().url, query, { preserveState: true, replace: true });
}

function clear(): void {
    (Object.keys(form) as (keyof ChangeFilters)[]).forEach((key) => {
        form[key] = '';
    });
    apply();
}

function outcomeLabel(value: string): string {
    return (
        props.options.outcomes.find((outcome) => outcome.value === value)
            ?.label ?? words(value)
    );
}

// Failed and stopped outcomes stand out; the rest read quietly.
function outcomeClass(value: string): string {
    return value === 'failed' ? 'text-destructive font-medium' : '';
}

const select =
    'h-11 w-full min-w-0 rounded-md border bg-background px-2 text-sm sm:h-9';
</script>

<template>
    <Head title="Changes" />

    <div class="mx-auto w-full max-w-6xl space-y-6 px-4 py-6 sm:px-6">
        <form
            class="grid grid-cols-2 gap-2 md:grid-cols-5"
            data-test="filters"
            @submit.prevent="apply"
            @change="apply"
        >
            <label class="col-span-2 space-y-1 text-xs text-muted-foreground">
                App
                <select v-model="form.project" :class="select" name="project">
                    <option value="">Every app</option>
                    <option
                        v-for="project in options.projects"
                        :key="project.id"
                        :value="String(project.id)"
                    >
                        {{ project.name }}
                    </option>
                </select>
            </label>
            <label class="space-y-1 text-xs text-muted-foreground">
                From
                <input
                    v-model="form.from"
                    type="date"
                    name="from"
                    :class="select"
                />
            </label>
            <label class="space-y-1 text-xs text-muted-foreground">
                To
                <input
                    v-model="form.to"
                    type="date"
                    name="to"
                    :class="select"
                />
            </label>
            <label class="space-y-1 text-xs text-muted-foreground">
                Outcome
                <select v-model="form.outcome" :class="select" name="outcome">
                    <option value="">Any</option>
                    <option
                        v-for="outcome in options.outcomes"
                        :key="outcome.value"
                        :value="outcome.value"
                    >
                        {{ outcome.label }}
                    </option>
                </select>
            </label>
            <label class="space-y-1 text-xs text-muted-foreground">
                Checks
                <select
                    v-model="form.verification"
                    :class="select"
                    name="verification"
                >
                    <option value="">Any</option>
                    <option
                        v-for="status in verifications"
                        :key="status"
                        :value="status"
                    >
                        {{ status }}
                    </option>
                </select>
            </label>
            <label class="space-y-1 text-xs text-muted-foreground">
                Driver
                <select v-model="form.driver" :class="select" name="driver">
                    <option value="">Any</option>
                    <option
                        v-for="driver in options.drivers"
                        :key="driver"
                        :value="driver"
                    >
                        {{ driver }}
                    </option>
                </select>
            </label>
            <label class="space-y-1 text-xs text-muted-foreground">
                Provider
                <select v-model="form.provider" :class="select" name="provider">
                    <option value="">Any</option>
                    <option
                        v-for="provider in options.providers"
                        :key="provider"
                        :value="provider"
                    >
                        {{ provider }}
                    </option>
                </select>
            </label>
            <label class="space-y-1 text-xs text-muted-foreground">
                Model
                <select v-model="form.model" :class="select" name="model">
                    <option value="">Any</option>
                    <option
                        v-for="model in options.models"
                        :key="model"
                        :value="model"
                    >
                        {{ model }}
                    </option>
                </select>
            </label>
            <label class="space-y-1 text-xs text-muted-foreground">
                Stop reason
                <select v-model="form.reason" :class="select" name="reason">
                    <option value="">Any</option>
                    <option
                        v-for="reason in options.reasons"
                        :key="reason"
                        :value="reason"
                    >
                        {{ words(reason) }}
                    </option>
                </select>
            </label>
            <div class="col-span-2 flex items-end md:col-span-1">
                <Button
                    type="button"
                    variant="ghost"
                    class="h-11 w-full sm:h-9"
                    @click="clear"
                    >Clear</Button
                >
            </div>
        </form>

        <p class="text-sm text-muted-foreground" data-test="total">
            {{ changes.total }} changes
        </p>

        <p v-if="changes.data.length === 0" class="text-muted-foreground">
            No change matches.
        </p>

        <!-- A table compares fields across rows; below md it reads as a list. -->
        <table
            v-if="changes.data.length > 0"
            class="hidden w-full text-sm md:table"
            data-test="changes-table"
        >
            <thead class="text-left text-xs text-muted-foreground">
                <tr class="border-b">
                    <th class="py-2 font-normal">Change</th>
                    <th class="py-2 font-normal">App</th>
                    <th class="py-2 font-normal">Outcome</th>
                    <th class="py-2 font-normal">Checks</th>
                    <th class="py-2 font-normal">Models</th>
                    <th class="py-2 text-right font-normal">Cost</th>
                    <th class="py-2 text-right font-normal">Took</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr
                    v-for="change in changes.data"
                    :key="change.id"
                    class="cursor-pointer hover:bg-muted/40"
                    data-test="change-row"
                    @click="router.visit(show(change.id).url)"
                >
                    <td class="py-2">
                        <Link
                            :href="show(change.id).url"
                            class="font-medium"
                            @click.stop
                            >#{{ change.id.slice(-8) }}</Link
                        >
                        <div class="text-xs text-muted-foreground tabular-nums">
                            {{ stamp(change.created_at) }}
                        </div>
                    </td>
                    <td class="max-w-48 truncate py-2">
                        {{ change.project.name }}
                    </td>
                    <td class="py-2">
                        <span :class="outcomeClass(change.outcome)">{{
                            outcomeLabel(change.outcome)
                        }}</span>
                        <div
                            v-if="change.stop_reason"
                            class="text-xs text-muted-foreground"
                        >
                            {{ words(change.stop_reason) }}
                        </div>
                        <div
                            v-if="change.published || change.pushed"
                            class="text-xs text-muted-foreground"
                        >
                            {{ change.published ? 'published' : 'pushed' }}
                        </div>
                    </td>
                    <td class="py-2">{{ change.verification ?? '—' }}</td>
                    <td class="max-w-56 py-2 text-xs break-words">
                        {{ change.models.join(', ') || '—' }}
                        <div class="text-muted-foreground">
                            {{ change.driver ?? '' }}
                        </div>
                    </td>
                    <td class="py-2 text-right tabular-nums">
                        {{ usd(change.cost_usd) }}
                        <div
                            v-if="change.unpriced_calls > 0"
                            class="text-xs text-destructive"
                        >
                            +{{ change.unpriced_calls }} unpriced
                        </div>
                    </td>
                    <td class="py-2 text-right tabular-nums">
                        {{ duration(change.elapsed_seconds) }}
                    </td>
                </tr>
            </tbody>
        </table>

        <ul
            v-if="changes.data.length > 0"
            class="divide-y border-y md:hidden"
            data-test="changes-list"
        >
            <li v-for="change in changes.data" :key="change.id">
                <Link
                    :href="show(change.id).url"
                    class="flex min-h-11 flex-col gap-0.5 py-3"
                >
                    <span class="flex justify-between gap-3">
                        <span class="min-w-0 truncate font-medium"
                            >#{{ change.id.slice(-8) }} ·
                            {{ change.project.name }}</span
                        >
                        <span class="shrink-0 tabular-nums">{{
                            usd(change.cost_usd)
                        }}</span>
                    </span>
                    <span
                        class="flex justify-between gap-3 text-sm text-muted-foreground"
                    >
                        <span :class="outcomeClass(change.outcome)"
                            >{{ outcomeLabel(change.outcome) }} · checks
                            {{ change.verification ?? '—' }}</span
                        >
                        <span class="shrink-0 tabular-nums">{{
                            duration(change.elapsed_seconds)
                        }}</span>
                    </span>
                </Link>
            </li>
        </ul>

        <nav
            v-if="changes.last_page > 1"
            class="flex items-center justify-between text-sm"
            aria-label="Pages"
        >
            <Link
                v-if="changes.prev_page_url"
                :href="changes.prev_page_url"
                class="flex min-h-11 items-center text-primary"
                preserve-scroll
                >Newer</Link
            >
            <span v-else />
            <span class="text-muted-foreground tabular-nums"
                >Page {{ changes.current_page }} of
                {{ changes.last_page }}</span
            >
            <Link
                v-if="changes.next_page_url"
                :href="changes.next_page_url"
                class="flex min-h-11 items-center text-primary"
                preserve-scroll
                >Older</Link
            >
            <span v-else />
        </nav>
    </div>
</template>
