<script setup lang="ts">
import {
    CircleCheck,
    CircleDashed,
    History,
    Lock,
    Package,
    ScanSearch,
    ShieldCheck,
    UserCheck,
} from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FeatureRequestAcceptedFindingController from '@/actions/App/Http/Controllers/FeatureRequestAcceptedFindingController';
import FeatureRequestFindingProposalController from '@/actions/App/Http/Controllers/FeatureRequestFindingProposalController';
import type { ProofLine } from '@/types';

// How we know a change works, in plain words: what the checks proved, how
// far the app's own tests reached into the change, what was caught and
// fixed before the owner saw it, and what nothing checks yet. A preview
// shows a change looks right; this shows why to trust it.
const props = defineProps<{ proof: ProofLine[] }>();

// Gaps lead, right under the verdict that counts them, so what is not
// checked never hides between passes. Then the passes: the first one
// shows, and the rest fold behind a count so the list stays short. A pass
// that tried the change itself (a new test that fails without it) leads,
// as it says more than "the old tests still pass". The rest (problems
// caught, how far the tests reached) always shows last.
const gaps = computed(() => props.proof.filter((line) => line.kind === 'gap'));
const passes = computed(() => {
    const passed = props.proof.filter((line) => line.kind === 'passed');

    return [
        ...passed.filter((line) => line.evidence),
        ...passed.filter((line) => !line.evidence),
    ];
});
// What the owner said the change does on purpose stays where its gap was,
// so saying so does not move the line away from under them.
const chosen = computed(() =>
    props.proof.filter((line) => line.kind === 'chosen'),
);
const others = computed(() =>
    props.proof.filter(
        (line) =>
            line.kind !== 'passed' &&
            line.kind !== 'gap' &&
            line.kind !== 'chosen',
    ),
);

// The finding being saved, so its control cannot be pressed twice.
const deciding = ref<string | null>(null);

function decide(decision: NonNullable<ProofLine['decision']>): void {
    const action = decision.accepted
        ? FeatureRequestAcceptedFindingController.destroy
        : FeatureRequestAcceptedFindingController.store;

    deciding.value = decision.finding;
    router.visit(
        action({ featureRequest: decision.change, kind: decision.finding }),
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => (deciding.value = null),
        },
    );
}

// The owner's answer to the agent's case for keeping what was found: yes
// keeps it this way, no has it fixed. The change goes on either way.
function answer(
    decision: NonNullable<ProofLine['decision']>,
    agreed: boolean,
): void {
    deciding.value = decision.finding;
    router.visit(
        FeatureRequestFindingProposalController.update({
            featureRequest: decision.change,
            kind: decision.finding,
        }),
        {
            data: { agreed },
            preserveScroll: true,
            preserveState: true,
            onFinish: () => (deciding.value = null),
        },
    );
}

// What the folded passes checked, so the fold says it ("such as safety,
// sign-in and speed") instead of hiding it behind a bare count.
const folded = computed(() => {
    const topics = [
        ...new Set(
            passes.value
                .slice(1)
                // Its pictures already show above the fold.
                .filter((line) => !line.pictures?.length)
                .map((line) => line.topic)
                .filter((topic) => topic !== undefined),
        ),
    ].slice(0, 3);

    return topics.length < 2
        ? topics.join('')
        : `${topics.slice(0, -1).join(', ')} and ${topics.at(-1)}`;
});

// Each picture keeps roughly its device's shape: phone, tablet, computer.
const sizes = ['w-11', 'w-22', 'w-34'];

// Seeing the changed screen on each device says more than any line, so its
// pictures show outside the fold, under the first pass.
const pictures = computed(
    () => props.proof.find((line) => line.pictures?.length)?.pictures ?? [],
);

// One plain verdict above the lines, never a percentage (direction 18
// §10): a gap outranks everything, and "well checked" needs a line that
// shows the change itself was tried.
const verdict = computed(() => {
    if (gaps.value.length > 0) {
        return {
            title: 'Checked, with gaps',
            tone: 'text-amber-600',
            detail:
                gaps.value.length === 1
                    ? 'One thing below is not checked yet.'
                    : `${gaps.value.length} things below are not checked yet.`,
        };
    }

    if (props.proof.some((line) => line.evidence)) {
        return { title: 'Well checked', tone: 'text-green-600', detail: null };
    }

    return {
        title: 'Lightly checked',
        tone: 'text-muted-foreground',
        detail: 'Nothing broke, but I could not see a check try what it changed.',
    };
});

const icons = {
    passed: { icon: CircleCheck, tone: 'text-green-600' },
    caught: { icon: ShieldCheck, tone: 'text-green-600' },
    reach: { icon: ScanSearch, tone: 'text-muted-foreground' },
    gap: { icon: CircleDashed, tone: 'text-amber-600' },
    // The owner read what it costs and wants it anyway.
    chosen: { icon: UserCheck, tone: 'text-muted-foreground' },
    rule: { icon: Lock, tone: 'text-muted-foreground' },
    // Whether the old way was kept working, and why.
    approach: { icon: History, tone: 'text-muted-foreground' },
    // What the app relies on that it did not before.
    packages: { icon: Package, tone: 'text-muted-foreground' },
};
</script>

