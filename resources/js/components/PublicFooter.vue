<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import {
    contact,
    home,
    login,
    pricing,
    privacy,
    register,
    terms,
} from '@/routes';
import { index } from '@/routes/projects';

// One footer for every public page, so nothing shifts as a visitor moves
// between them. Its first links point at parts of the home page.
const sections = [
    { hash: '#different', label: 'How it works' },
    { hash: '#ideas', label: 'Ready-made apps' },
    { hash: '#questions', label: 'Questions' },
];

// On the home page itself the link glides to its part, so the visitor
// sees they moved down this page rather than landing somewhere new.
function jumpTo(event: MouseEvent, hash: string): void {
    const target =
        window.location.pathname === home().url
            ? document.querySelector(hash)
            : null;

    if (target === null) {
        return;
    }

    event.preventDefault();
    target.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'auto'
            : 'smooth',
    });
    window.history.replaceState(window.history.state, '', hash);
}
</script>

<template>
    <footer class="border-t">
        <div
            class="mx-auto grid max-w-7xl gap-10 px-4 py-14 text-sm sm:grid-cols-2 sm:px-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]"
        >
            <div>
                <div class="flex items-center gap-2"><AppLogo /></div>
                <p class="mt-3 text-muted-foreground">
                    Apps that pass their own tests.
                </p>
            </div>
            <nav aria-label="Product">
                <p class="font-medium">Product</p>
                <ul class="mt-3 space-y-2 text-muted-foreground">
                    <li v-for="section in sections" :key="section.hash">
                        <a
                            :href="`${home().url}${section.hash}`"
                            class="hover:text-foreground"
                            @click="jumpTo($event, section.hash)"
                            >{{ section.label }}</a
                        >
                    </li>
                    <li>
                        <Link :href="pricing()" class="hover:text-foreground"
                            >Pricing</Link
                        >
                    </li>
                </ul>
            </nav>
            <nav aria-label="Company">
                <p class="font-medium">Company</p>
                <ul class="mt-3 space-y-2 text-muted-foreground">
                    <li>
                        <Link :href="contact()" class="hover:text-foreground"
                            >Contact</Link
                        >
                    </li>
                    <li>
                        <Link :href="terms()" class="hover:text-foreground"
                            >Terms</Link
                        >
                    </li>
                    <li>
                        <Link :href="privacy()" class="hover:text-foreground"
                            >Privacy</Link
                        >
                    </li>
                </ul>
            </nav>
            <nav aria-label="Account">
                <p class="font-medium">Account</p>
                <ul class="mt-3 space-y-2 text-muted-foreground">
                    <li v-if="$page.props.auth.user">
                        <Link :href="index()" class="hover:text-foreground"
                            >Your apps</Link
                        >
                    </li>
                    <template v-else>
                        <li>
                            <Link
                                :href="register()"
                                class="hover:text-foreground"
                                >Start an app</Link
                            >
                        </li>
                        <li>
                            <Link :href="login()" class="hover:text-foreground"
                                >Log in</Link
                            >
                        </li>
                    </template>
                </ul>
            </nav>
        </div>
    </footer>
</template>
