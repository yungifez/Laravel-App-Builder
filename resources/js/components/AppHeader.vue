<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { getInitials } from '@/composables/useInitials';
import { index as projectsIndex } from '@/routes/projects';
import type { BreadcrumbItem } from '@/types';

// An owner has one place to go: their apps. A sidebar with one link took
// a quarter of the screen, so a thin bar holds the logo and the account.
type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const auth = computed(() => page.props.auth);
</script>

<template>
    <header class="border-b">
        <div class="mx-auto flex h-14 items-center gap-3 px-3 sm:px-4">
            <Link
                :href="projectsIndex()"
                class="flex min-h-11 min-w-0 items-center rounded-lg select-none"
                data-test="home-link"
            >
                <AppLogo />
            </Link>

            <div
                v-if="props.breadcrumbs.length > 1"
                class="hidden min-w-0 text-muted-foreground sm:block"
            >
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>

            <div class="ml-auto flex shrink-0 items-center gap-1">
                <NotificationBell />
                <DropdownMenu>
                    <DropdownMenuTrigger :as-child="true">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-11 rounded-full sm:size-10"
                            aria-label="Your account"
                            data-test="account-menu"
                        >
                            <Avatar class="size-8 overflow-hidden rounded-full">
                                <AvatarImage
                                    v-if="auth.user.avatar"
                                    :src="auth.user.avatar"
                                    :alt="auth.user.name"
                                />
                                <AvatarFallback
                                    class="rounded-full bg-secondary text-xs font-semibold text-secondary-foreground"
                                >
                                    {{ getInitials(auth.user?.name) }}
                                </AvatarFallback>
                            </Avatar>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <UserMenuContent :user="auth.user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </header>
</template>
