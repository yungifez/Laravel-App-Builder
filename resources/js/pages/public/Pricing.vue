<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { ref } from 'vue';
import { register } from '@/routes';
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
</script>

<template>
    <Head title="Pricing" />

    <section class="bg-muted/50">
        <div class="mx-auto max-w-6xl px-4 pt-16 pb-12 sm:px-6 sm:pt-24">
            <h1
                class="max-w-2xl font-display text-4xl leading-[1.05] sm:text-5xl"
            >
                Pay for how much you build
            </h1>
            <p class="mt-4 max-w-xl text-lg text-muted-foreground">
                Every plan builds and checks changes the same way. Bigger plans
                include more use each month, and use starts again every month.
            </p>

            <div
                class="mt-12 grid divide-y border-y sm:grid-cols-3 sm:divide-x sm:divide-y-0"
            >
                <div
                    v-for="plan in plans"
                    :key="plan.key"
                    class="flex flex-col gap-6 py-6 sm:px-6 sm:first:pl-0 sm:last:pr-0"
                    :data-test="`plan-${plan.key}`"
                >
                    <div>
                        <h2 class="font-display text-2xl">{{ plan.name }}</h2>
                        <p class="mt-2">
                            <span class="font-display text-3xl">
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
                        <span
                            v-else
                            class="inline-flex min-h-11 items-center text-sm text-muted-foreground sm:min-h-9"
                        >
                            Opens soon
                        </span>
                    </div>
                </div>
            </div>

            <div class="mt-12 max-w-xl">
                <h2 class="font-display text-xl">Every plan</h2>
                <ul class="mt-4 divide-y border-y">
                    <li
                        v-for="line in included"
                        :key="line"
                        class="flex items-center gap-2 py-3"
                    >
                        <Check class="size-4 shrink-0 text-muted-foreground" />
                        {{ line }}
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
