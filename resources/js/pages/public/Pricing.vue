<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Check, Plus } from '@lucide/vue';
import { ref } from 'vue';
import PageMeta from '@/components/PageMeta.vue';
import { contact, register } from '@/routes';
import { edit } from '@/routes/billing';
import { store } from '@/routes/billing/plan';

type Plan = {
    key: string;
    name: string;
    price: number;
    times: number;
    open: boolean;
};

// Every plan builds the same way; plans differ only in how much use a
// month includes. Each line under "Every plan" must stay true of the
// product as it is.
defineProps<{
    plans: Plan[];
    currentPlan: string | null;
}>();

const choosing = ref<string | null>(null);

function choose(plan: Plan): void {
    choosing.value = plan.key;
    router.post(
        store().url,
        { plan: plan.key },
        { onFinish: () => (choosing.value = null) },
    );
}

const included = [
    'Every change checked before you see it',
    'A live preview of every change',
    'Undo any change you kept',
    "Your app's code, yours to download",
];

// What people ask before they pick a plan, in the words the product uses
// when use runs out (ConstructRun) and when it starts again (MeasureUsage).
const questions = [
    {
        ask: 'What counts as use?',
        answer: 'The AI’s work on your changes: planning them, writing them and fixing them. Changing how your app looks yourself uses none.',
    },
    {
        ask: 'What happens when I run out?',
        answer: 'The AI takes no new changes until your use starts again. Nothing in your app changes, and you can still change how it looks yourself.',
    },
    {
        ask: 'When does my use start again?',
        answer: 'Every month, on the day you signed up or moved to your plan.',
    },
];
</script>

<template>
    <PageMeta
        title="Pricing"
        description="Every plan builds the same way and checks each change before you see it. Bigger plans only include more use each month."
    />

    <section class="mx-auto max-w-7xl px-4 pt-20 pb-24 sm:px-8 sm:pt-32">
        <h1
            class="max-w-3xl font-display text-4xl leading-[1.05] font-medium tracking-[-0.035em] text-balance sm:text-6xl"
        >
            Pay for how much you build.
            <span class="text-muted-foreground"
                >Use starts again each month.</span
            >
        </h1>

        <div class="mt-12 sm:mt-16">
            <div
                class="grid divide-y rounded-xl border bg-background shadow-2xl shadow-black/5 sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:border-input"
            >
                <div
                    v-for="plan in plans"
                    :key="plan.key"
                    class="flex flex-col gap-6 p-6"
                    :data-test="`plan-${plan.key}`"
                >
                    <div>
                        <h2 class="font-display text-xl font-medium">
                            {{ plan.name }}
                        </h2>
                        <p class="mt-2">
                            <span
                                class="font-display text-4xl font-medium tracking-[-0.03em]"
                            >
                                ${{ plan.price }}
                            </span>
                            <span class="text-muted-foreground">
                                {{ plan.price === 0 ? '' : ' a month' }}
                            </span>
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{
                                plan.times > 1
                                    ? `${plan.times} times the use of ${plans[0].name}`
                                    : 'Enough to try it on a small app'
                            }}
                        </p>
                    </div>

                    <div class="mt-auto">
                        <span
                            v-if="currentPlan === plan.key"
                            class="inline-flex min-h-11 items-center text-sm text-muted-foreground sm:min-h-9"
                        >
                            <Check class="mr-1.5 size-4" /> Your plan
                        </span>
                        <Link
                            v-else-if="currentPlan === null && plan.open"
                            :href="register()"
                            class="inline-flex min-h-11 press items-center rounded-md px-4 text-sm font-medium select-none sm:min-h-9"
                            :class="
                                plan.price === 0
                                    ? 'border bg-background hover:bg-muted'
                                    : 'bg-primary text-primary-foreground hover:bg-primary/90'
                            "
                        >
                            {{
                                plan.price === 0
                                    ? 'Start free'
                                    : `Start with ${plan.name}`
                            }}
                        </Link>
                        <Link
                            v-else-if="plan.price === 0"
                            :href="edit()"
                            class="inline-flex min-h-11 items-center rounded-md border bg-background px-4 text-sm font-medium select-none hover:bg-muted sm:min-h-9"
                        >
                            Change your plan
                        </Link>
                        <button
                            v-else-if="plan.open"
                            type="button"
                            class="inline-flex min-h-11 press items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground select-none hover:bg-primary/90 disabled:opacity-60 sm:min-h-9"
                            :disabled="choosing !== null"
                            @click="choose(plan)"
                        >
                            {{
                                choosing === plan.key
                                    ? 'Opening…'
                                    : `Move to ${plan.name}`
                            }}
                        </button>
                        <p
                            v-else
                            class="flex min-h-11 flex-wrap items-center gap-x-3 text-sm sm:min-h-9"
                        >
                            <span class="text-muted-foreground"
                                >Opens soon</span
                            >
                            <Link
                                :href="contact()"
                                class="font-medium text-primary underline-offset-4 hover:underline"
                                >Tell me when it opens</Link
                            >
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <h2
            class="mt-28 max-w-3xl font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:mt-40 sm:text-[2.75rem]"
        >
            Every plan has it all.
            <span class="text-muted-foreground"
                >Bigger plans only include more use.</span
            >
        </h2>
        <ul class="mt-10 grid gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
            <li
                v-for="line in included"
                :key="line"
                class="flex items-center gap-2 font-medium"
            >
                <Check class="size-4 shrink-0 text-muted-foreground" />
                {{ line }}
            </li>
        </ul>

        <div
            class="mt-28 grid gap-10 sm:mt-40 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16"
        >
            <h2
                class="font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:text-[2.75rem]"
            >
                Questions about&nbsp;use.
            </h2>
            <div class="divide-y border-y" data-test="pricing-questions">
                <details
                    v-for="question in questions"
                    :key="question.ask"
                    class="group"
                >
                    <summary
                        class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 py-5 font-medium select-none [&::-webkit-details-marker]:hidden"
                    >
                        {{ question.ask }}
                        <Plus
                            class="size-4 shrink-0 text-muted-foreground transition-transform duration-base group-open:rotate-45"
                            aria-hidden="true"
                        />
                    </summary>
                    <p class="max-w-2xl pb-5 text-pretty text-muted-foreground">
                        {{ question.answer }}
                    </p>
                </details>
            </div>
        </div>
    </section>
</template>
