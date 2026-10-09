<script setup lang="ts">
import {
    CircleAlert,
    CircleCheck,
    CircleDot,
    CircleMinus,
    Eye,
    FlaskConical,
    NotebookPen,
    Pencil,
    Play,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type { WorkStep } from '@/types';

// One step of how a change was made. The agent's own plain words read as
// speech, its reasons before it acts as quieter asides, each stage of the work as a heading, and what was done as a
// quieter note with an icon.
defineProps<{ step: WorkStep }>();

// A finished check shows what it found: passed, failed, or failed as it
// did before the change, which is the app's old problem, not a new one.
const icons: Record<
    Exclude<WorkStep['kind'], 'thought' | 'thinking' | 'stage'>,
    { icon: LucideIcon; tone?: string }
> = {
    read: { icon: Eye },
    changed: { icon: Pencil },
    tested: { icon: FlaskConical },
    tried: { icon: Play },
    noted: { icon: NotebookPen },
    passed: { icon: CircleCheck, tone: 'text-green-600' },
    failed: { icon: CircleAlert, tone: 'text-red-600' },
    known: { icon: CircleMinus },
};
</script>

<template>
    <p v-if="step.kind === 'thought'" class="leading-relaxed">
        {{ step.text }}
    </p>
    <p
        v-else-if="step.kind === 'thinking'"
        class="leading-relaxed text-muted-foreground italic"
    >
        {{ step.text }}
    </p>
    <p
        v-else-if="step.kind === 'stage'"
        class="flex items-center gap-2 pt-1 text-xs font-medium"
    >
        <CircleDot class="size-3.5 shrink-0 text-muted-foreground" />
        {{ step.text }}
    </p>
    <p v-else class="flex items-center gap-2 text-xs text-muted-foreground">
        <component
            :is="icons[step.kind].icon"
            :class="['size-3.5 shrink-0', icons[step.kind].tone]"
        />
        {{ step.text }}
    </p>
</template>
