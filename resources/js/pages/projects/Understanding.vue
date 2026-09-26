<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import NotesPart from '@/components/NotesPart.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { show as showFeatureRequest } from '@/routes/feature-requests';
import { index, show } from '@/routes/projects';
import { show as showUnderstanding } from '@/routes/projects/understanding';
import type {
    CheckFinding,
    NotesSection,
    ProjectSummary,
    UnderstandingArea,
} from '@/types';

const props = defineProps<{
    project: Pick<ProjectSummary, 'id' | 'name'>;
    revision: string | null;
    about: { introduction: string; sections: NotesSection[] };
    guidance: string | null;
    areas: UnderstandingArea[];
    problems: string[];
    changes: { id: number; summary: string; at: string | null }[];
    looks: number;
    check?: CheckFinding[];
}>();

const checking = ref(false);

const connections = computed(() =>
    props.areas.flatMap((area) =>
        area.connections.map((connection) => ({ from: area, ...connection })),
    ),
);

// Notes are Markdown; the owner reads them as plain text. Lines wrapped
// in the file are joined, and list markers become bullets.
function plain(text: string): string {
    return text
        .replace(/\*\*(.+?)\*\*|__(.+?)__/g, '$1$2')
        .replace(/([^\n])\n(?!\s*[-*]\s|\n)\s*/g, '$1 ')
        .replace(/^\s*[-*]\s+/gm, '• ');
}

function day(iso: string | null): string {
    return iso === null ? '' : new Date(iso).toLocaleDateString();
}

function runCheck(): void {
    router.reload({
        only: ['check'],
        onStart: () => (checking.value = true),
        onFinish: () => (checking.value = false),
    });
}

watch(
    () => props.project,
    (project) =>
        setLayoutProps({
            breadcrumbs: [
                { title: 'Projects', href: index() },
                { title: project.name, href: show(project.id) },
                {
                    title: 'Your app',
                    href: showUnderstanding(project.id),
                },
            ],
        }),
    { immediate: true },
);
</script>

