// Point-and-edit overlay, injected by the preview gateway into the pages of an
// editable preview. It talks only to the builder that embeds the preview (its
// origin is set in "data-builder-origin" on this script's tag).
//
// While editing is on, hovering outlines an element and clicking selects it
// instead of following links. The builder receives the element's source
// location, never its contents beyond a short text sample. The builder can
// send inline styles to show an edit before it is saved.
//
// A selected element that can be changed gets handles: resize (right, bottom,
// corner), turn, move, and the space inside it. Dragging one sends the raw
// values it points at; the builder snaps them to the scale (or not, when the
// owner fine tunes) and sends the styles back, so the element and its frame
// follow the snapped value. Saving waits until the handle is let go. In a row,
// a column or a grid, the move handle and the arrow keys put the part in
// another place among the parts beside it instead.
(() => {
    const script = document.currentScript;
    const origin = script && script.dataset.builderOrigin;

    if (!origin || window.parent === window) {
        return;
    }

    // The customer's app can be any colour, so no one colour stands out on
    // every page. The builder's marks take a violet that apps seldom use
    // for their parts, apart from the green and orange of spacing, with a
    // white edge (HALO) that keeps them in view on a violet or dark page.
    const ACCENT = '#7c3aed';
    const HOVER = '#a78bfa';
    const HALO = 'rgba(255, 255, 255, 0.85)';
    const INSIDE = 'rgba(34, 197, 94, 0.22)';
    const OUTSIDE = 'rgba(249, 115, 22, 0.18)';

    let editing = false;
    let selected = null;
    // What the owner last clicked, which may be inside the part chosen.
    let clickedOn = null;
    // Whether a change reaches only uses of the part at this place (the
    // builder's "Only this one"), or everywhere the part is written.
    let reachInstance = true;
    let handlesOn = false;
    let drag = null;
    let hint = null;
    const styled = new Set();

    const style = document.createElement('style');
    style.textContent = `
[data-builder-overlay]{position:fixed;inset:0;pointer-events:none;z-index:2147483647;--bz:1}
[data-builder-overlay] *{box-sizing:border-box}
[data-builder-overlay] [data-part=hover]{position:fixed;display:none;border:calc(1.5px*var(--bz)) solid ${HOVER};border-radius:2px;box-shadow:0 0 0 calc(1px*var(--bz)) ${HALO}}
[data-builder-overlay] [data-part=twin]{position:fixed;border:calc(1px*var(--bz)) dashed ${ACCENT};border-radius:2px;opacity:.6}
[data-builder-overlay] [data-part=frame]{position:fixed;display:none;outline:calc(2px*var(--bz)) solid ${ACCENT};box-shadow:inset 0 0 0 calc(1px*var(--bz)) ${HALO},0 0 0 calc(3px*var(--bz)) ${HALO};transform-origin:50% 50%}
[data-builder-overlay] [data-part=inside]{position:absolute;border-style:solid;border-color:${INSIDE}}
[data-builder-overlay] [data-part=outside]{position:absolute;border-style:solid;border-color:${OUTSIDE}}
[data-builder-overlay] [data-part=inside],[data-builder-overlay] [data-part=outside]{opacity:0;transition:opacity .12s}
[data-builder-overlay][data-spacing] [data-part=inside],[data-builder-overlay][data-spacing] [data-part=outside],[data-builder-overlay][data-spacing-drag] [data-part=inside],[data-builder-overlay][data-spacing-drag] [data-part=outside],[data-builder-overlay]:has([data-handle^=pad]:hover) [data-part=inside],[data-builder-overlay]:has([data-handle^=pad]:hover) [data-part=outside]{opacity:1}
[data-builder-overlay] [data-handle]{position:absolute;pointer-events:auto;touch-action:none;width:calc(10px*var(--bz));height:calc(10px*var(--bz));transform:translate(-50%,-50%);background:#fff;border:calc(1.5px*var(--bz)) solid ${ACCENT};border-radius:2px}
[data-builder-overlay] [data-handle]::after{content:"";position:absolute;inset:calc(-6px*var(--bz))}
[data-builder-overlay] [data-handle=e]::after{inset:calc(-6px*var(--bz)) calc(-10px*var(--bz)) calc(-6px*var(--bz)) 0}
[data-builder-overlay] [data-handle=s]::after{inset:0 calc(-6px*var(--bz)) calc(-10px*var(--bz))}
[data-builder-overlay] [data-handle=se]::after{inset:0 calc(-10px*var(--bz)) calc(-10px*var(--bz)) 0}
[data-builder-overlay] [data-handle=e]{left:100%;top:50%;cursor:ew-resize}
[data-builder-overlay] [data-handle=s]{left:50%;top:100%;cursor:ns-resize}
[data-builder-overlay] [data-handle=se]{left:100%;top:100%;cursor:nwse-resize}
[data-builder-overlay] [data-handle=rotate]{left:50%;top:calc(-24px*var(--bz));border-radius:50%;cursor:grab}
[data-builder-overlay] [data-handle=move]{left:0;top:calc(-16px*var(--bz));width:calc(18px*var(--bz));height:calc(18px*var(--bz));transform:translate(0,-50%);border-radius:50%;cursor:move;display:grid;place-items:center;color:${ACCENT}}
[data-builder-overlay] [data-handle=move] svg{width:70%;height:70%}
[data-builder-overlay] [data-handle^=pad]{background:#22c55e;border-color:#fff;border-radius:9px}
[data-builder-overlay] [data-handle^=pad]::after{inset:calc(-3px*var(--bz))}
[data-builder-overlay] [data-handle=pad-l],[data-builder-overlay] [data-handle=pad-r]{width:calc(5px*var(--bz));height:calc(16px*var(--bz));cursor:ew-resize}
[data-builder-overlay] [data-handle=pad-t],[data-builder-overlay] [data-handle=pad-b]{width:calc(16px*var(--bz));height:calc(5px*var(--bz));cursor:ns-resize}
[data-builder-overlay] [data-part=stem]{position:absolute;left:50%;top:calc(-24px*var(--bz));height:calc(24px*var(--bz));width:calc(1px*var(--bz));background:${ACCENT};box-shadow:0 0 0 calc(1px*var(--bz)) ${HALO}}
[data-builder-overlay][data-fixed] [data-handle],[data-builder-overlay][data-fixed] [data-part=stem]{display:none}
[data-builder-overlay][data-thin] [data-handle=e],[data-builder-overlay][data-slim] [data-handle=s],[data-builder-overlay][data-slim] [data-handle=pad-l],[data-builder-overlay][data-slim] [data-handle=pad-r]{display:none}
[data-builder-overlay][data-narrow] [data-handle=pad-l],[data-builder-overlay][data-narrow] [data-handle=pad-r],[data-builder-overlay][data-short] [data-handle=pad-t],[data-builder-overlay][data-short] [data-handle=pad-b]{display:none}
[data-builder-overlay] [data-part=chip]{position:fixed;display:none;transform:translateX(-50%);background:${ACCENT};color:#fff;font:500 calc(11px*var(--bz))/1.2 system-ui,sans-serif;padding:calc(3px*var(--bz)) calc(6px*var(--bz));border-radius:calc(4px*var(--bz));white-space:nowrap;font-variant-numeric:tabular-nums;box-shadow:0 0 0 calc(1px*var(--bz)) ${HALO}}
html[data-builder-dragging],html[data-builder-dragging] *{user-select:none!important;cursor:var(--builder-cursor)!important}
[data-builder-overlay] [data-part=drop]{position:fixed;display:none;background:${ACCENT};border-radius:2px;box-shadow:0 0 0 calc(2px*var(--bz)) #fff}
[data-builder-overlay] [data-part=ghost]{position:fixed;display:none;border:calc(1.5px*var(--bz)) dashed ${ACCENT};border-radius:2px;background:rgba(124,58,237,.08)}
[data-builder-overlay][data-writing] [data-handle],[data-builder-overlay][data-writing] [data-part=stem],[data-builder-overlay][data-writing] [data-part=chip]{display:none}
[contenteditable][data-builder-writing]{outline:none;cursor:text;caret-color:${ACCENT}}
[data-builder-overlay][data-reordering] [data-part=frame]{outline-style:dashed;opacity:.5}
[data-builder-overlay][data-reordering] [data-handle],[data-builder-overlay][data-reordering] [data-part=stem],[data-builder-overlay][data-reordering] [data-part=outside]{display:none}
`;
    (document.head || document.documentElement).appendChild(style);

    // A rebuilt page loads behind the one on show and takes its place once
    // drawn. Its own entrance effects (fading or sliding in) would then play
    // in front of the owner after every change, so the page draws settled
    // and its effects come back once it is on show.
    const settle = document.createElement('style');
    settle.textContent =
        '*,*::before,*::after{transition:none!important;animation-duration:0s!important;animation-delay:0s!important}';
    (document.head || document.documentElement).appendChild(settle);
    const unsettle = () => {
        if (settle.isConnected) {
            setTimeout(() => settle.remove(), 300);
        }
    };
    // A page the builder never sets up (it swaps a page in after 15 seconds
    // at most) gets its effects back all the same.
    setTimeout(unsettle, 15000);

    const part = (name, parent) => {
        const element = document.createElement('div');
        element.setAttribute('data-part', name);
        parent.appendChild(element);

        return element;
    };

    const layer = document.createElement('div');
    layer.setAttribute('data-builder-overlay', '');
    layer.setAttribute('data-fixed', '');
    document.documentElement.appendChild(layer);

    const hoverBox = part('hover', layer);
    const twins = part('twins', layer);
    const outside = part('outside', layer);
    const frame = part('frame', layer);
    const inside = part('inside', frame);
    part('stem', frame);
    const chip = part('chip', layer);
    const drop = part('drop', layer);
    const ghost = part('ghost', layer);

    const handles = {};

    for (const name of [
        'e',
        's',
        'se',
        'rotate',
        'move',
        'pad-l',
        'pad-r',
        'pad-t',
        'pad-b',
    ]) {
        const handle = document.createElement('div');
        handle.setAttribute('data-handle', name);
        frame.appendChild(handle);
        handles[name] = handle;
    }

    handles.move.innerHTML =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M2 12h20M9 5l3-3 3 3M9 19l3 3 3-3M5 9l-3 3 3 3M19 9l3 3-3 3"/></svg>';
    handles.e.title = 'Drag to change the width';
    handles.s.title = 'Drag to change the height';
    handles.se.title = 'Drag to change the size';
    handles.rotate.title = 'Drag to turn it';
    handles.move.title = 'Drag to move it';
    handles.move.addEventListener('pointerenter', () => {
        handles.move.title =
            selected && keepsPlace(selected)
                ? 'Drag to put it in another place'
                : 'Drag to move it';
    });

    for (const side of ['l', 'r', 't', 'b']) {
        handles[`pad-${side}`].title = 'Drag to change the space inside';
    }

    const number = (value) => parseFloat(value) || 0;

    // An angle in degrees from a CSS angle such as "45deg" or "0.25turn".
    const degrees = (value) => {
        const match = /(-?[\d.]+)(deg|rad|turn|grad)?\s*$/.exec(value || '');

        if (!match) {
            return 0;
        }

        const amount = parseFloat(match[1]);

        return (
            {
                rad: (amount * 180) / Math.PI,
                turn: amount * 360,
                grad: amount * 0.9,
            }[match[2]] ?? amount
        );
    };

    // How an element sits on the page: its size without transforms, where
    // its middle is, how far it is turned and moved, and its space.
    const measure = (element) => {
        const computed = getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        const width =
            element instanceof HTMLElement ? element.offsetWidth : rect.width;
        const height =
            element instanceof HTMLElement ? element.offsetHeight : rect.height;
        const rotate =
            computed.rotate && computed.rotate !== 'none'
                ? degrees(computed.rotate)
                : 0;
        const matrix = /^matrix\(([^,]+),\s*([^,]+)/.exec(
            computed.transform || '',
        );
        const [x = '0', y = '0'] =
            computed.translate && computed.translate !== 'none'
                ? computed.translate.split(/\s+/)
                : [];
        const along = (value, size) =>
            value.endsWith('%') ? (number(value) / 100) * size : number(value);

        return {
            width,
            height,
            cx: rect.left + rect.width / 2,
            cy: rect.top + rect.height / 2,
            bottom: rect.bottom,
            rotate,
            angle:
                rotate +
                (matrix
                    ? (Math.atan2(number(matrix[2]), number(matrix[1])) * 180) /
                      Math.PI
                    : 0),
            tx: along(x, width),
            ty: along(y, height),
            padding: ['Top', 'Right', 'Bottom', 'Left'].map((side) =>
                number(computed[`padding${side}`]),
            ),
            border: ['Top', 'Right', 'Bottom', 'Left'].map((side) =>
                number(computed[`border${side}Width`]),
            ),
            margin: ['Top', 'Right', 'Bottom', 'Left'].map((side) =>
                Math.max(0, number(computed[`margin${side}`])),
            ),
        };
    };

    const placeHover = (element) => {
        if (!element || !element.isConnected || element === selected) {
            hoverBox.style.display = 'none';

            return;
        }

        const rect = element.getBoundingClientRect();
        Object.assign(hoverBox.style, {
            display: 'block',
            top: `${rect.top}px`,
            left: `${rect.left}px`,
            width: `${rect.width}px`,
            height: `${rect.height}px`,
        });
    };

    // Draw the selected element's frame, turned as the element is, with its
    // space inside (green) and outside (orange).
    const placeFrame = () => {
        placeTwins();

        // A hidden part has nowhere to draw the frame.
        if (
            !selected ||
            !selected.isConnected ||
            selected.getClientRects().length === 0
        ) {
            frame.style.display =
                outside.style.display =
                chip.style.display =
                    'none';

            return;
        }

        tellNeighbours();

        const m = measure(selected);
        const [pt, pr, pb, pl] = m.padding;
        const [bt, br, bb, bl] = m.border;
        const [mt, mr, mb, ml] = m.margin;

        Object.assign(frame.style, {
            display: 'block',
            left: `${m.cx - m.width / 2}px`,
            top: `${m.cy - m.height / 2}px`,
            width: `${m.width}px`,
            height: `${m.height}px`,
            transform: `rotate(${m.angle}deg)`,
        });
        Object.assign(inside.style, {
            top: `${bt}px`,
            right: `${br}px`,
            bottom: `${bb}px`,
            left: `${bl}px`,
            borderWidth: `${pt}px ${pr}px ${pb}px ${pl}px`,
        });
        Object.assign(outside.style, {
            display: 'block',
            left: `${m.cx - m.width / 2 - ml}px`,
            top: `${m.cy - m.height / 2 - mt}px`,
            width: `${m.width + ml + mr}px`,
            height: `${m.height + mt + mb}px`,
            borderWidth: `${mt}px ${mr}px ${mb}px ${ml}px`,
            transform: `rotate(${m.angle}deg)`,
        });

        handles['pad-l'].style.left = `${bl + pl}px`;
        handles['pad-l'].style.top = '50%';
        handles['pad-r'].style.left = `${m.width - br - pr}px`;
        handles['pad-r'].style.top = '50%';
        handles['pad-t'].style.top = `${bt + pt}px`;
        handles['pad-t'].style.left = '50%';
        handles['pad-b'].style.top = `${m.height - bb - pb}px`;
        handles['pad-b'].style.left = '50%';

        // Space handles would cover a small part's contents, and the part
        // itself must stay free to grab and drag to a new place.
        layer.toggleAttribute('data-narrow', m.width < 120 * zoom());
        layer.toggleAttribute('data-short', m.height < 64 * zoom());
        // On a line of words or a small link, the side and space handles
        // would sit on the words; the corner handle still changes the size,
        // and the panel still sets the space.
        layer.toggleAttribute('data-thin', m.width < 48 * zoom());
        layer.toggleAttribute('data-slim', m.height < 32 * zoom());

        chip.textContent =
            hint ?? `${Math.round(m.width)} × ${Math.round(m.height)}`;
        Object.assign(chip.style, {
            display: 'block',
            left: `${m.cx}px`,
            top: `${m.bottom + 8 * zoom()}px`,
        });
    };

    const zoom = () => number(layer.style.getPropertyValue('--bz')) || 1;

    const located = (node) =>
        node instanceof Element && !node.closest('[data-builder-overlay]')
            ? node.closest('[data-builder-source],[data-builder-instance]')
            : null;

    const send = (message) =>
        window.parent.postMessage({ builder: true, ...message }, origin);

    // The words a part shows, when they are all it holds: those can be
    // typed over in place.
    const plainWords = (element) => {
        const nodes = [...element.childNodes].filter(
            (node) => node.nodeType !== Node.COMMENT_NODE,
        );
        const words = element.textContent.replace(/\s+/g, ' ').trim();

        return nodes.length > 0 &&
            nodes.every((node) => node.nodeType === Node.TEXT_NODE) &&
            words !== ''
            ? words
            : null;
    };

    // The parts a change to this one also changes: each item of the same
    // list, or each use of the same piece on the page.
    const twinsOf = (element) => {
        const kind =
            reachInstance && element.getAttribute('data-builder-instance')
                ? 'instance'
                : 'source';

        return matching({
            kind,
            value: element.getAttribute(`data-builder-${kind}`),
        }).filter((twin) => twin !== element);
    };

    // Outline the parts a change here also changes, so the owner sees its
    // reach before making it.
    const placeTwins = () => {
        const shown =
            selected && selected.isConnected
                ? twinsOf(selected)
                      .filter((twin) => twin.getClientRects().length > 0)
                      .slice(0, 60)
                : [];

        while (twins.children.length > shown.length) {
            twins.lastChild.remove();
        }

        shown.forEach((twin, index) => {
            const box = twins.children[index] ?? part('twin', twins);
            const rect = twin.getBoundingClientRect();

            Object.assign(box.style, {
                left: `${rect.left}px`,
                top: `${rect.top}px`,
                width: `${rect.width}px`,
                height: `${rect.height}px`,
            });
        });
    };

    // Whether the owner clicked something inside the part that the app's
    // own templates do not draw: a chart's canvas, a map, a library's
    // insides or HTML the app fills in. It shows here but is drawn by code,
    // so the owner can change the part around it, not what it draws.
    const drawnByCode = (element) => {
        const at = clickedOn;

        if (
            !(at instanceof Element) ||
            at === element ||
            !element.contains(at) ||
            located(at) !== element
        ) {
            return null;
        }

        const svg = at.closest('svg');

        // The insides of an icon or drawing written in the app are its own.
        if (
            svg &&
            (svg.hasAttribute('data-builder-source') ||
                svg.hasAttribute('data-builder-instance'))
        ) {
            return null;
        }

        return (svg ?? at).tagName.toLowerCase();
    };

    const describe = (element) => ({
        source: element.getAttribute('data-builder-source'),
        instance: element.getAttribute('data-builder-instance'),
        // How many are drawn on the page from the same place, this one
        // included, by where the part is written and where it is used.
        copies: {
            source: matching({
                kind: 'source',
                value: element.getAttribute('data-builder-source'),
            }).length,
            instance: matching({
                kind: 'instance',
                value: element.getAttribute('data-builder-instance'),
            }).length,
        },
        loop: element.hasAttribute('data-builder-loop'),
        when: element.getAttribute('data-builder-when'),
        drawnBy: drawnByCode(element),
        // The files the page is drawn from: those the part sits in, nearest
        // first, then the rest. Words shown through "{{ }}" are often
        // written in one of them.
        places: (() => {
            const files = [];
            const add = (stamp) => {
                const file = stamp?.replace(/:\d+:\d+$/, '');

                if (file && !files.includes(file)) {
                    files.push(file);
                }
            };

            for (
                let around = element;
                around;
                around = located(around.parentElement)
            ) {
                add(around.getAttribute('data-builder-source'));
                add(around.getAttribute('data-builder-instance'));
            }

            for (const other of document.querySelectorAll(
                '[data-builder-source],[data-builder-instance]',
            )) {
                add(other.getAttribute('data-builder-source'));
                add(other.getAttribute('data-builder-instance'));
            }

            return files.slice(0, 40);
        })(),
        tag: element.tagName.toLowerCase(),
        // What it holds, so the builder offers only the choices that do
        // something: arranging needs parts inside, text needs words.
        holds: {
            // A drawing's shapes are not parts to arrange.
            parts:
                element instanceof SVGElement
                    ? 0
                    : [...element.children].filter(
                          (child) => !['BR', 'WBR'].includes(child.tagName),
                      ).length,
            words: /\S/.test(element.textContent ?? ''),
        },
        // Whether it moves from place to place in a row or a grid, rather
        // than off its place by some pixels.
        snaps: snaps(element),
        text: (element.innerText || element.getAttribute('aria-label') || '')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 80),
        width: Math.round(element.getBoundingClientRect().width),
        height: Math.round(element.getBoundingClientRect().height),
        words: plainWords(element),
        // Where the link goes as the app draws it, even when the app works
        // the address out, so the owner can go there.
        href: element.closest('a[href]')?.href ?? null,
        // What kind of part it is, to name one that shows no words.
        kind: kindOf(element),
        // Its own words, as the parts list names it: none for a part that
        // only holds other parts.
        name: wordsOf(element),
        // The picture it shows, so the owner sees it beside the choice.
        src: element instanceof HTMLImageElement ? element.currentSrc : null,
        // The parts it sits in, nearest first, so the owner sees where it
        // is and can pick one.
        trail: (() => {
            const trail = [];

            for (
                let around = located(element.parentElement);
                around && trail.length < 6;
                around = located(around.parentElement)
            ) {
                trail.push({ kind: kindOf(around), words: wordsOf(around) });
            }

            return trail;
        })(),
        // The colours the part is drawn in now, so a colour of its own
        // can be shown to the owner as it is.
        colors: (() => {
            const computed = getComputedStyle(element);
            // A drawing without insides or lines paints them "none".
            const paint = (value) =>
                value === 'none' ? 'rgba(0, 0, 0, 0)' : value;

            return {
                text_color: computed.color,
                background: computed.backgroundColor,
                border_color: computed.borderTopColor,
                fill_color: paint(computed.fill),
                stroke_color: paint(computed.stroke),
            };
        })(),
        // The sizes the part is drawn at now, so a slider with nothing
        // chosen starts where the part is.
        drawn: (() => {
            const computed = getComputedStyle(element);

            return {
                text_size: parseFloat(computed.fontSize),
                // Lines as a share of the words' size, or null for the
                // font's own spacing.
                line_height:
                    computed.lineHeight === 'normal'
                        ? null
                        : parseFloat(computed.lineHeight) /
                          parseFloat(computed.fontSize),
                letter_spacing:
                    computed.letterSpacing === 'normal'
                        ? 0
                        : parseFloat(computed.letterSpacing) /
                          parseFloat(computed.fontSize),
                font_style: computed.fontStyle,
                text_decoration: computed.textDecorationLine,
                object_fit: computed.objectFit,
                max_width: computed.maxWidth,
                rem: parseFloat(
                    getComputedStyle(document.documentElement).fontSize,
                ),
            };
        })(),
    });

    // The parts on show, in page order, as the builder lists them. The
    // list is kept, so the builder can name a part by its place in it.
    let outlined = [];
    const KINDS = {
        a: 'Link',
        button: 'Button',
        h1: 'Heading',
        h2: 'Heading',
        h3: 'Heading',
        h4: 'Heading',
        h5: 'Heading',
        h6: 'Heading',
        p: 'Text',
        ul: 'List',
        ol: 'List',
        li: 'Item',
        img: 'Picture',
        svg: 'Picture',
        picture: 'Picture',
        video: 'Video',
        nav: 'Menu',
        header: 'Top of the page',
        footer: 'Bottom of the page',
        main: 'Main area',
        form: 'Form',
        input: 'Field',
        textarea: 'Field',
        select: 'Field',
        label: 'Label',
        table: 'Table',
    };
    const WORDY = [
        'a',
        'button',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'p',
        'li',
        'label',
    ];
    // A small part with no words is a shape.
    const shapeLike = (element) => {
        const rect = element.getBoundingClientRect();

        return (
            rect.width <= 24 &&
            rect.height <= 24 &&
            element.innerText.trim() === ''
        );
    };

    // What kind of part it is, in plain words.
    const kindOf = (element) =>
        KINDS[element.tagName.toLowerCase()] ??
        (plainWords(element) !== null
            ? 'Text'
            : shapeLike(element) || element.children.length === 0
              ? 'Shape'
              : boxKind(element));

    // A box is named by how it lays out its parts, which owners can see:
    // side by side, one under another, or in a grid.
    const boxKind = (element) => {
        const style = getComputedStyle(element);

        if (style.display.endsWith('grid')) {
            return 'Grid';
        }

        if (style.display.endsWith('flex') && element.children.length > 1) {
            return style.flexDirection.startsWith('column') ? 'Column' : 'Row';
        }

        return 'Box';
    };

    // Words tell parts apart. A box shows none: its words are its parts'.
    const wordsOf = (element) =>
        (
            plainWords(element) ??
            element.getAttribute('aria-label') ??
            (WORDY.includes(element.tagName.toLowerCase())
                ? element.innerText
                : '')
        )
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 60);

    const outline = () => {
        outlined = [
            ...document.querySelectorAll(
                '[data-builder-source],[data-builder-instance]',
            ),
        ].filter((element) => {
            const rect = element.getBoundingClientRect();

            return (
                rect.width > 0 &&
                rect.height > 0 &&
                !element.closest('[data-builder-overlay]') &&
                !element.parentElement?.closest('svg')
            );
        });

        // What a shape is made of is not listed.
        outlined = outlined.filter((element) => {
            for (
                let around = located(element.parentElement);
                around;
                around = located(around.parentElement)
            ) {
                if (shapeLike(around)) {
                    return false;
                }
            }

            return true;
        });

        // A box that only wraps one part the same size looks just like it,
        // so the list leaves it out. A link or button stays: it is what the
        // owner clicks.
        const inside = new Map();

        for (const element of outlined) {
            const around = located(element.parentElement);
            inside.set(around, [...(inside.get(around) ?? []), element]);
        }

        const same = (a, b) =>
            ['top', 'left', 'width', 'height'].every(
                (side) =>
                    Math.abs(
                        a.getBoundingClientRect()[side] -
                            b.getBoundingClientRect()[side],
                    ) < 1,
            );
        outlined = outlined.filter((element) => {
            const parts = inside.get(element) ?? [];

            return !(
                parts.length === 1 &&
                !['a', 'button'].includes(element.tagName.toLowerCase()) &&
                same(element, parts[0])
            );
        });
        const listed = new Set(outlined);

        return outlined.slice(0, 300).map((element) => {
            let depth = 0;

            for (
                let around = located(element.parentElement);
                around;
                around = located(around.parentElement)
            ) {
                depth += listed.has(around) ? 1 : 0;
            }

            return {
                depth,
                kind: kindOf(element),
                words: wordsOf(element),
                selected: element === selected,
            };
        });
    };

    const matching = (location) => {
        if (!location) {
            return [];
        }

        const value = CSS.escape(location.value);
        // A saved edit knows where the part is written but not whether that
        // is a part or one use of a shared piece; no place is both.
        const kinds =
            location.kind === 'any' ? ['source', 'instance'] : [location.kind];

        return [
            ...document.querySelectorAll(
                kinds
                    .map((kind) => `[data-builder-${kind}="${value}"]`)
                    .join(','),
            ),
        ];
    };

    // Where a part is written, as the builder names it, or null.
    const locate = (element) =>
        element?.dataset?.builderInstance
            ? { kind: 'instance', value: element.dataset.builderInstance }
            : element?.dataset?.builderSource
              ? { kind: 'source', value: element.dataset.builderSource }
              : null;

    // A part about to be taken out, and where it was: after the part
    // before it, or first in the part around it. Both are written before
    // it, so a rebuilt app still finds them there. Null when neither has a
    // place of its own.
    const spot = (element) => {
        const before = element.previousElementSibling;
        const after = before ? locate(before) : null;
        const inside = before ? null : locate(element.parentElement);

        return after || inside
            ? { html: element.outerHTML, after, inside }
            : null;
    };

    // Which way the parts beside the selected one lie, for the panel's
    // move buttons. The page may still be settling when a part is chosen:
    // styles, fonts and pictures arrive and a row can start as a column.
    // So it is told again whenever the frame is placed and the answer
    // changed.
    let toldWays = null;
    const tellNeighbours = () => {
        if (!selected) {
            return;
        }

        const earlier = way(selected, neighbour(selected, -1));
        const later = way(selected, neighbour(selected, 1));
        const now = `${earlier} ${later}`;

        if (now !== toldWays) {
            toldWays = now;
            send({ type: 'neighbours', earlier, later });
        }
    };

    const resized =
        typeof ResizeObserver === 'function'
            ? new ResizeObserver(() => placeFrame())
            : null;

    const choose = (element, tell) => {
        resized?.disconnect();
        selected = element;
        reachInstance = true;
        hoverBox.style.display = 'none';

        if (element) {
            resized?.observe(element);
        }

        placeFrame();

        if (element && tell) {
            send({ type: 'select', element: describe(element) });
        }

        toldWays = null;
        tellNeighbours();
    };

    // What a drag on each handle changes, from where the pointer started.
    // Distances are turned into the element's own directions first, so a
    // turned element still grows along its own edges.
    const dragged = (event) => {
        const { kind, x, y, m } = drag;
        const dx = event.clientX - x;
        const dy = event.clientY - y;
        const turn = (-m.angle * Math.PI) / 180;
        const across = dx * Math.cos(turn) - dy * Math.sin(turn);
        const down = dx * Math.sin(turn) + dy * Math.cos(turn);
        const [pt, pr, pb, pl] = m.padding;
        const size = (value) => Math.max(0, Math.round(value));

        switch (kind) {
            case 'e':
                return { width: size(m.width + across) };
            case 's':
                return { height: size(m.height + down) };
            case 'se':
                return {
                    width: size(m.width + across),
                    height: size(m.height + down),
                };
            case 'rotate': {
                const from = Math.atan2(y - m.cy, x - m.cx);
                const to = Math.atan2(
                    event.clientY - m.cy,
                    event.clientX - m.cx,
                );
                let angle = m.rotate + ((to - from) * 180) / Math.PI;
                angle = ((((angle + 180) % 360) + 360) % 360) - 180;

                return { rotate: Math.round(angle) };
            }
            case 'move':
                return {
                    translate_x: Math.round(m.tx + dx),
                    translate_y: Math.round(m.ty + dy),
                };
            case 'pad-l':
                return { padding_x: size(pl + across) };
            case 'pad-r':
                return { padding_x: size(pr - across) };
            case 'pad-t':
                return { padding_y: size(pt + down) };
            default:
                return { padding_y: size(pb - down) };
        }
    };

    for (const [kind, handle] of Object.entries(handles)) {
        handle.addEventListener('pointerdown', (event) => {
            if (!selected || !handlesOn || event.button !== 0) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            // A part with a place in a row or a grid is dragged from one
            // place to another, as dragging the part itself does.
            if (kind === 'move' && keepsPlace(selected)) {
                pressed = { x: event.clientX, y: event.clientY };

                return;
            }

            handle.setPointerCapture(event.pointerId);
            drag = {
                kind,
                x: event.clientX,
                y: event.clientY,
                m: measure(selected),
                values: null,
            };
            document.documentElement.setAttribute('data-builder-dragging', '');
            layer.toggleAttribute('data-spacing-drag', kind.startsWith('pad'));
            document.documentElement.style.setProperty(
                '--builder-cursor',
                kind === 'rotate'
                    ? 'grabbing'
                    : getComputedStyle(handle).cursor,
            );
            hoverBox.style.display = 'none';
        });

        handle.addEventListener('pointermove', (event) => {
            if (!drag || drag.kind !== kind) {
                return;
            }

            drag.values = dragged(event);
            send({
                type: 'adjust',
                phase: 'move',
                values: drag.values,
                alt: event.altKey,
            });
        });

        const finish = (event) => {
            if (!drag || drag.kind !== kind) {
                return;
            }

            send({
                type: 'adjust',
                phase: 'end',
                values: drag.values ?? {},
                alt: event.altKey,
            });
            drag = null;
            hint = null;
            document.documentElement.removeAttribute('data-builder-dragging');
            layer.removeAttribute('data-spacing-drag');
            requestAnimationFrame(placeFrame);
        };

        handle.addEventListener('pointerup', finish);
        handle.addEventListener('pointercancel', finish);
    }

    document.addEventListener(
        'mousemove',
        (event) => {
            if (editing && !drag && !reorder) {
                placeHover(located(event.target));
            }
        },
        true,
    );

    // Drag the selected part itself to put it before or after a part next
    // to it. Only parts written side by side in the same file can change
    // places; the items of a list drawn from data share one place in the
    // source, so they cannot.
    const place = (element) =>
        element.getAttribute('data-builder-instance') ||
        element.getAttribute('data-builder-source') ||
        '';
    const fileOf = (location) => location.replace(/:\d+:\d+$/, '');

    const siblings = (element) =>
        [...(element.parentElement?.children ?? [])].filter(
            (other) =>
                other !== element &&
                other.matches(
                    '[data-builder-source],[data-builder-instance]',
                ) &&
                place(other) !== place(element) &&
                fileOf(place(other)) === fileOf(place(element)),
        );

    // The part just before (-1) or after (1) an element that it can change
    // places with, skipping any it cannot.
    const neighbour = (element, direction) => {
        const order = [...(element.parentElement?.children ?? [])];
        const index = order.indexOf(element);
        const around = siblings(element);

        return direction < 0
            ? around.filter((other) => order.indexOf(other) < index).at(-1)
            : around.find((other) => order.indexOf(other) > index);
    };

    // Which way another part lies on screen from this one: up, down, left
    // or right. A row, a column and a reversed row all read the same way
    // to the owner as they see them.
    const way = (element, other) => {
        if (!other) {
            return null;
        }

        const from = element.getBoundingClientRect();
        const to = other.getBoundingClientRect();

        // A part wholly above or below reads as up or down, even when it
        // is much wider: a button under a wide heading is below it.
        if (to.bottom <= from.top || to.top >= from.bottom) {
            return to.top < from.top ? 'up' : 'down';
        }

        if (to.right <= from.left || to.left >= from.right) {
            return to.left < from.left ? 'left' : 'right';
        }

        const x = to.left + to.width / 2 - (from.left + from.width / 2);
        const y = to.top + to.height / 2 - (from.top + from.height / 2);

        if (Math.abs(x) > Math.abs(y)) {
            return x < 0 ? 'left' : 'right';
        }

        return y < 0 ? 'up' : 'down';
    };

    // Put the selected part before or after another one straight away,
    // and ask the builder to save it. The rebuilt app replaces the page,
    // so moving the element here only shows the result sooner.
    const moveTo = (target, placement) => {
        const parent = selected.parentElement;
        const from = [...parent.children].indexOf(selected);

        if (placement === 'before') {
            target.before(selected);
        } else {
            target.after(selected);
        }

        // How many places it went among the parts beside it, so undo can
        // put it back at once.
        const by =
            selected.parentElement === parent
                ? [...parent.children].indexOf(selected) - from
                : null;

        send({ type: 'move', to: describe(target), placement, by });
        choose(selected, false);
    };

    // Move the selected part one place earlier or later.
    const shift = (direction) => {
        const target = selected && neighbour(selected, direction);

        if (target) {
            moveTo(target, direction < 0 ? 'before' : 'after');
        }
    };

    // The part after (1) or before (-1) the selected one on the page.
    const following = (direction) => {
        const parts = [
            ...document.querySelectorAll(
                '[data-builder-source],[data-builder-instance]',
            ),
        ].filter((part) => part.getClientRects().length > 0);
        const index = parts.indexOf(selected);

        return parts[index + direction] ?? null;
    };

    // Which way the parent lays out its children: across or down.
    const axisOf = (parent) => {
        const computed = getComputedStyle(parent);

        if (computed.display.includes('flex')) {
            return computed.flexDirection.startsWith('row') ? 'x' : 'y';
        }

        return computed.display.includes('grid') ? 'grid' : 'y';
    };

    // Whether the part sits in a row, a column or a grid beside parts it
    // can change places with. Such a part moves from place to place, never
    // off its place by some pixels, so the layout stays as it was made.
    const snaps = (element) =>
        element.parentElement !== null &&
        /flex|grid/.test(getComputedStyle(element.parentElement).display) &&
        siblings(element).length > 0;

    // Whether the move handle and the arrow keys keep such a part to its
    // places. The owner can turn this off to place it anywhere, shifted
    // from where the layout puts it.
    let moveFreely = false;
    const keepsPlace = (element) => !moveFreely && snaps(element);

    let pressed = null;
    let reorder = null;
    let swallowClick = false;

    const dropAt = (event) => {
        let best = null;

        for (const candidate of reorder.candidates) {
            const rect = candidate.getBoundingClientRect();
            const dx = Math.max(
                rect.left - event.clientX,
                0,
                event.clientX - rect.right,
            );
            const dy = Math.max(
                rect.top - event.clientY,
                0,
                event.clientY - rect.bottom,
            );
            const distance = Math.hypot(dx, dy);

            if (best === null || distance < best.distance) {
                best = { element: candidate, rect, distance };
            }
        }

        if (best === null) {
            return null;
        }

        const { rect } = best;
        const across =
            reorder.axis === 'x' ||
            (reorder.axis === 'grid' &&
                event.clientY >= rect.top &&
                event.clientY <= rect.bottom);
        const before = across
            ? event.clientX < rect.left + rect.width / 2
            : event.clientY < rect.top + rect.height / 2;

        return {
            element: best.element,
            rect,
            across,
            placement: before ? 'before' : 'after',
        };
    };

    const showDrop = (target) => {
        if (!target) {
            drop.style.display = 'none';

            return;
        }

        const { rect, across, placement } = target;
        const thick = 3 * zoom();
        Object.assign(
            drop.style,
            across
                ? {
                      display: 'block',
                      left: `${(placement === 'before' ? rect.left : rect.right) - thick / 2}px`,
                      top: `${rect.top}px`,
                      width: `${thick}px`,
                      height: `${rect.height}px`,
                  }
                : {
                      display: 'block',
                      left: `${rect.left}px`,
                      top: `${(placement === 'before' ? rect.top : rect.bottom) - thick / 2}px`,
                      width: `${rect.width}px`,
                      height: `${thick}px`,
                  },
        );
    };

    const endReorder = () => {
        if (reorder) {
            send({ type: 'holding', on: false });
        }

        reorder = null;
        pressed = null;
        drop.style.display = ghost.style.display = 'none';
        layer.removeAttribute('data-reordering');
        document.documentElement.removeAttribute('data-builder-dragging');
        hint = null;
        placeFrame();
    };

    document.addEventListener(
        'pointerdown',
        (event) => {
            if (
                editing &&
                handlesOn &&
                selected &&
                !writing &&
                event.button === 0 &&
                located(event.target) === selected
            ) {
                pressed = { x: event.clientX, y: event.clientY };
            }
        },
        true,
    );

    document.addEventListener(
        'pointermove',
        (event) => {
            if (
                pressed &&
                !reorder &&
                Math.hypot(
                    event.clientX - pressed.x,
                    event.clientY - pressed.y,
                ) > 5
            ) {
                const candidates = siblings(selected);

                if (candidates.length === 0) {
                    pressed = null;

                    return;
                }

                const rect = selected.getBoundingClientRect();
                send({ type: 'holding', on: true });
                reorder = {
                    candidates,
                    axis: axisOf(selected.parentElement),
                    offsetX: pressed.x - rect.left,
                    offsetY: pressed.y - rect.top,
                    target: null,
                };
                layer.setAttribute('data-reordering', '');
                document.documentElement.setAttribute(
                    'data-builder-dragging',
                    '',
                );
                document.documentElement.style.setProperty(
                    '--builder-cursor',
                    'grabbing',
                );
                hoverBox.style.display = 'none';
                Object.assign(ghost.style, {
                    display: 'block',
                    width: `${rect.width}px`,
                    height: `${rect.height}px`,
                });
            }

            if (reorder) {
                event.preventDefault();
                Object.assign(ghost.style, {
                    left: `${event.clientX - reorder.offsetX}px`,
                    top: `${event.clientY - reorder.offsetY}px`,
                });
                reorder.target = dropAt(event);
                showDrop(reorder.target);
                hint = reorder.target ? 'Drop to move it here' : null;
                placeFrame();
            }
        },
        true,
    );

    document.addEventListener(
        'pointerup',
        () => {
            if (reorder?.target && selected) {
                moveTo(reorder.target.element, reorder.target.placement);
            }

            swallowClick = reorder !== null;
            pressed = null;

            if (reorder) {
                endReorder();
            }
        },
        true,
    );

    // Double-click a part that holds only words to type over them, as in
    // any editor. Enter keeps the new words and Escape puts the old back.
    let writing = null;

    // A new part is written in before it is saved, so its words go with it
    // once the builder knows where it is.
    const startWriting = (element, fresh = false) => {
        if (writing || !editing || plainWords(element) === null) {
            return;
        }

        writing = { element, before: element.textContent, fresh };
        element.setAttribute('contenteditable', 'plaintext-only');
        element.setAttribute('data-builder-writing', '');
        layer.setAttribute('data-writing', '');
        element.focus();
        const range = document.createRange();
        range.selectNodeContents(element);
        const selection = window.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(range);
        element.addEventListener('blur', () => stopWriting(true), {
            once: true,
        });
        // The rebuilt app would take the words half written: it waits.
        send({ type: 'holding', on: true });
    };

    const stopWriting = (keep) => {
        if (!writing) {
            return;
        }

        const { element, before, fresh } = writing;
        writing = null;
        const after = element.textContent.replace(/\s+/g, ' ').trim();
        element.removeAttribute('contenteditable');
        element.removeAttribute('data-builder-writing');
        layer.removeAttribute('data-writing');
        window.getSelection()?.removeAllRanges();

        if (
            !keep ||
            after === '' ||
            after === before.replace(/\s+/g, ' ').trim()
        ) {
            element.textContent = before;
        } else {
            send({
                type: 'words',
                before: before.replace(/\s+/g, ' ').trim(),
                text: after,
                fresh,
            });
        }

        send({ type: 'holding', on: false });
        placeFrame();
    };

    document.addEventListener(
        'dblclick',
        (event) => {
            const element = located(event.target);

            if (editing && element && element === selected) {
                event.preventDefault();
                startWriting(element);
            }
        },
        true,
    );

    // While designing, the app's own fields and buttons do not take focus
    // or submit: a click picks a part, and keys go to the designer.
    document.addEventListener(
        'mousedown',
        (event) => {
            if (writing?.element.contains(event.target)) {
                return;
            }

            if (editing && !event.target.closest?.('[data-builder-overlay]')) {
                event.preventDefault();
                // Keys still come here, to the page, not to a field.
                window.focus();
            }
        },
        true,
    );

    document.addEventListener(
        'submit',
        (event) => {
            if (editing) {
                event.preventDefault();
                event.stopPropagation();
            }
        },
        true,
    );

    // Links and images would start the browser's own drag.
    document.addEventListener(
        'dragstart',
        (event) => {
            if (editing) {
                event.preventDefault();
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

            event.preventDefault();
            event.stopPropagation();

            if (writing?.element.contains(event.target)) {
                return;
            }

            // Ctrl or Cmd and a click follows a link, as in a browser. The
            // builder decides where it opens.
            const link =
                (event.ctrlKey || event.metaKey) &&
                event.target.closest?.('a[href]');

            if (link) {
                send({ type: 'follow', href: link.href });

                return;
            }

            // The page keeps focus where it is, so the words would not
            // lose it: a click elsewhere keeps them.
            stopWriting(true);

            if (swallowClick) {
                swallowClick = false;

                return;
            }

            const element = located(event.target);
            clickedOn = event.target;

            // Clicking the selected part again, as a double-click does,
            // keeps the panel as it is instead of loading the part again.
            if (element && element !== selected) {
                choose(element, true);
            }
        },
        true,
    );

    // A picture file dragged from the owner's computer onto a picture in
    // the app goes in its place: it shows at once, and the builder keeps
    // it. Dropped anywhere else, it does nothing, instead of the browser
    // opening the file in place of the app.
    const carriesFiles = (event) =>
        editing && [...(event.dataTransfer?.types ?? [])].includes('Files');
    const pictureUnder = (event) => {
        const image = event.target.closest?.('img');

        return image && located(image) === image ? image : null;
    };

    document.addEventListener(
        'dragover',
        (event) => {
            if (carriesFiles(event)) {
                event.preventDefault();
                event.dataTransfer.dropEffect = pictureUnder(event)
                    ? 'copy'
                    : 'none';
            }
        },
        true,
    );

    document.addEventListener(
        'drop',
        (event) => {
            if (!carriesFiles(event)) {
                return;
            }

            event.preventDefault();
            const image = pictureUnder(event);
            const picture = event.dataTransfer.files[0];

            if (!image || !picture?.type.startsWith('image/')) {
                return;
            }

            if (image !== selected) {
                choose(image, true);
            }

            image.removeAttribute('srcset');
            image.src = URL.createObjectURL(picture);
            send({ type: 'dropped', picture });
        },
        true,
    );

    // The arrow keys, as steps across and down.
    const arrows = {
        arrowleft: [-1, 0],
        arrowright: [1, 0],
        arrowup: [0, -1],
        arrowdown: [0, 1],
    };

    // Move the selected part towards the neighbour an arrow key points at,
    // so it goes the way the owner pressed, even in a reversed row;
    // otherwise left and up mean earlier.
    const toward = (key) => {
        const [x, y] = arrows[key];
        const pointed = key.slice('arrow'.length);

        if (way(selected, neighbour(selected, -1)) === pointed) {
            shift(-1);
        } else if (way(selected, neighbour(selected, 1)) === pointed) {
            shift(1);
        } else {
            shift(x + y);
        }
    };

    // Keys go to the app while it has focus; the builder handles the ones
    // that belong to editing. Arrow keys move the selected part.
    document.addEventListener(
        'keydown',
        (event) => {
            if (!editing) {
                return;
            }

            if (writing) {
                event.stopPropagation();

                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    stopWriting(true);
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    stopWriting(false);
                }

                return;
            }

            const key = event.key.toLowerCase();

            if (key === 'escape' && reorder) {
                endReorder();
            } else if (key === 'escape') {
                choose(null, false);
                send({ type: 'key', key: 'escape' });
            } else if ((event.metaKey || event.ctrlKey) && key === 'z') {
                event.preventDefault();
                send({ type: 'key', key: event.shiftKey ? 'redo' : 'undo' });
            } else if ((event.metaKey || event.ctrlKey) && key === 'y') {
                event.preventDefault();
                send({ type: 'key', key: 'redo' });
            } else if (
                (event.metaKey || event.ctrlKey) &&
                key === 'd' &&
                selected &&
                handlesOn
            ) {
                // A copy right after it, as in design tools.
                event.preventDefault();
                send({ type: 'key', key: 'duplicate' });
            } else if (
                (event.metaKey || event.ctrlKey) &&
                event.altKey &&
                (event.code === 'KeyC' || event.code === 'KeyV') &&
                selected
            ) {
                // Copy a part's look, or give it the look copied, as in
                // design tools.
                event.preventDefault();
                send({
                    type: 'key',
                    key: event.code === 'KeyC' ? 'copy-look' : 'paste-look',
                });
            } else if (
                (key === 'delete' || key === 'backspace') &&
                selected &&
                handlesOn
            ) {
                event.preventDefault();
                send({ type: 'key', key: 'hide' });
            } else if (key === 'enter' && selected) {
                // Enter goes into the part, Shift+Enter out to the one
                // around it, and Tab to the next part, as in design tools.
                event.preventDefault();
                const element = near(event.shiftKey ? 'parent' : 'child');

                if (element) {
                    choose(element, true);
                }
            } else if (key === 'tab' && selected) {
                event.preventDefault();
                const element = following(event.shiftKey ? -1 : 1);

                if (element) {
                    choose(element, true);
                    element.scrollIntoView({ block: 'nearest' });
                }
            } else if (
                arrows[key] &&
                selected &&
                handlesOn &&
                (event.altKey || keepsPlace(selected))
            ) {
                // Alt and an arrow, or an arrow on a part in a row or a
                // grid, move the part before or after the one next to it.
                event.preventDefault();
                toward(key);
            } else if (arrows[key] && selected && handlesOn) {
                event.preventDefault();
                const [x, y] = arrows[key];
                send({
                    type: 'nudge',
                    steps: x !== 0 ? { translate_x: x } : { translate_y: y },
                    big: event.shiftKey,
                });
            }
        },
        true,
    );

    const refresh = () => {
        placeFrame();
        hoverBox.style.display = 'none';
    };

    let reporting = false;

    window.addEventListener(
        'scroll',
        () => {
            refresh();

            if (!reporting) {
                reporting = true;
                requestAnimationFrame(() => {
                    reporting = false;
                    send({
                        type: 'scrolled',
                        path: location.pathname,
                        x: window.scrollX,
                        y: window.scrollY,
                    });
                });
            }
        },
        true,
    );
    window.addEventListener('resize', refresh);
    // Late styles, pictures and fonts move parts after the page first draws.
    window.addEventListener('load', refresh);
    void document.fonts?.ready.then(refresh);

    // Scroll back to where the owner was before the app reloaded. The page
    // may still be drawing, so keep trying for a moment until it is tall
    // enough.
    const scrollBack = (to) => {
        const started = performance.now();
        const attempt = () => {
            window.scrollTo(to.x, to.y);

            if (
                Math.abs(window.scrollY - to.y) > 1 &&
                performance.now() - started < 2000
            ) {
                requestAnimationFrame(attempt);
            }
        };

        attempt();
    };

    // Run once the page shows elements from a place in the source. The app
    // draws itself after this script runs, so right after a reload they may
    // not be there yet; stop waiting after a few seconds.
    let waiting = null;

    const whenDrawn = (location, then) => {
        waiting?.disconnect();
        waiting = null;

        const found = matching(location);

        if (found.length > 0) {
            then(found);

            return;
        }

        const observer = new MutationObserver(() => {
            const elements = matching(location);

            if (elements.length > 0) {
                observer.disconnect();
                then(elements);
            }
        });

        observer.observe(document.documentElement, {
            childList: true,
            subtree: true,
        });
        waiting = observer;
        setTimeout(() => observer.disconnect(), 5000);
    };

    // The part around the selected one, or the first part inside it.
    const near = (direction) => {
        if (!selected) {
            return null;
        }

        if (direction === 'parent') {
            return located(selected.parentElement);
        }

        return (
            [
                ...selected.querySelectorAll(
                    '[data-builder-source],[data-builder-instance]',
                ),
            ][0] ?? null
        );
    };

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

        // The builder page can start to listen after the app said it was
        // ready (the app opened first); it then asks again.
        if (message.type === 'hello') {
            send({ type: 'ready', path: location.pathname });

            return;
        }

        // Go to another page of the app, as following a link would.
        if (message.type === 'go' && typeof message.href === 'string') {
            const to = new URL(message.href, location.href);

            if (to.origin === location.origin) {
                location.assign(to.href);
            }

            return;
        }

        // Words typed in the builder's panel, or put back by undo and redo,
        // show at once. Only parts that hold nothing but words change.
        if (message.type === 'words') {
            const parts = message.location
                ? matching(message.location)
                : selected
                  ? [selected]
                  : [];

            for (const part of parts) {
                if (plainWords(part) !== null) {
                    part.textContent = String(message.text ?? '');
                }
            }

            placeFrame();
        }

        // A new picture the owner chose, shown at once in the picked part;
        // the rebuilt app shows the kept file.
        if (
            message.type === 'picture' &&
            !message.location &&
            selected instanceof HTMLImageElement &&
            message.picture instanceof Blob
        ) {
            // The builder's own address for the file cannot load here, so
            // the file itself comes over.
            selected.removeAttribute('srcset');
            selected.src = URL.createObjectURL(message.picture);
            selected.addEventListener('load', () => placeFrame(), {
                once: true,
            });
        }

        // A chosen picture not saved in this app yet: the builder names
        // where it is, as the owner may have picked another part since.
        if (
            message.type === 'picture' &&
            message.location &&
            message.picture instanceof Blob
        ) {
            for (const image of matching(message.location)) {
                if (image instanceof HTMLImageElement) {
                    image.removeAttribute('srcset');
                    image.src = URL.createObjectURL(message.picture);
                }
            }
        }

        // A picture undone or redone: the file it showed, as the app serves
        // it, at once.
        if (
            message.type === 'picture' &&
            message.location &&
            typeof message.src === 'string' &&
            /^(\/(?!\/)|https?:\/\/)/.test(message.src)
        ) {
            for (const image of matching(message.location)) {
                if (image instanceof HTMLImageElement) {
                    image.removeAttribute('srcset');
                    image.src = message.src;
                    image.addEventListener('load', () => placeFrame(), {
                        once: true,
                    });
                }
            }
        }

        // Play the selected part's entrance again. A part drawn anew starts
        // from its "starting" look and its animations start over, so it is
        // taken off the page for one style pass and put back.
        if (message.type === 'play' && selected?.isConnected) {
            settle.remove();
            const was = selected.style.display;
            selected.style.display = 'none';
            void selected.offsetWidth;
            selected.style.display = was;
        }

        // The space around the selected part shows only while the owner
        // works with it, so the part itself stays easy to see.
        if (message.type === 'spacing') {
            layer.toggleAttribute('data-spacing', Boolean(message.on));
        }

        if (message.type === 'mode') {
            // The builder sets up a page only once it is on show.
            unsettle();
            stopWriting(false);
            editing = Boolean(message.editing);
            document.documentElement.style.cursor = editing ? 'crosshair' : '';

            // A field the owner was typing in keeps no focus while designing.
            if (editing) {
                document.activeElement?.blur?.();
            }

            if (!editing) {
                hoverBox.style.display = 'none';
                choose(null, false);
            }
        }

        if (message.type === 'zoom') {
            layer.style.setProperty(
                '--bz',
                String(1 / (Number(message.zoom) || 1)),
            );
            placeFrame();
        }

        if (message.type === 'free') {
            moveFreely = Boolean(message.enabled);
        }

        if (message.type === 'handles') {
            handlesOn = Boolean(message.enabled);
            layer.toggleAttribute('data-fixed', !handlesOn);
        }

        if (message.type === 'hint') {
            hint = message.text || null;
            placeFrame();
        }

        if (message.type === 'scroll' && message.to) {
            scrollBack(message.to);
        }

        // Select a part the builder names: the same part after a reload, or
        // the one around or inside the selected part.
        if (message.type === 'shift') {
            shift(message.direction);
        }

        // An arrow the owner pressed with Alt in the builder, outside the
        // app: the same move as pressing it in the app.
        if (
            message.type === 'toward' &&
            selected &&
            handlesOn &&
            Object.hasOwn(arrows, message.key)
        ) {
            toward(message.key);
        }

        // The builder lists the page's parts; the owner points at one to
        // see it in the app, and picks one to change it.
        if (message.type === 'outline') {
            send({ type: 'outline', parts: outline() });
        }

        // The part the selected one sits in, so many steps out.
        const outFromSelected = (steps) => {
            let element = selected;

            for (let step = 0; element && step < steps; step++) {
                element = located(element.parentElement);
            }

            return element;
        };

        if (message.type === 'glance') {
            const element = Number.isInteger(message.up)
                ? outFromSelected(message.up)
                : outlined[message.index];

            placeHover(element?.isConnected ? element : null);
        }

        if (message.type === 'pick' && Number.isInteger(message.up)) {
            const element = outFromSelected(message.up);

            if (element) {
                choose(element, true);
            }
        }

        if (message.type === 'pick' && Number.isInteger(message.index)) {
            const element = outlined[message.index];

            if (element?.isConnected) {
                const rect = element.getBoundingClientRect();

                // A part out of sight comes to the middle, with room for its
                // handles.
                if (rect.top < 0 || rect.bottom > window.innerHeight) {
                    element.scrollIntoView({ block: 'center' });
                }

                choose(element, true);
            }
        }

        if (
            message.type === 'pick' &&
            !Number.isInteger(message.index) &&
            !Number.isInteger(message.up)
        ) {
            if (message.location) {
                whenDrawn(message.location, (elements) =>
                    choose(elements[0], Boolean(message.tell)),
                );
            } else {
                const element = near(message.direction);

                if (element) {
                    choose(element, true);
                }
            }
        }

        if (message.type === 'reach') {
            reachInstance = message.instance === true;
            placeTwins();
        }

        // Show an edit before it is saved: set inline styles on every element
        // from the same place in the source (for example, each item of a list).
        if (message.type === 'style') {
            for (const element of styled) {
                element.setAttribute(
                    'style',
                    element.getAttribute('data-builder-style') || '',
                );
                element.setAttribute(
                    'class',
                    element.getAttribute('data-builder-class') || '',
                );
            }

            styled.clear();

            for (const part of message.parts || []) {
                for (const element of matching(part.location)) {
                    if (!element.hasAttribute('data-builder-style')) {
                        element.setAttribute(
                            'data-builder-style',
                            element.getAttribute('style') || '',
                        );
                        element.setAttribute(
                            'data-builder-class',
                            element.getAttribute('class') || '',
                        );
                    }

                    element.setAttribute(
                        'style',
                        element.getAttribute('data-builder-style'),
                    );
                    // Classes the part has after an undo or redo, so what
                    // they remove goes too.
                    element.setAttribute(
                        'class',
                        part.classes ??
                            element.getAttribute('data-builder-class'),
                    );
                    Object.assign(element.style, part.styles || {});
                    styled.add(element);
                }
            }

            requestAnimationFrame(placeFrame);
        }

        // A copy of the selected part, a new part after it, or the part
        // taken out, shown at once;
        // the rebuilt app replaces the page.
        if (message.type === 'reshape' && selected) {
            if (message.how === 'duplicate') {
                selected.after(selected.cloneNode(true));
            } else if (message.how === 'add' && message.markup) {
                const holder = document.createElement('template');
                holder.innerHTML = message.markup;

                const part = holder.content.firstElementChild;

                if (part) {
                    selected.after(part);
                    placeFrame();
                    startWriting(part, true);
                }
            } else if (message.how === 'remove') {
                const gone = selected;

                choose(null, false);
                send({ type: 'taken', spot: spot(gone) });
                gone.remove();
            }
        }

        // A part the builder takes away at once: a copy undone, or a part
        // removed again. The rebuilt app replaces the page.
        if (message.type === 'take') {
            for (const part of matching(message.location)) {
                if (selected && part.contains(selected)) {
                    choose(null, false);
                }

                send({ type: 'taken', spot: spot(part), edit: message.edit });
                part.remove();
            }

            placeFrame();
        }

        // A part the builder moves back at once among the parts beside it:
        // a move undone. The rebuilt app replaces the page.
        if (message.type === 'budge' && Number.isInteger(message.by)) {
            const [part, ...more] = matching(message.location);
            const siblings = part ? [...part.parentElement.children] : [];
            const to = siblings.indexOf(part) + message.by;

            if (part && more.length === 0 && to >= 0 && to < siblings.length) {
                const other = siblings[to];

                if (message.by < 0) {
                    other.before(part);
                } else {
                    other.after(part);
                }

                if (selected === part) {
                    choose(part, false);
                }

                placeFrame();
            }
        }

        // A part the builder puts back at once where it was: a removal
        // undone, or a copy made again. The rebuilt app replaces the page.
        if (message.type === 'put' && message.spot) {
            const { html, after, inside } = message.spot;
            const [anchor, ...more] = matching(after ?? inside);
            const holder = document.createElement('template');
            holder.innerHTML = html;
            const part = holder.content.firstElementChild;

            if (anchor && more.length === 0 && part) {
                if (after) {
                    anchor.after(part);
                } else {
                    anchor.prepend(part);
                }

                placeFrame();
            }
        }

        if (message.type === 'clear') {
            choose(null, false);
        }

        // The colours of the app's theme, as the app draws them now (light
        // or dark), so the builder shows the owner the real ones. A colour
        // the app does not have is left out.
        if (message.type === 'theme') {
            themeTokens = message.tokens || [];
            sendTheme();
        }

        // A theme colour the owner is choosing shows on every part drawn in
        // it at once, before it is saved; null shows the app's own again.
        if (
            message.type === 'recolor' &&
            /^[A-Za-z0-9_-]+$/.test(message.token)
        ) {
            if (message.value) {
                document.documentElement.style.setProperty(
                    `--${message.token}`,
                    message.value,
                );
            } else {
                document.documentElement.style.removeProperty(
                    `--${message.token}`,
                );
            }

            sendTheme();
        }

        // The owner shows the app's light or dark look, to see and change
        // its colours, whatever the device prefers.
        if (message.type === 'look' && typeof message.dark === 'boolean') {
            showLook(message.dark);
            sendTheme();
        }
    });

    // How the app writes its dark look apart, found in its styles until
    // one is: a `.dark` class, an attribute such as `[data-theme="dark"]`,
    // or the device's own setting, `@media (prefers-color-scheme: dark)`.
    // Each is shown its own way, since apps do not share one.
    const DARK_ATTRIBUTE = /\[(data-[\w-]+)=["']?dark["']?\]/;
    const SCHEME_MEDIA = /prefers-color-scheme\s*:\s*(dark|light)/;
    let darkLook = null;
    let schemeRules = [];
    let shownScheme = null;

    const findDarkLook = () => {
        const found = { classes: false, attribute: null, media: [] };
        const search = (rules) => {
            for (const rule of rules) {
                const selector = rule.selectorText ?? '';

                found.classes ||= selector.includes('.dark');
                found.attribute ??= DARK_ATTRIBUTE.exec(selector)?.[1] ?? null;

                if (rule.media && SCHEME_MEDIA.test(rule.media.mediaText)) {
                    found.media.push(rule);
                }

                if (rule.cssRules) {
                    search(rule.cssRules);
                }
            }
        };

        for (const sheet of document.styleSheets) {
            try {
                search(sheet.cssRules);
            } catch {
                // A stylesheet from another address cannot be read.
            }
        }

        if (found.classes) {
            darkLook = { kind: 'class' };
        } else if (found.attribute) {
            darkLook = { kind: 'attribute', name: found.attribute };
        } else if (found.media.length > 0) {
            // Each rule keeps its own words, since it may ask more of the
            // device than its setting, as a width.
            schemeRules = found.media.map((rule) => ({
                rule,
                text: rule.media.mediaText,
                dark: SCHEME_MEDIA.exec(rule.media.mediaText)[1] === 'dark',
            }));
            darkLook = { kind: 'media' };
        }

        return darkLook;
    };

    const hasDarkLook = () => (darkLook ?? findDarkLook()) !== null;

    const showsDark = () => {
        const root = document.documentElement;

        switch (darkLook?.kind) {
            case 'attribute':
                return root.getAttribute(darkLook.name) === 'dark';
            case 'media':
                return (
                    shownScheme ??
                    matchMedia('(prefers-color-scheme: dark)').matches
                );
            default:
                return root.classList.contains('dark');
        }
    };

    const showLook = (dark) => {
        const root = document.documentElement;
        hasDarkLook();

        switch (darkLook?.kind) {
            case 'attribute':
                root.setAttribute(darkLook.name, dark ? 'dark' : 'light');
                break;
            case 'media':
                // Each rule for the device's setting is turned on or off
                // for the look chosen, whatever the device prefers.
                shownScheme = dark;

                for (const { rule, text, dark: forDark } of schemeRules) {
                    rule.media.mediaText = text.replace(
                        /\(\s*prefers-color-scheme\s*:\s*(dark|light)\s*\)/,
                        forDark === dark
                            ? '(min-width: 0px)'
                            : '(max-width: 0px) and (min-width: 1px)',
                    );
                }
                break;
            default:
                root.classList.toggle('dark', dark);
        }

        root.style.colorScheme = dark ? 'dark' : 'light';
    };

    // The app's colours the builder asked for, by the variable that holds
    // each, as the app draws them now, and whether it shows its dark look,
    // whose colours are written apart.
    let themeTokens = [];

    const sendTheme = () => {
        const probe = document.createElement('span');
        probe.hidden = true;
        document.body.appendChild(probe);
        const colors = {};

        for (const token of themeTokens) {
            probe.style.color = `var(--${token}, rgb(1, 2, 3))`;
            const color = getComputedStyle(probe).color;

            if (color !== 'rgb(1, 2, 3)') {
                colors[token] = color;
            }
        }

        probe.remove();
        send({
            type: 'theme',
            colors,
            looks: hasDarkLook(),
            dark: showsDark(),
        });
    };

    send({ type: 'ready', path: location.pathname });

    // Apps that change page without loading one (Inertia, Livewire) only
    // change the address, so say so, and the builder keeps the owner's page.
    let path = location.pathname;
    const moved = () => {
        if (location.pathname !== path) {
            path = location.pathname;
            send({ type: 'page', path });
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

    // Say when the page has drawn its parts, so the builder can show this
    // page in place of the old one without a blank moment.
    // A browser can hold back animation frames in a page it is not
    // showing, so a short timer says it too.
    let told = false;
    const tell = () => {
        if (!told) {
            told = true;
            send({ type: 'drawn' });
        }
    };
    const drawn = () => {
        requestAnimationFrame(() => requestAnimationFrame(tell));
        setTimeout(tell, 100);
    };
    const parts = '[data-builder-source],[data-builder-instance]';

    if (document.querySelector(parts)) {
        drawn();
    } else {
        const watcher = new MutationObserver(() => {
            if (document.querySelector(parts)) {
                watcher.disconnect();
                drawn();
            }
        });

        watcher.observe(document.documentElement, {
            childList: true,
            subtree: true,
        });
        setTimeout(() => {
            watcher.disconnect();
            drawn();
        }, 5000);
    }
})();
