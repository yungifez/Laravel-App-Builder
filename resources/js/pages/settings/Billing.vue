<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { pricing } from '@/routes';
import { edit, portal } from '@/routes/billing';
import { store } from '@/routes/billing/plan';

type Plan = {
    key: string;
    name: string;
    price: number;
    times: number;
    open: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Plan and use',
                href: edit(),
            },
        ],
    },
});

const props = defineProps<{
    plans: Plan[];
    currentPlan: string;
    usage: { percent: number; resetsOn: string; unlimited: boolean };
    canManage: boolean;
}>();

const choosing = ref<string | null>(null);
const current = props.plans.find((plan) => plan.key === props.currentPlan);

function choose(plan: Plan): void {
    choosing.value = plan.key;
    router.post(
        store().url,
        { plan: plan.key },
        { onFinish: () => (choosing.value = null) },
    );
}
</script>

<template>
    <Head title="Plan and use" />

    <h1 class="sr-only">Plan and use</h1>

    <div class="space-y-10">
        <section class="space-y-4">
            <Heading
                variant="small"
                :title="`You are on ${current?.name ?? 'Free'}`"
                :description="
                    usage.unlimited
                        ? 'You run this platform, so your use has no limit.'
                        : `You have used ${usage.percent}% of this month's use. It starts again on ${usage.resetsOn}.`
                "
            />
            <div
                v-if="!usage.unlimited"
                class="h-2 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-valuenow="usage.percent"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="This month's use"
            >
                <div
                    class="h-full rounded-full transition-[width] duration-500 ease-settle"
                    :class="
                        usage.percent >= 100
                            ? 'bg-destructive'
                            : 'bg-foreground'
                    "
                    :style="{ width: `${usage.percent}%` }"
                />
            </div>
            <a
                v-if="canManage"
                :href="portal().url"
                class="inline-block text-sm text-primary hover:underline"
            >
                Change your card, see invoices or cancel
            </a>
        </section>

        <section class="space-y-4">
            <Heading
                variant="small"
                title="Plans"
                description="Plans differ only in how much use each month includes."
            />
            <ul class="divide-y border-y">
                <li
                    v-for="plan in plans"
                    :key="plan.key"
                    class="flex items-center justify-between gap-4 py-3"
                >
                    <div>
                        <p class="font-medium">{{ plan.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            ${{ plan.price }}{{ plan.price ? ' a month' : '' }}
                            <template v-if="plan.times > 1">
                                · {{ plan.times }} times the use of
                                {{ plans[0].name }}
                            </template>
                        </p>
                    </div>
                    <span
                        v-if="plan.key === currentPlan"
                        class="inline-flex items-center text-sm text-muted-foreground"
                    >
                        <Check class="mr-1.5 size-4" /> Your plan
                    </span>
                    <a
                        v-else-if="plan.price === 0 && canManage"
                        :href="portal().url"
                        class="text-sm text-primary hover:underline"
                    >
                        Cancel in billing
                    </a>
                    <button
                        v-else-if="plan.price > 0 && plan.open"
                        type="button"
                        class="inline-flex min-h-11 press items-center rounded-md border bg-background px-3 text-sm font-medium select-none hover:bg-muted disabled:opacity-60 sm:min-h-8"
                        :disabled="choosing !== null"
                        @click="choose(plan)"
                    >
                        {{ choosing === plan.key ? 'Opening…' : 'Move here' }}
                    </button>
                    <span
                        v-else-if="plan.price > 0"
                        class="text-sm text-muted-foreground"
                    >
                        Opens soon
                    </span>
                </li>
            </ul>
            <Link
                :href="pricing()"
                class="text-sm text-primary hover:underline"
            >
                Compare plans
            </Link>
        </section>
    </div>
</template>
