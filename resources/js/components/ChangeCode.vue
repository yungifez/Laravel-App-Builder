<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ChangedFile } from '@/types';

// Narrow beside a chat, the file list gives its room to the code.
const props = defineProps<{ files: ChangedFile[]; narrow?: boolean }>();

type Row =
    | { kind: 'hunk'; text: string }
    | {
          kind: 'line';
          sign: '+' | '-' | ' ';
          text: string;
          old: number | null;
          new: number | null;
      };

// A unified diff as rows with the line numbers on each side, the way a
// code review shows it. The git header lines say nothing a reader needs.
function rows(diff: string): Row[] {
    const result: Row[] = [];
    let oldLine = 0;
    let newLine = 0;

    for (const line of diff.split('\n')) {
        const hunk = line.match(/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@(.*)$/);

        if (hunk) {
            oldLine = Number(hunk[1]);
            newLine = Number(hunk[2]);
            result.push({ kind: 'hunk', text: hunk[3].trim() });
        } else if (line.startsWith('+') && !line.startsWith('+++')) {
            result.push({
                kind: 'line',
                sign: '+',
                text: line.slice(1),
                old: null,
                new: newLine++,
            });
        } else if (line.startsWith('-') && !line.startsWith('---')) {
            result.push({
                kind: 'line',
                sign: '-',
                text: line.slice(1),
                old: oldLine++,
                new: null,
            });
        } else if (line.startsWith(' ')) {
            result.push({
                kind: 'line',
                sign: ' ',
                text: line.slice(1),
                old: oldLine++,
                new: newLine++,
            });
        }
    }

    return result;
}

const shown = computed(() =>
    props.files.map((file) => ({
        ...file,
        name: file.path.split('/').pop() ?? file.path,
        folder: file.path.split('/').slice(0, -1).join('/'),
        rows: rows(file.diff),
    })),
);

const tones = {
    '+': 'bg-green-500/10',
    '-': 'bg-red-500/10',
    ' ': '',
} as const;

const signs = {
    '+': 'text-green-700 dark:text-green-400',
    '-': 'text-red-700 dark:text-red-400',
    ' ': 'text-muted-foreground',
} as const;

const list = ref<HTMLElement | null>(null);

function open(path: string): void {
    list.value
        ?.querySelector(`[data-path="${CSS.escape(path)}"]`)
        ?.scrollIntoView({ block: 'start', behavior: 'smooth' });
}
</script>

<template>
    <div
        :class="[
            'grid min-h-0',
            narrow ? 'grid-cols-1' : 'grid-cols-[15rem_minmax(0,1fr)]',
        ]"
        data-test="change-code"
    >
        <nav
            v-if="!narrow"
            class="min-h-0 space-y-0.5 overflow-y-auto border-r p-2"
            aria-label="Files"
        >
            <p class="px-2 pt-1 pb-2 text-xs text-muted-foreground">
                {{ files.length }} {{ files.length === 1 ? 'file' : 'files' }}
            </p>
            <button
                v-for="file in shown"
                :key="file.path"
                type="button"
                class="flex w-full items-baseline gap-2 rounded-md px-2 py-1.5 text-left hover:bg-muted"
                :title="file.path"
                @click="open(file.path)"
            >
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-mono text-xs">{{
                        file.name
                    }}</span>
                    <span
                        v-if="file.folder"
                        class="block truncate text-xs text-muted-foreground"
                        >{{ file.folder }}</span
                    >
                </span>
                <span class="shrink-0 font-mono text-xs tabular-nums">
                    <span class="text-green-600">+{{ file.additions }}</span>
                    <span class="text-red-600"> −{{ file.deletions }}</span>
                </span>
            </button>
        </nav>

        <div ref="list" class="min-h-0 space-y-4 overflow-y-auto p-4">
            <section
                v-for="file in shown"
                :key="file.path"
                :data-path="file.path"
                class="scroll-mt-4 overflow-hidden rounded-lg border"
            >
                <header
                    class="flex items-baseline gap-2 border-b bg-muted/40 px-3 py-2"
                >
                    <span class="font-mono text-sm font-medium">{{
                        file.name
                    }}</span>
                    <span
                        class="min-w-0 flex-1 truncate text-xs text-muted-foreground"
                        >{{ file.folder }}</span
                    >
                    <span class="shrink-0 font-mono text-xs tabular-nums">
                        <span class="text-green-600"
                            >+{{ file.additions }}</span
                        >
                        <span class="text-red-600"> −{{ file.deletions }}</span>
                    </span>
                </header>
                <div class="overflow-x-auto">
                    <div class="w-max min-w-full font-mono text-xs leading-5">
                        <template
                            v-for="(row, index) in file.rows"
                            :key="index"
                        >
                            <div
                                v-if="row.kind === 'hunk'"
                                class="border-y bg-muted/30 px-3 py-0.5 text-muted-foreground first:border-t-0"
                            >
                                {{ row.text || '…' }}
                            </div>
                            <div
                                v-else
                                :class="[
                                    'grid grid-cols-[3rem_3rem_1.5rem_auto]',
                                    tones[row.sign],
                                ]"
                            >
                                <span
                                    class="pr-2 text-right text-muted-foreground/70 select-none"
                                    >{{ row.old ?? '' }}</span
                                >
                                <span
                                    class="pr-2 text-right text-muted-foreground/70 select-none"
                                    >{{ row.new ?? '' }}</span
                                >
                                <span
                                    :class="[
                                        'text-center select-none',
                                        signs[row.sign],
                                    ]"
                                    >{{ row.sign }}</span
                                >
                                <span class="pr-4 whitespace-pre">{{
                                    row.text || ' '
                                }}</span>
                            </div>
                        </template>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
