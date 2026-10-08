<script setup lang="ts">
import { Form, router, usePoll } from '@inertiajs/vue3';
import { Clock } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import PreviewClockController from '@/actions/App/Http/Controllers/PreviewClockController';
import PreviewScheduledTaskRunController from '@/actions/App/Http/Controllers/PreviewScheduledTaskRunController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { AppClock, ScheduledTask } from '@/types';

const props = defineProps<{
    projectId: string;
    schedule: ScheduledTask[] | null | undefined;
    clock?: AppClock | null;
    // The change the owner is trying, when the tools work on its copy.
    copy?: string | null;
}>();

const emit = defineEmits<{ ran: [] }>();

const jumps = [
    { key: 'day', label: 'A day' },
    { key: 'week', label: 'A week' },
    { key: 'month', label: 'A month' },
] as const;

// The jump asked for, until the app has moved.
const asked = ref<string | null>(null);
const moving = computed(() => asked.value !== null || !!props.clock?.moving);

// A jump runs in the background: follow it until it is done.
const clockPoll = usePoll(
    2000,
    { only: ['clock', 'schedule'] },
    { autoStart: false },
);

watch(
    () => props.clock?.moving,
    (now, before) => {
        if (now) {
            clockPoll.start();

            return;
        }

        clockPoll.stop();

        // What the skipped time ran may have sent email or hit a problem.
        if (before) {
            emit('ran');
        }
    },
    { immediate: true },
);

function move(jump: string): void {
    asked.value = jump;
    router.put(
        PreviewClockController.update.url(props.projectId, {
            query: { copy: props.copy },
        }),
        { jump },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['clock', 'schedule'],
            onSuccess: () => {
                if (jump === 'today') {
                    toast('Your app is back to today');
                }
            },
            onError: (errors) =>
                toast.error(
                    errors.jump ??
                        'Your app could not move in time. This is our fault. Try again.',
                ),
            onFinish: () => (asked.value = null),
        },
    );
}

// The date in the app, the way the owner reads dates.
const today = computed(() =>
    props.clock
        ? new Date(props.clock.now).toLocaleString(undefined, {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '',
);

// What the last jump ran, in one line.
const ranWords = computed(() =>
    (props.clock?.ran ?? [])
        .map((task) =>
            task.times === 1 ? task.words : `${task.words} ×${task.times}`,
        )
        .join(', '),
);
const failedWords = computed(() =>
    (props.clock?.ran ?? [])
        .filter((task) => task.failed > 0)
        .map((task) => task.words)
        .join(', '),
);

// The task run last, so the owner sees it went through.
const ran = ref<string | null>(null);

// How long until it runs next, which reads the same on every clock.
function next(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    // From the time in the app, which can be ahead of the real one.
    const minutes = Math.round(
        (new Date(iso).getTime() -
            Date.now() -
            (props.clock?.ahead ?? 0) * 1000) /
            60000,
    );

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
        <!-- Time sits at the top, so the tasks below run by the app's day. -->
        <div
            v-if="clock"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-3 py-2 text-sm"
            :class="clock.ahead > 0 && 'bg-primary/10'"
            data-test="app-clock"
        >
            <p class="min-w-0 flex-1">
                <span class="text-muted-foreground">In your app it is</span>
                {{ today }}
            </p>
            <div class="flex shrink-0 items-center gap-1">
                <span class="mr-1 text-muted-foreground">Jump ahead</span>
                <Button
                    v-for="jump in jumps"
                    :key="jump.key"
                    size="sm"
                    variant="outline"
                    class="h-11 sm:h-8"
                    :disabled="moving"
                    :data-test="`app-clock-${jump.key}`"
                    @click="move(jump.key)"
                    >{{ jump.label }}</Button
                >
                <Button
                    v-if="clock.ahead > 0"
                    size="sm"
                    variant="ghost"
                    class="h-11 sm:h-8"
                    :disabled="moving"
                    data-test="app-clock-today"
                    @click="move('today')"
                    >Back to today</Button
                >
            </div>
            <p
                v-if="moving"
                class="basis-full text-xs text-muted-foreground"
                data-test="app-clock-moving"
            >
                Moving ahead, and running what your app would have done in that
                time…
            </p>
            <p
                v-else-if="clock.error"
                class="basis-full text-xs text-destructive"
                data-test="app-clock-error"
            >
                {{ clock.error }}
            </p>
            <p
                v-else-if="clock.ahead > 0"
                class="basis-full text-xs text-muted-foreground"
                data-test="app-clock-ran"
            >
                <template v-if="ranWords">Ran {{ ranWords }}. </template>
                <span v-if="failedWords" class="text-destructive"
                    >{{ failedWords }} failed. See Problems.
                </span>
                What happened in that time stays when you go back.
            </p>
        </div>

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
