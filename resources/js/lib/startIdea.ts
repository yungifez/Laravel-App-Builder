// What a visitor typed in the box on the home page, kept for the new-app
// form on "Your apps", which they reach after signing up or logging in.
// When they started from a ready-made idea, its key comes too, so the form
// can fill in the rest of that idea. It stays in this tab only and is read
// once. Storage can be blocked, so a lost idea only means an empty box.
const key = 'builder.start-idea';

export type StartIdea = { idea: string; starter: string | null };

export function keepIdea(idea: string, starter: string | null = null): void {
    try {
        sessionStorage.setItem(key, JSON.stringify({ idea, starter }));
    } catch {
        // The visitor types it again.
    }
}

// Reads the idea without using it up, so a page on the way can show it.
export function peekIdea(): string {
    try {
        const kept = JSON.parse(sessionStorage.getItem(key) ?? 'null');

        return typeof kept?.idea === 'string' ? kept.idea : '';
    } catch {
        return '';
    }
}

export function takeIdea(): StartIdea {
    try {
        const kept = JSON.parse(sessionStorage.getItem(key) ?? 'null');
        sessionStorage.removeItem(key);

        return {
            idea: typeof kept?.idea === 'string' ? kept.idea : '',
            starter: typeof kept?.starter === 'string' ? kept.starter : null,
        };
    } catch {
        return { idea: '', starter: null };
    }
}
