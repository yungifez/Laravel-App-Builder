<script setup lang="ts">
import type { RequestImage } from '@/types';

// The pictures an owner attached to a message, beside what they wrote. A tap
// opens one at full size.
withDefaults(
    defineProps<{ images: RequestImage[]; align?: 'start' | 'end' }>(),
    { align: 'end' },
);
</script>

<template>
    <ul
        v-if="images.length > 0"
        :class="[
            'flex flex-wrap gap-1.5',
            align === 'end' ? 'justify-end' : 'justify-start',
        ]"
        data-test="message-images"
    >
        <li v-for="image in images" :key="image.url">
            <a
                :href="image.url"
                target="_blank"
                rel="noopener"
                class="block rounded-lg focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            >
                <img
                    :src="image.url"
                    :alt="image.name"
                    loading="lazy"
                    class="size-20 rounded-lg border object-cover"
                />
            </a>
        </li>
    </ul>
</template>
