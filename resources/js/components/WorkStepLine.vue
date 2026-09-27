<script setup lang="ts">
import {
    Eye,
    FlaskConical,
    NotebookPen,
    Pencil,
    Play,
    RotateCcw,
} from '@lucide/vue';
import type { WorkStep } from '@/types';

// One step of how a change was made. The agent's own plain words read as
// speech; what it did reads as a quieter note with an icon.
defineProps<{ step: WorkStep }>();

const icons = {
    read: Eye,
    changed: Pencil,
    tested: FlaskConical,
    tried: Play,
    noted: NotebookPen,
    repair: RotateCcw,
};
</script>

<template>
    <p v-if="step.kind === 'thought'" class="leading-relaxed">
        {{ step.text }}
    </p>
    <p v-else class="flex items-center gap-2 text-xs text-muted-foreground">
        <component :is="icons[step.kind]" class="size-3.5 shrink-0" />
        {{ step.text }}
    </p>
</template>
