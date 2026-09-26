<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import NewProjectController from '@/actions/App/Http/Controllers/NewProjectController';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { when } from '@/lib/when';
import { index, show } from '@/routes/projects';
import type { ProjectListItem } from '@/types';

defineProps<{ projects: ProjectListItem[]; canStartNew: boolean }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Your apps', href: index() }],
    },
});

const textarea =
    'w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';
</script>

<template>
    <Head title="Your apps" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold tracking-tight">Your apps</h1>

            <div class="flex flex-wrap gap-2">
                <Dialog>
                    <DialogTrigger as-child>
                        <Button
                            variant="outline"
                            class="h-11 select-none sm:h-9"
                            data-test="bring-in-open"
                        >
                            Bring in an app you have
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Bring in an app you have</DialogTitle>
                            <DialogDescription>
                                I make my own copy. Your app stays as it is
                                until you keep a change.
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            v-bind="ProjectController.store.form()"
                            class="space-y-6"
                            v-slot="{ errors, processing }"
                        >
                            <div class="grid gap-2">
                                <Label for="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    placeholder="Acme"
                                />
                                <InputError :message="errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="source_path">Where it is</Label>
                                <Input
                                    id="source_path"
                                    name="source_path"
                                    required
                                    placeholder="/srv/acme"
                                    class="font-mono"
                                />
                                <InputError :message="errors.source_path" />
                            </div>

                            <Button
                                :disabled="processing"
                                class="h-11 w-full select-none sm:h-9 sm:w-auto"
                                data-test="create-project-button"
                            >
                                Bring it in
                            </Button>
                        </Form>
                    </DialogContent>
                </Dialog>

                <Dialog v-if="canStartNew">
                    <DialogTrigger as-child>
                        <Button
                            class="h-11 select-none sm:h-9"
                            data-test="start-new-open"
                        >
                            Start a new app
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Start a new app</DialogTitle>
                            <DialogDescription>
                                I set up a working app to start from. Then you
                                tell me what to change.
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            v-bind="NewProjectController.store.form()"
                            class="space-y-6"
                            data-test="start-new"
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
                                <Label for="purpose"
                                    >What is your app for?</Label
                                >
                                <textarea
                                    id="purpose"
                                    name="purpose"
                                    rows="3"
                                    required
                                    placeholder="My cleaners see their jobs for the day, and customers book a clean online."
                                    :class="textarea"
                                />
                                <InputError :message="errors.purpose" />
                            </div>

                            <Button
                                :disabled="processing"
                                class="h-11 w-full select-none sm:h-9 sm:w-auto"
                                data-test="start-project-button"
                            >
                                Start my app
                            </Button>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </header>

        <p
            v-if="projects.length === 0"
            class="text-sm text-muted-foreground"
            data-test="no-apps"
        >
            You have no apps yet. Start a new one, or bring in one you already
            have.
        </p>

        <template v-else>
            <!-- A table where there is width to compare apps side by side;
                 the same rows read as a list on a phone. -->
            <table
                class="hidden w-full text-sm md:table"
                data-test="apps-table"
            >
                <thead>
                    <tr
                        class="border-b text-left text-xs text-muted-foreground"
                    >
                        <th class="py-2 pr-4 font-normal">App</th>
                        <th class="py-2 pr-4 font-normal">Live</th>
                        <th class="py-2 pr-4 font-normal">Last change kept</th>
                        <th class="py-2 text-right font-normal">
                            Waiting for you
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="project in projects"
                        :key="project.id"
                        class="relative hover:bg-muted/50"
                    >
                        <td class="py-3 pr-4 font-medium">
                            <!-- The name's link covers the whole row. -->
                            <Link
                                :href="show(project.id)"
                                class="select-none after:absolute after:inset-0"
                            >
                                {{ project.name }}
                            </Link>
                        </td>
                        <td class="py-3 pr-4">
                            <span v-if="project.published_at"
                                >Since {{ when(project.published_at) }}</span
                            >
                            <span v-else class="text-muted-foreground"
                                >Not yet</span
                            >
                        </td>
                        <td class="py-3 pr-4">
                            <span
                                v-if="project.changed_at"
                                class="inline-block first-letter:uppercase"
                                >{{ when(project.changed_at) }}</span
                            >
                            <span v-else class="text-muted-foreground"
                                >None yet</span
                            >
                        </td>
                        <td class="py-3 text-right tabular-nums">
                            <span v-if="project.waiting > 0" class="font-medium"
                                >{{ project.waiting }} to look at</span
                            >
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="divide-y border-y md:hidden" data-test="apps-list">
                <li v-for="project in projects" :key="project.id">
                    <Link
                        :href="show(project.id)"
                        class="flex min-h-11 items-center justify-between gap-4 py-3 select-none"
                    >
                        <span class="min-w-0">
                            <span class="block font-medium break-words">{{
                                project.name
                            }}</span>
                            <span class="block text-sm text-muted-foreground">
                                <template v-if="project.published_at"
                                    >Live since
                                    {{ when(project.published_at) }}</template
                                >
                                <template v-else>Not live yet</template>
                            </span>
                        </span>
                        <span
                            v-if="project.waiting > 0"
                            class="shrink-0 text-sm font-medium tabular-nums"
                            >{{ project.waiting }} to look at</span
                        >
                    </Link>
                </li>
            </ul>
        </template>
    </div>
</template>
