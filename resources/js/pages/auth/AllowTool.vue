<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { approve, deny } from '@/routes/passport/authorizations';

// Where the owner lets their own tool in through OAuth: a connector in the
// Claude app, VS Code or Cursor. Allowing sends them back to the tool, on
// another site, so these are plain forms: Inertia cannot follow a redirect
// away from us.
defineOptions({
    layout: {
        title: 'Connect your own tool',
        description:
            'It makes the changes you ask for in your apps. I still check and review what it hands back.',
    },
});

defineProps<{
    tool: string;
    returnsTo: string | null;
    authToken: string;
    csrfToken: string;
}>();

// The spinner covers the pressed label rather than sitting beside it, so
// the buttons keep their width and do not move under the finger. It sits
// in a span: a button with an icon as a direct child gets less padding.
const sending = ref<'allow' | 'deny' | null>(null);
</script>

<template>
    <Head title="Connect your own tool" />

    <div class="space-y-6 text-sm">
        <p class="break-words" data-test="allow-tool-ask">
            Let <span class="font-medium">{{ tool }}</span> work on your apps?
            <template v-if="returnsTo">
                Then you go back to {{ returnsTo }}.</template
            >
        </p>

        <div class="flex flex-wrap gap-2">
            <form
                :action="approve.url()"
                method="post"
                @submit="sending = 'allow'"
            >
                <input type="hidden" name="_token" :value="csrfToken" />
                <input type="hidden" name="auth_token" :value="authToken" />
                <Button
                    type="submit"
                    :disabled="sending !== null"
                    class="relative h-11 select-none sm:h-9"
                    data-test="allow-tool"
                >
                    <span :class="{ invisible: sending === 'allow' }"
                        >Allow</span
                    >
                    <span
                        v-if="sending === 'allow'"
                        class="absolute inset-0 flex items-center justify-center"
                    >
                        <Spinner />
                    </span>
                </Button>
            </form>
            <form :action="deny.url()" method="post" @submit="sending = 'deny'">
                <input type="hidden" name="_token" :value="csrfToken" />
                <input type="hidden" name="_method" value="DELETE" />
                <input type="hidden" name="auth_token" :value="authToken" />
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="sending !== null"
                    class="relative h-11 select-none sm:h-9"
                    data-test="deny-tool"
                >
                    <span :class="{ invisible: sending === 'deny' }"
                        >Not now</span
                    >
                    <span
                        v-if="sending === 'deny'"
                        class="absolute inset-0 flex items-center justify-center"
                    >
                        <Spinner />
                    </span>
                </Button>
            </form>
        </div>

        <p class="text-xs text-muted-foreground">
            You can sign it out at any time in Settings, under Tools.
        </p>
    </div>
</template>
