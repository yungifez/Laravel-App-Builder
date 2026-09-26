<script setup lang="ts">
import { Form, Head, router, setLayoutProps, usePoll } from '@inertiajs/vue3';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import FeatureRequestController from '@/actions/App/Http/Controllers/FeatureRequestController';
import PreviewController from '@/actions/App/Http/Controllers/PreviewController';
import ProjectPreviewController from '@/actions/App/Http/Controllers/ProjectPreviewController';
import VisualEditController from '@/actions/App/Http/Controllers/VisualEditController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import {
    describeValue,
    devices,
    inlineStyles,
    properties,
} from '@/lib/visualProperties';
import type { PropertyDefinition } from '@/lib/visualProperties';
import { show as showPreview } from '@/routes/previews';
import { index, show } from '@/routes/projects';
import { show as showEditor } from '@/routes/projects/editor';
import type {
    Device,
    EditorPreview,
    InspectedElement,
    ProjectSummary,
    SelectedElement,
    VisualEditSummary,
    VisualProperty,
    VisualValue,
} from '@/types';

const props = defineProps<{
    project: Pick<ProjectSummary, 'id' | 'name'>;
    preview: EditorPreview | null;
    element?: InspectedElement | null;
    edits: VisualEditSummary[];
}>();

watch(
    () => props.project,
    (project) =>
        setLayoutProps({
            breadcrumbs: [
                { title: 'Projects', href: index() },
                { title: project.name, href: show(project.id) },
                { title: 'Change how it looks', href: showEditor(project.id) },
            ],
        }),
    { immediate: true },
);

const frame = ref<HTMLIFrameElement | null>(null);
const frameSource = ref<string | null>(null);
const frameKey = ref(0);
const framePath = ref('/');
const device = ref<Device>('base');
const pointing = ref(true);
const selected = ref<SelectedElement | null>(null);
const onlyThisOne = ref(true);
const changes = reactive<Partial<Record<VisualProperty, VisualValue | null>>>(
    {},
);
const saving = ref(false);
const saveError = ref<string | null>(null);

const running = computed(() => props.preview?.status === 'ready');
const busy = computed(
    () =>
        props.preview?.status === 'starting' ||
        props.preview?.updating === true,
);
const frameWidth = computed(
    () => devices.find((option) => option.key === device.value)?.width ?? null,
);
const deviceLabel = (key: Device) =>
    devices.find((option) => option.key === key)?.label ?? key;

// The place in the source the edit goes to: the one use of a shared piece,
// or where the element is written (which changes every use of it).
const target = computed(() => {
    if (selected.value === null) {
        return null;
    }

    return onlyThisOne.value && selected.value.instance
        ? { value: selected.value.instance, instance: true }
        : { value: selected.value.source, instance: false };
});

const hasChanges = computed(() => Object.keys(changes).length > 0);

function post(message: Record<string, unknown>): void {
    if (props.preview === null) {
        return;
    }

    frame.value?.contentWindow?.postMessage(
        { builder: true, ...message },
        props.preview.origin,
    );
}

function clearChanges(): void {
    for (const key of Object.keys(changes)) {
        delete changes[key as VisualProperty];
    }
}

function inspect(): void {
    if (target.value?.value == null) {
        return;
    }

    router.reload({
        only: ['element'],
        data: {
            target: target.value.value,
            instance: target.value.instance ? 1 : 0,
        },
    });
}

function onMessage(event: MessageEvent): void {
    if (
        props.preview === null ||
        event.origin !== props.preview.origin ||
        event.source !== frame.value?.contentWindow ||
        event.data?.builder !== true
    ) {
        return;
    }

    if (event.data.type === 'ready') {
        framePath.value = String(event.data.path ?? '/');
        post({ type: 'mode', editing: pointing.value });
    }

    if (event.data.type === 'select') {
        selected.value = event.data.element as SelectedElement;
        onlyThisOne.value = true;
        saveError.value = null;
        clearChanges();
        inspect();
    }
}