<template>
    <Head :title="`${project.name}: your app`" />

    <div class="flex h-full flex-1 flex-col gap-10 p-4">
        <Heading
            :title="project.name"
            description="What I know about your app. Change anything that is wrong; I use it for every change."
        />

        <p
            v-if="revision === null"
            class="max-w-2xl text-sm text-muted-foreground"
        >
            This app has no history yet, so there is nothing to show.
        </p>

        <template v-else>
            <section class="max-w-2xl space-y-4" data-test="about">
                <Heading variant="small" title="About your app" />

                <NotesPart
                    :project-id="project.id"
                    :revision="revision"
                    part="introduction"
                    :text="about.introduction"
                    label="what it is for"
                >
                    <p
                        v-if="about.introduction"
                        class="text-sm whitespace-pre-line"
                    >
                        {{ plain(about.introduction) }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        Nothing written yet.
                    </p>
                </NotesPart>

                <div
                    v-for="section in about.sections"
                    :key="section.heading"
                    class="space-y-1 border-t pt-4"
                >
                    <h3 class="text-sm font-medium">{{ section.heading }}</h3>
                    <NotesPart
                        :project-id="project.id"
                        :revision="revision"
                        :part="`section:${section.heading}`"
                        :text="section.body"
                        :label="section.heading.toLowerCase()"
                        :rows="6"
                    >
                        <p
                            class="text-sm whitespace-pre-line text-muted-foreground"
                        >
                            {{ plain(section.body) }}
                        </p>
                    </NotesPart>
                </div>
            </section>

            <section class="max-w-2xl space-y-4" data-test="areas">
                <Heading
                    variant="small"
                    title="How things work"
                    description="The parts of your app and what people can do in each"
                />

                <p
                    v-if="areas.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No parts are described yet.
                </p>

                <ul v-else class="divide-y border-y">
                    <li
                        v-for="area in areas"
                        :key="area.key"
                        class="space-y-2 py-4"
                    >
                        <h3 class="font-medium">{{ area.name }}</h3>
                        <NotesPart
                            :project-id="project.id"
                            :revision="revision"
                            :part="`summary:${area.key}`"
                            :text="area.summary ?? ''"
                            label="what it does"
                            :rows="2"
                        >
                            <p
                                v-if="area.summary"
                                class="text-sm text-muted-foreground"
                            >
                                {{ area.summary }}
                            </p>
                            <p v-else class="text-sm text-muted-foreground">
                                Not described yet.
                            </p>
                        </NotesPart>
                        <p v-if="area.behaviors.length" class="text-sm">
                            People can: {{ area.behaviors.join(', ') }}.
                        </p>
                    </li>
                </ul>
            </section>

            <section class="max-w-2xl space-y-4" data-test="rules">
                <Heading
                    variant="small"
                    title="Things that must always be true"
                    description="I keep these the same in every change, and say how I checked"
                />

                <ul class="divide-y border-y">
                    <li
                        v-for="area in areas"
                        :key="area.key"
                        class="space-y-1 py-4"
                    >
                        <h3 class="text-sm font-medium">{{ area.name }}</h3>
                        <NotesPart
                            :project-id="project.id"
                            :revision="revision"
                            :part="`rules:${area.key}`"
                            :text="area.rules.join('\n')"
                            :label="`the rules for ${area.name}`"
                            :rows="Math.max(3, area.rules.length + 1)"
                            hint="One rule per line."
                        >
                            <ul
                                v-if="area.rules.length"
                                class="list-disc space-y-1 pl-5 text-sm"
                            >
                                <li v-for="rule in area.rules" :key="rule">
                                    {{ plain(rule) }}
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">
                                No rules yet.
                            </p>
                        </NotesPart>
                    </li>
                </ul>
            </section>

            <section class="max-w-2xl space-y-4" data-test="connections">
                <Heading
                    variant="small"
                    title="Things this is connected to"
                    description="When one of these changes, I check the other"
                />

                <ul v-if="connections.length" class="divide-y border-y text-sm">
                    <li
                        v-for="connection in connections"
                        :key="`${connection.from.key}-${connection.to}`"
                        class="py-3"
                    >
                        <span class="font-medium">{{
                            connection.from.name
                        }}</span>
                        is connected to
                        <span class="font-medium">{{ connection.name }}</span
                        >: {{ connection.reason }}
                        <span
                            v-if="connection.strength !== 'strong'"
                            class="text-muted-foreground"
                        >
                            ({{
                                connection.strength === 'possible'
                                    ? 'maybe'
                                    : 'in the past'
                            }})</span
                        >
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    No connections are known yet.
                </p>
            </section>

            <section class="max-w-2xl space-y-4" data-test="guidance">
                <Heading
                    variant="small"
                    title="Guidance from your developer"
                    description="How your app should be built. I follow it in every change."
                />
                <NotesPart
                    :project-id="project.id"
                    :revision="revision"
                    :part="`section:Engineering direction`"
                    :text="guidance ?? ''"
                    label="the guidance"
                    :rows="6"
                    hint="One point per line works well."
                >
                    <p
                        v-if="guidance"
                        class="text-sm whitespace-pre-line text-muted-foreground"
                    >
                        {{ plain(guidance) }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        None yet.
                    </p>
                </NotesPart>
            </section>

            <section class="max-w-2xl space-y-4" data-test="what-changed">
                <Heading variant="small" title="What changed" />

                <ol v-if="changes.length" class="divide-y border-y">
                    <li v-for="change in changes" :key="change.id">
                        <Link
                            :href="showFeatureRequest(change.id)"
                            class="flex min-h-11 items-baseline justify-between gap-4 py-3 text-sm hover:bg-muted/50"
                        >
                            <span class="min-w-0">{{ change.summary }}</span>
                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                                >{{ day(change.at) }}</span
                            >
                        </Link>
                    </li>
                </ol>
                <p v-else class="text-sm text-muted-foreground">
                    No changes kept yet.
                </p>
                <p v-if="looks > 0" class="text-sm text-muted-foreground">
                    And {{ looks }} {{ looks === 1 ? 'change' : 'changes' }} to
                    how it looks.
                </p>
            </section>

            <section class="max-w-2xl space-y-4" data-test="quick-check">
                <Heading
                    variant="small"
                    title="Quick check"
                    description="Look for obvious gaps between these notes and your app"
                />

                <Button
                    variant="outline"
                    class="h-11 select-none sm:h-9"
                    :disabled="checking"
                    data-test="check-button"
                    @click="runCheck"
                >
                    Check my app
                </Button>

                <template v-if="check !== undefined">
                    <p
                        v-if="check.length === 0"
                        class="text-sm"
                        data-test="check-clear"
                    >
                        No obvious problems found.
                    </p>
                    <ul
                        v-else
                        class="divide-y border-y"
                        data-test="check-findings"
                    >
                        <li
                            v-for="finding in check"
                            :key="finding.title"
                            class="py-3 text-sm"
                        >
                            <p>{{ finding.title }}</p>
                            <Collapsible v-if="finding.details.length">
                                <CollapsibleTrigger
                                    class="min-h-11 text-xs text-muted-foreground underline-offset-4 select-none hover:underline sm:min-h-0"
                                >
                                    Details
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <ul
                                        class="mt-1 space-y-0.5 font-mono text-xs break-all text-muted-foreground"
                                    >
                                        <li
                                            v-for="detail in finding.details"
                                            :key="detail"
                                        >
                                            {{ detail }}
                                        </li>
                                    </ul>
                                </CollapsibleContent>
                            </Collapsible>
                        </li>
                    </ul>
                </template>
            </section>

            <Collapsible v-if="problems.length" class="max-w-2xl">
                <CollapsibleTrigger
                    class="text-xs text-muted-foreground select-none hover:underline"
                >
                    Details: some notes could not be read
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <ul class="mt-1 font-mono text-xs text-muted-foreground">
                        <li v-for="problem in problems" :key="problem">
                            {{ problem }}
                        </li>
                    </ul>
                </CollapsibleContent>
            </Collapsible>
        </template>
    </div>
</template>
