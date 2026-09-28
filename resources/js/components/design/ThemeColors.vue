<script setup lang="ts">
import { computed, ref } from 'vue';
import { themeColorLabel, themeColors } from '@/lib/themeColors';

const props = defineProps<{
    /** The app's own theme colours as it draws them now, by token. */
    colors: Record<string, string>;
    /** Whether the app shows its dark look, whose colours are its own. */
    dark: boolean;
}>();

const emit = defineEmits<{
    /** The owner is trying a colour: show it, do not save it yet. */
    preview: [token: string, value: string];
    /** The owner chose a colour. */
    change: [token: string, value: string];
}>();

// A colour the app does not have is left out.
const shown = computed(() =>
    themeColors.filter((choice) => props.colors[choice.token] !== undefined),
);

// The colour the owner points at or is on, named in the heading, since a
// phone has no hover to show a swatch's name.
const pointed = ref<string | null>(null);

// A colour picker takes "#rrggbb"; the app may write its colours any way
// CSS allows, so the browser draws one and reads it back.
let canvas: CanvasRenderingContext2D | null = null;

function hex(color: string | undefined): string {
    if (color === undefined || typeof document === 'undefined') {
        return '#000000';
    }

    canvas ??= document
        .createElement('canvas')
        .getContext('2d', { willReadFrequently: true });

    if (canvas === null) {
        return '#000000';
    }

    canvas.clearRect(0, 0, 1, 1);
    canvas.fillStyle = color;
    canvas.fillRect(0, 0, 1, 1);

    const [red, green, blue] = canvas.getImageData(0, 0, 1, 1).data;

    return `#${[red, green, blue].map((part) => part.toString(16).padStart(2, '0')).join('')}`;
}

function picked(event: Event): string {
    return (event.target as HTMLInputElement).value;
}
</script>

<template>
    <!-- Each colour changes every part drawn in it, for the look the app
         shows now, so the owner sets the app's colours in one place. -->
    <section v-if="shown.length > 0" class="px-4 pb-4" data-test="app-colours">
        <h3 class="pb-1 text-xs font-medium text-muted-foreground">
            {{
                pointed === null
                    ? "Your app's colours"
                    : themeColorLabel(pointed)
            }}<span v-if="dark"> in the dark</span>
        </h3>
        <div
            class="grid grid-cols-9"
            role="group"
            aria-label="Your app's colours"
        >
            <label
                v-for="choice in shown"
                :key="choice.token"
                class="relative grid aspect-square max-h-11 cursor-pointer place-items-center rounded-md select-none focus-within:ring-2 focus-within:ring-foreground hover:bg-muted"
                @pointerenter="pointed = choice.token"
                @pointerleave="pointed = null"
                @focusin="pointed = choice.token"
                @focusout="pointed = null"
            >
                <span
                    class="size-5 rounded-full border shadow-xs"
                    :style="{ background: colors[choice.token] }"
                />
                <input
                    type="color"
                    class="absolute inset-0 size-full cursor-pointer opacity-0"
                    :aria-label="choice.label"
                    :value="hex(colors[choice.token])"
                    :data-test="`app-colour-${choice.token}`"
                    @input="emit('preview', choice.token, picked($event))"
                    @change="emit('change', choice.token, picked($event))"
                />
            </label>
        </div>
    </section>
</template>
