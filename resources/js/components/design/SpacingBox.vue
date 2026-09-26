<script setup lang="ts">
import MeasureField from '@/components/design/MeasureField.vue';
import type { AppPreviewState } from '@/composables/useAppPreview';

defineProps<{ state: AppPreviewState }>();
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
                <MeasureField :state="state" property="margin_x" mark="↔" />
                <MeasureField :state="state" property="margin_y" mark="↕" />
            </div>
        </div>
        <div class="rounded-md border bg-muted/40 p-2">
            <div class="flex items-center justify-between gap-2">
                <span class="text-[11px] text-muted-foreground">Inside</span>
                <div class="grid w-40 grid-cols-2 gap-1">
                    <MeasureField
                        :state="state"
                        property="padding_x"
                        mark="↔"
                    />
                    <MeasureField
                        :state="state"
                        property="padding_y"
                        mark="↕"
                    />
                </div>
            </div>
        </div>
        <button
            type="button"
            class="mt-1 min-h-11 text-[11px] text-muted-foreground underline-offset-2 select-none hover:text-foreground hover:underline sm:min-h-6"
            @click="state.change('margin_x', 'auto')"
        >
            Centre it
        </button>
    </div>
</template>
