/**
 * Say when something happened the way a person would: "today",
 * "yesterday", or a short date. Owners scan for recency, not timestamps.
 */
export function when(iso: string | null, now: Date = new Date()): string {
    if (iso === null) {
        return '';
    }

    const date = new Date(iso);
    const days = Math.round(
        (startOfDay(now).getTime() - startOfDay(date).getTime()) / 86_400_000,
    );

    if (days <= 0) {
        return 'today';
    }

    if (days === 1) {
        return 'yesterday';
    }

    return date.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: date.getFullYear() === now.getFullYear() ? undefined : 'numeric',
    });
}

function startOfDay(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}
