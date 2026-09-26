// Point-and-edit overlay, injected by the preview gateway into the pages of an
// editable preview. It talks only to the builder that embeds the preview (its
// origin is set in "data-builder-origin" on this script's tag).
//
// While editing is on, hovering outlines an element and clicking selects it
// instead of following links. The builder receives the element's source
// location, never its contents beyond a short text sample. The builder can
// send inline styles to show an edit before it is saved.
(() => {
    const script = document.currentScript;
    const origin = script && script.dataset.builderOrigin;

    if (!origin || window.parent === window) {
        return;
    }

    let editing = false;
    let selected = null;
    const styled = new Set();

    const box = (color) => {
        const element = document.createElement('div');
        element.setAttribute('data-builder-overlay', '');
        element.style.cssText = `position:fixed;pointer-events:none;z-index:2147483647;border:2px solid ${color};border-radius:2px;display:none;box-sizing:border-box`;
        document.documentElement.appendChild(element);

        return element;
    };

    const hoverBox = box('#60a5fa');
    const selectedBox = box('#2563eb');

    const place = (target, element) => {
        if (!element || !element.isConnected) {
            target.style.display = 'none';

            return;
        }

        const rect = element.getBoundingClientRect();
        Object.assign(target.style, {
            display: 'block',
            top: `${rect.top}px`,
            left: `${rect.left}px`,
            width: `${rect.width}px`,
            height: `${rect.height}px`,
        });
    };

    const located = (node) =>
        node instanceof Element
            ? node.closest('[data-builder-source],[data-builder-instance]')
            : null;

    const send = (message) =>
        window.parent.postMessage({ builder: true, ...message }, origin);

    const describe = (element) => ({
        source: element.getAttribute('data-builder-source'),
        instance: element.getAttribute('data-builder-instance'),
        tag: element.tagName.toLowerCase(),
        text: (element.innerText || element.getAttribute('aria-label') || '')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 80),
        width: Math.round(element.getBoundingClientRect().width),
    });

    const matching = (location) => {
        if (!location) {
            return [];
        }

        const value = CSS.escape(location.value);

        return [
            ...document.querySelectorAll(
                `[data-builder-${location.kind}="${value}"]`,
            ),
        ];
    };

    document.addEventListener(
        'mousemove',
        (event) => {
            if (editing) {
                place(hoverBox, located(event.target));
            }
        },
        true,
    );

    document.addEventListener(
        'click',
        (event) => {
            if (!editing) {
                return;
            }

            const element = located(event.target);
            event.preventDefault();
            event.stopPropagation();

            if (!element) {
                return;
            }

            selected = element;
            place(selectedBox, selected);
            send({ type: 'select', element: describe(element) });
        },
        true,
    );

    const refresh = () => {
        place(selectedBox, selected);
        hoverBox.style.display = 'none';
    };

    window.addEventListener('scroll', refresh, true);
    window.addEventListener('resize', refresh);

    window.addEventListener('message', (event) => {
        if (
            event.origin !== origin ||
            event.source !== window.parent ||
            !event.data ||
            event.data.builder !== true
        ) {
            return;
        }

        const message = event.data;

        if (message.type === 'mode') {
            editing = Boolean(message.editing);
            document.documentElement.style.cursor = editing ? 'crosshair' : '';

            if (!editing) {
                hoverBox.style.display = 'none';
            }
        }

        // Show an edit before it is saved: set inline styles on every element
        // from the same place in the source (for example, each item of a list).
        if (message.type === 'style') {
            for (const element of styled) {
                element.setAttribute(
                    'style',
                    element.getAttribute('data-builder-style') || '',
                );
            }

            styled.clear();

            for (const element of matching(message.location)) {
                if (!element.hasAttribute('data-builder-style')) {
                    element.setAttribute(
                        'data-builder-style',
                        element.getAttribute('style') || '',
                    );
                }

                element.setAttribute(
                    'style',
                    element.getAttribute('data-builder-style'),
                );
                Object.assign(element.style, message.styles || {});
                styled.add(element);
            }

            requestAnimationFrame(refresh);
        }

        if (message.type === 'clear') {
            selected = null;
            selectedBox.style.display = 'none';
        }
    });

    send({ type: 'ready', path: location.pathname });
})();
