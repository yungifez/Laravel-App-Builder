<script setup lang="ts">
import { useNow } from '@vueuse/core';
import { computed } from 'vue';

// How long something has run, counted each second. It lives in its own
// component so the tick re-renders only this text, not the thread.
const props = defineProps<{ since: string }>();

const now = useNow({ interval: 1000 });

const text = computed(() => {
    const seconds = Math.max(
        0,
        Math.floor((now.value.getTime() - Date.parse(props.since)) / 1000),
    );

    return seconds < 60
        ? `${seconds}s`
        : `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s`;
});
</script>

<template>
    <span class="tabular-nums">{{ text }}</span>
</template>
