<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import NewProjectController from '@/actions/App/Http/Controllers/NewProjectController';
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

defineProps<{ projects: ProjectSummary[]; canStartNew: boolean }>();

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
                    Start a new app or bring in one you already have.
                </CardDescription>
            </CardHeader>
        </Card>

        <section
            v-if="canStartNew"
            class="max-w-xl space-y-6"
            data-test="start-new"
        >
            <Heading
                variant="small"
                title="Start a new app"
                description="I set up a working app to start from. Then you tell me what to change."
            />

            <Form
                v-bind="NewProjectController.store.form()"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="new-name">Name</Label>
                    <Input
                        id="new-name"
                        name="name"
                        required
                        placeholder="Bright Cleaning"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="purpose">What is your app for?</Label>
                    <textarea
                        id="purpose"
                        name="purpose"
                        rows="3"
                        required
                        placeholder="My cleaners see their jobs for the day, and customers book a clean online."
                        class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    />
                    <InputError :message="errors.purpose" />
                </div>

                <Button
                    :disabled="processing"
                    class="h-11 select-none sm:h-9"
                    data-test="start-project-button"
                >
                    Start my app
                </Button>
            </Form>
        </section>

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
