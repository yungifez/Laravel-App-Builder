<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { CircleAlert, Footprints } from '@lucide/vue';
import { computed, watch } from 'vue';
import { toast } from 'vue-sonner';
import PreviewFaultController from '@/actions/App/Http/Controllers/PreviewFaultController';
import type { AppHappenings, AppFault } from '@/types';

const props = defineProps<{
    projectId: string;
    happenings: AppHappenings | undefined;
    // The change the owner is trying, when the tools work on its copy.
    copy?: string | null;
}>();

// What the owner can pretend is down while they try the app. The app's
// pages then show what a visitor would see.
const faults: { key: AppFault; label: string }[] = [
    { key: 'none', label: 'All works' },
    { key: 'mail', label: 'Email is down' },
    { key: 'http', label: 'Outside services do not answer' },
    { key: 'file', label: 'Storage is full' },
];

const form = useForm<{ fault: AppFault }>({
    fault: props.happenings?.fault ?? 'none',
});
watch(
    () => props.happenings?.fault,
    (fault) => (form.fault = fault ?? 'none'),
);

function pretend(fault: AppFault): void {
    form.fault = fault;
    form.put(
        PreviewFaultController.update.url(props.projectId, {
            query: { copy: props.copy },
        }),
        {
            preserveScroll: true,
            preserveState: true,
            only: ['happenings'],
            onSuccess: () => {
                toast(
                    fault === 'none'
                        ? 'Your app works as usual again'
                        : `${faults.find((item) => item.key === fault)?.label}. Try your app and see what a visitor sees.`,
                );
            },
            onError: (errors) => {
                toast.error(
                    errors.fault ??
                        'This could not be set. This is our fault. Try again.',
                );
                router.reload({ only: ['happenings'] });
            },
        },
    );
}

const pretending = computed(
    () => props.happenings?.fault && props.happenings.fault !== 'none',
);
</script>

<template>
    <div
        class="flex h-full min-h-0 flex-col overflow-hidden rounded-lg border bg-background"
        data-test="app-happenings"
    >
        <!-- What to pretend sits at the top, so the list below shows what
             the app did while it was so. -->
        <div
            class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-3 py-2 text-sm"
            :class="pretending && 'bg-destructive/10'"
        >
            <label for="app-fault" class="text-muted-foreground">What if</label>
            <select
                id="app-fault"
                class="h-9 min-w-0 flex-1 rounded-md border bg-background px-2 text-sm sm:max-w-72"
                :value="form.fault"
                :disabled="form.processing"
                data-test="app-fault"
                @change="
                    pretend(
                        ($event.target as HTMLSelectElement).value as AppFault,
                    )
                "
            >
                <option
                    v-for="fault in faults"
                    :key="fault.key"
                    :value="fault.key"
                >
                    {{ fault.label }}
                </option>
            </select>
            <p
                v-if="pretending"
                class="basis-full text-xs text-destructive"
                data-test="app-fault-on"
            >
                Your app on show acts as if this is so, until you choose "All
                works" or it starts again. Nothing is sent or stored for real
                either way.
            </p>
        </div>

        <div
            v-if="happenings === undefined"
            class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
        >
            Looking at what your app did…
        </div>

        <div
            v-else-if="happenings.requests.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center"
            data-test="app-happenings-empty"
        >
            <Footprints class="size-6 text-muted-foreground" />
            <p class="text-lg font-medium">Nothing yet</p>
            <p class="max-w-xs text-sm text-muted-foreground">
                Use your app, then look here: each page says what your app
                saved, sent, stored and asked behind it.
            </p>
        </div>

        <ol
            v-else
            class="min-h-0 flex-1 overflow-y-auto"
            data-test="app-happenings-list"
        >
            <li
                v-for="request in happenings.requests"
                :key="request.id"
                class="border-b px-3 py-3"
                :data-test="`app-happening-${request.id}`"
            >
                <p class="text-sm font-medium">{{ request.page }}</p>
                <p
                    v-if="request.outcome"
                    :class="[
                        'text-xs',
                        request.status >= 500
                            ? 'text-destructive'
                            : 'text-muted-foreground',
                    ]"
                >
                    {{ request.outcome }}
                </p>
                <ul v-if="request.did.length > 0" class="mt-1 space-y-0.5">
                    <li
                        v-for="(step, index) in request.did"
                        :key="index"
                        :class="[
                            'flex items-start gap-1.5 text-sm',
                            step.failed
                                ? 'text-destructive'
                                : 'text-muted-foreground',
                        ]"
                    >
                        <CircleAlert
                            v-if="step.failed"
                            class="mt-0.5 size-3.5 shrink-0"
                        />
                        <span
                            v-else
                            class="mt-2 size-1.5 shrink-0 rounded-full bg-current opacity-60"
                        />
                        <span>{{ step.text }}</span>
                    </li>
                </ul>
                <p v-else class="mt-1 text-sm text-muted-foreground">
                    Only showed the page
                </p>
            </li>
        </ol>
    </div>
</template>
