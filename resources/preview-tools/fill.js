// Form filler, injected by the preview gateway into every page of a preview.
// It talks only to the builder that embeds the preview (its origin is set in
// "data-builder-origin" on this script's tag), so a person who opens the app
// from a shared link gets nothing from it.
//
// It says how many empty fields the page on show has. When the builder asks,
// it puts an example value in each one, the way typing would, so the app's
// own code sees it. It never sends a form: the owner looks, then presses the
// app's own button. It acts only on a message from the builder's origin and
// answers only to it, with counts and never with what a field holds.
//
// It reads only what every web page has (the kind of a field, its name, its
// label), so it works whatever the app's screens are made with. A choice the
// app draws itself, without a field of the browser, is left for the owner.
(() => {
    const script = document.currentScript;
    const origin = script && script.dataset.builderOrigin;

    if (!origin || window.parent === window) {
        return;
    }

    const send = (message) =>
        window.parent.postMessage({ builder: true, ...message }, origin);

    // Who the example details are about. Each fill takes the next person,
    // so an app that takes an email only once takes a second sign-up.
    const PEOPLE = [
        ['Ada', 'Lovelace'],
        ['Grace', 'Hopper'],
        ['Alan', 'Turing'],
        ['Katherine', 'Johnson'],
        ['Edsger', 'Dijkstra'],
        ['Margaret', 'Hamilton'],
    ];
    const NEVER = new Set([
        'hidden',
        'file',
        'submit',
        'button',
        'reset',
        'image',
        'range',
        'color',
        'search',
        'week',
    ]);

    // Which fill this is, kept for the app's address so it goes on after a
    // reload. A browser that keeps nothing for a framed page counts from one.
    let turn = 0;
    const next = () => {
        try {
            turn = Number(localStorage.getItem('builder-fill') ?? 0) || 0;
            localStorage.setItem('builder-fill', String(turn + 1));
        } catch {
            turn += 1;

            return turn - 1;
        }

        return turn;
    };

    const person = (at) => {
        const [first, last] = PEOPLE[at % PEOPLE.length];
        const round = Math.floor(at / PEOPLE.length);
        const again = round === 0 ? '' : String(round + 1);

        return {
            first,
            last,
            name: `${first} ${last}`,
            user: `${first}${again}`.toLowerCase(),
            email: `${first}.${last}${again}@example.com`.toLowerCase(),
        };
    };

    const pad = (number) => String(number).padStart(2, '0');
    const day = (date) =>
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    // A week from now: a day most forms take, for a booking or a deadline.
    const soon = () => new Date(Date.now() + 7 * 24 * 60 * 60 * 1000);

    const shown = (field) =>
        field.getClientRects().length > 0 &&
        getComputedStyle(field).visibility !== 'hidden' &&
        field.closest('[aria-hidden="true"],[inert],[data-builder-overlay]') ===
            null;

    // What the field is for, in the words the app gave it.
    const label = (field) =>
        [...(field.labels ?? [])]
            .map((item) => item.textContent)
            .join(' ')
            .trim() ||
        field.getAttribute('aria-label') ||
        '';
    const hint = (field) =>
        [
            field.getAttribute('autocomplete'),
            field.name,
            field.id,
            field.getAttribute('placeholder'),
            label(field),
        ]
            .join(' ')
            .toLowerCase();

    // A sign-in form asks for a password the app already has. An example
    // one would only be refused, so the whole form is left alone. The
    // builder signs the owner in with "Sign in as".
    // Fields outside any form go with a password field outside any form.
    const signsIn = (field) =>
        [
            ...document.querySelectorAll(
                'input[autocomplete~="current-password"]',
            ),
        ].some((password) => password.form === field.form);

    const choosesPassword = (field) =>
        /(^|\s)new-password(\s|$)/.test(
            field.getAttribute('autocomplete') ?? '',
        );

    // A password nobody knows. It is made new on each fill and put only in
    // the field: never sent to the builder, never kept. The owner comes
    // back with "Sign in as". It holds each kind of character that
    // password rules ask for.
    const secret = () => {
        const kinds = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnopqrstuvwxyz',
            '23456789',
            '!#%*+-=?@_',
        ];

        return [...crypto.getRandomValues(new Uint32Array(24))]
            .map((random, at) => {
                const kind = kinds[at % kinds.length];

                return kind[random % kind.length];
            })
            .join('');
    };

    // The fields a fill would change: on show, open to typing, still empty,
    // and not a search box, a one-time code or a sign-in.
    const fields = () => {
        const groups = new Set();

        return [...document.querySelectorAll('input,textarea,select')].filter(
            (field) => {
                const type = (
                    field.getAttribute('type') ?? 'text'
                ).toLowerCase();

                if (
                    field.disabled ||
                    field.readOnly ||
                    NEVER.has(type) ||
                    !shown(field) ||
                    field.closest('[role=search]') !== null ||
                    signsIn(field)
                ) {
                    return false;
                }

                const words = hint(field);

                // Never a card or a one-time code. A password only where
                // the app says a new one is chosen.
                if (
                    (type === 'password' && !choosesPassword(field)) ||
                    /(^|\s)cc-|one-time-code|captcha|\botp\b|search/.test(words)
                ) {
                    return false;
                }

                if (type === 'checkbox') {
                    // Only a box the form needs, such as its terms. Others
                    // are a choice, and stay as the app set them.
                    return (
                        !field.checked &&
                        (field.required ||
                            /terms|agree|accept|consent|privacy|policy/.test(
                                words,
                            ))
                    );
                }

                if (type === 'radio') {
                    const group = `${field.form ? [...document.forms].indexOf(field.form) : ''}/${field.name}`;
                    const chosen = [
                        ...document.querySelectorAll('input[type=radio]'),
                    ].some(
                        (other) =>
                            other.form === field.form &&
                            other.name === field.name &&
                            other.checked,
                    );

                    if (chosen || groups.has(group)) {
                        return false;
                    }

                    groups.add(group);

                    return true;
                }

                if (field instanceof HTMLSelectElement) {
                    return (
                        !field.multiple &&
                        field.value === '' &&
                        choice(field) !== null
                    );
                }

                return field.value === '';
            },
        );
    };

    // The first thing a list lets a person choose.
    const choice = (select) =>
        [...select.options].find(
            (option) => !option.disabled && option.value !== '',
        ) ?? null;

    // A number the field takes: inside its lowest and highest, on its step.
    const number = (field, wanted) => {
        const low = field.min === '' ? null : Number(field.min);
        const high = field.max === '' ? null : Number(field.max);
        let value = wanted;

        if (low !== null && !Number.isNaN(low) && value < low) {
            value = low;
        }

        if (high !== null && !Number.isNaN(high) && value > high) {
            value = high;
        }

        return String(value);
    };

    const within = (field, value) => {
        if (field.min !== '' && value < field.min) {
            return field.min;
        }

        return field.max !== '' && value > field.max ? field.max : value;
    };

    // The words a field with no known purpose gets: its own label, so the
    // owner sees which answer went where.
    const plain = (field) => {
        const words = (label(field) || field.getAttribute('placeholder') || '')
            .replace(/[*:]/g, '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();

        return words !== '' && words.length <= 30
            ? `Example ${words}`
            : 'Example text';
    };

    // The example value for one field.
    const value = (field, who) => {
        const type = (field.getAttribute('type') ?? 'text').toLowerCase();
        const words = hint(field);

        if (type === 'email' || /e-?mail/.test(words)) {
            return who.email;
        }

        if (type === 'tel' || /phone|mobile|\btel\b/.test(words)) {
            return '+12025550143';
        }

        if (type === 'url' || /website|\burl\b|homepage/.test(words)) {
            return 'https://example.com';
        }

        if (type === 'number') {
            return number(
                field,
                /price|amount|cost|budget|total/.test(words) ? 25 : 1,
            );
        }

        if (type === 'date') {
            return within(
                field,
                /birth|\bdob\b/.test(words) ? '1990-01-15' : day(soon()),
            );
        }

        if (type === 'time') {
            return within(field, '10:00');
        }

        if (type === 'datetime-local') {
            return within(field, `${day(soon())}T10:00`);
        }

        if (type === 'month') {
            return within(field, day(soon()).slice(0, 7));
        }

        if (field instanceof HTMLTextAreaElement) {
            return /address/.test(words)
                ? '12 Example Street'
                : 'This is an example message.';
        }

        if (/first.?name|given.?name/.test(words)) {
            return who.first;
        }

        if (/last.?name|surname|family.?name/.test(words)) {
            return who.last;
        }

        if (/user.?name|nickname|\bhandle\b/.test(words)) {
            return who.user;
        }

        if (/company|organi[sz]ation|business|employer/.test(words)) {
            return 'Example Company';
        }

        if (/street|address/.test(words)) {
            return '12 Example Street';
        }

        if (/\bcity\b|\btown\b/.test(words)) {
            return 'Springfield';
        }

        if (/\bzip\b|postal|postcode/.test(words)) {
            return '62701';
        }

        if (/\bstate\b|province|region/.test(words)) {
            return 'Illinois';
        }

        if (/country/.test(words)) {
            return 'United States';
        }

        if (/numeric|decimal/.test(field.getAttribute('inputmode') ?? '')) {
            return '1';
        }

        // Only a field for the person's own name. "Room name" is not one.
        if (
            /(^|\s)name(\s|$)/.test(field.getAttribute('autocomplete') ?? '') ||
            /^(your |full )?name$/.test(
                label(field).replace(/[*:]/g, '').trim().toLowerCase() ||
                    field.name.toLowerCase(),
            )
        ) {
            return who.name;
        }

        return plain(field);
    };

    // Put a value in a field as typing does. The app's own code may keep
    // its own copy of what a field holds (React does), so the value is set
    // the browser's way and the field then says it changed.
    const type = (field, text) => {
        const kind =
            field instanceof HTMLTextAreaElement
                ? HTMLTextAreaElement
                : field instanceof HTMLSelectElement
                  ? HTMLSelectElement
                  : HTMLInputElement;
        const fits =
            field.maxLength > 0 ? text.slice(0, field.maxLength) : text;

        Object.getOwnPropertyDescriptor(kind.prototype, 'value').set.call(
            field,
            fits,
        );
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const fill = () => {
        const empty = fields();

        if (empty.length === 0) {
            return 0;
        }

        const who = person(next());
        // One password for each form, so the field that asks for it again
        // gets the same one.
        const secrets = new Map();

        for (const field of empty) {
            const kind = (field.getAttribute('type') ?? 'text').toLowerCase();

            if (kind === 'checkbox' || kind === 'radio') {
                field.click();
            } else if (kind === 'password') {
                if (!secrets.has(field.form)) {
                    secrets.set(field.form, secret());
                }

                type(field, secrets.get(field.form));
            } else if (field instanceof HTMLSelectElement) {
                type(field, choice(field).value);
            } else {
                type(field, value(field, who));
            }
        }

        empty[0].scrollIntoView({ block: 'nearest' });

        return empty.length;
    };

    // Say how many fields are empty, when that changes.
    let told = null;
    const tell = (always = false) => {
        const empty = fields().length;

        if (always || empty !== told) {
            told = empty;
            send({ type: 'fields', empty });
        }
    };

    let timer;
    const soonTell = () => {
        clearTimeout(timer);
        timer = setTimeout(tell, 250);
    };

    window.addEventListener('message', (event) => {
        if (
            event.origin !== origin ||
            event.source !== window.parent ||
            event.data?.builder !== true
        ) {
            return;
        }

        // The builder page can start to listen after the app opened; it
        // then asks.
        if (event.data.type === 'fields') {
            tell(true);
        }

        if (event.data.type === 'fill') {
            send({ type: 'filled', fields: fill() });
            tell(true);
        }
    });

    // A form can come after the page (a dialog, a next step), and typing
    // changes what is still empty.
    new MutationObserver(soonTell).observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['hidden', 'disabled', 'class', 'style', 'open'],
    });
    document.addEventListener('input', soonTell, true);
    document.addEventListener('change', soonTell, true);

    tell(true);
})();
