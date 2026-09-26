<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, show } from '@/routes/projects';
import type { ProjectSummary } from '@/types';

defineProps<{ projects: ProjectSummary[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Projects', href: index() }],
    },
});
</script>

<template>
    <Head title="Projects" />

    <div class="flex h-full flex-1 flex-col gap-8 p-4">
        <Heading
            title="Projects"
            description="The apps I build and change for you"
        />

        <ul v-if="projects.length > 0" class="grid gap-4 md:grid-cols-2">
            <li v-for="project in projects" :key="project.id">
                <Link :href="show(project.id)" class="block">
                    <Card class="transition-colors hover:bg-muted/50">
                        <CardHeader>
                            <CardTitle>{{ project.name }}</CardTitle>
                            <CardDescription class="truncate font-mono">
                                {{ project.source_path }}
                            </CardDescription>
                        </CardHeader>
                    </Card>
                </Link>
            </li>
        </ul>

        <Card v-else>
            <CardHeader>
                <CardTitle>No projects yet</CardTitle>
                <CardDescription>
                    Add a customer application below to start requesting
                    features for it.
                </CardDescription>
            </CardHeader>
        </Card>

        <section class="max-w-xl space-y-6">
            <Heading
                variant="small"
                title="Add a project"
                description="Bring in an app that already exists"
            />

            <Form
                v-bind="ProjectController.store.form()"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" name="name" required placeholder="Acme" />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="source_path">Source path</Label>
                    <Input
                        id="source_path"
                        name="source_path"
                        required
                        placeholder="/srv/customer-app"
                        class="font-mono"
                    />
                    <InputError :message="errors.source_path" />
                </div>

                <Button
                    :disabled="processing"
                    data-test="create-project-button"
                >
                    Add project
                </Button>
            </Form>
        </section>
    </div>
</template>
