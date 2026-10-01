<script setup lang="ts">
import {
    CircleDot,
    Eye,
    FlaskConical,
    NotebookPen,
    Pencil,
    Play,
} from '@lucide/vue';
import type { WorkStep } from '@/types';

// One step of how a change was made. The agent's own plain words read as
// speech, its reasons before it acts as quieter asides, each stage of the work as a heading, and what was done as a
// quieter note with an icon.
defineProps<{ step: WorkStep }>();

const icons = {
    read: Eye,
    changed: Pencil,
    tested: FlaskConical,
    tried: Play,
    noted: NotebookPen,
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
        <component :is="icons[step.kind]" class="size-3.5 shrink-0" />
        {{ step.text }}
    </p>
</template>
