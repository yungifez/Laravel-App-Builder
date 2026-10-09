<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';

// What a public page says about itself in search results and in the
// preview when someone shares its link. The head-key replaces the
// default description in app.blade.php rather than adding a second one.
const props = defineProps<{
    title: string;
    description: string;
}>();

const site = usePage().props.name;
</script>

<template>
    <Head :title="props.title">
        <meta
            head-key="description"
            name="description"
            :content="props.description"
        />
        <meta head-key="og:type" property="og:type" content="website" />
        <meta head-key="og:site_name" property="og:site_name" :content="site" />
        <meta head-key="og:title" property="og:title" :content="props.title" />
        <meta
            head-key="og:description"
            property="og:description"
            :content="props.description"
        />
        <meta head-key="twitter:card" name="twitter:card" content="summary" />
    </Head>
</template>
