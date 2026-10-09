<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { ArrowLeft, ChevronRight, Database, File, Trash2 } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import PreviewDataController from '@/actions/App/Http/Controllers/PreviewDataController';
import PreviewFileController from '@/actions/App/Http/Controllers/PreviewFileController';
import PreviewRowController from '@/actions/App/Http/Controllers/PreviewRowController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { when } from '@/lib/when';
import type { SavedRows, SavedTable, StoredFile } from '@/types';

const props = defineProps<{
    projectId: string;
    data: SavedTable[] | null | undefined;
    rows: SavedRows | null | undefined;
    files: StoredFile[] | null | undefined;
    // The change the owner is trying, when the tools work on its copy.
    copy?: string | null;
}>();

const emit = defineEmits<{ restarted: [] }>();

// What the app holds comes first; empty tables follow, quieter, in the
// same order as before.
const own = computed(() =>
    (props.data ?? [])
        .filter((table) => table.own)
        .sort((a, b) => Number(a.rows === 0) - Number(b.rows === 0)),
);
const laravel = computed(() =>
    (props.data ?? []).filter((table) => !table.own),
);

// Emptying an app that has nothing of the owner's saved does nothing they
// would see, so it is not offered.
const saved = computed(
    () =>
        own.value.some((table) => table.rows !== 0) ||
        (props.files?.length ?? 0) > 0,
);

// Clearing what was saved cannot be undone, so it is asked for twice, in
// place, where the owner's eyes already are.
const asking = ref<'examples' | 'empty' | 'lots' | null>(null);

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

// A deleted row goes at once; it comes back if the app keeps it.
const deleting = ref<Set<string>>(new Set());
const shownRows = computed(() =>
    (shown.value?.rows ?? [])
        .map((values, index) => ({
            values,
            index,
            id: shown.value?.ids[index] ?? null,
        }))
        .filter(
            (row) =>
                row.id === null ||
                !deleting.value.has(`${shown.value?.name}:${row.id}`),
        ),
);

function remove(id: string): void {
    const table = opened.value;
    const key = `${table}:${id}`;

    deleting.value = new Set([...deleting.value, key]);

    // The count changes, and the rows are read again from it.
    router.delete(
        PreviewRowController.destroy.url(props.projectId, {
            query: { copy: props.copy },
        }),
        {
            data: { table, row: id },
            only: ['data'],
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                deleting.value = new Set(
                    [...deleting.value].filter((kept) => kept !== key),
                );
                toast.error(
                    errors.app ??
                        errors.table ??
                        'Your app could not delete it. This is our fault. Try again.',
                );
            },
        },
    );
}

// A changed value shows at once, until the rows are read again; it goes
// back if the app refuses it.
const changed = ref<Map<string, string | null>>(new Map());
const changing = ref<{ id: string; column: string; draft: string } | null>(
    null,
);
const changingInput = ref<HTMLInputElement[]>([]);

watch(
    () => props.rows,
    () => (changed.value = new Map()),
);

function place(id: string, column: string): string {
    return `${opened.value}:${id}:${column}`;
}

function valueOf(id: string | null, at: number, value: string | null) {
    const column = shown.value?.columns[at];

    if (id === null || column === undefined) {
        return value;
    }

    const key = place(id, column);

    return changed.value.has(key) ? changed.value.get(key)! : value;
}

function canChange(index: number, at: number): boolean {
    const column = shown.value?.columns[at];

    return (
        column !== undefined &&
        (shown.value?.changeable.includes(column) ?? false) &&
        !(shown.value?.cut[index] ?? []).includes(at)
    );
}

function startChanging(id: string, at: number, value: string | null): void {
    const column = shown.value?.columns[at];

    if (column === undefined) {
        return;
    }

    changing.value = { id, column, draft: value ?? '' };
    nextTick(() => changingInput.value[0]?.select());
}

function isChanging(id: string | null, at: number): boolean {
    return (
        id !== null &&
        changing.value?.id === id &&
        changing.value.column === shown.value?.columns[at]
    );
}

function change(before: string | null): void {
    const now = changing.value;
    changing.value = null;

    if (now === null || now.draft === (before ?? '')) {
        return;
    }

    const key = place(now.id, now.column);
    const value = now.draft === '' ? null : now.draft;

    changed.value = new Map(changed.value).set(key, value);

    router.patch(
        PreviewRowController.update.url(props.projectId, {
            query: { copy: props.copy },
        }),
        { table: opened.value, row: now.id, column: now.column, value },
        {
            only: ['rows'],
            preserveScroll: true,
            preserveState: true,
            // The answer reads the table named in the page address, which
            // an earlier reload can have left out.
            onSuccess: () => shown.value === undefined && readRows(),
            onError: (errors) => {
                const kept = new Map(changed.value);
                kept.delete(key);
                changed.value = kept;
                toast.error(
                    errors.value ??
                        errors.app ??
                        errors.table ??
                        'Your app could not change it. This is our fault. Try again.',
                );
            },
        },
    );
}

function fileUrl(file: StoredFile): string {
    return PreviewFileController.show.url(props.projectId, {
        query: { path: file.path, copy: props.copy },
    });
}

function size(bytes: number): string {
    if (bytes < 1000) {
        return `${bytes} bytes`;
    }

    return bytes < 1_000_000
        ? `${Math.round(bytes / 1000)} KB`
        : `${(bytes / 1_000_000).toFixed(1)} MB`;
}

