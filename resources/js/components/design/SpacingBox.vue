<script setup lang="ts">
import PixelField from '@/components/design/PixelField.vue';
import type { VisualProperty, VisualValue } from '@/types';

defineProps<{
    valueOf: (property: VisualProperty) => VisualValue | null;
}>();

const emit = defineEmits<{
    change: [property: VisualProperty, value: VisualValue | null];
}>();
</script>

<template>
    <!-- Space around a part, drawn the way it sits on the page: outside the
         edge, then inside it, each as left-and-right and top-and-bottom. -->
    <div
        class="rounded-lg border border-dashed p-2"
        role="group"
        aria-label="Space"
    >
        <div class="flex items-center justify-between gap-2 pb-2">
            <span class="text-[11px] text-muted-foreground">Outside</span>
            <div class="grid w-40 grid-cols-2 gap-1">
                <PixelField
                    label="Outside, left and right"
                    :value="valueOf('margin_x')"
                    allow-negative
                    unit="↔"
                    @change="emit('change', 'margin_x', $event)"
                />
                <PixelField
                    label="Outside, top and bottom"
                    :value="valueOf('margin_y')"
                    allow-negative
                    unit="↕"
                    @change="emit('change', 'margin_y', $event)"
                />
            </div>
        </div>
        <div class="rounded-md border bg-muted/40 p-2">
            <div class="flex items-center justify-between gap-2">
                <span class="text-[11px] text-muted-foreground">Inside</span>
                <div class="grid w-40 grid-cols-2 gap-1">
                    <PixelField
                        label="Inside, left and right"
                        :value="valueOf('padding_x')"
                        unit="↔"
                        @change="emit('change', 'padding_x', $event)"
                    />
                    <PixelField
                        label="Inside, top and bottom"
                        :value="valueOf('padding_y')"
                        unit="↕"
                        @change="emit('change', 'padding_y', $event)"
                    />
                </div>
            </div>
        </div>
        <button
            type="button"
            class="mt-1 min-h-11 text-[11px] text-muted-foreground underline-offset-2 select-none hover:text-foreground hover:underline sm:min-h-6"
            @click="emit('change', 'margin_x', 'auto')"
        >
            Centre it
        </button>
    </div>
</template>
