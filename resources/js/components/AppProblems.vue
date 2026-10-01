<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { CircleCheck, LoaderCircle, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import ClearedProblemController from '@/actions/App/Http/Controllers/ClearedProblemController';
import PreviewProblemFixController from '@/actions/App/Http/Controllers/PreviewProblemFixController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { when } from '@/lib/when';
import { show as showProject } from '@/routes/projects';
import type { AppProblem } from '@/types';

const props = defineProps<{
    projectId: string;
    problems: AppProblem[] | undefined;
    // The change the owner is trying, when the tools work on its copy.
    copy?: string | null;
}>();

// Problems still to deal with, and those fixed or cleared, which stay out
// of the way until the app runs into them again.
const open = computed(() =>
    (props.problems ?? []).filter(
        (problem) => !['fixed', 'cleared'].includes(problem.state),
    ),
);
const done = computed(() =>
    (props.problems ?? []).filter((problem) =>
        ['fixed', 'cleared'].includes(problem.state),
    ),
);

function at(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    return when(iso) === 'today'
        ? new Date(iso).toLocaleTimeString([], {
              hour: 'numeric',
              minute: '2-digit',
          })
        : when(iso);
}

function times(problem: AppProblem): string {
    return `${problem.count === 1 ? 'Once' : `${problem.count} times`} · last at ${at(problem.last_at)}`;
}
</script>

<template>
    <div
        class="flex h-full min-h-0 flex-col overflow-hidden rounded-lg border bg-background"
        data-test="app-problems"
    >
        <div
            v-if="problems === undefined"
            class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
        >
            Looking for problems…
        </div>

        <template v-else>
            <div
                v-if="open.length === 0"
                class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center"
                data-test="app-problems-empty"
            >
                <CircleCheck class="size-6 text-muted-foreground" />
                <p class="text-lg font-medium">No problems</p>
                <p class="max-w-xs text-sm text-muted-foreground">
                    If something goes wrong while you try your app, it shows
                    here, and I can fix it.
                </p>
            </div>

            <ul v-else class="min-h-0 flex-1 overflow-y-auto">
                <li
                    v-for="problem in open"
                    :key="problem.id"
                    class="flex flex-col gap-2 border-b px-3 py-3"
                    :data-test="`app-problem-${problem.id}`"
                >
                    <div class="flex flex-wrap items-start gap-2">
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0 text-destructive"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm">{{ problem.words }}</p>
                            <p class="text-xs text-muted-foreground">
                                <span
                                    v-if="problem.state === 'back'"
                                    class="text-destructive"
                                    data-test="app-problem-back"
                                    >{{
                                        problem.change
                                            ? 'Came back after it was fixed'
                                            : 'Came back after you cleared it'
                                    }}
                                    ·
                                </span>
                                <template v-if="problem.stopped">
                                    <Link
                                        :href="
                                            showProject(projectId, {
                                                query: {
                                                    change: problem.stopped,
                                                },
                                            })
                                        "
                                        class="underline underline-offset-2 hover:text-foreground"
                                        data-test="app-problem-stopped"
                                        >My last try to fix it stopped</Link
                                    >
                                    ·
                                </template>
                                {{ times(problem) }}
                            </p>
                        </div>

                        <Link
                            v-if="problem.state === 'fixing' && problem.change"
                            :href="
                                showProject(projectId, {
                                    query: { change: problem.change },
                                })
                            "
                            class="flex min-h-11 shrink-0 items-center gap-1.5 text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground sm:min-h-8"
                            data-test="app-problem-fixing"
                        >
                            <LoaderCircle class="size-3.5 animate-spin" />
                            Being fixed
                        </Link>
                        <div v-else class="flex shrink-0 items-start gap-1">
                            <!-- A cleared problem is put away for the app,
                                 so the copy of a change only asks a fix. -->
                            <Form
                                v-if="!copy"
                                v-bind="
                                    ClearedProblemController.store.form(
                                        projectId,
                                    )
                                "
                                :transform="() => ({ problem: problem.id })"
                                :options="{
                                    preserveScroll: true,
                                    preserveState: true,
                                    only: ['problems'],
                                }"
                                v-slot="{ processing }"
                            >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="h-11 sm:h-8"
                                    :disabled="processing"
                                    title="Take it off the list. It shows again if it happens again."
                                    :data-test="`app-problem-clear-${problem.id}`"
                                    >Clear</Button
                                >
                            </Form>
                            <Form
                                v-bind="
                                    PreviewProblemFixController.store.form(
                                        projectId,
                                        { query: { copy } },
                                    )
                                "
                                :transform="() => ({ problem: problem.id })"
                                v-slot="{ processing, errors }"
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="h-11 sm:h-8"
                                    :disabled="processing"
                                    :data-test="`app-problem-fix-${problem.id}`"
                                    >Ask me to fix this</Button
                                >
                                <InputError :message="errors.fix" />
                            </Form>
                        </div>
                    </div>
                    <details class="text-xs text-muted-foreground">
                        <summary
                            class="min-h-11 cursor-pointer select-none sm:min-h-0"
                        >
                            Details for your developer
                        </summary>
                        <div class="mt-1 space-y-1 font-mono break-all">
                            <p class="text-foreground">
                                <template v-if="problem.class"
                                    >{{ problem.class }}:
                                </template>
                                {{ problem.message }}
                            </p>
                            <p v-if="problem.place">at {{ problem.place }}</p>
                            <p v-for="place in problem.trace" :key="place">
                                from {{ place }}
                            </p>
                        </div>
                    </details>
                </li>
            </ul>

            <details
                v-if="done.length > 0"
                class="shrink-0 border-t text-sm"
                data-test="app-problems-done"
            >
                <summary
                    class="flex min-h-11 cursor-pointer items-center px-3 text-xs text-muted-foreground select-none"
                >
                    Fixed or cleared ({{ done.length }})
                </summary>
                <ul class="max-h-60 overflow-y-auto">
                    <li
                        v-for="problem in done"
                        :key="problem.id"
                        class="flex items-center gap-2 border-t px-3 py-2"
                    >
                        <CircleCheck
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm">{{ problem.words }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    problem.state === 'fixed'
                                        ? 'Fixed'
                                        : 'Cleared'
                                }}
                                · {{ times(problem) }}
                            </p>
                        </div>
                        <Link
                            v-if="problem.state === 'fixed' && problem.change"
                            :href="
                                showProject(projectId, {
                                    query: { change: problem.change },
                                })
                            "
                            class="shrink-0 text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                            >See the fix</Link
                        >
                        <Form
                            v-else
                            v-bind="
                                ClearedProblemController.destroy.form([
                                    projectId,
                                    problem.id,
                                ])
                            "
                            :options="{
                                preserveScroll: true,
                                preserveState: true,
                                only: ['problems'],
                            }"
                            v-slot="{ processing }"
                        >
                            <Button
                                size="sm"
                                variant="ghost"
                                class="h-11 sm:h-8"
                                :disabled="processing"
                                :data-test="`app-problem-unclear-${problem.id}`"
                                >Show again</Button
                            >
                        </Form>
                    </li>
                </ul>
            </details>
        </template>
    </div>
</template>
