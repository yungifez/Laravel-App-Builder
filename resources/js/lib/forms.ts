import { nextTick } from 'vue';

/**
 * Ties a field to its error line, so a screen reader reads the error
 * when the field gets focus. The error line's id is "<name>-error".
 */
export function fieldError(
    errors: Partial<Record<string, string>>,
    name: string,
): Record<string, string> {
    return errors[name]
        ? { 'aria-invalid': 'true', 'aria-describedby': `${name}-error` }
        : {};
}

/**
 * After a failed send, put the cursor in the first field to fix, so
 * nobody has to hunt for what went wrong.
 */
export function focusFirstError(): void {
    void nextTick(() =>
        document
            .querySelector<HTMLElement>('form [aria-invalid="true"]')
            ?.focus(),
    );
}
