// Stamp every HTML element in the app's Vue templates with the place it was
// written, so a preview can say which source line an element came from:
//
//   <div class="p-4">  →  <div data-builder-source="resources/js/pages/Home.vue:12:9" class="p-4">
//
// Runs in a preview workspace only, after `npm ci` and before the build. It
// uses the app's own Vue compiler, from the workspace's node_modules (or the
// control plane's, when the app has none). The
// line and column are those of the element's "<" in the committed file.
//
// A component used in a template gets `data-builder-instance` instead. Vue
// passes it through to the element the component renders, so a selected
// button knows both where the button is defined and where this one is used.
//
// An element drawn once for each item of a list (in or under a `v-for`) also
// gets `data-builder-loop`, and one drawn only at times (`v-if`, `v-else-if`,
// `v-else`, `v-show`, itself or on a `<template>` around it) gets
// `data-builder-when` ("if", "either" or "show"), so the designer can say a change reaches every item,
// or that the part is not always there.
//
// Usage: node locate-sources.mjs [directory ...]  (default: resources/js)

import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join, relative, sep } from 'node:path';

const ATTRIBUTE = 'data-builder-source';
const INSTANCE_ATTRIBUTE = 'data-builder-instance';
const LOOP_ATTRIBUTE = 'data-builder-loop';
const WHEN_ATTRIBUTE = 'data-builder-when';
const ELEMENT = 1;
const DIRECTIVE = 7;
const PLAIN_ELEMENT = 0;
const COMPONENT = 1;

// Built-in and head-only components that render no element of their own.
const SKIPPED_COMPONENTS = new Set([
    'Transition',
    'TransitionGroup',
    'KeepAlive',
    'Teleport',
    'Suspense',
    'component',
    'Head',
]);

const root = process.cwd();
const require = createRequire(join(root, 'package.json'));

let compiler;

try {
    compiler = require('@vue/compiler-sfc');
} catch {
    try {
        // Fall back to the compiler next to this script.
        compiler = createRequire(import.meta.url)('@vue/compiler-sfc');
    } catch {
        console.error('No @vue/compiler-sfc was found; nothing was stamped.');
        process.exit(0);
    }
}

const directories =
    process.argv.slice(2).length > 0 ? process.argv.slice(2) : ['resources/js'];
let files = 0;
let elements = 0;

for (const directory of directories) {
    for (const file of vueFiles(join(root, directory))) {
        const stamped = stamp(file);

        if (stamped > 0) {
            files++;
            elements += stamped;
        }
    }
}

console.log(JSON.stringify({ files, elements }));

function* vueFiles(directory) {
    let entries;

    try {
        entries = readdirSync(directory);
    } catch {
        return;
    }

    for (const entry of entries) {
        if (entry === 'node_modules' || entry.startsWith('.')) {
            continue;
        }

        const path = join(directory, entry);

        if (statSync(path).isDirectory()) {
            yield* vueFiles(path);
        } else if (entry.endsWith('.vue')) {
            yield path;
        }
    }
}

// Insert the attribute after each plain element's tag name, from the end of
// the file backwards so earlier offsets stay valid. A file that already has
// stamps is left alone, so its positions always match the committed file.
function stamp(file) {
    const source = readFileSync(file, 'utf8');

    if (source.includes('data-builder-')) {
        return 0;
    }

    const { descriptor, errors } = compiler.parse(source, { filename: file });

    if (errors.length > 0 || !descriptor.template?.ast) {
        return 0;
    }

    const name = relative(root, file).split(sep).join('/');
    const insertions = [];

    walk(descriptor.template.ast.children, (node, around, next) => {
        if (node.type !== ELEMENT) {
            return;
        }

        const attribute =
            node.tagType === PLAIN_ELEMENT &&
            node.tag !== 'template' &&
            node.tag !== 'slot'
                ? ATTRIBUTE
                : node.tagType === COMPONENT &&
                    !SKIPPED_COMPONENTS.has(node.tag)
                  ? INSTANCE_ATTRIBUTE
                  : null;
        const { offset, line, column } = node.loc.start;

        if (
            attribute === null ||
            source.slice(offset, offset + 1 + node.tag.length) !==
                `<${node.tag}`
        ) {
            return;
        }

        const when = shownAt(node, next) ?? around.when;

        insertions.push({
            at: offset + 1 + node.tag.length,
            text:
                ` ${attribute}="${name}:${line}:${column}"` +
                (around.loop || directive(node, 'for')
                    ? ` ${LOOP_ATTRIBUTE}`
                    : '') +
                (when ? ` ${WHEN_ATTRIBUTE}="${when}"` : ''),
        });
    });

    if (insertions.length === 0) {
        return 0;
    }

    let output = source;

    for (const { at, text } of insertions.sort((a, b) => b.at - a.at)) {
        output = output.slice(0, at) + text + output.slice(at);
    }

    writeFileSync(file, output);

    return insertions.length;
}

// Visit each node with what it sits in: whether a list repeats it, and when
// a <template> around it is shown (a <template> draws no element of its own,
// so its condition belongs to what it holds).
function walk(nodes, visit, around = { loop: false, when: null }) {
    const elements = (nodes ?? []).filter((node) => node.type === ELEMENT);

    for (const node of nodes ?? []) {
        const next = elements[elements.indexOf(node) + 1] ?? null;
        visit(node, around, next);

        const within = {
            loop: around.loop || directive(node, 'for'),
            when:
                node.type === ELEMENT && node.tag === 'template'
                    ? (shownAt(node, next) ?? around.when)
                    : null,
        };

        walk(node.children, visit, within);

        for (const branch of node.branches ?? []) {
            walk(branch.children, visit, within);
        }
    }
}

function directive(node, name) {
    return (node.props ?? []).some(
        (prop) => prop.type === DIRECTIVE && prop.name === name,
    );
}

// "either" for one of several shown in turn (a v-if with a v-else after it,
// a v-else-if or a v-else), "if" for a v-if alone, "show" for v-show (hidden,
// not removed), or null. The next element says whether a v-if has an else.
function shownAt(node, next) {
    if (directive(node, 'else-if') || directive(node, 'else')) {
        return 'either';
    }

    if (directive(node, 'if')) {
        return next && (directive(next, 'else-if') || directive(next, 'else'))
            ? 'either'
            : 'if';
    }

    return directive(node, 'show') ? 'show' : null;
}
