<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ArrowRight, ChevronDown } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { keepIdea } from '@/lib/startIdea';
import { register } from '@/routes';
import { index } from '@/routes/projects';
import type { Starter } from '@/types';

// The public menu's one real job: start an app from a ready-made idea in
// two clicks, from any public page. The idea goes with the visitor to the
// new-app form, as the box on the home page sends it.
defineProps<{ starters: Starter[] }>();

const page = usePage();

function startFrom(starter: Starter): void {
    keepIdea(starter.purpose, starter.key);
    router.visit(page.props.auth.user ? index() : register());
}
</script>

<template>
    <DropdownMenu v-if="starters.length > 0">
        <DropdownMenuTrigger
            class="group inline-flex items-center gap-1 text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:text-foreground data-[state=open]:text-foreground"
            data-test="ready-made-menu"
        >
            Ready-made apps
            <ChevronDown
                class="size-3.5 transition-transform duration-base group-data-[state=open]:rotate-180"
                aria-hidden="true"
            />
        </DropdownMenuTrigger>
        <DropdownMenuContent
            align="start"
            :side-offset="12"
            :collision-padding="16"
            class="w-[22rem] max-w-[calc(100vw-2rem)] p-1.5"
        >
            <DropdownMenuLabel
                class="px-2.5 pt-1.5 pb-2 text-xs font-normal text-muted-foreground"
            >
                Start an app from one of these
            </DropdownMenuLabel>
            <DropdownMenuItem
                v-for="starter in starters"
                :key="starter.key"
                class="group/item items-start gap-3 px-2.5 py-2"
                :data-test="`ready-made-${starter.key}`"
                @select="startFrom(starter)"
            >
                <span class="min-w-0 flex-1">
                    <span class="block font-medium">{{ starter.name }}</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">{{
                        starter.purpose
                    }}</span>
                </span>
                <ArrowRight
                    class="mt-0.5 opacity-0 transition-opacity duration-quick group-focus/item:opacity-100"
                    aria-hidden="true"
                />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
