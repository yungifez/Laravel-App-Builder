<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { LoaderCircle, UserRound, UserRoundPlus } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import PreviewPersonController from '@/actions/App/Http/Controllers/PreviewPersonController';
import PreviewSignInController from '@/actions/App/Http/Controllers/PreviewSignInController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { PreviewPerson } from '@/types';

const props = defineProps<{
    projectId: string;
    people: PreviewPerson[] | null | undefined;
    // The page of the app on show, to stay on once signed in.
    path: string;
    // The change the owner is trying, when the tools work on its copy.
    copy?: string | null;
}>();

const emit = defineEmits<{ open: [href: string] }>();

// Who can sign in is read from the app each time the menu opens, so
// someone who just signed up is there.
function opened(open: boolean): void {
    if (open) {
        router.reload({ only: ['people'] });
    }
}

function label(person: PreviewPerson): string {
    return person.name ?? person.email ?? `Person ${person.id}`;
}

const signIn = useHttp({ person: '', to: '/' });
const signingIn = ref<string | null>(null);

async function signInAs(person: PreviewPerson): Promise<void> {
    signIn.person = person.id;
    signIn.to = props.path;
    signingIn.value = person.id;

    try {
        const { url } = (await signIn.post(
            PreviewSignInController.store.url(props.projectId, {
                query: { copy: props.copy },
            }),
        )) as { url: string };

        emit('open', url);
        // The app takes a moment to show it, so say who they are now.
        toast(`You are signed in as ${label(person)}`);
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

// A new app has nobody to be yet, so the owner can make someone, with the
// app's own example details, and be them at once.
const make = useHttp({ to: '/' });

async function makePerson(): Promise<void> {
    make.to = props.path;
    signingIn.value = 'new';

    try {
        const { person, url } = (await make.post(
            PreviewPersonController.store.url(props.projectId, {
                query: { copy: props.copy },
            }),
        )) as { person: PreviewPerson; url: string };

        emit('open', url);
        toast(`You are signed in as ${label(person)}, a new test person`);
    } catch {
        const errors = make.errors as Record<string, string | undefined>;

        toast.error(
            errors.app ??
                'Your app could not make a test person. This is our fault. Try again.',
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
                Nobody has signed up yet.
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
                    <span class="w-full truncate">{{ label(person) }}</span>
                    <span
                        v-if="person.name && person.email"
                        class="w-full truncate text-xs text-muted-foreground"
                        >{{ person.email }}</span
                    >
                </DropdownMenuItem>
            </template>
            <template v-if="people !== undefined">
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    :disabled="signingIn !== null"
                    data-test="sign-in-as-new"
                    @select="makePerson"
                >
                    <UserRoundPlus class="size-4" />
                    Make a test person
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
