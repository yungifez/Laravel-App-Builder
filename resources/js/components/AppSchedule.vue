<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Clock } from '@lucide/vue';
import { ref } from 'vue';
import PreviewScheduledTaskRunController from '@/actions/App/Http/Controllers/PreviewScheduledTaskRunController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { ScheduledTask } from '@/types';

defineProps<{
    projectId: string;
    schedule: ScheduledTask[] | null | undefined;
    // The change the owner is trying, when the tools work on its copy.
    copy?: string | null;
}>();

const emit = defineEmits<{ ran: [] }>();

// The task run last, so the owner sees it went through.
const ran = ref<string | null>(null);

// How long until it runs next, which reads the same on every clock.
function next(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    const minutes = Math.round((new Date(iso).getTime() - Date.now()) / 60000);

    if (minutes < 1) {
        return 'runs next in under a minute';
    }

    if (minutes < 60) {
        return `runs next in ${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 48) {
        return `runs next in ${hours} ${hours === 1 ? 'hour' : 'hours'}`;
    }

    return `runs next in ${Math.round(hours / 24)} days`;
}
</script>

<template>
    <div
        class="flex h-full min-h-0 flex-col overflow-hidden rounded-lg border bg-background"
        data-test="app-schedule"
    >
        <div
            v-if="schedule === undefined"
            class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
        >
            Looking at what your app does on its own…
        </div>

        <div
            v-else-if="schedule === null || schedule.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center"
            data-test="app-schedule-empty"
        >
            <Clock class="size-6 text-muted-foreground" />
            <p class="text-lg font-medium">Nothing runs on its own</p>
            <p class="max-w-xs text-sm text-muted-foreground">
                Tasks your app runs at set times, such as a daily reminder
                email, show here. You can run them now to try them.
            </p>
        </div>

        <ul v-else class="min-h-0 flex-1 overflow-y-auto">
            <li
                v-for="task in schedule"
                :key="task.name"
                class="flex flex-wrap items-center gap-2 border-b px-3 py-2"
                :data-test="`app-task-${task.name}`"
            >
                <Clock class="size-4 shrink-0 text-muted-foreground" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm" :title="task.command">
                        {{ task.words }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ task.when }}
                        <template v-if="task.next">
                            · {{ next(task.next) }}</template
                        >
                    </p>
                </div>
                <Form
                    v-bind="
                        PreviewScheduledTaskRunController.store.form(
                            projectId,
                            {
                                query: { copy },
                            },
                        )
                    "
                    :transform="() => ({ task: task.name })"
                    :options="{
                        preserveScroll: true,
                        preserveState: true,
                        only: ['schedule'],
                    }"
                    class="flex shrink-0 flex-col items-end"
                    v-slot="{ processing, errors }"
                    @start="ran = null"
                    @success="
                        ran = task.name;
                        emit('ran');
                    "
                >
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-11 sm:h-8"
                        :disabled="processing"
                        :data-test="`app-task-run-${task.name}`"
                        >{{
                            processing
                                ? 'Running…'
                                : ran === task.name
                                  ? 'Ran just now'
                                  : 'Run it now'
                        }}</Button
                    >
                    <InputError :message="errors.task" />
                </Form>
            </li>
        </ul>
    </div>
</template>
