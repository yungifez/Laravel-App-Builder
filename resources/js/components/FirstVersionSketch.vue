<script setup lang="ts">
import { computed } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import type { FirstVersionSketch } from '@/types';

const props = defineProps<{
    sketch: FirstVersionSketch;
    /** Says what is being done now, under the drawing. */
    building?: boolean;
}>();

// The app's own look, as its stylesheet has it. An app without one is
// drawn in the workspace's own quiet colours.
const look = computed(() => {
    const own = props.sketch.look;

    return {
        '--sketch-bg': own?.background ?? 'var(--background)',
        '--sketch-fg': own?.foreground ?? 'var(--foreground)',
        '--sketch-primary': own?.primary ?? 'var(--muted-foreground)',
        '--sketch-muted': own?.muted_foreground ?? 'var(--muted-foreground)',
        '--sketch-border': own?.border ?? 'var(--border)',
        '--sketch-radius': own?.radius ?? '0.5rem',
        fontFamily: own?.font
            ? `'${own.font}', ui-sans-serif, system-ui, sans-serif`
            : undefined,
    };
});
</script>

<template>
    <!-- A drawing, not the app: muted shapes with nothing to click, so it
         never passes for the real thing. -->
    <div
        class="@container absolute inset-0 flex flex-col overflow-hidden bg-(--sketch-bg) text-(--sketch-fg) select-none"
        :style="look"
        data-test="first-version-sketch"
    >
        <div
            class="pointer-events-none flex min-h-0 flex-1 flex-col"
            aria-hidden="true"
        >
            <div
                class="flex h-12 shrink-0 items-center gap-2 border-b border-(--sketch-border) px-4"
            >
                <span
                    class="size-5 shrink-0 rounded-(--sketch-radius) bg-(--sketch-primary) opacity-80"
                />
                <span
                    class="truncate text-sm font-semibold"
                    data-test="sketch-name"
                    >{{ sketch.name }}</span
                >
            </div>
            <div class="flex min-h-0 flex-1 flex-col @2xl:flex-row">
                <ul
                    v-if="sketch.parts.length > 0"
                    class="flex max-h-36 shrink-0 flex-col gap-0.5 overflow-hidden border-b border-(--sketch-border) p-3 @2xl:max-h-none @2xl:w-56 @2xl:border-r @2xl:border-b-0"
                >
                    <li
                        v-for="part in sketch.parts"
                        :key="part.name"
                        :class="[
                            'flex items-center gap-2 px-2 py-1.5 text-sm motion-safe:transition-[color,opacity] motion-safe:duration-700',
                            part.made
                                ? 'text-(--sketch-fg)'
                                : 'text-(--sketch-muted) opacity-60',
                        ]"
                        :data-test="
                            part.made ? 'sketch-part-made' : 'sketch-part'
                        "
                    >
                        <span
                            :class="[
                                'size-1.5 shrink-0 rounded-full motion-safe:transition-colors motion-safe:duration-700',
                                part.made
                                    ? 'bg-(--sketch-primary)'
                                    : 'bg-(--sketch-border)',
                            ]"
                        />
                        <span class="truncate">{{ part.name }}</span>
                    </li>
                </ul>
                <div class="flex min-h-0 flex-1 flex-col gap-3 p-6 opacity-60">
                    <span
                        class="h-6 w-2/5 rounded-(--sketch-radius) bg-(--sketch-border)"
                    />
                    <span
                        class="h-3 w-3/5 rounded-(--sketch-radius) bg-(--sketch-border)"
                    />
                    <div class="mt-3 grid grid-cols-1 gap-3 @lg:grid-cols-2">
                        <span
                            class="h-24 rounded-(--sketch-radius) border border-(--sketch-border)"
                        />
                        <span
                            class="hidden h-24 rounded-(--sketch-radius) border border-(--sketch-border) @lg:block"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="building"
            class="absolute inset-x-0 bottom-4 flex justify-center px-4"
        >
            <p
                role="status"
                class="flex max-w-full items-center gap-2 rounded-full border bg-background/95 px-3 py-1.5 font-sans text-sm text-muted-foreground shadow-sm"
                data-test="first-version-making"
            >
                <Spinner class="size-4 shrink-0" />
                <span class="truncate">{{
                    sketch.now
                        ? `Building your app: ${sketch.now}`
                        : 'Making the first version of your app…'
                }}</span>
            </p>
        </div>
    </div>
</template>
