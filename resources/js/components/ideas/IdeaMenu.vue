<script setup lang="ts">
import { Form, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, GitBranch, Lightbulb, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ExperimentController from '@/actions/App/Http/Controllers/ExperimentController';
import ProjectExperimentController from '@/actions/App/Http/Controllers/ProjectExperimentController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Idea, Ideas } from '@/types';

const props = defineProps<{
    projectId: number;
    ideas: Ideas & { current: Idea };
}>();

const page = usePage();
// Power users see the branches behind ideas.
const technical = computed(() => page.props.auth.user.detail_level >= 3);
const others = computed(() =>
    props.ideas.open.filter((idea) => idea.id !== props.ideas.current.id),
);
const discarding = ref(false);

function move(to: Idea | null): void {
    router.put(ProjectExperimentController.update.url(props.projectId), {
        experiment: to?.id ?? null,
    });
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                class="h-11 max-w-[45%] min-w-0 shrink gap-1.5 bg-muted px-2 select-none sm:h-9"
                :aria-label="`Idea: ${ideas.current.name}`"
                data-test="idea-menu"
            >
                <Lightbulb class="size-4 shrink-0" />
                <!-- A phone has no room for the name; the menu shows it. -->
                <span class="hidden truncate sm:inline">{{
                    ideas.current.name
                }}</span>
                <ChevronDown class="size-4 shrink-0 opacity-60" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" :collision-padding="16" class="w-64">
            <p class="px-2 py-1.5 text-sm font-medium sm:hidden">
                {{ ideas.current.name }}
            </p>
            <p
                v-if="technical"
                class="flex items-center gap-1.5 px-2 py-1.5 font-mono text-xs text-muted-foreground"
                data-test="idea-branch"
            >
                <GitBranch class="size-3.5" /> {{ ideas.current.branch }}
            </p>
            <DropdownMenuItem data-test="idea-leave" @select="move(null)">
                Back to your app
                <span
                    v-if="technical"
                    class="ml-auto font-mono text-xs text-muted-foreground"
                    >{{ ideas.main }}</span
                >
            </DropdownMenuItem>
            <DropdownMenuItem
                v-for="idea in others"
                :key="idea.id"
                @select="move(idea)"
            >
                <Lightbulb class="size-4" />
                <span class="truncate">{{ idea.name }}</span>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                class="text-destructive focus:text-destructive"
                data-test="idea-discard"
                @select="discarding = true"
            >
                <Trash2 class="size-4 text-destructive" />
                Throw this idea away
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <Dialog v-model:open="discarding">
        <DialogContent>
            <DialogHeader>
                <DialogTitle
                    >Throw “{{ ideas.current.name }}” away?</DialogTitle
                >
                <DialogDescription>
                    Its changes are deleted. Your app stays as it is.
                </DialogDescription>
            </DialogHeader>
            <Form
                v-bind="ExperimentController.destroy.form(ideas.current.id)"
                class="flex justify-end gap-2"
                v-slot="{ processing }"
                @success="discarding = false"
            >
                <Button
                    type="button"
                    variant="ghost"
                    class="h-11 sm:h-9"
                    @click="discarding = false"
                >
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    :disabled="processing"
                    class="h-11 select-none sm:h-9"
                    data-test="idea-discard-confirm"
                >
                    Throw it away
                </Button>
            </Form>
        </DialogContent>
    </Dialog>
</template>
