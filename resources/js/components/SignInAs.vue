<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { LoaderCircle, UserRound } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import PreviewSignInController from '@/actions/App/Http/Controllers/PreviewSignInController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { PreviewPerson } from '@/types';

const props = defineProps<{
    projectId: string;
    people: PreviewPerson[] | null | undefined;
    // The page of the app on show, to stay on once signed in.
    path: string;
}>();

const emit = defineEmits<{ open: [href: string] }>();

// Who can sign in is read from the app each time the menu opens, so
// someone who just signed up is there.
function opened(open: boolean): void {
    if (open) {
        router.reload({ only: ['people'] });
    }
}

const signIn = useHttp({ person: '', to: '/' });
const signingIn = ref<string | null>(null);

async function signInAs(person: PreviewPerson): Promise<void> {
    signIn.person = person.id;
    signIn.to = props.path;
    signingIn.value = person.id;

    try {
        const { url } = (await signIn.post(
            PreviewSignInController.store.url(props.projectId),
        )) as { url: string };

        emit('open', url);
    } catch {
        // The app's own reason comes under "app"; ours under "person".
        const errors = signIn.errors as Record<string, string | undefined>;

        toast.error(
            errors.app ??
                errors.person ??
                'Your app could not sign them in. This is our fault. Try again.',
        );
    } finally {
        signingIn.value = null;
    }
}
</script>

<template>
    <DropdownMenu @update:open="opened">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="size-11 sm:size-9"
                aria-label="Sign in as someone"
                title="Sign in as someone, with no password"
                data-test="sign-in-as"
            >
                <LoaderCircle
                    v-if="signingIn !== null"
                    class="size-4 animate-spin"
                />
                <UserRound v-else class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-64">
            <DropdownMenuLabel class="text-xs font-normal text-muted-foreground"
                >Sign in to your app as</DropdownMenuLabel
            >
            <p
                v-if="people === undefined"
                class="px-2 py-1.5 text-sm text-muted-foreground"
            >
                Looking…
            </p>
            <p
                v-else-if="people === null || people.length === 0"
                class="px-2 py-1.5 text-sm text-muted-foreground"
                data-test="sign-in-as-nobody"
            >
                Nobody has signed up yet. Sign up in your app, and you can come
                back here to be anyone who did, with no password.
            </p>
            <template v-else>
                <DropdownMenuItem
                    v-for="person in people"
                    :key="person.id"
                    class="flex-col items-start gap-0"
                    :disabled="signingIn !== null"
                    :data-test="`sign-in-as-${person.id}`"
                    @select="signInAs(person)"
                >
                    <span class="w-full truncate">{{
                        person.name ?? person.email ?? `Person ${person.id}`
                    }}</span>
                    <span
                        v-if="person.name && person.email"
                        class="w-full truncate text-xs text-muted-foreground"
                        >{{ person.email }}</span
                    >
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
