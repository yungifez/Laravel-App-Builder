<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowUp, ImagePlus, Search, ShieldCheck, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import NewProjectController from '@/actions/App/Http/Controllers/NewProjectController';
import BringInApp from '@/components/BringInApp.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { useAttachedImages } from '@/composables/useAttachedImages';
import { when } from '@/lib/when';
import { index, show } from '@/routes/projects';
import type { DesignOption, ProjectListItem, Starter } from '@/types';

const props = defineProps<{
    projects: ProjectListItem[];
    canStartNew: boolean;
    designs: DesignOption[];
    starters: Starter[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Your apps', href: index() }],
    },
});

// A filter helps only once the list no longer fits at a glance.
const searchable = computed(() => props.projects.length > 6);

const purposeField = ref<HTMLTextAreaElement | null>(null);
// A sketch or screenshot of what the owner has in mind, for the first
// version to follow.
const pictures = useAttachedImages();
const pictureInput = pictures.input;
const nameField = ref<HTMLInputElement | null>(null);

// A ready-made idea to start from, one tap each. It fills in the box and
// picks its look, and the owner sees what the first version includes,
// unticks what they do not want, and can still change every word.
const starter = ref<Starter | null>(null);
const looksField = ref<HTMLFieldSetElement | null>(null);

function useStarter(picked: Starter): void {
    if (purposeField.value === null || nameField.value === null) {
        return;
    }

    const name = nameField.value.value.trim();

    // A name the owner typed is theirs; one a starter filled in is not.
    if (name === '' || props.starters.some((s) => s.name === name)) {
        nameField.value.value = picked.name;
    }

    purposeField.value.value = picked.purpose;
    starter.value = picked;

    const look = looksField.value?.querySelector<HTMLInputElement>(
        `input[name="design"][value="${picked.design}"]`,
    );

    if (look) {
        look.checked = true;
    }

    purposeField.value.focus();
}

