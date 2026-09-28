<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { ArrowLeft, ChevronRight, Database } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PreviewDataController from '@/actions/App/Http/Controllers/PreviewDataController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { SavedRows, SavedTable } from '@/types';

const props = defineProps<{
    projectId: number;
    data: SavedTable[] | null | undefined;
    rows: SavedRows | null | undefined;
}>();

const emit = defineEmits<{ restarted: [] }>();

const own = computed(() => (props.data ?? []).filter((table) => table.own));
const laravel = computed(() =>
    (props.data ?? []).filter((table) => !table.own),
);

// Clearing what was saved cannot be undone, so it is asked for twice, in
// place, where the owner's eyes already are.
const asking = ref<'examples' | 'empty' | null>(null);

// One table opened in place of the list, read when opened and again when
// its count changes, so a new sign-up shows while the owner looks.
const opened = ref<string | null>(null);
const openedTable = computed(
    () =>
        (props.data ?? []).find((table) => table.name === opened.value) ?? null,
);
const shown = computed(() =>
    props.rows?.name === opened.value ? props.rows : undefined,
);

function readRows(): void {
    if (opened.value !== null) {
        router.reload({ only: ['rows'], data: { table: opened.value } });
    }
}

function open(table: SavedTable): void {
    opened.value = table.name;
    readRows();
}

watch(() => openedTable.value?.rows, readRows);

function rows(table: SavedTable): string {
    if (table.rows === null) {
        return '';
    }

    return table.rows === 1 ? '1 row' : `${table.rows.toLocaleString()} rows`;
}
</script>

<template>
    <div
        class="flex h-full min-h-0 flex-col overflow-hidden rounded-lg border bg-background"
        data-test="app-data"
    >
        <div
            v-if="data === undefined"
            class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
        >
            Looking at what your app saved…
        </div>

        <template v-else>
            <div
                v-if="openedTable"
                class="flex min-h-0 flex-1 flex-col"
                data-test="app-data-rows"
            >
                <div class="flex shrink-0 items-center gap-1 border-b px-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-9"
                        aria-label="All tables"
                        title="All tables"
                        @click="opened = null"
                    >
                        <ArrowLeft class="size-4" />
                    </Button>
                    <span class="truncate text-sm font-medium">{{
                        openedTable.words
                    }}</span>
                    <span
                        class="shrink-0 text-xs text-muted-foreground tabular-nums"
                        >· {{ rows(openedTable) }}</span
                    >
                </div>
                <div
                    v-if="shown === undefined"
                    class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
                >
                    Reading…
                </div>
                <p
                    v-else-if="shown === null || shown.rows.length === 0"
                    class="flex flex-1 items-center justify-center p-6 text-center text-sm text-muted-foreground"
                >
                    Nothing saved here yet.
                </p>
                <div v-else class="min-h-0 flex-1 overflow-auto">
                    <table class="w-max min-w-full text-xs">
                        <thead class="sticky top-0 bg-background">
                            <tr>
                                <th
                                    v-for="column in shown.columns"
                                    :key="column"
                                    class="border-b px-3 py-2 text-left font-medium whitespace-nowrap text-muted-foreground"
                                >
                                    {{ column }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in shown.rows"
                                :key="index"
                                class="border-b"
                            >
                                <td
                                    v-for="(value, at) in row"
                                    :key="at"
                                    class="max-w-60 truncate px-3 py-2 whitespace-nowrap"
                                    :title="value ?? ''"
                                >
                                    <span
                                        v-if="value === null"
                                        class="text-muted-foreground"
                                        >—</span
                                    >
                                    <template v-else>{{ value }}</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        v-if="shown.more"
                        class="px-3 py-2 text-xs text-muted-foreground"
                    >
                        The newest {{ shown.rows.length }} are shown.
                    </p>
                </div>
            </div>
            <div
                v-else-if="own.length === 0"
                class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center"
                data-test="app-data-empty"
            >
                <Database class="size-6 text-muted-foreground" />
                <p class="text-lg font-medium">Nothing saved yet</p>
                <p class="max-w-xs text-sm text-muted-foreground">
                    What your app saves while you try it, such as sign-ups,
                    shows here.
                </p>
            </div>

            <ul v-else-if="!openedTable" class="min-h-0 flex-1 overflow-y-auto">
                <li v-for="table in own" :key="table.name" class="border-b">
                    <button
                        type="button"
                        class="flex min-h-11 w-full items-center gap-3 px-3 text-left hover:bg-muted/50"
                        :data-test="`app-data-${table.name}`"
                        @click="open(table)"
                    >
                        <span class="min-w-0 flex-1 truncate text-sm">{{
                            table.words
                        }}</span>
                        <span
                            class="shrink-0 text-xs text-muted-foreground tabular-nums"
                            >{{ rows(table) }}</span
                        >
                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                    </button>
                </li>
            </ul>

            <details
                v-if="laravel.length > 0 && !openedTable"
                class="shrink-0 border-t text-sm"
            >
                <summary
                    class="flex min-h-11 cursor-pointer items-center px-3 text-xs text-muted-foreground select-none"
                >
                    Kept by your app for its own work ({{ laravel.length }})
                </summary>
                <ul class="max-h-48 overflow-y-auto">
                    <li
                        v-for="table in laravel"
                        :key="table.name"
                        class="flex min-h-9 items-center gap-3 border-t px-3 text-muted-foreground"
                    >
                        <span class="min-w-0 flex-1 truncate text-xs">{{
                            table.words
                        }}</span>
                        <span class="shrink-0 text-xs tabular-nums">{{
                            rows(table)
                        }}</span>
                    </li>
                </ul>
            </details>

            <Form
                v-bind="PreviewDataController.update.form(projectId)"
                :transform="() => ({ with: asking })"
                :options="{
                    preserveScroll: true,
                    preserveState: true,
                    only: ['data'],
                }"
                class="flex shrink-0 flex-wrap items-center gap-2 border-t p-2"
                v-slot="{ processing, errors }"
                @success="
                    asking = null;
                    emit('restarted');
                "
            >
                <template v-if="asking === null">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="h-11 sm:h-8"
                        data-test="app-data-examples"
                        @click="asking = 'examples'"
                        >Start again with examples</Button
                    >
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="h-11 sm:h-8"
                        data-test="app-data-empty-it"
                        @click="asking = 'empty'"
                        >Empty it</Button
                    >
                </template>
                <template v-else>
                    <p class="min-w-0 flex-1 text-xs text-muted-foreground">
                        {{
                            asking === 'examples'
                                ? 'Everything saved goes, and your example data takes its place.'
                                : 'Everything saved goes, and the app starts empty.'
                        }}
                        You may need to sign up again.
                    </p>
                    <Button
                        type="submit"
                        size="sm"
                        :variant="
                            asking === 'empty' ? 'destructive' : 'default'
                        "
                        class="h-11 sm:h-8"
                        :disabled="processing"
                        data-test="app-data-confirm"
                        >{{
                            processing
                                ? 'Starting again…'
                                : asking === 'examples'
                                  ? 'Start again'
                                  : 'Empty it'
                        }}</Button
                    >
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="h-11 sm:h-8"
                        :disabled="processing"
                        @click="asking = null"
                        >Keep it</Button
                    >
                </template>
                <InputError class="basis-full" :message="errors.app" />
            </Form>
        </template>
    </div>
</template>