function rows(table: SavedTable): string {
    if (table.rows === null) {
        return '';
    }

    return table.rows === 0 ? 'Empty' : `${table.rows.toLocaleString()} saved`;
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
                    v-else-if="shown === null || shownRows.length === 0"
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
                                <th v-if="shown.key" class="border-b" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in shownRows"
                                :key="row.id ?? row.index"
                                class="group border-b"
                            >
                                <td
                                    v-for="(value, at) in row.values"
                                    :key="at"
                                    class="max-w-60 p-0"
                                >
                                    <input
                                        v-if="isChanging(row.id, at)"
                                        ref="changingInput"
                                        v-model="changing!.draft"
                                        class="w-full min-w-24 bg-muted px-3 py-2 outline-none"
                                        :aria-label="`New ${shown.columns[at]}`"
                                        data-test="app-data-change-input"
                                        @keydown.enter.prevent="change(value)"
                                        @keydown.escape.prevent="
                                            changing = null
                                        "
                                        @blur="change(value)"
                                    />
                                    <button
                                        v-else-if="
                                            row.id !== null &&
                                            canChange(row.index, at)
                                        "
                                        type="button"
                                        class="block w-full truncate px-3 py-2 text-left whitespace-nowrap hover:bg-muted/60"
                                        :title="`Change ${shown.columns[at]}`"
                                        :data-test="`app-data-change-${row.id}-${shown.columns[at]}`"
                                        @click="
                                            startChanging(
                                                row.id,
                                                at,
                                                valueOf(row.id, at, value),
                                            )
                                        "
                                    >
                                        <span
                                            v-if="
                                                valueOf(row.id, at, value) ===
                                                null
                                            "
                                            class="text-muted-foreground"
                                            >—</span
                                        >
                                        <template v-else>{{
                                            valueOf(row.id, at, value)
                                        }}</template>
                                    </button>
                                    <span
                                        v-else
                                        class="block truncate px-3 py-2 whitespace-nowrap"
                                        :title="value ?? ''"
                                    >
                                        <span
                                            v-if="value === null"
                                            class="text-muted-foreground"
                                            >—</span
                                        >
                                        <template v-else>{{ value }}</template>
                                    </span>
                                </td>
                                <td
                                    v-if="shown.key"
                                    class="sticky right-0 w-0 bg-background p-0"
                                >
                                    <button
                                        v-if="row.id !== null"
                                        type="button"
                                        class="grid size-11 place-items-center text-muted-foreground hover:text-destructive sm:size-8 sm:opacity-0 sm:group-hover:opacity-100 sm:focus-visible:opacity-100"
                                        aria-label="Delete this row"
                                        title="Delete this row"
                                        :data-test="`app-data-delete-${row.id}`"
                                        @click="remove(row.id)"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </button>
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
                v-else-if="own.length === 0 && !files?.length"
                class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center"
                data-test="app-data-empty"
            >
                <Database class="size-6 text-muted-foreground" />
                <p class="text-lg font-medium">Nothing saved yet</p>
                <p class="max-w-xs text-sm text-muted-foreground">
                    What your app saves while you try it, such as sign-ups and
                    uploaded pictures, shows here.
                </p>
            </div>

            <div
                v-else-if="!openedTable"
                class="min-h-0 flex-1 overflow-y-auto"
            >
                <ul>
                    <li v-for="table in own" :key="table.name" class="border-b">
                        <button
                            type="button"
                            class="flex min-h-11 w-full items-center gap-3 px-3 text-left hover:bg-muted/50"
                            :data-test="`app-data-${table.name}`"
                            @click="open(table)"
                        >
                            <span
                                :class="[
                                    'min-w-0 flex-1 truncate text-sm',
                                    table.rows === 0 && 'text-muted-foreground',
                                ]"
                                >{{ table.words }}</span
                            >
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

                <!-- Files the app stored, such as uploads: a picture shows,
                 anything else downloads. -->
                <section v-if="files?.length" data-test="app-data-files">
                    <h3 class="px-3 pt-4 pb-1 text-xs text-muted-foreground">
                        Files ({{ files.length }})
                    </h3>
                    <ul>
                        <li
                            v-for="file in files"
                            :key="file.path"
                            class="border-b"
                        >
                            <a
                                :href="fileUrl(file)"
                                target="_blank"
                                rel="noopener"
                                class="flex min-h-11 items-center gap-3 px-3 py-1.5 hover:bg-muted/50"
                                :data-test="`app-file-${file.path}`"
                            >
                                <img
                                    v-if="file.picture"
                                    :src="fileUrl(file)"
                                    alt=""
                                    loading="lazy"
                                    class="size-8 shrink-0 rounded bg-muted object-cover"
                                />
                                <File
                                    v-else
                                    class="size-8 shrink-0 p-1.5 text-muted-foreground"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm">{{
                                        file.name
                                    }}</span>
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                        >{{ size(file.size) }} ·
                                        {{ when(file.stored_at) }}</span
                                    >
                                </span>
                            </a>
                        </li>
                    </ul>
                </section>
            </div>

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
                v-bind="
                    PreviewDataController.update.form(projectId, {
                        query: { copy },
                    })
                "
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
                        data-test="app-data-lots"
                        @click="asking = 'lots'"
                        >Add lots more</Button
                    >
                    <Button
                        v-if="saved"
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
                        <template v-if="asking === 'lots'">
                            Hundreds more of each kind join what is saved, so
                            you see how your app copes with lots.
                        </template>
                        <template v-else>
                            {{
                                asking === 'examples'
                                    ? 'Everything saved goes, and your example data takes its place.'
                                    : 'Everything saved goes, and the app starts empty.'
                            }}
                            You may need to sign up again.
                        </template>
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
                                ? asking === 'lots'
                                    ? 'Adding…'
                                    : 'Starting again…'
                                : asking === 'examples'
                                  ? 'Start again'
                                  : asking === 'lots'
                                    ? 'Add them'
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
