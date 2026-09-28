<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';

defineProps<{
    name: string;
    open: boolean;
    /** Something in it is already changed, so it says so while folded. */
    changed?: boolean;
}>();

const emit = defineEmits<{ toggle: [] }>();
</script>

<template>
    <!-- One group of choices in the panel. Only one is open at a time, so
         the owner faces one question, not every choice at once. It unfolds
         instead of popping in, as Reveal does. Groups in a row sit edge to
         edge, without the panel's gap between them. -->
    <section
        class="panel-section border-t border-border/60 [&:has(+.panel-section)]:mb-0"
    >
        <h3>
            <button
                type="button"
                class="flex min-h-11 w-full items-center gap-2 text-left text-xs font-medium transition-colors hover:text-foreground sm:min-h-9"
                :class="open ? 'text-foreground' : 'text-muted-foreground'"
                :aria-expanded="open"
                @click="emit('toggle')"
            >
                <span class="min-w-0 flex-1 truncate">{{ name }}</span>
                <span
                    v-if="changed"
                    class="size-1.5 shrink-0 rounded-full bg-foreground"
                    title="Changed"
                />
                <ChevronDown
                    :class="[
                        'size-4 shrink-0 transition-transform duration-base ease-settle',
                        open && 'rotate-180',
                    ]"
                />
            </button>
        </h3>
        <div
            :class="[
                'grid transition-[grid-template-rows,opacity] duration-base ease-settle',
                open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] opacity-0',
            ]"
            :inert="!open"
        >
            <div class="min-h-0 overflow-clip [overflow-clip-margin:0.25rem]">
                <div class="space-y-2 pb-4">
                    <slot />
                </div>
            </div>
        </div>
    </section>
</template>
