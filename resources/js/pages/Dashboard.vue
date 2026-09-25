<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
        <Heading
            title="Internal builder prototype"
            description="Control plane for the builder prototype. For internal use only."
        />

        <Card>
            <CardHeader>
                <CardTitle>No projects yet</CardTitle>
                <CardDescription>
                    Nothing has been built or run here. Projects, feature
                    previews and verification results will appear once those
                    capabilities are added.
                </CardDescription>
            </CardHeader>
        </Card>
    </div>
</template>
