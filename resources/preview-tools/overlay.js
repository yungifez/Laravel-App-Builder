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
// follow the snapped value. Saving waits until the handle is let go.
(() => {
    const script = document.currentScript;
    const origin = script && script.dataset.builderOrigin;

    if (!origin || window.parent === window) {
        return;
    }

    const ACCENT = '#2563eb';
    const INSIDE = 'rgba(34, 197, 94, 0.22)';
    const OUTSIDE = 'rgba(249, 115, 22, 0.18)';

    let editing = false;
    let selected = null;
    let handlesOn = false;
    let drag = null;
    let hint = null;
    const styled = new Set();

    const style = document.createElement('style');
    style.textContent = `
[data-builder-overlay]{position:fixed;inset:0;pointer-events:none;z-index:2147483647;--bz:1}
[data-builder-overlay] *{box-sizing:border-box}
[data-builder-overlay] [data-part=hover]{position:fixed;display:none;border:calc(1.5px*var(--bz)) solid #60a5fa;border-radius:2px}
[data-builder-overlay] [data-part=frame]{position:fixed;display:none;outline:calc(2px*var(--bz)) solid ${ACCENT};transform-origin:50% 50%}
[data-builder-overlay] [data-part=inside]{position:absolute;border-style:solid;border-color:${INSIDE}}
[data-builder-overlay] [data-part=outside]{position:absolute;border-style:solid;border-color:${OUTSIDE}}
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
[data-builder-overlay] [data-part=stem]{position:absolute;left:50%;top:calc(-24px*var(--bz));height:calc(24px*var(--bz));width:calc(1px*var(--bz));background:${ACCENT}}
[data-builder-overlay][data-fixed] [data-handle],[data-builder-overlay][data-fixed] [data-part=stem]{display:none}
[data-builder-overlay][data-narrow] [data-handle=pad-l],[data-builder-overlay][data-narrow] [data-handle=pad-r],[data-builder-overlay][data-short] [data-handle=pad-t],[data-builder-overlay][data-short] [data-handle=pad-b]{display:none}
[data-builder-overlay] [data-part=chip]{position:fixed;display:none;transform:translateX(-50%);background:${ACCENT};color:#fff;font:500 calc(11px*var(--bz))/1.2 system-ui,sans-serif;padding:calc(3px*var(--bz)) calc(6px*var(--bz));border-radius:calc(4px*var(--bz));white-space:nowrap;font-variant-numeric:tabular-nums}
html[data-builder-dragging],html[data-builder-dragging] *{user-select:none!important;cursor:var(--builder-cursor)!important}
[data-builder-overlay] [data-part=drop]{position:fixed;display:none;background:${ACCENT};border-radius:2px;box-shadow:0 0 0 calc(2px*var(--bz)) #fff}
[data-builder-overlay] [data-part=ghost]{position:fixed;display:none;border:calc(1.5px*var(--bz)) dashed ${ACCENT};border-radius:2px;background:rgba(37,99,235,.08)}
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

    const describe = (element) => ({
        source: element.getAttribute('data-builder-source'),
        instance: element.getAttribute('data-builder-instance'),
        tag: element.tagName.toLowerCase(),
        text: (element.innerText || element.getAttribute('aria-label') || '')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 80),
        width: Math.round(element.getBoundingClientRect().width),
        height: Math.round(element.getBoundingClientRect().height),
    });

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

    const resized =
        typeof ResizeObserver === 'function'
            ? new ResizeObserver(() => placeFrame())
            : null;

    const choose = (element, tell) => {
        resized?.disconnect();
        selected = element;
        hoverBox.style.display = 'none';

        if (element) {
            resized?.observe(element);
        }

        placeFrame();

        if (element && tell) {
            send({ type: 'select', element: describe(element) });
        }

        if (element) {
            send({
                type: 'neighbours',
                earlier: neighbour(element, -1) !== undefined,
                later: neighbour(element, 1) !== undefined,
            });
        }
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
            handle.setPointerCapture(event.pointerId);
            drag = {
                kind,
                x: event.clientX,
                y: event.clientY,
                m: measure(selected),
                values: null,
            };
            document.documentElement.setAttribute('data-builder-dragging', '');
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

    // Put the selected part before or after another one straight away,
    // and ask the builder to save it. The rebuilt app replaces the page,
    // so moving the element here only shows the result sooner.
    const moveTo = (target, placement) => {
        send({ type: 'move', to: describe(target), placement });

        if (placement === 'before') {
            target.before(selected);
        } else {
            target.after(selected);
        }

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

    // While designing, the app's own fields and buttons do not take focus
    // or submit: a click picks a part, and keys go to the designer.
    document.addEventListener(
        'mousedown',
        (event) => {
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

            if (swallowClick) {
                swallowClick = false;

                return;
            }

            const element = located(event.target);

            if (element) {
                choose(element, true);
            }
        },
        true,
    );

    // Keys go to the app while it has focus; the builder handles the ones
    // that belong to editing. Arrow keys move the selected part.
    document.addEventListener(
        'keydown',
        (event) => {
            if (!editing) {
                return;
            }

            const key = event.key.toLowerCase();
            const arrows = {
                arrowleft: [-1, 0],
                arrowright: [1, 0],
                arrowup: [0, -1],
                arrowdown: [0, 1],
            };

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
            } else if (arrows[key] && event.altKey && selected && handlesOn) {
                // Alt and an arrow move the part before or after the one
                // next to it.
                event.preventDefault();
                const [x, y] = arrows[key];
                shift(x + y);
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

        if (message.type === 'mode') {
            // The builder sets up a page only once it is on show.
            unsettle();
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

        if (message.type === 'pick') {
            if (message.location) {
                whenDrawn(message.location, (elements) =>
                    choose(elements[0], false),
                );
            } else {
                const element = near(message.direction);

                if (element) {
                    choose(element, true);
                }
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

        if (message.type === 'clear') {
            choose(null, false);
        }

        // The colours of the app's theme, as the app draws them now (light
        // or dark), so the builder shows the owner the real ones. A colour
        // the app does not have is left out.
        if (message.type === 'theme') {
            const probe = document.createElement('span');
            probe.hidden = true;
            document.body.appendChild(probe);
            const colors = {};

            for (const token of message.tokens || []) {
                probe.style.color = `var(--color-${token}, var(--${token}, rgb(1, 2, 3)))`;
                const color = getComputedStyle(probe).color;

                if (color !== 'rgb(1, 2, 3)') {
                    colors[token] = color;
                }
            }

            probe.remove();
            send({ type: 'theme', colors });
        }
    });

    send({ type: 'ready', path: location.pathname });

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
