<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { destroy } from '@/routes/operations/sign-in';

// Wraps every signed-in page. While an operator is signed in as a person,
// a small bar says so and takes them back to their own account.
</script>

<template>
    <slot />
    <div
        v-if="$page.props.auth?.impersonating"
        class="fixed bottom-3 left-3 z-50 flex items-center gap-3 rounded-md bg-foreground py-1.5 pr-1.5 pl-3 text-sm text-background shadow-lg"
        data-test="signed-in-as"
    >
        <span class="max-w-48 truncate"
            >Signed in as {{ $page.props.auth.user?.name }}</span
        >
        <Link
            :href="destroy().url"
            method="delete"
            as="button"
            :preserve-state="false"
            class="min-h-9 rounded-sm bg-background/15 px-2.5 font-medium select-none hover:bg-background/25"
            data-test="sign-in-back"
        >
            Back to your account
        </Link>
    </div>
</template>
