/**
 * Formatting for the operations screens, where exact numbers matter more
 * than friendly wording.
 */

export function duration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    if (seconds < 60) {
        return `${Math.round(seconds * 10) / 10}s`;
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m ${Math.round(seconds % 60)}s`;
    }

    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

export function usd(amount: number | null | undefined): string {
    if (amount === null || amount === undefined) {
        return '—';
    }

    return `$${amount.toFixed(amount !== 0 && amount < 0.1 ? 4 : 2)}`;
}

export function stamp(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

export function short(sha: string | null | undefined): string {
    return sha ? sha.slice(0, 10) : '—';
}

export function words(value: string | null | undefined): string {
    return value ? value.replaceAll('_', ' ') : '—';
}

// A share always travels with what it is a share of.
export function share(part: number, whole: number): string {
    return whole === 0
        ? '—'
        : `${Math.round((part / whole) * 100)}% (${part} of ${whole})`;
}
