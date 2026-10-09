import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

// The page is first drawn on the server, which knows the owner's language
// from the request and their time zone from the cookie the browser leaves.
// Until the browser takes the page over, dates are written as the server
// writes them, so both draw the same page (a day group drawn differently
// also moves every row after it). The first visit, before the cookie, is
// drawn in UTC.
const settled = ref(false);

/** Remember the owner's time zone, so the server draws dates in it. */
export function rememberTimeZone(): void {
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    document.cookie = `time_zone=${encodeURIComponent(zone)}; path=/; max-age=31536000; SameSite=Lax`;
}

/** The browser has the page now; dates follow the owner's own settings. */
export function settleDates(): void {
    settled.value = true;
}

/**
 * Say when something happened the way a person would: "today",
 * "yesterday", or a short date. Owners scan for recency, not timestamps.
 */
export function when(iso: string | null, now: Date = new Date()): string {
    if (iso === null) {
        return '';
    }

    const date = new Date(iso);
    const clock = settled.value
        ? undefined
        : (usePage().props.clock ?? { locale: 'en', timeZone: 'UTC' });
    const locale = clock?.locale;
    const timeZone = clock?.timeZone;
    const days = Math.round(
        (calendarDay(now, timeZone) - calendarDay(date, timeZone)) / 86_400_000,
    );

    if (days <= 0) {
        return 'today';
    }

    if (days === 1) {
        return 'yesterday';
    }

    const year = (moment: Date) =>
        new Intl.DateTimeFormat('en', { year: 'numeric', timeZone }).format(
            moment,
        );

    return date.toLocaleDateString(locale, {
        day: 'numeric',
        month: 'short',
        year: year(date) === year(now) ? undefined : 'numeric',
        timeZone,
    });
}

// Midnight UTC of the day the moment falls on in the time zone, so two
// moments are days apart by plain subtraction.
function calendarDay(moment: Date, timeZone: string | undefined): number {
    const parts = new Intl.DateTimeFormat('en-CA', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        timeZone,
    }).format(moment);

    return Date.parse(parts);
}
