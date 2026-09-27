<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { radii } from '@/lib/visualProperties';
import type { VisualValue } from '@/types';

const props = defineProps<{
    label: string;
    kind: 'color' | 'radius';
    value: VisualValue | null;
    options: { value: VisualValue; label: string }[];
    /** The app's own colours by token, as it draws them. */
    colors?: Record<string, string>;
}>();

const emit = defineEmits<{ change: [value: VisualValue | null] }>();

// Two theme colours the app draws the same are one choice to the owner:
// the later one is left out, unless it is the one chosen.
const shown = computed(() => {
    if (props.kind !== 'color' || props.colors === undefined) {
        return props.options;
    }

    const seen = new Set<string>();

    return props.options.filter((option) => {
        const drawn = props.colors?.[option.value] ?? String(option.value);

        if (seen.has(drawn) && option.value !== props.value) {
            return false;
        }

        seen.add(drawn);

        return true;
    });
});

// Each swatch shows the choice itself: the colour or the corner.
function look(kind: string, option: VisualValue): Record<string, string> {
    switch (kind) {
        case 'color':
            return option === 'transparent'
                ? {
                      background:
                          'repeating-linear-gradient(45deg, var(--muted) 0 3px, transparent 3px 6px)',
                  }
                : {
                      background: props.colors?.[option] ?? `var(--${option})`,
                  };
        default:
            return {
                borderTopLeftRadius: radii[option] ?? '0',
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
                v-for="option in shown"
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
                    v-else
                    class="size-4 border-t-2 border-l-2 border-foreground/70"
                    :style="look(kind, option.value)"
                />
            </button>
        </div>
    </div>
</template>
