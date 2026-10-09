<script setup lang="ts">
import { router, useForm, useHttp } from '@inertiajs/vue3';
import { CircleAlert, Copy, Footprints, LoaderCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import PreviewFaultController from '@/actions/App/Http/Controllers/PreviewFaultController';
import PreviewTwiceController from '@/actions/App/Http/Controllers/PreviewTwiceController';
import { faults } from '@/lib/appFaults';
import type { AppHappenings, AppFault } from '@/types';

const props = defineProps<{
    projectId: string;
    happenings: AppHappenings | undefined;
    // The change the owner is trying, when the tools work on its copy.
    copy?: string | null;
}>();

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
const chosen = computed(() => faults.find((item) => item.key === form.fault));

// The newest form sent again twice at the same moment, as a double click
// or two people at once would. What came of it stays under that form.
const twice = useHttp({});
const twiceWords = ref<{ words: string; broke: boolean } | null>(null);

async function sendTwice(): Promise<void> {
    twiceWords.value = null;

    try {
        const { words, broke } = (await twice.post(
            PreviewTwiceController.store.url(props.projectId, {
                query: { copy: props.copy },
            }),
        )) as { words: string; broke: boolean };

        twiceWords.value = { words, broke };
        router.reload({ only: ['happenings'] });
    } catch {
        const errors = twice.errors as Record<string, string | undefined>;

        toast.error(
            errors.app ??
                'Your app could not be sent the form twice. This is our fault. Try again.',
        );
    }
}
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
                :title="
                    chosen?.hint ||
                    'Pretend one thing is down and see what a visitor sees'
                "
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
                {{ chosen?.hint }} Your app on show acts as if this is so, until
                you choose "All works" or it starts again. Nothing is sent or
                stored for real either way.
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
                Use your app, then come back here to see what it did behind each
                page.
            </p>
        </div>

        <ol
            v-else
            class="min-h-0 flex-1 overflow-y-auto"
            data-test="app-happenings-list"
        >
            <li
                v-for="(request, place) in happenings.requests"
                :key="request.id"
                class="border-b px-3 py-3"
                :data-test="`app-happening-${request.id}`"
            >
                <p class="text-sm font-medium">
                    {{ request.page }}
                    <span
                        v-if="request.times > 1"
                        class="font-normal text-muted-foreground"
                        :data-test="`app-happening-times-${request.id}`"
                    >
                        · {{ request.times }} times
                    </span>
                </p>
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
                <div
                    v-if="request.id === happenings.again"
                    class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1"
                >
                    <button
                        type="button"
                        class="inline-flex min-h-9 items-center gap-1.5 text-sm text-primary underline-offset-4 hover:underline disabled:opacity-60"
                        :disabled="twice.processing"
                        title="Send this form again twice, at the same moment, as a double click or two people at once would"
                        data-test="app-happening-twice"
                        @click="sendTwice"
                    >
                        <LoaderCircle
                            v-if="twice.processing"
                            class="size-3.5 animate-spin"
                        />
                        <Copy v-else class="size-3.5" />
                        What if this is sent twice at once?
                    </button>
                </div>
                <p
                    v-if="twiceWords && place === 0"
                    :class="[
                        'mt-2 text-sm',
                        twiceWords.broke
                            ? 'text-destructive'
                            : 'text-muted-foreground',
                    ]"
                    data-test="app-happening-twice-words"
                >
                    Sent twice at once: {{ twiceWords.words }}
                </p>
            </li>
        </ol>
    </div>
</template>
