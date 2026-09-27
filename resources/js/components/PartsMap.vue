<script setup lang="ts">
import { computed, ref } from 'vue';
import type { UnderstandingArea } from '@/types';

const props = defineProps<{ areas: UnderstandingArea[] }>();
const emit = defineEmits<{ visit: [key: string] }>();

// Parts sit on an ellipse, so every link between two parts is a straight
// line that never runs through a third one.
const nodes = computed(() => {
    const count = props.areas.length;

    return props.areas.map((area, index) => {
        const angle = -Math.PI / 2 + (2 * Math.PI * index) / count;

        return {
            area,
            x: count === 1 ? 50 : 50 + 36 * Math.cos(angle),
            y: count === 1 ? 50 : 50 + 32 * Math.sin(angle),
        };
    });
});

const strength = { strong: 3, possible: 2, historical: 1 } as const;

// Both parts usually name the same link, so draw each pair once, as the
// strongest either side claims.
const links = computed(() => {
    const at = new Map(nodes.value.map((node) => [node.area.key, node]));
    const pairs = new Map<
        string,
        {
            from: string;
            to: string;
            strength: keyof typeof strength;
            reason: string;
        }
    >();

    for (const area of props.areas) {
        for (const connection of area.connections) {
            if (!at.has(connection.to) || connection.to === area.key) {
                continue;
            }

            const key = [area.key, connection.to].sort().join('|');
            const known = pairs.get(key);

            if (
                !known ||
                strength[connection.strength] > strength[known.strength]
            ) {
                pairs.set(key, {
                    from: area.key,
                    to: connection.to,
                    strength: connection.strength,
                    reason: connection.reason,
                });
            }
        }
    }

    return [...pairs.values()].map((link) => ({
        ...link,
        a: at.get(link.from)!,
        b: at.get(link.to)!,
    }));
});

const hovered = ref<string | null>(null);

function lit(link: { from: string; to: string }): boolean {
    return hovered.value === link.from || hovered.value === link.to;
}

function near(key: string): boolean {
    return (
        hovered.value === null ||
        hovered.value === key ||
        links.value.some(
            (link) => lit(link) && (link.from === key || link.to === key),
        )
    );
}
</script>

<template>
    <div
        class="relative h-72 rounded-2xl bg-muted/30 sm:h-80"
        data-test="parts-map"
    >
        <svg
            class="absolute inset-0 size-full"
            viewBox="0 0 100 100"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <line
                v-for="link in links"
                :key="`${link.from}-${link.to}`"
                :x1="link.a.x"
                :y1="link.a.y"
                :x2="link.b.x"
                :y2="link.b.y"
                vector-effect="non-scaling-stroke"
                :stroke-width="lit(link) ? 2 : 1.25"
                :stroke-dasharray="
                    link.strength === 'strong'
                        ? undefined
                        : link.strength === 'possible'
                          ? '6 5'
                          : '1.5 4'
                "
                :class="[
                    'transition-all duration-base',
                    lit(link)
                        ? 'stroke-foreground'
                        : hovered
                          ? 'stroke-border'
                          : 'stroke-muted-foreground/50',
                ]"
            />
        </svg>

        <button
            v-for="node in nodes"
            :key="node.area.key"
            type="button"
            :style="{ left: `${node.x}%`, top: `${node.y}%` }"
            :class="[
                'absolute flex min-h-11 max-w-[42%] -translate-x-1/2 -translate-y-1/2 flex-col items-center rounded-xl border bg-background px-4 py-2 transition duration-quick ease-snap select-none hover:-translate-y-[calc(50%+1px)] hover:border-foreground/40 active:scale-[0.97] sm:max-w-56',
                near(node.area.key) ? 'opacity-100' : 'opacity-40',
            ]"
            :data-test="`map-${node.area.key}`"
            @mouseenter="hovered = node.area.key"
            @mouseleave="hovered = null"
            @focus="hovered = node.area.key"
            @blur="hovered = null"
            @click="emit('visit', node.area.key)"
        >
            <span class="max-w-full truncate text-sm font-semibold">{{
                node.area.name
            }}</span>
            <span class="text-xs text-muted-foreground tabular-nums">
                {{ node.area.rules.length }}
                {{ node.area.rules.length === 1 ? 'rule' : 'rules' }}
            </span>
        </button>

        <p
            v-if="links.length"
            class="absolute bottom-2 left-3 flex items-center gap-3 text-xs text-muted-foreground"
        >
            <span class="flex items-center gap-1.5">
                <span class="w-4 border-t border-muted-foreground" />
                Linked
            </span>
            <span class="flex items-center gap-1.5">
                <span
                    class="w-4 border-t border-dashed border-muted-foreground"
                />
                Maybe linked
            </span>
        </p>
    </div>
</template>
