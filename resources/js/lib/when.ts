import { ref } from 'vue';

// The page is first drawn on the server, which knows neither the owner's
// language nor their time zone. Until the browser takes the page over,
// dates are written as the server writes them, so both draw the same page
// (a day group drawn differently also moves every row after it).
const settled = ref(false);

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
    const locale = settled.value ? undefined : 'en';
    const timeZone = settled.value ? undefined : 'UTC';
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
