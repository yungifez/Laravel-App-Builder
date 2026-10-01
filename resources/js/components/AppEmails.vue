<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowLeft, Mail, Trash2 } from '@lucide/vue';
import { useScreen } from '@/composables/useScreen';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import PreviewEmailController from '@/actions/App/Http/Controllers/PreviewEmailController';
import { when } from '@/lib/when';
import type { SentEmail } from '@/types';

const props = defineProps<{
    projectId: string;
    emails: SentEmail[] | undefined;
    // Where the app on show is served. A link there opens in the app.
    origin: string | null;
    // A change's copy keeps its emails; they are only read here.
    readonly?: boolean;
}>();

const emit = defineEmits<{ open: [href: string] }>();

const wide = useScreen('(min-width: 768px)');
const chosenId = ref<string | null>(null);

// Deleted emails leave the list at once; they come back only if the app
// could not mark them.
const deleting = ref<Set<string>>(new Set());
const emails = computed(() =>
    props.emails?.filter((email) => !deleting.value.has(email.id)),
);

function remove(ids: string[]): void {
    deleting.value = new Set([...deleting.value, ...ids]);

    if (chosenId.value !== null && ids.includes(chosenId.value)) {
        chosenId.value = null;
    }

    router.delete(PreviewEmailController.destroy.url(props.projectId), {
        data: { emails: ids },
        only: ['emails'],
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            deleting.value = new Set(
                [...deleting.value].filter((id) => !ids.includes(id)),
            );
            toast.error(
                errors.app ?? 'The emails could not be deleted. Try again.',
            );
        },
    });
}

// A wide screen shows the list and an email side by side, the newest
// until the owner picks another. A phone shows one at a time.
const chosen = computed(
    () =>
        emails.value?.find((email) => email.id === chosenId.value) ??
        (wide.value ? (emails.value?.[0] ?? null) : null),
);

function sentAt(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    return when(iso) === 'today'
        ? new Date(iso).toLocaleTimeString([], {
              hour: 'numeric',
              minute: '2-digit',
          })
        : when(iso);
}

// A link to the app opens that page in the app on show, so a sign-up or a
// password reset can be followed to the end. Any other link opens in a new
// tab.
function follow(href: string | null): void {
    if (href === null || href.startsWith('#')) {
        return;
    }

    const url = new URL(href, props.origin ?? window.location.href);

    if (props.origin !== null && url.origin === props.origin) {
        emit('open', url.href);
    } else if (url.protocol === 'http:' || url.protocol === 'https:') {
        window.open(url.href, '_blank', 'noopener,noreferrer');
    }
}

// The email is drawn in a frame without scripts. Its links are followed
// here instead of inside the frame.
function watchLinks(event: Event): void {
    const document = (event.target as HTMLIFrameElement).contentDocument;

    document?.addEventListener('click', (click) => {
        const link = (click.target as Element | null)?.closest?.('a[href]');

        if (link) {
            click.preventDefault();
            follow(link.getAttribute('href'));
        }
    });
}

// Plain words with their web addresses made into links.
const pieces = computed(() =>
    (chosen.value?.text ?? '')
        .split(/(https?:\/\/[^\s<>"')]+)/)
        .map((text, index) => ({ text, link: index % 2 === 1 })),
);
</script>

<template>
    <div
        class="flex h-full min-h-0 overflow-hidden rounded-lg border bg-background"
        data-test="app-emails"
    >
        <div
            v-if="emails === undefined"
            class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
        >
            Looking for emails…
        </div>

        <div
            v-else-if="emails.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center"
            data-test="app-emails-empty"
        >
            <Mail class="size-6 text-muted-foreground" />
            <p class="text-lg font-medium">No emails yet</p>
            <p class="max-w-xs text-sm text-muted-foreground">
                Emails your app sends, like a password reset, show here. Nobody
                really gets them while you try your app.
            </p>
        </div>

        <template v-else-if="emails">
            <div
                v-show="wide || chosen === null"
                class="flex min-h-0 w-full shrink-0 flex-col border-r md:w-72"
            >
                <div
                    class="flex min-h-11 items-center justify-between gap-2 border-b px-3 text-xs text-muted-foreground"
                >
                    <span class="tabular-nums"
                        >{{ emails.length }}
                        {{ emails.length === 1 ? 'email' : 'emails' }}</span
                    >
                    <button
                        v-if="!readonly"
                        type="button"
                        class="min-h-11 select-none hover:text-foreground sm:min-h-8"
                        data-test="app-emails-delete-all"
                        @click="remove(emails.map((email) => email.id))"
                    >
                        Delete all
                    </button>
                </div>
                <ul
                    class="min-h-0 flex-1 overflow-y-auto"
                    aria-label="Emails your app sent"
                >
                    <li v-for="email in emails" :key="email.id">
                        <button
                            type="button"
                            :aria-current="chosen?.id === email.id"
                            :class="[
                                'flex min-h-11 w-full flex-col gap-0.5 border-b px-3 py-2 text-left',
                                chosen?.id === email.id
                                    ? 'bg-muted'
                                    : 'hover:bg-muted/50',
                            ]"
                            :data-test="`app-email-${email.id}`"
                            @click="chosenId = email.id"
                        >
                            <span class="flex items-baseline gap-2">
                                <span class="min-w-0 flex-1 truncate text-sm">{{
                                    email.subject || 'No subject'
                                }}</span>
                                <span
                                    class="shrink-0 text-xs text-muted-foreground"
                                    >{{ sentAt(email.sent_at) }}</span
                                >
                            </span>
                            <span class="truncate text-xs text-muted-foreground"
                                >To {{ email.to }}</span
                            >
                        </button>
                    </li>
                </ul>
            </div>

            <article
                v-if="chosen"
                class="flex min-h-0 min-w-0 flex-1 flex-col"
                data-test="app-email"
            >
                <header class="flex items-start gap-2 border-b px-3 py-2">
                    <button
                        v-if="!wide"
                        type="button"
                        class="-ml-1 grid size-11 shrink-0 place-items-center"
                        aria-label="All emails"
                        @click="chosenId = null"
                    >
                        <ArrowLeft class="size-4" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-base font-medium">
                            {{ chosen.subject || 'No subject' }}
                        </h2>
                        <p class="truncate text-xs text-muted-foreground">
                            To {{ chosen.to }} · From {{ chosen.from }}
                        </p>
                    </div>
                    <button
                        v-if="!readonly"
                        type="button"
                        class="-mr-1 grid size-11 shrink-0 place-items-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground sm:size-8"
                        aria-label="Delete this email"
                        title="Delete this email"
                        data-test="app-email-delete"
                        @click="remove([chosen.id])"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </header>
                <!-- Drawn as its reader sees it, on white as most email
                     is, without its scripts. -->
                <iframe
                    v-if="chosen.html !== null"
                    :key="chosen.id"
                    :srcdoc="chosen.html"
                    sandbox="allow-same-origin"
                    title="The email"
                    class="min-h-0 flex-1 bg-white"
                    data-test="app-email-body"
                    @load="watchLinks"
                />
                <p
                    v-else
                    class="min-h-0 flex-1 overflow-y-auto p-3 text-sm whitespace-pre-wrap"
                    data-test="app-email-body"
                >
                    <template v-for="(piece, index) in pieces" :key="index"
                        ><button
                            v-if="piece.link"
                            type="button"
                            class="text-left break-all underline underline-offset-2"
                            @click="follow(piece.text)"
                        >
                            {{ piece.text }}</button
                        ><template v-else>{{ piece.text }}</template></template
                    >
                </p>
            </article>
        </template>
    </div>
</template>
