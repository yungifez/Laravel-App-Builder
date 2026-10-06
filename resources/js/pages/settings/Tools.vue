<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Plug } from '@lucide/vue';
import ToolController from '@/actions/App/Http/Controllers/Settings/ToolController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/tools';

type Tool = {
    id: string;
    name: string;
    allowed: string;
    used: string;
};

defineProps<{
    tools: Tool[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Tools you let in',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Tools you let in" />

    <h1 class="sr-only">Tools you let in</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Tools you let in"
            description="The Claude app, VS Code or Cursor, when you pressed Allow so they could work on your apps"
        />

        <div class="overflow-hidden rounded-lg border border-border">
            <!-- Signing out takes one press and is undone by allowing the
                 tool again, so no "are you sure" step. -->
            <div
                v-for="tool in tools"
                :key="tool.id"
                class="flex items-center justify-between gap-4 border-b p-4 last:border-b-0"
                data-test="let-in-tool"
            >
                <div class="min-w-0 space-y-1">
                    <p class="truncate font-medium tracking-tight">
                        {{ tool.name }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Allowed {{ tool.allowed }}
                        <span class="mx-1 text-muted-foreground/50">/</span>
                        Last used {{ tool.used }}
                    </p>
                </div>
                <Form
                    v-bind="ToolController.destroy.form(tool.id)"
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="processing"
                        class="h-11 shrink-0 select-none sm:h-8"
                        data-test="sign-out-tool"
                    >
                        Sign out
                    </Button>
                </Form>
            </div>

            <div v-if="tools.length === 0" class="p-8 text-center">
                <div
                    class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-muted"
                >
                    <Plug class="h-7 w-7 text-muted-foreground" />
                </div>
                <p class="font-medium">No tools yet</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    A tool shows here once you press Allow for it
                </p>
            </div>
        </div>
    </div>
</template>
