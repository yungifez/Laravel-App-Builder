<script setup lang="ts">
import {
    CircleCheck,
    CircleDashed,
    ScanSearch,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import type { ProofLine } from '@/types';

// How we know a change works, in plain words: what the checks proved, how
// far the app's own tests reached into the change, what was caught and
// fixed before the owner saw it, and what nothing checks yet. A preview
// shows a change looks right; this shows why to trust it.
const props = defineProps<{ proof: ProofLine[] }>();

// Passes lead: the first one shows, and the rest fold behind a count so
// the list stays short. Everything else (problems caught, how far the
// tests reached, gaps) always shows after them; a gap is never folded.
const passes = computed(() =>
    props.proof.filter((line) => line.kind === 'passed'),
);
const others = computed(() =>
    props.proof.filter((line) => line.kind !== 'passed'),
);

const icons = {
    passed: { icon: CircleCheck, tone: 'text-green-600' },
    caught: { icon: ShieldCheck, tone: 'text-green-600' },
    reach: { icon: ScanSearch, tone: 'text-muted-foreground' },
    gap: { icon: CircleDashed, tone: 'text-amber-600' },
};
</script>

<template>
    <section
        v-if="proof.length > 0"
        class="space-y-1.5"
        data-test="change-proof"
    >
        <h3 class="text-xs font-medium">How I know it works</h3>
        <p
            v-if="passes.length > 0"
            class="flex items-start gap-2 text-xs text-muted-foreground"
        >
            <CircleCheck class="mt-px size-3.5 shrink-0 text-green-600" />
            {{ passes[0].text }}
        </p>
        <details
            v-if="passes.length > 1"
            class="group text-xs text-muted-foreground"
            data-test="change-proof-more"
        >
            <summary
                class="flex min-h-11 cursor-pointer list-none items-center gap-2 select-none hover:text-foreground sm:min-h-6 [&::-webkit-details-marker]:hidden"
            >
                <CircleCheck class="size-3.5 shrink-0 text-green-600" />
                <span class="underline-offset-2 group-hover:underline">
                    {{ passes.length - 1 }} more
                    {{ passes.length === 2 ? 'check' : 'checks' }} passed
                </span>
            </summary>
            <ul class="mt-1 space-y-1 pl-5.5">
                <li v-for="line in passes.slice(1)" :key="line.text">
                    {{ line.text }}
                </li>
            </ul>
        </details>
        <ul v-if="others.length > 0" class="space-y-1">
            <li
                v-for="line in others"
                :key="line.text"
                class="flex items-start gap-2 text-xs text-muted-foreground"
            >
                <component
                    :is="icons[line.kind].icon"
                    :class="['mt-px size-3.5 shrink-0', icons[line.kind].tone]"
                />
                {{ line.text }}
            </li>
        </ul>
    </section>
</template>
