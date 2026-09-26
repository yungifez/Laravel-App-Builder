<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { FeatureRequestStatus } from '@/types';

const props = defineProps<{ status: FeatureRequestStatus }>();

const labels: Record<FeatureRequestStatus, string> = {
    generating: 'Working on it',
    generated: 'Ready for you',
    failed: 'Could not finish',
    cancelled: 'Stopped',
};

const variant = computed(() =>
    props.status === 'failed'
        ? 'destructive'
        : props.status === 'generated'
          ? 'default'
          : 'secondary',
);
</script>

<template>
    <Badge :variant="variant">{{ labels[status] }}</Badge>
</template>
