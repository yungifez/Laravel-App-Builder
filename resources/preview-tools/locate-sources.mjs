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
// Usage: node locate-sources.mjs [directory ...]  (default: resources/js)

import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join, relative, sep } from 'node:path';

const ATTRIBUTE = 'data-builder-source';
const INSTANCE_ATTRIBUTE = 'data-builder-instance';
const ELEMENT = 1;
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

    walk(descriptor.template.ast.children, (node) => {
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

        insertions.push({
            at: offset + 1 + node.tag.length,
            text: ` ${attribute}="${name}:${line}:${column}"`,
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

function walk(nodes, visit) {
    for (const node of nodes ?? []) {
        visit(node);
        walk(node.children, visit);

        for (const branch of node.branches ?? []) {
            walk(branch.children, visit);
        }
    }
}