// Leaving a starter takes back the words it filled in, but keeps any the
// owner changed, as those are theirs.
function clearStarter(): void {
    if (starter.value === null) {
        return;
    }

    if (purposeField.value?.value.trim() === starter.value.purpose) {
        purposeField.value.value = '';
    }

    if (nameField.value?.value.trim() === starter.value.name) {
        nameField.value.value = '';
    }

    starter.value = null;
    purposeField.value?.focus();
}

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
            class="mx-auto w-full max-w-5xl px-4 pt-12 pb-10 sm:pt-20 sm:pb-16"
        >
            <h1
                class="max-w-2xl font-display text-4xl leading-[1.05] tracking-tight text-balance sm:text-6xl"
            >
                What do you want to make?
            </h1>
            <p class="mt-4 max-w-xl text-pretty text-muted-foreground">
                Say it in a sentence or two. I set up a working app, then you
                shape it.
            </p>

            <Form
                v-bind="NewProjectController.store.form()"
                class="mt-8 max-w-2xl"
                data-test="start-new"
                v-slot="{ errors, processing }"
            >
                <div
                    :class="[
                        'rounded-md border border-input bg-background transition-shadow focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/30',
                        pictures.dragging.value &&
                            'border-ring ring-[3px] ring-ring/30',
                    ]"
                    @dragover.prevent="pictures.dragging.value = true"
                    @dragleave.self="pictures.dragging.value = false"
                    @drop.prevent="pictures.drop"
                >
                    <label for="purpose" class="sr-only"
                        >What is your app for?</label
                    >
                    <textarea
                        id="purpose"
                        ref="purposeField"
                        name="purpose"
                        rows="3"
                        required
                        placeholder="My cleaners see their jobs for the day, and customers book a clean online."
                        class="block w-full resize-none bg-transparent px-5 pt-4 pb-2 text-base outline-none placeholder:text-muted-foreground"
                        @keydown="submitOnShortcut"
                        @paste="pictures.paste"
                    />
                    <ul
                        v-if="pictures.images.value.length > 0"
                        class="flex flex-wrap gap-2 px-5 pb-2"
                        data-test="start-images"
                    >
                        <li
                            v-for="(image, index) in pictures.images.value"
                            :key="image.url"
                            class="relative"
                        >
                            <img
                                :src="image.url"
                                :alt="image.file.name"
                                class="size-14 rounded-md border object-cover"
                            />
                            <button
                                type="button"
                                class="absolute -top-1.5 -right-1.5 flex size-6 items-center justify-center rounded-full border bg-background text-muted-foreground after:absolute after:-inset-2.5 hover:text-foreground"
                                :aria-label="`Remove ${image.file.name}`"
                                @click="pictures.remove(index)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </li>
                    </ul>
                    <input
                        ref="pictureInput"
                        type="file"
                        name="images[]"
                        multiple
                        accept="image/png,image/jpeg,image/webp,image/gif"
                        class="hidden"
                        data-test="start-image-input"
                        @change="
                            pictures.add(
                                Array.from(
                                    ($event.target as HTMLInputElement).files ??
                                        [],
                                ),
                            )
                        "
                    />
                    <div class="flex flex-wrap items-center gap-2 px-3 pb-3">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-11 shrink-0 text-muted-foreground sm:size-9"
                            aria-label="Attach a picture"
                            title="Attach a sketch or screenshot"
                            :disabled="
                                pictures.images.value.length >= pictures.max
                            "
                            data-test="start-attach"
                            @click="pictureInput?.click()"
                        >
                            <ImagePlus class="size-4" />
                        </Button>
                        <label for="new-name" class="sr-only">Name</label>
                        <input
                            id="new-name"
                            ref="nameField"
                            name="name"
                            required
                            autocomplete="off"
                            placeholder="Name it"
                            class="h-11 min-w-0 flex-1 rounded-sm bg-muted px-3 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring/50 sm:h-9 sm:w-36 sm:flex-none"
                        />

                        <fieldset
                            v-if="designs.length > 0"
                            ref="looksField"
                            class="order-last grid w-full grid-cols-4 gap-1 sm:order-none sm:flex sm:w-auto sm:flex-wrap sm:items-center"
                            data-test="looks"
                        >
                            <legend class="sr-only">Pick a look</legend>
                            <label
                                v-for="(design, i) in designs"
                                :key="design.key"
                                :title="design.description"
                                class="flex h-14 cursor-pointer flex-col items-center justify-center gap-1 rounded-sm px-1 text-xs text-muted-foreground select-none hover:text-foreground has-checked:bg-muted has-checked:text-foreground has-focus-visible:ring-2 has-focus-visible:ring-ring/50 sm:h-9 sm:flex-row sm:justify-start sm:gap-2 sm:px-2.5 sm:text-sm"
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
                <InputError
                    class="mt-2"
                    :message="errors.images ?? errors['images.0']"
                />

                <div
                    v-if="starter"
                    class="mt-4 px-1"
                    data-test="starter-includes"
                >
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="text-sm font-medium">
                            Your first version includes
                        </p>
                        <button
                            type="button"
                            class="min-h-11 text-sm text-muted-foreground underline-offset-4 select-none hover:text-foreground hover:underline sm:min-h-0"
                            data-test="starter-clear"
                            @click="clearStarter"
                        >
                            Start from something else
                        </button>
                    </div>
                    <ul class="mt-2 space-y-1">
                        <li v-for="item in starter.includes" :key="item">
                            <label
                                class="flex min-h-11 cursor-pointer items-start gap-2.5 text-sm sm:min-h-0 sm:py-1"
                            >
                                <input
                                    type="checkbox"
                                    name="includes[]"
                                    :value="item"
                                    checked
                                    class="mt-0.5 size-4 shrink-0 accent-primary"
                                />
                                {{ item }}
                            </label>
                        </li>
                    </ul>
                </div>

                <div
                    v-else-if="starters.length > 0"
                    class="mt-3 flex flex-wrap gap-2"
                    data-test="starters"
                >
                    <button
                        v-for="item in starters"
                        :key="item.key"
                        type="button"
                        class="min-h-11 rounded-sm border px-3 text-sm text-muted-foreground transition-colors duration-quick select-none hover:bg-muted hover:text-foreground sm:min-h-8"
                        :data-test="`starter-${item.key}`"
                        @click="useStarter(item)"
                    >
                        {{ item.name }}
                    </button>
                </div>
            </Form>

            <p class="mt-5 text-sm text-muted-foreground">
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
            class="mx-auto flex w-full max-w-5xl flex-wrap items-end justify-between gap-3 px-4 pt-10 pb-6"
        >
            <h1 class="font-display text-4xl tracking-tight">Your apps</h1>
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
                class="border-t pt-4 text-sm text-muted-foreground"
                data-test="no-apps"
            >
                Your apps will show here.
            </p>

            <template v-else>
                <div
                    class="flex min-h-11 items-center justify-between gap-3 border-t pt-4 pb-5"
                >
                    <h2
                        v-if="canStartNew"
                        class="font-display text-2xl tracking-tight"
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
                            class="h-11 w-52 rounded-sm bg-muted pr-3 pl-8 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring/50 sm:h-9"
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
                    class="grid gap-x-6 gap-y-8 sm:grid-cols-2 lg:grid-cols-3"
                    data-test="apps-grid"
                >
                    <li v-for="project in shown" :key="project.id">
                        <Link
                            :href="show(project.id)"
                            class="group flex h-full flex-col gap-3 rounded-md select-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            :data-test="`app-${project.id}`"
                        >
                            <span
                                aria-hidden="true"
                                class="grid aspect-[16/10] place-items-center overflow-hidden rounded-md border bg-muted transition-colors duration-quick group-hover:border-foreground/40"
                            >
                                <img
                                    v-if="project.picture"
                                    :src="project.picture"
                                    alt=""
                                    loading="lazy"
                                    class="size-full object-cover object-top"
                                    data-test="app-picture"
                                />
                                <span
                                    v-else
                                    class="font-display text-5xl text-muted-foreground/60"
                                    >{{
                                        project.name.charAt(0).toUpperCase()
                                    }}</span
                                >
                            </span>
                            <span class="flex items-center gap-3">
                                <span class="min-w-0">
                                    <span
                                        class="block truncate font-medium underline-offset-4 group-hover:underline"
                                        >{{ project.name }}</span
                                    >
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
                                        <template v-if="project.offline > 0">
                                            <span aria-hidden="true">·</span>
                                            <span data-test="app-offline"
                                                >{{ project.offline }}
                                                {{
                                                    project.offline === 1
                                                        ? 'change'
                                                        : 'changes'
                                                }}
                                                not live yet</span
                                            >
                                        </template>
                                    </span>
                                </span>
                            </span>

                            <span
                                class="mt-auto flex min-h-5 flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t pt-2 font-mono text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                            >
                                <span class="flex items-center gap-3">
                                    <span
                                        v-if="project.edited_at"
                                        data-test="app-edited"
                                        >Edited
                                        {{ when(project.edited_at) }}</span
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
                                        {{
                                            project.tests === 1
                                                ? 'test'
                                                : 'tests'
                                        }}
                                    </span>
                                </span>
                                <span
                                    v-if="project.waiting > 0"
                                    class="font-medium text-foreground"
                                    >{{ project.waiting }} to look at</span
                                >
                                <span
                                    v-else-if="project.now === 'working'"
                                    class="flex items-center gap-1.5 text-foreground"
                                    data-test="app-working"
                                    ><span
                                        aria-hidden="true"
                                        class="size-1.5 animate-pulse rounded-full bg-foreground/60"
                                    />Making a change</span
                                >
                                <span
                                    v-else-if="project.now === 'stopped'"
                                    class="font-medium text-destructive"
                                    data-test="app-stopped"
                                    >A change stopped</span
                                >
                            </span>
                        </Link>
                    </li>
                </ul>
            </template>
        </section>
    </div>
</template>
