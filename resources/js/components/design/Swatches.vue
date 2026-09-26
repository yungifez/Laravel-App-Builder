<script setup lang="ts">
import { Check } from '@lucide/vue';
import { radii, shadows } from '@/lib/visualProperties';
import type { VisualValue } from '@/types';

defineProps<{
    label: string;
    kind: 'color' | 'radius' | 'shadow';
    value: VisualValue | null;
    options: { value: VisualValue; label: string }[];
}>();

const emit = defineEmits<{ change: [value: VisualValue | null] }>();

// Each swatch shows the choice itself: the colour, the corner or the shadow.
function look(kind: string, option: VisualValue): Record<string, string> {
    switch (kind) {
        case 'color':
            return option === 'transparent'
                ? {
                      background:
                          'repeating-linear-gradient(45deg, var(--muted) 0 3px, transparent 3px 6px)',
                  }
                : { background: `var(--${option})` };
        case 'radius':
            return {
                borderTopLeftRadius: radii[option] ?? '0',
            };
        default:
            return {
                boxShadow:
                    option === 'none' ? 'none' : (shadows[option] ?? 'none'),
            };
    }
}
</script>

<template>
    <div class="flex items-center gap-2">
        <span class="w-14 shrink-0 text-xs text-muted-foreground">{{
            label
        }}</span>
        <div
            class="flex min-w-0 flex-1 flex-wrap gap-0.5"
            role="group"
            :aria-label="label"
        >
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                :aria-pressed="value === option.value"
                :aria-label="option.label"
                :title="option.label"
                :class="[
                    'grid size-11 place-items-center rounded-md select-none sm:size-7',
                    value === option.value
                        ? 'ring-2 ring-foreground ring-offset-1 ring-offset-background'
                        : 'hover:bg-muted',
                ]"
                @click="
                    emit('change', value === option.value ? null : option.value)
                "
            >
                <span
                    v-if="kind === 'color'"
                    class="grid size-5 place-items-center rounded-full border shadow-xs"
                    :style="look(kind, option.value)"
                >
                    <Check
                        v-if="value === option.value"
                        class="size-3 text-white mix-blend-difference"
                    />
                </span>
                <span
                    v-else-if="kind === 'radius'"
                    class="size-4 border-t-2 border-l-2 border-foreground/70"
                    :style="look(kind, option.value)"
                />
                <span
                    v-else
                    class="grid size-6 place-items-center rounded bg-zinc-100"
                >
                    <span
                        class="size-3.5 rounded-sm bg-white"
                        :style="look(kind, option.value)"
                    />
                </span>
            </button>
        </div>
    </div>
</template>
