<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { CircleCheck, TriangleAlert } from '@lucide/vue';
import PreviewProblemFixController from '@/actions/App/Http/Controllers/PreviewProblemFixController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { when } from '@/lib/when';
import type { AppProblem } from '@/types';

defineProps<{
    projectId: number;
    problems: AppProblem[] | undefined;
}>();

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

        <div
            v-else-if="problems.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center"
            data-test="app-problems-empty"
        >
            <CircleCheck class="size-6 text-muted-foreground" />
            <p class="text-lg font-medium">No problems</p>
            <p class="max-w-xs text-sm text-muted-foreground">
                If something goes wrong while you try your app, it shows here,
                and I can fix it.
            </p>
        </div>

        <ul v-else class="min-h-0 flex-1 overflow-y-auto">
            <li
                v-for="problem in problems"
                :key="problem.id"
                class="flex flex-col gap-2 border-b px-3 py-3"
                :data-test="`app-problem-${problem.id}`"
            >
                <div class="flex items-start gap-2">
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0 text-destructive"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm">{{ problem.words }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                problem.count === 1
                                    ? 'Once'
                                    : `${problem.count} times`
                            }}
                            · last at {{ at(problem.last_at) }}
                        </p>
                    </div>
                    <Form
                        v-bind="
                            PreviewProblemFixController.store.form(projectId)
                        "
                        :transform="() => ({ problem: problem.id })"
                        v-slot="{ processing, errors }"
                        class="shrink-0"
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
    </div>
</template>
