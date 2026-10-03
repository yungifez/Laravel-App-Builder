<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usd } from '@/lib/operations';
import { attention, numbers } from '@/routes/operations';
import { index as projectsIndex } from '@/routes/projects';

type Day = { date: string; joined: number; changes: number };

// How the business is doing: the money first, then the people, then the
// days behind both.
const props = defineProps<{
    days: number;
    revenue: {
        monthly_usd: number;
        plans: {
            key: string;
            name: string;
            price: number;
            paying: number;
            given: number;
        }[];
    };
    people: {
        total: number;
        joined: number;
        verified: number;
        building: number;
    };
    spend: { total_usd: number; completeness: string };
    daily: Day[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Your apps', href: projectsIndex().url },
        { title: 'Operations', href: attention().url },
        { title: 'Numbers', href: numbers().url },
    ],
});

const windows = [7, 30, 90];

const paying = computed(() =>
    props.revenue.plans.reduce((sum, plan) => sum + plan.paying, 0),
);

// What the window earned at today's prices, to set the AI cost against.
const earned = computed(() => (props.revenue.monthly_usd * props.days) / 30);

const charts = computed(() =>
    (
        [
            { key: 'joined', title: 'People who joined' },
            { key: 'changes', title: 'Changes asked for' },
        ] as const
    ).map((chart) => {
        const values = props.daily.map((day) => day[chart.key]);

        return {
            ...chart,
            total: values.reduce((sum, value) => sum + value, 0),
            most: Math.max(1, ...values),
        };
    }),
);

function day(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}

const whole = new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
});
</script>

<template>
    <Head title="Numbers" />

    <div class="mx-auto w-full max-w-5xl space-y-12 px-4 py-6 sm:px-6">
        <nav class="flex gap-1" aria-label="Window" data-test="window">
            <Link
                v-for="window in windows"
                :key="window"
                :href="numbers({ query: { days: window } }).url"
                class="flex min-h-11 items-center rounded-md px-3 text-sm select-none sm:min-h-9"
                :class="
                    window === days
                        ? 'bg-muted font-medium'
                        : 'text-muted-foreground hover:bg-muted/60'
                "
                preserve-scroll
            >
                {{ window }} days
            </Link>
        </nav>

        <section data-test="revenue">
            <p class="text-sm text-muted-foreground">Coming in each month</p>
            <p class="mt-1 font-display text-5xl tabular-nums">
                {{ whole.format(revenue.monthly_usd) }}
            </p>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ paying }} paying
                <template v-for="plan in revenue.plans" :key="plan.key">
                    · {{ plan.name }} {{ plan.paying }}
                    <template v-if="plan.given"
                        >(+{{ plan.given }} given)</template
                    >
                </template>
            </p>
        </section>

        <dl
            class="grid grid-cols-2 gap-x-6 gap-y-6 border-y py-6 sm:grid-cols-4"
            data-test="people"
        >
            <div>
                <dt class="text-sm text-muted-foreground">Joined</dt>
                <dd class="mt-1 text-2xl tabular-nums">{{ people.joined }}</dd>
                <dd class="text-xs text-muted-foreground">
                    {{ people.verified }} confirmed their email
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Building</dt>
                <dd class="mt-1 text-2xl tabular-nums">
                    {{ people.building }}
                </dd>
                <dd class="text-xs text-muted-foreground">
                    asked for a change
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Everyone</dt>
                <dd class="mt-1 text-2xl tabular-nums">{{ people.total }}</dd>
                <dd class="text-xs text-muted-foreground">with an account</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">AI cost</dt>
                <dd class="mt-1 text-2xl tabular-nums" data-test="spend">
                    {{ usd(spend.total_usd) }}
                </dd>
                <dd class="text-xs text-muted-foreground">
                    against {{ whole.format(earned) }} earned{{
                        spend.completeness === 'partial'
                            ? ' · at least this much'
                            : ''
                    }}
                </dd>
            </div>
        </dl>

        <section
            v-for="chart in charts"
            :key="chart.key"
            class="space-y-3"
            :data-test="`chart-${chart.key}`"
        >
            <h2 class="flex items-baseline justify-between gap-4">
                <span class="font-medium">{{ chart.title }}</span>
                <span class="text-sm text-muted-foreground tabular-nums"
                    >{{ chart.total }} in {{ days }} days</span
                >
            </h2>
            <div
                class="flex h-28 items-end gap-px border-b"
                role="img"
                :aria-label="`${chart.title}: ${chart.total} in ${days} days`"
            >
                <div
                    v-for="item in daily"
                    :key="item.date"
                    class="group relative flex h-full flex-1 items-end"
                    :title="`${day(item.date)}: ${item[chart.key]}`"
                >
                    <div
                        class="w-full rounded-t-[2px] bg-foreground/70 transition-[height] duration-500 ease-settle group-hover:bg-foreground"
                        :style="{
                            height: `${(item[chart.key] / chart.most) * 100}%`,
                        }"
                    />
                </div>
            </div>
            <div
                class="flex justify-between text-xs text-muted-foreground tabular-nums"
            >
                <span>{{ day(daily[0].date) }}</span>
                <span>{{ day(daily[daily.length - 1].date) }}</span>
            </div>
        </section>

        <p class="text-xs text-muted-foreground">
            Money uses the prices on the pricing page. Stripe has the exact
            amounts after discounts and tax.
        </p>
    </div>
</template>
