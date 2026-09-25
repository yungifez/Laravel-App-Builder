<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as projectsIndex } from '@/routes/projects';

defineProps<{ projectCount: number }>();

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
                <CardTitle v-if="projectCount === 0">No projects yet</CardTitle>
                <CardTitle v-else>
                    {{ projectCount }}
                    {{ projectCount === 1 ? 'project' : 'projects' }}
                </CardTitle>
                <CardDescription>
                    Add a customer application, request a feature, preview the
                    generated change and adjust its steps.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Button as-child>
                    <Link :href="projectsIndex()">Go to projects</Link>
                </Button>
            </CardContent>
        </Card>
    </div>
</template>
