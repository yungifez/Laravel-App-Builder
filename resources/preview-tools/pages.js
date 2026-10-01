// Page reporter, injected by the preview gateway into the pages of a preview
// that is not edited, such as a change the owner tries. It talks only to the
// builder that embeds the preview (its origin is set in "data-builder-origin"
// on this script's tag). It says which page is on show, and goes to a page
// when the builder asks, so the builder's back, forward and page list work.
(() => {
    const script = document.currentScript;
    const origin = script && script.dataset.builderOrigin;

    if (!origin || window.parent === window) {
        return;
    }

    const send = (type) =>
        window.parent.postMessage(
            { builder: true, type, path: location.pathname },
            origin,
        );

    window.addEventListener('message', (event) => {
        if (
            event.origin !== origin ||
            event.source !== window.parent ||
            event.data?.builder !== true
        ) {
            return;
        }

        // The builder page can start to listen after the app said it was
        // ready; it then asks again.
        if (event.data.type === 'hello') {
            send('ready');
        }

        if (event.data.type === 'go' && typeof event.data.href === 'string') {
            const to = new URL(event.data.href, location.href);

            if (to.origin === location.origin) {
                location.assign(to.href);
            }
        }
    });

    send('ready');

    // Apps that change page without loading one (Inertia, Livewire) only
    // change the address, so say so.
    let path = location.pathname;
    const moved = () => {
        if (location.pathname !== path) {
            path = location.pathname;
            send('page');
        }
    };

    for (const name of ['pushState', 'replaceState']) {
        const change = history[name];
        history[name] = function (...args) {
            const result = change.apply(this, args);
            moved();

            return result;
        };
    }

    window.addEventListener('popstate', moved);
})();
