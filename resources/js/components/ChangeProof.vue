<script setup lang="ts">
import {
    CircleCheck,
    CircleDashed,
    ScanSearch,
    ShieldCheck,
} from '@lucide/vue';
import type { ProofLine } from '@/types';

// How we know a change works, in plain words: what the checks proved, how
// far the app's own tests reached into the change, what was caught and
// fixed before the owner saw it, and what nothing checks yet. A preview
// shows a change looks right; this shows why to trust it.
defineProps<{ proof: ProofLine[] }>();

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
        <ul class="space-y-1">
            <li
                v-for="line in proof"
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