<template>
    <section
        v-if="proof.length > 0"
        class="space-y-1.5"
        data-test="change-proof"
    >
        <h3 class="text-xs font-medium">
            How I know it works:
            <span :class="verdict.tone" data-test="change-proof-verdict">{{
                verdict.title
            }}</span>
        </h3>
        <p v-if="verdict.detail" class="text-xs text-muted-foreground">
            {{ verdict.detail }}
        </p>
        <ul
            v-if="gaps.length > 0"
            class="space-y-1"
            data-test="change-proof-gaps"
        >
            <li
                v-for="line in gaps"
                :key="line.text"
                class="flex items-start gap-2 text-xs text-muted-foreground"
            >
                <CircleDashed class="mt-px size-3.5 shrink-0 text-amber-600" />
                <div>
                    {{ line.text }}
                    <ul
                        v-if="line.items?.length"
                        class="mt-0.5 list-disc space-y-0.5 pl-4 marker:text-muted-foreground"
                    >
                        <li v-for="item in line.items" :key="item">
                            {{ item }}
                        </li>
                    </ul>
                    <!-- The agent thinks it should stay; only the owner decides. -->
                    <div
                        v-if="line.decision?.proposal"
                        class="mt-1 space-y-0.5"
                        data-test="change-proof-proposal"
                    >
                        <p class="text-foreground">
                            I think this is right as it is:
                            {{ line.decision.proposal }}
                        </p>
                        <div class="flex flex-wrap gap-x-4">
                            <button
                                type="button"
                                class="flex min-h-11 items-center font-medium text-foreground underline-offset-2 select-none hover:underline disabled:opacity-50 sm:min-h-6"
                                :disabled="deciding === line.decision.finding"
                                data-test="change-proof-agree"
                                @click="answer(line.decision, true)"
                            >
                                Yes, keep it this way
                            </button>
                            <button
                                type="button"
                                class="flex min-h-11 items-center font-medium text-foreground underline-offset-2 select-none hover:underline disabled:opacity-50 sm:min-h-6"
                                :disabled="deciding === line.decision.finding"
                                data-test="change-proof-refuse"
                                @click="answer(line.decision, false)"
                            >
                                No, fix it
                            </button>
                        </div>
                    </div>
                    <button
                        v-else-if="line.decision"
                        type="button"
                        class="mt-0.5 flex min-h-11 items-center font-medium text-foreground underline-offset-2 select-none hover:underline disabled:opacity-50 sm:min-h-6"
                        :disabled="deciding === line.decision.finding"
                        data-test="change-proof-accept"
                        @click="decide(line.decision)"
                    >
                        I want it this way
                    </button>
                </div>
            </li>
        </ul>
        <ul
            v-if="chosen.length > 0"
            class="space-y-1"
            data-test="change-proof-chosen"
        >
            <li
                v-for="line in chosen"
                :key="line.text"
                class="flex items-start gap-2 text-xs text-muted-foreground"
            >
                <UserCheck class="mt-px size-3.5 shrink-0" />
                <div>
                    {{ line.text }}
                    <button
                        v-if="line.decision"
                        type="button"
                        class="mt-0.5 flex min-h-11 items-center underline-offset-2 select-none hover:text-foreground hover:underline disabled:opacity-50 sm:min-h-6"
                        :disabled="deciding === line.decision.finding"
                        data-test="change-proof-undo-accept"
                        @click="decide(line.decision)"
                    >
                        Undo
                    </button>
                </div>
            </li>
        </ul>
        <p
            v-if="passes.length > 0"
            class="flex items-start gap-2 text-xs text-muted-foreground"
        >
            <CircleCheck class="mt-px size-3.5 shrink-0 text-green-600" />
            {{ passes[0].text }}
        </p>
        <ul
            v-if="pictures.length > 0"
            class="flex items-end gap-2 pl-5.5"
            data-test="change-proof-pictures"
        >
            <li v-for="(picture, index) in pictures" :key="picture.url">
                <a
                    :href="picture.url"
                    target="_blank"
                    rel="noopener"
                    class="group block rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <img
                        :src="picture.url"
                        :alt="`The changed screen on a ${picture.label.toLowerCase()}`"
                        loading="lazy"
                        :class="[
                            'h-24 rounded-md border object-cover object-top transition-opacity group-hover:opacity-80',
                            sizes[index] ?? sizes[2],
                        ]"
                    />
                    <span
                        class="mt-0.5 block text-[11px] text-muted-foreground"
                        >{{ picture.label }}</span
                    >
                </a>
            </li>
        </ul>
        <details
            v-if="passes.length > 1"
            class="group text-xs text-muted-foreground"
            data-test="change-proof-more"
        >
            <summary
                class="flex min-h-11 cursor-pointer list-none items-center gap-2 select-none hover:text-foreground sm:min-h-6 [&::-webkit-details-marker]:hidden"
            >
                <CircleCheck class="size-3.5 shrink-0 text-green-600" />
                <span class="underline-offset-2 group-hover:underline">
                    {{ passes.length - 1 }} more
                    {{ passes.length === 2 ? 'check' : 'checks' }}
                    passed<template v-if="folded"
                        >, such as {{ folded }}</template
                    >
                </span>
            </summary>
            <ul class="mt-1 space-y-1 pl-5.5">
                <li v-for="line in passes.slice(1)" :key="line.text">
                    {{ line.text }}
                </li>
            </ul>
        </details>
        <ul v-if="others.length > 0" class="space-y-1">
            <li
                v-for="line in others"
                :key="line.text"
                class="flex items-start gap-2 text-xs text-muted-foreground"
            >
                <component
                    :is="icons[line.kind].icon"
                    :class="['mt-px size-3.5 shrink-0', icons[line.kind].tone]"
                />
                <div>
                    {{ line.text }}
                    <ul
                        v-if="line.items?.length"
                        class="mt-0.5 list-disc space-y-0.5 pl-4 marker:text-muted-foreground"
                    >
                        <li v-for="item in line.items" :key="item">
                            {{ item }}
                        </li>
                    </ul>
                </div>
            </li>
        </ul>
    </section>
</template>
