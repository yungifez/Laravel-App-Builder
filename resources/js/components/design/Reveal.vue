<script setup lang="ts">
defineProps<{ open: boolean }>();
</script>

<template>
    <!-- Rows that only make sense for some choices, such as a direction for
         a row or column. They unfold and fold away instead of popping in
         and pushing the rows below. Made for a space-y-2 stack: it takes
         back the gap above it, and gives it again only when open. -->
    <div
        :class="[
            '-mt-2 grid transition-[grid-template-rows,opacity] duration-base ease-settle',
            open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] opacity-0',
        ]"
        :inert="!open"
    >
        <div class="min-h-0 overflow-clip [overflow-clip-margin:0.25rem]">
            <div class="space-y-2 pt-2">
                <slot />
            </div>
        </div>
    </div>
</template>
