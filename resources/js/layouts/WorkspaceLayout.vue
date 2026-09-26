<script setup lang="ts">
import { Toaster } from '@/components/ui/sonner';
</script>

<template>
    <!-- Like other app builders, an app opens as one full-screen workspace:
         no sidebar and no page header, so the app itself gets the room. -->
    <div class="flex h-svh flex-col overflow-hidden bg-background">
        <slot />
        <Toaster />
    </div>
</template>
