// What a visitor typed in the box on the home page, kept for the new-app
// form on "Your apps", which they reach after signing up or logging in.
// When they started from a ready-made idea, its key comes too, so the form
// can fill in the rest of that idea. It is read once.
//
// It lives in this browser for a day, not in this tab: the link in the
// sign-up email opens a new tab, and that tab must still find the idea.
// A phone's mail app often opens the link in its own browser, which has
// none of this one's storage, so the idea is lost there; keeping it with
// the account on the server would fix that too. Storage can be blocked,
// so a lost idea only means an empty box.
const key = 'builder.start-idea';
const lifetime = 24 * 60 * 60 * 1000;

export type StartIdea = { idea: string; starter: string | null };

type Kept = { idea?: unknown; starter?: unknown; at?: unknown };

export function keepIdea(idea: string, starter: string | null = null): void {
    try {
        localStorage.setItem(
            key,
            JSON.stringify({ idea, starter, at: Date.now() }),
        );
    } catch {
        // The visitor types it again.
    }
}

// The idea if it is still fresh. An old one is dropped, so a visitor who
// comes back next week does not find last week's sentence in the box.
function read(): Kept | null {
    const kept: Kept | null = JSON.parse(localStorage.getItem(key) ?? 'null');

    if (
        kept &&
        typeof kept.at === 'number' &&
        Date.now() - kept.at < lifetime
    ) {
        return kept;
    }

    localStorage.removeItem(key);

    return null;
}

// Reads the idea without using it up, so a page on the way can show it.
export function peekIdea(): string {
    try {
        const kept = read();

        return typeof kept?.idea === 'string' ? kept.idea : '';
    } catch {
        return '';
    }
}

export function takeIdea(): StartIdea {
    try {
        const kept = read();
        localStorage.removeItem(key);

        return {
            idea: typeof kept?.idea === 'string' ? kept.idea : '',
            starter: typeof kept?.starter === 'string' ? kept.starter : null,
        };
    } catch {
        return { idea: '', starter: null };
    }
}
