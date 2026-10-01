<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { Spinner } from '@/components/ui/spinner';

defineProps<{
    name: string;
}>();

// The app was asleep and is starting. Asking again opens it once it is
// ready: the server then sends this page on to the app.
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(() => router.reload(), 3000);
});

onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head :title="name" />

    <main
        class="flex min-h-svh flex-col items-center justify-center gap-3 bg-background px-4 text-center text-foreground"
    >
        <Spinner class="size-5 text-muted-foreground" />
        <h1 class="text-xl font-semibold tracking-[-0.02em]">
            Getting {{ name }} ready
        </h1>
        <p class="max-w-sm text-sm text-muted-foreground">
            It was resting. This takes about a minute, and the app opens by
            itself.
        </p>
    </main>
</template>
