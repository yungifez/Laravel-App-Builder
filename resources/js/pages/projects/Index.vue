<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowUp, Search, ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import NewProjectController from '@/actions/App/Http/Controllers/NewProjectController';
import BringInApp from '@/components/BringInApp.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { when } from '@/lib/when';
import { index, show } from '@/routes/projects';
import type { DesignOption, ProjectListItem } from '@/types';

const props = defineProps<{
    projects: ProjectListItem[];
    canStartNew: boolean;
    designs: DesignOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Your apps', href: index() }],
    },
});

// A filter helps only once the list no longer fits at a glance.
const searchable = computed(() => props.projects.length > 6);
const query = ref('');
const shown = computed(() => {
    const words = query.value.trim().toLowerCase();

    return words === ''
        ? props.projects
        : props.projects.filter((project) =>
              project.name.toLowerCase().includes(words),
          );
});

// Ctrl or Cmd and Enter starts the app, as in the chat.
function submitOnShortcut(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        (event.target as HTMLElement).closest('form')?.requestSubmit();
    }
}
</script>

<template>
    <Head title="Your apps" />

    <div class="flex h-full flex-1 flex-col">
        <!-- Making a new app comes first: say what it is for, and go. -->
        <section
            v-if="canStartNew"
            class="mx-auto w-full max-w-2xl px-4 pt-12 pb-10 sm:pt-20 sm:pb-14"
        >
            <h1
                class="text-center text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
            >
                What do you want to make?
            </h1>
            <p class="mt-3 text-center text-balance text-muted-foreground">
                Say it in a sentence or two. I set up a working app, then you
                shape it.
            </p>

            <Form
                v-bind="NewProjectController.store.form()"
                class="mt-8"
                data-test="start-new"
                v-slot="{ errors, processing }"
            >
                <div
                    class="rounded-2xl border bg-card shadow-sm transition-shadow focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/30"
                >
                    <label for="purpose" class="sr-only"
                        >What is your app for?</label
                    >
                    <textarea
                        id="purpose"
                        name="purpose"
                        rows="3"
                        required
                        placeholder="My cleaners see their jobs for the day, and customers book a clean online."
                        class="block w-full resize-none bg-transparent px-5 pt-4 pb-2 text-base outline-none placeholder:text-muted-foreground"
                        @keydown="submitOnShortcut"
                    />
                    <div class="flex flex-wrap items-center gap-2 px-3 pb-3">
                        <label for="new-name" class="sr-only">Name</label>
                        <input
                            id="new-name"
                            name="name"
                            required
                            autocomplete="off"
                            placeholder="Name it"
                            class="h-11 min-w-0 flex-1 rounded-lg bg-muted px-3 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring/50 sm:h-9 sm:w-36 sm:flex-none"
                        />

                        <fieldset
                            v-if="designs.length > 0"
                            class="order-last grid w-full grid-cols-4 gap-1 sm:order-none sm:flex sm:w-auto sm:flex-wrap sm:items-center"
                            data-test="looks"
                        >
                            <legend class="sr-only">Pick a look</legend>
                            <label
                                v-for="(design, i) in designs"
                                :key="design.key"
                                :title="design.description"
                                class="flex h-14 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg px-1 text-xs text-muted-foreground select-none hover:text-foreground has-checked:bg-muted has-checked:text-foreground has-focus-visible:ring-2 has-focus-visible:ring-ring/50 sm:h-9 sm:flex-row sm:justify-start sm:gap-2 sm:px-2.5 sm:text-sm"
                                :data-test="`look-${design.key}`"
                            >
                                <input
                                    type="radio"
                                    name="design"
                                    :value="design.key"
                                    :checked="i === 0"
                                    class="sr-only"
                                />
                                <span
                                    aria-hidden="true"
                                    class="size-4 shrink-0 rounded-full border"
                                    :style="{
                                        background: `linear-gradient(135deg, ${design.colors.background} 50%, ${design.colors.primary} 50%)`,
                                        borderColor: design.colors.border,
                                    }"
                                />
                                {{ design.name }}
                            </label>
                        </fieldset>

                        <Button
                            size="icon"
                            :disabled="processing"
                            class="ml-auto size-11 shrink-0 rounded-full select-none sm:size-9"
                            aria-label="Start my app"
                            title="Start my app"
                            data-test="start-project-button"
                        >
                            <ArrowUp class="size-4" />
                        </Button>
                    </div>
                </div>
                <InputError class="mt-2" :message="errors.purpose" />
                <InputError class="mt-2" :message="errors.name" />
                <InputError class="mt-2" :message="errors.design" />
            </Form>

            <p class="mt-5 text-center text-sm text-muted-foreground">
                Or
                <BringInApp>
                    <button
                        type="button"
                        class="min-h-11 font-medium text-foreground underline-offset-4 select-none hover:underline sm:min-h-0"
                        data-test="bring-in-open"
                    >
                        bring in an app you have
                    </button>
                </BringInApp>
            </p>
        </section>

        <header
            v-else
            class="flex flex-wrap items-center justify-between gap-3 p-4"
        >
            <h1 class="text-xl font-semibold tracking-tight">Your apps</h1>
            <BringInApp>
                <Button
                    variant="outline"
                    class="h-11 select-none sm:h-9"
                    data-test="bring-in-open"
                >
                    Bring in an app you have
                </Button>
            </BringInApp>
        </header>

        <section class="mx-auto w-full max-w-5xl px-4 pb-16">
            <p
                v-if="projects.length === 0"
                class="text-center text-sm text-muted-foreground"
                data-test="no-apps"
            >
                Your apps will show here.
            </p>

            <template v-else>
                <div
                    class="flex min-h-11 items-center justify-between gap-3 pb-3"
                >
                    <h2
                        v-if="canStartNew"
                        class="text-sm font-medium text-muted-foreground"
                    >
                        Your apps
                    </h2>
                    <label v-if="searchable" class="relative ml-auto">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <input
                            v-model="query"
                            type="search"
                            aria-label="Find an app"
                            placeholder="Find an app"
                            class="h-11 w-52 rounded-lg bg-muted pr-3 pl-8 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring/50 sm:h-9"
                            data-test="apps-filter"
                        />
                    </label>
                </div>

                <p
                    v-if="shown.length === 0"
                    class="text-sm text-muted-foreground"
                    data-test="apps-none-found"
                >
                    No app is called that.
                </p>

                <ul
                    v-else
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    data-test="apps-grid"
                >
                    <li v-for="project in shown" :key="project.id">
                        <Link
                            :href="show(project.id)"
                            class="group flex h-full flex-col gap-4 rounded-xl border bg-card p-4 transition-colors select-none hover:border-foreground/20 hover:bg-muted/40"
                            :data-test="`app-${project.id}`"
                        >
                            <span class="flex items-center gap-3">
                                <span
                                    aria-hidden="true"
                                    class="grid size-9 shrink-0 place-items-center rounded-lg bg-muted text-sm font-semibold uppercase"
                                    >{{ project.name.charAt(0) }}</span
                                >
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{
                                        project.name
                                    }}</span>
                                    <span
                                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                                    >
                                        <span
                                            aria-hidden="true"
                                            :class="[
                                                'size-1.5 rounded-full',
                                                project.published_at
                                                    ? 'bg-green-500'
                                                    : 'bg-muted-foreground/40',
                                            ]"
                                        />
                                        <template v-if="project.published_at"
                                            >Live since
                                            {{
                                                when(project.published_at)
                                            }}</template
                                        >
                                        <template v-else>Not live yet</template>
                                    </span>
                                </span>
                            </span>

                            <span
                                class="mt-auto flex items-center justify-between gap-3 text-xs text-muted-foreground tabular-nums"
                            >
                                <span
                                    v-if="project.tests"
                                    class="flex items-center gap-1.5"
                                    data-test="app-tests"
                                >
                                    <ShieldCheck
                                        class="size-3.5 text-green-600"
                                    />
                                    {{ project.tests }}
                                    {{ project.tests === 1 ? 'test' : 'tests' }}
                                </span>
                                <span
                                    v-else-if="project.changed_at"
                                    class="first-letter:uppercase"
                                    >Changed
                                    {{ when(project.changed_at) }}</span
                                >
                                <span v-else>No changes yet</span>
                                <span
                                    v-if="project.waiting > 0"
                                    class="rounded-full bg-primary/15 px-2 py-0.5 font-medium text-foreground"
                                    >{{ project.waiting }} to look at</span
                                >
                            </span>
                        </Link>
                    </li>
                </ul>
            </template>
        </section>
    </div>
</template>
