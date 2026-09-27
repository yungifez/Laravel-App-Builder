<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{ ready: boolean }>();

// Beside a chat on a wide screen: the plan the change is built from, in
// the owner's words, or its code for whoever reads code. The open change
// moves its content into the two places below.
const TAB_KEY = 'builder.beside-tab';

type Tab = 'plan' | 'code';

function remembered(): Tab | null {
    try {
        const tab = window.localStorage.getItem(TAB_KEY);

        return tab === 'plan' || tab === 'code' ? tab : null;
    } catch {
        return null;
    }
}

// Whoever chose to see code opens on the code; everyone else on the plan.
const page = usePage();
const tab = ref<Tab>(
    (typeof window !== 'undefined' && remembered()) ||
        ((page.props.auth.user.detail_level ?? 1) >= 3 ? 'code' : 'plan'),
);

function choose(next: Tab): void {
    tab.value = next;

    try {
        window.localStorage.setItem(TAB_KEY, next);
    } catch {
        // Without storage the choice lasts until the page reloads.
    }
}

const tabs = [
    { key: 'plan', label: 'Plan' },
    { key: 'code', label: 'Code' },
] as const;
</script>

<template>
    <aside class="flex min-h-0 flex-col border-l" data-test="beside-panel">
        <div
            class="flex h-11 shrink-0 items-end gap-5 border-b px-5 text-sm"
            role="tablist"
            aria-label="Beside the chat"
        >
            <button
                v-for="option in tabs"
                :key="option.key"
                type="button"
                role="tab"
                :aria-selected="tab === option.key"
                :class="[
                    '-mb-px border-b-2 pb-2.5 transition-colors duration-200 select-none',
                    tab === option.key
                        ? 'border-foreground font-medium text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground',
                ]"
                :data-test="`beside-${option.key}`"
                @click="choose(option.key)"
            >
                {{ option.label }}
            </button>
        </div>
        <div class="relative min-h-0 flex-1 overflow-y-auto text-sm">
            <p
                v-if="!ready"
                class="px-5 py-5 leading-relaxed text-muted-foreground"
                data-test="beside-waiting"
            >
                {{
                    tab === 'plan'
                        ? 'The plan shows here once I know what to build.'
                        : 'The code shows here once I start building.'
                }}
            </p>
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 translate-y-1"
            >
                <div
                    v-show="tab === 'plan'"
                    id="beside-plan"
                    class="px-5 py-5"
                    data-test="beside-plan-content"
                />
            </Transition>
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 translate-y-1"
            >
                <div
                    v-show="tab === 'code'"
                    id="beside-code"
                    class="px-5 py-5"
                    data-test="beside-code-content"
                />
            </Transition>
        </div>
    </aside>
</template>