onMounted(() => window.addEventListener('message', onMessage));
onBeforeUnmount(() => window.removeEventListener('message', onMessage));

watch(pointing, (editing) => post({ type: 'mode', editing }));

watch(onlyThisOne, () => {
    clearChanges();
    inspect();
});

watch(device, () => clearChanges());

// Show unsaved values in the preview straight away.
watch(
    () => ({ ...changes }),
    (current) => {
        if (target.value?.value == null) {
            return;
        }

        post({
            type: 'style',
            location: {
                kind: target.value.instance ? 'instance' : 'source',
                value: target.value.value,
            },
            styles: inlineStyles(current),
        });
    },
);

// Open the app once it is running, and reload it after each rebuild.
watch(
    () => [props.preview?.status, props.preview?.revision] as const,
    ([status], previous) => {
        if (status !== 'ready' || props.preview === null) {
            frameSource.value = null;

            return;
        }

        if (frameSource.value === null) {
            frameSource.value = showPreview(props.preview.id).url;
        } else if (previous?.[1] !== props.preview.revision) {
            frameSource.value = props.preview.origin + framePath.value;
            frameKey.value++;
            inspect();
        }
    },
    { immediate: true },
);

const { start, stop } = usePoll(
    2000,
    { only: ['preview', 'edits'] },
    { autoStart: false },
);

watch(busy, (value) => (value ? start() : stop()), { immediate: true });

function current(property: VisualProperty) {
    return props.element?.values[device.value][property] ?? null;
}

function valueOf(property: VisualProperty): VisualValue | null {
    return property in changes
        ? (changes[property] ?? null)
        : (current(property)?.value ?? null);
}

function visible(definition: PropertyDefinition): boolean {
    const layout = valueOf('layout');

    return (
        definition.when === undefined ||
        (definition.when === 'flex-or-grid'
            ? layout === 'flex' || layout === 'grid'
            : layout === definition.when)
    );
}

function setChoice(property: VisualProperty, value: string): void {
    const definition = properties.find((item) => item.key === property);
    const option =
        definition?.input.kind === 'choice'
            ? definition.input.options.find(
                  (item) => String(item.value) === value,
              )
            : undefined;

    changes[property] = option?.value ?? null;
}

function setNumber(property: VisualProperty, value: string): void {
    changes[property] = value === '' ? null : Number(value);
}

function save(): void {
    if (
        props.preview === null ||
        props.element == null ||
        target.value?.value == null
    ) {
        return;
    }

    saving.value = true;
    saveError.value = null;

    router.post(
        VisualEditController.store.url(props.project.id),
        {
            preview: props.preview.id,
            target: target.value.value,
            instance: target.value.instance,
            revision: props.element.revision,
            device: device.value,
            changes: { ...changes },
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                clearChanges();
                inspect();
            },
            onError: (errors) =>
                (saveError.value = Object.values(errors)[0] ?? null),
            onFinish: () => (saving.value = false),
        },
    );
}

const reasons: Record<NonNullable<InspectedElement['reason']>, string> = {
    updating:
        'Your last change is still being put in place. Try again in a moment.',
    not_found:
        "I can't find this part in your app. Ask me to change it instead.",
    dynamic:
        "How this part looks depends on what is happening in the app, so it can't be changed here. Ask me to change it instead.",
};

const groups = computed(() =>
    [...new Set(properties.map((definition) => definition.group))].map(
        (group) => ({
            name: group,
            items: properties.filter(
                (definition) =>
                    definition.group === group && visible(definition),
            ),
        }),
    ),
);
</script>

