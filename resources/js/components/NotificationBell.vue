<script setup lang="ts">
import { Link, router, usePage, usePoll } from '@inertiajs/vue3';
import {
    Bell,
    BellRing,
    CircleAlert,
    CircleCheck,
    MessageCircleQuestion,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import NotificationReadController from '@/actions/App/Http/Controllers/NotificationReadController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { OwnerNotification } from '@/types';

const page = usePage();
const unread = computed(() => page.props.notifications?.unread ?? 0);
const items = computed(() => page.props.notifications?.items ?? []);

// A change takes minutes, so look for news now and then wherever the
// owner is.
usePoll(15000, { only: ['notifications'] });

const icons = {
    ready: { icon: CircleCheck, class: 'text-emerald-600' },
    question: { icon: MessageCircleQuestion, class: 'text-amber-600' },
    failed: { icon: CircleAlert, class: 'text-destructive' },
};

// The browser can tell the owner while they are in another tab or app,
// once they allow it.
const permission = ref<NotificationPermission | 'unsupported'>('unsupported');
const seen = new Set<string>();

onMounted(() => {
    permission.value =
        'Notification' in window ? Notification.permission : 'unsupported';
    items.value.forEach((item) => seen.add(item.id));
});

function tell(item: OwnerNotification): void {
    const note = new Notification(item.title, {
        body: item.body,
        tag: item.id,
    });

    note.onclick = () => {
        window.focus();
        router.visit(NotificationController.show.url(item.id));
        note.close();
    };
}

watch(items, (current) => {
    for (const item of current) {
        if (seen.has(item.id)) {
            continue;
        }

        seen.add(item.id);

        if (!item.read && document.hidden && permission.value === 'granted') {
            tell(item);
        }
    }
});

async function allow(): Promise<void> {
    permission.value = await Notification.requestPermission();
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative size-11 shrink-0 sm:size-9"
                :aria-label="
                    unread > 0
                        ? `${unread} things need you`
                        : 'Nothing needs you'
                "
                data-test="notifications"
            >
                <BellRing v-if="unread > 0" class="size-4" />
                <Bell v-else class="size-4" />
                <span
                    v-if="unread > 0"
                    class="absolute top-1 right-1 grid min-w-4 place-items-center rounded-full bg-foreground px-1 text-[10px] leading-4 font-medium text-background"
                    data-test="notifications-unread"
                    >{{ unread }}</span
                >
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            align="end"
            :collision-padding="16"
            class="w-80 max-w-[calc(100vw-2rem)]"
        >
            <p
                v-if="items.length === 0"
                class="px-2 py-6 text-center text-sm text-muted-foreground"
            >
                Nothing needs you
            </p>
            <DropdownMenuItem
                v-for="item in items"
                :key="item.id"
                as-child
                class="items-start gap-2 py-2"
            >
                <Link
                    :href="NotificationController.show.url(item.id)"
                    :data-test="`notification-${item.id}`"
                >
                    <component
                        :is="icons[item.kind].icon"
                        :class="[
                            'mt-0.5 size-4 shrink-0',
                            icons[item.kind].class,
                        ]"
                    />
                    <span class="min-w-0 flex-1">
                        <span
                            :class="[
                                'block text-sm',
                                item.read
                                    ? 'text-muted-foreground'
                                    : 'font-medium',
                            ]"
                            >{{ item.title }}</span
                        >
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{ item.body }}</span
                        >
                    </span>
                </Link>
            </DropdownMenuItem>
            <template v-if="permission === 'default' || unread > 0">
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    v-if="permission === 'default'"
                    data-test="notifications-allow"
                    @select="allow"
                >
                    <BellRing class="size-4" />
                    Tell me when I'm in another tab
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="unread > 0"
                    data-test="notifications-read"
                    @select="
                        router.post(
                            NotificationReadController.url(),
                            {},
                            { preserveScroll: true, only: ['notifications'] },
                        )
                    "
                >
                    Mark all read
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
