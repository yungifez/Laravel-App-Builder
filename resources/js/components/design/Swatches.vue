<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { radii } from '@/lib/visualProperties';
import type { VisualValue } from '@/types';

const props = defineProps<{
    label: string;
    /** The group's full name, when the label is short, as "Colour" is. */
    name?: string;
    kind: 'color' | 'radius';
    value: VisualValue | null;
    options: { value: VisualValue; label: string }[];
    /** The app's own colours by token, as it draws them. */
    colors?: Record<string, string>;
    /** The colour the part is drawn in now. */
    own?: string;
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

// With nothing chosen, the swatch the part is drawn in now is marked
// quietly, so the owner sees where they start from.
const current = computed(() => {
    if (props.kind !== 'color' || props.value != null || !props.own) {
        return null;
    }

    return (
        shown.value.find(
            (option) =>
                (option.value === 'transparent'
                    ? 'rgba(0, 0, 0, 0)'
                    : props.colors?.[option.value]) === props.own,
        )?.value ?? null
    );
});

// A colour of its own is none of the choices, so it is shown first, as
// the part draws it: marked as the one in use when it was set, or
// quietly as where the owner starts from when nothing is chosen.
const ownColor = computed(() =>
    props.kind === 'color' &&
    props.own &&
    (props.value === 'custom' ||
        (props.value == null && current.value === null))
        ? props.own
        : null,
);

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
                      // Until the app's colours are read, the swatch stays
                      // blank: a variable of the same name here would be
                      // the editor's own colour, not the app's.
                      background: props.colors?.[option] ?? 'var(--muted)',
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
            :aria-label="name ?? label"
        >
            <span
                v-if="ownColor"
                role="img"
                :aria-label="
                    value === 'custom'
                        ? 'Its own colour, in use'
                        : 'Its own colour, as it is now'
                "
                :title="
                    value === 'custom'
                        ? 'Its own colour'
                        : 'Its own colour (as it is now)'
                "
                :class="[
                    'grid size-11 place-items-center rounded-md ring-offset-1 ring-offset-background select-none sm:size-7',
                    value === 'custom'
                        ? 'ring-2 ring-foreground'
                        : 'ring-1 ring-muted-foreground/60',
                ]"
                data-test="own-colour"
                :data-current="value !== 'custom' || undefined"
            >
                <span
                    class="grid size-5 place-items-center rounded-full border shadow-xs"
                    :style="{
                        background: `linear-gradient(${ownColor}, ${ownColor}), repeating-linear-gradient(45deg, var(--muted) 0 3px, transparent 3px 6px)`,
                    }"
                >
                    <Check
                        v-if="value === 'custom'"
                        class="size-3 text-white mix-blend-difference"
                    />
                </span>
            </span>
            <button
                v-for="option in shown"
                :key="option.value"
                type="button"
                :aria-pressed="value === option.value"
                :aria-label="
                    current === option.value
                        ? `${option.label}, as it is now`
                        : option.label
                "
                :title="
                    current === option.value
                        ? `${option.label} (as it is now)`
                        : option.label
                "
                :data-current="current === option.value || undefined"
                :class="[
                    'grid size-11 place-items-center rounded-md select-none sm:size-7',
                    value === option.value
                        ? 'ring-2 ring-foreground ring-offset-1 ring-offset-background'
                        : current === option.value
                          ? 'ring-1 ring-muted-foreground/60 ring-offset-1 ring-offset-background hover:bg-muted'
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