<template>
    <Head title="Change how it looks" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            title="Change how it looks"
            description="Point at a part of your app, then change its size, space and shape"
        />

        <section
            v-if="
                preview === null ||
                preview.status === 'stopped' ||
                preview.status === 'failed'
            "
            class="max-w-2xl space-y-4"
            data-test="editor-start"
        >
            <p
                v-if="preview?.status === 'failed'"
                class="text-sm text-destructive"
            >
                {{ preview.error ?? 'Your app could not start.' }}
            </p>
            <Form
                v-bind="ProjectPreviewController.store.form(project.id)"
                v-slot="{ errors, processing }"
                class="space-y-2"
            >
                <Button :disabled="processing" data-test="open-app-button">
                    {{ preview === null ? 'Open my app' : 'Open my app again' }}
                </Button>
                <InputError :message="errors.preview" />
            </Form>
        </section>

        <p
            v-else-if="preview.status === 'starting'"
            class="text-sm text-muted-foreground"
            data-test="editor-starting"
        >
            Starting your app. The first time can take a few minutes.
        </p>

        <div
            v-else
            class="grid min-h-0 flex-1 gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] [&>*]:min-w-0"
        >
            <section class="flex min-h-[70vh] flex-col gap-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div
                        class="inline-flex rounded-md bg-muted p-1"
                        role="group"
                        aria-label="Device"
                    >
                        <button
                            v-for="option in devices"
                            :key="option.key"
                            type="button"
                            :aria-pressed="device === option.key"
                            :class="[
                                'min-h-11 rounded px-4 text-sm select-none',
                                device === option.key
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground',
                            ]"
                            @click="device = option.key"
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <div class="flex items-center gap-2">
                        <span
                            v-if="preview.updating"
                            class="text-sm text-muted-foreground"
                            data-test="editor-updating"
                        >
                            Putting your change in place…
                        </span>
                        <label
                            class="flex min-h-11 items-center gap-2 text-sm select-none"
                        >
                            <input
                                v-model="pointing"
                                type="checkbox"
                                class="size-4"
                            />
                            Point at parts
                        </label>
                        <Form
                            v-bind="PreviewController.destroy.form(preview.id)"
                            v-slot="{ processing }"
                        >
                            <Button variant="ghost" :disabled="processing">
                                Close
                            </Button>
                        </Form>
                    </div>
                </div>

                <div
                    class="min-h-0 flex-1 overflow-auto rounded-md border bg-muted/40"
                >
                    <iframe
                        v-if="frameSource !== null"
                        ref="frame"
                        :key="frameKey"
                        :src="frameSource"
                        title="Your app"
                        class="mx-auto block h-full min-h-[70vh] bg-background"
                        :style="{
                            width:
                                frameWidth === null
                                    ? '100%'
                                    : `${frameWidth}px`,
                            minWidth:
                                frameWidth === null ? '1024px' : undefined,
                        }"
                        data-test="editor-frame"
                    />
                </div>
            </section>

            <aside class="space-y-6" data-test="inspector">
                <p
                    v-if="selected === null"
                    class="text-sm text-muted-foreground"
                >
                    Click a part of your app to see what it does and change how
                    it looks.
                </p>

                <template v-else-if="element">
                    <section class="space-y-2">
                        <h3 class="text-base font-medium">What this does</h3>
                        <template v-if="element.area">
                            <p class="text-sm">
                                Part of <strong>{{ element.area.name }}</strong
                                ><template v-if="element.area.summary">
                                    : {{ element.area.summary }}</template
                                >
                            </p>
                            <div
                                v-if="element.area.rules.length > 0"
                                class="space-y-1"
                            >
                                <h4 class="text-sm font-medium">
                                    Why it's here
                                </h4>
                                <ul class="list-disc space-y-1 pl-5 text-sm">
                                    <li
                                        v-for="rule in element.area.rules"
                                        :key="rule"
                                    >
                                        {{ rule }}
                                    </li>
                                </ul>
                            </div>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">
                            I have no notes about this part yet.
                        </p>
                    </section>

                    <div
                        v-if="selected.instance && selected.source"
                        class="space-y-2"
                        data-test="shared-choice"
                    >
                        <p class="text-sm">
                            This is a piece your app uses in more than one
                            place.
                        </p>
                        <div
                            class="inline-flex rounded-md bg-muted p-1"
                            role="group"
                        >
                            <button
                                type="button"
                                :aria-pressed="onlyThisOne"
                                :class="[
                                    'min-h-11 rounded px-3 text-sm select-none',
                                    onlyThisOne
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground',
                                ]"
                                @click="onlyThisOne = true"
                            >
                                Only this one
                            </button>
                            <button
                                type="button"
                                :aria-pressed="!onlyThisOne"
                                :class="[
                                    'min-h-11 rounded px-3 text-sm select-none',
                                    !onlyThisOne
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground',
                                ]"
                                @click="onlyThisOne = false"
                            >
                                Every one like it
                            </button>
                        </div>
                        <p
                            v-if="!onlyThisOne && element.shared"
                            class="text-sm text-muted-foreground"
                        >
                            Used in {{ element.shared.uses }}
                            {{
                                element.shared.uses === 1 ? 'place' : 'places'
                            }}. A change here changes all of them.
                        </p>
                    </div>

                    <p
                        v-if="!element.editable && element.reason"
                        class="text-sm"
                        data-test="not-editable"
                    >
                        {{ reasons[element.reason] }}
                    </p>

                    <section v-else class="space-y-4" data-test="properties">
                        <p class="text-sm text-muted-foreground">
                            On {{ deviceLabel(device)
                            }}{{
                                device === 'base'
                                    ? ' and up'
                                    : device === 'md'
                                      ? ' and desktops'
                                      : ''
                            }}
                        </p>

                        <div
                            v-for="group in groups"
                            :key="group.name"
                            class="space-y-1"
                        >
                            <h4 class="text-sm font-medium">
                                {{ group.name }}
                            </h4>
                            <div
                                v-for="definition in group.items"
                                :key="definition.key"
                                class="grid grid-cols-[minmax(0,1fr)_11rem] items-center gap-2 border-t py-1 first:border-t-0"
                            >
                                <Label
                                    :for="`property-${definition.key}`"
                                    class="font-normal"
                                >
                                    {{ definition.label }}
                                    <span
                                        v-if="
                                            !(definition.key in changes) &&
                                            current(definition.key) &&
                                            current(definition.key)?.from !==
                                                device
                                        "
                                        class="block text-xs text-muted-foreground"
                                    >
                                        from
                                        {{
                                            deviceLabel(
                                                current(definition.key)!.from,
                                            )
                                        }}
                                    </span>
                                </Label>

                                <select
                                    v-if="definition.input.kind === 'choice'"
                                    :id="`property-${definition.key}`"
                                    :value="valueOf(definition.key) ?? ''"
                                    class="h-11 w-full min-w-0 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                                    @change="
                                        setChoice(
                                            definition.key,
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option value="">not set</option>
                                    <option
                                        v-if="
                                            valueOf(definition.key) !== null &&
                                            !definition.input.options.some(
                                                (option) =>
                                                    option.value ===
                                                    valueOf(definition.key),
                                            )
                                        "
                                        :value="valueOf(definition.key)!"
                                    >
                                        {{
                                            describeValue(
                                                definition,
                                                valueOf(definition.key),
                                            )
                                        }}
                                    </option>
                                    <option
                                        v-for="option in definition.input
                                            .options"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>

                                <div v-else class="flex items-center gap-1">
                                    <input
                                        :id="`property-${definition.key}`"
                                        type="number"
                                        :min="
                                            definition.input.kind === 'count'
                                                ? 1
                                                : definition.input.allowNegative
                                                  ? undefined
                                                  : 0
                                        "
                                        :max="
                                            definition.input.kind === 'count'
                                                ? 12
                                                : undefined
                                        "
                                        :value="
                                            typeof valueOf(definition.key) ===
                                            'number'
                                                ? valueOf(definition.key)
                                                : ''
                                        "
                                        :placeholder="
                                            describeValue(
                                                definition,
                                                valueOf(definition.key),
                                            )
                                        "
                                        class="h-11 w-full min-w-0 rounded-md border border-input bg-transparent px-2 text-sm placeholder:text-muted-foreground dark:bg-input/30"
                                        @change="
                                            setNumber(
                                                definition.key,
                                                (
                                                    $event.target as HTMLInputElement
                                                ).value,
                                            )
                                        "
                                    />
                                    <span
                                        v-if="
                                            definition.input.kind === 'pixels'
                                        "
                                        class="text-xs text-muted-foreground"
                                        >px</span
                                    >
                                    <button
                                        v-if="
                                            definition.input.kind ===
                                                'pixels' &&
                                            definition.input.allowAuto
                                        "
                                        type="button"
                                        class="min-h-11 px-1 text-xs underline select-none"
                                        @click="
                                            changes[definition.key] = 'auto'
                                        "
                                    >
                                        Centre
                                    </button>
                                </div>
                            </div>
                        </div>

                        <p
                            v-if="saveError"
                            class="text-sm text-destructive"
                            data-test="save-error"
                        >
                            {{ saveError }}
                        </p>

                        <div class="flex gap-2">
                            <Button
                                :disabled="
                                    !hasChanges || saving || preview.updating
                                "
                                data-test="save-look-button"
                                @click="save"
                            >
                                Save
                            </Button>
                            <Button
                                variant="ghost"
                                :disabled="!hasChanges || saving"
                                @click="clearChanges"
                            >
                                Undo
                            </Button>
                        </div>
                    </section>

                    <section class="space-y-2">
                        <h3 class="text-sm font-medium">
                            Want something else here?
                        </h3>
                        <Form
                            v-bind="
                                FeatureRequestController.store.form(project.id)
                            "
                            v-slot="{ errors, processing }"
                            class="space-y-2"
                        >
                            <input
                                type="hidden"
                                name="selection[file]"
                                :value="element.file"
                            />
                            <input
                                type="hidden"
                                name="selection[line]"
                                :value="element.line"
                            />
                            <input
                                type="hidden"
                                name="selection[column]"
                                :value="element.target.split(':').pop()"
                            />
                            <input
                                type="hidden"
                                name="selection[tag]"
                                :value="element.tag ?? selected.tag"
                            />
                            <input
                                type="hidden"
                                name="selection[text]"
                                :value="selected.text"
                            />
                            <input
                                v-if="element.area"
                                type="hidden"
                                name="selection[area]"
                                :value="element.area.name"
                            />
                            <Label for="fallback-prompt" class="sr-only"
                                >Your change</Label
                            >
                            <textarea
                                id="fallback-prompt"
                                name="prompt"
                                rows="2"
                                required
                                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm placeholder:text-muted-foreground dark:bg-input/30"
                                placeholder="Show the price next to each item"
                            />
                            <InputError :message="errors.prompt" />
                            <Button variant="outline" :disabled="processing">
                                Ask me to change it instead
                            </Button>
                        </Form>
                    </section>

                    <Collapsible>
                        <CollapsibleTrigger
                            class="min-h-11 text-sm text-muted-foreground underline select-none"
                        >
                            Details
                        </CollapsibleTrigger>
                        <CollapsibleContent
                            class="space-y-1 pt-2 text-xs text-muted-foreground"
                        >
                            <p>{{ element.target }}</p>
                            <p class="font-mono break-words">
                                {{ element.classes || 'no classes' }}
                            </p>
                        </CollapsibleContent>
                    </Collapsible>
                </template>

                <p v-else class="text-sm text-muted-foreground">Looking…</p>

                <p
                    v-if="edits.length > 0"
                    class="text-xs text-muted-foreground"
                >
                    {{ edits.length }}
                    {{ edits.length === 1 ? 'change' : 'changes' }}
                    to how it looks saved recently, without asking me to write
                    code.
                </p>
            </aside>
        </div>
    </div>
</template>
