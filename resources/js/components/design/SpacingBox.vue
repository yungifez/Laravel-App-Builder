<script setup lang="ts">
import { AlignHorizontalJustifyCenter } from '@lucide/vue';
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
            <button
                type="button"
                class="ml-auto flex size-11 items-center justify-center rounded text-muted-foreground select-none hover:bg-muted hover:text-foreground sm:size-7"
                aria-label="Centre it"
                title="Centre it"
                data-test="centre-it"
                @click="state.change('margin_x', 'auto')"
            >
                <AlignHorizontalJustifyCenter class="size-4" />
            </button>
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
    </div>
</template>
