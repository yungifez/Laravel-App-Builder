// Stamp every HTML element in the app's templates with the place it was
// written, so a preview can say which source line an element came from:
//
//   <div class="p-4">  →  <div data-builder-source="resources/js/pages/Home.vue:12:9" class="p-4">
//
// Vue files and Blade views (Livewire's too) are stamped, so the designer
// works whatever the app's screens are made with. Runs in a preview
// workspace only, after `npm ci` and before the build. Vue files are read
// with the app's own Vue compiler, from the workspace's node_modules (or
// the control plane's, when the app has none). Blade views are read tag by
// tag, skipping echoes, comments and PHP, so they need nothing. The line and
// column are those of the element's "<" in the committed file.
//
// A component used in a template gets `data-builder-instance` instead. Vue
// passes it through to the element the component renders, and so does a
// Blade component that prints its {{ $attributes }}, so a selected button
// knows both where the button is defined and where this one is used.
//
// An element drawn once for each item of a list (in or under a `v-for`, a
// Blade @foreach or an Alpine x-for) also gets `data-builder-loop`, and one
// drawn only at times (`v-if`, `v-else-if`, `v-else`, `v-show`, a Blade @if
// or @unless, itself or on a `<template>` around it) gets
// `data-builder-when` ("if", "either" or "show"), so the designer can say a change reaches every item,
// or that the part is not always there.
//
// Usage: node locate-sources.mjs [--stage <dir>] [directory ...]  (default: resources/js resources/views)
//
// With --stage, the files staged under <dir> are stamped instead, each named
// by its place under <dir>, which is its place in the app. They are stamped
// before they are moved into the app, so a build that watches it sees them
// change once.

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

// Blade directives that draw what they hold once for each item.
const BLADE_LOOPS = new Set(['foreach', 'forelse', 'for', 'while']);

// Blade directives that draw what they hold only at times.
const BLADE_WHENS = new Set([
    'if',
    'unless',
    'isset',
    'empty',
    'auth',
    'guest',
    'can',
    'cannot',
    'canany',
    'env',
    'production',
    'switch',
    'error',
    'session',
    'hasSection',
    'sectionMissing',
]);

// Blade directives that hold markup until their @end…, but draw it always
// (or somewhere else), so they say nothing about when it shows.
const BLADE_BLOCKS = new Set([
    'push',
    'prepend',
    'pushOnce',
    'prependOnce',
    'once',
    'fragment',
    'component',
    'persist',
    'teleport',
]);

// Blade directives whose contents are not markup: they are skipped whole.
const BLADE_RAW = new Set(['php', 'verbatim', 'script', 'assets']);

// One branch of a block ends and the next begins.
const BLADE_BRANCHES = new Set([
    'else',
    'elseif',
    'elseauth',
    'elseguest',
    'elsecan',
    'elsecannot',
    'elsecanany',
    'elseenv',
    'case',
    'default',
]);

const root = process.cwd();
const require = createRequire(join(root, 'package.json'));

const options = process.argv.slice(2);
const base = options[0] === '--stage' ? join(root, options[1]) : root;
const named = options[0] === '--stage' ? options.slice(2) : options;
const directories =
    named.length > 0 ? named : ['resources/js', 'resources/views'];
let compiler;
let files = 0;
let elements = 0;

for (const directory of directories) {
    for (const file of templateFiles(join(base, directory))) {
        const stamped = file.endsWith('.vue') ? stamp(file) : stampBlade(file);

        if (stamped > 0) {
            files++;
            elements += stamped;
        }
    }
}

console.log(JSON.stringify({ files, elements }));

// The app's Vue compiler, loaded the first time a Vue file is found, so an
// app with none (Livewire, Blade) needs no Vue at all. Null when there is
// none: its Vue files are left unstamped.
function vueCompiler() {
    if (compiler !== undefined) {
        return compiler;
    }

    try {
        compiler = require('@vue/compiler-sfc');
    } catch {
        try {
            // Fall back to the compiler next to this script.
            compiler = createRequire(import.meta.url)('@vue/compiler-sfc');
        } catch {
            console.error(
                'No @vue/compiler-sfc was found; Vue files were not stamped.',
            );
            compiler = null;
        }
    }

    return compiler;
}

function* templateFiles(directory) {
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
            yield* templateFiles(path);
        } else if (entry.endsWith('.vue') || entry.endsWith('.blade.php')) {
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

    if (vueCompiler() === null) {
        return 0;
    }

    const { descriptor, errors } = compiler.parse(source, { filename: file });

    if (errors.length > 0 || !descriptor.template?.ast) {
        return 0;
    }

    const name = relative(base, file).split(sep).join('/');
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

// Stamp a Blade view. It is read as tokens (elements, directives and end
// tags), skipping comments, echoes, PHP and script contents, where a "<"
// is not an element. Each plain element gets its place, and each Blade
// component (<x-…>) where it is used; a Livewire tag passes what it is
// given to the component's code, not to an element, so it is left alone.
function stampBlade(file) {
    const source = readFileSync(file, 'utf8');

    if (source.includes('data-builder-')) {
        return 0;
    }

    const name = relative(base, file).split(sep).join('/');
    const tokens = bladeTokens(source);
    const insertions = [];
    const blocks = [];
    const templates = [];

    // A block shown in turn with another (an @if with an @else) says
    // "either", so each opener learns first whether a branch follows.
    const branched = new Set();
    const open = [];

    for (const token of tokens) {
        if (token.type === 'open') {
            open.push(token);
        } else if (token.type === 'branch' && open.length > 0) {
            branched.add(open[open.length - 1]);
        } else if (token.type === 'close') {
            open.pop();
        }
    }

    for (const token of tokens) {
        if (token.type === 'open') {
            blocks.push({
                kind: token.kind,
                either: branched.has(token),
                branch: false,
            });

            continue;
        }

        if (token.type === 'branch') {
            const block = blocks[blocks.length - 1];

            // A @forelse's @empty part is drawn instead of the list.
            if (block && block.kind === 'loop') {
                block.kind = 'when';
                block.either = true;
            }

            continue;
        }

        if (token.type === 'close') {
            blocks.pop();

            continue;
        }

        if (token.type === 'end') {
            if (token.tag === 'template') {
                templates.pop();
            }

            continue;
        }

        const { tag, at, head } = token;

        if (tag === 'template') {
            templates.push(
                /\sx-for\s*=/.test(head)
                    ? 'loop'
                    : /\sx-if\s*=/.test(head)
                      ? 'if'
                      : null,
            );

            continue;
        }

        const attribute =
            /^x[-:]slot\b/.test(tag) ||
            /^livewire[:-]?/.test(tag) ||
            /^(slot|script|style)$/i.test(tag)
                ? null
                : /^x[-:]/.test(tag)
                  ? INSTANCE_ATTRIBUTE
                  : ATTRIBUTE;

        if (attribute === null) {
            continue;
        }

        const loop =
            blocks.some((block) => block.kind === 'loop') ||
            templates.includes('loop') ||
            /\sx-for\s*=/.test(head);
        const within = [...blocks]
            .reverse()
            .find((block) => block.kind === 'when');
        const when = /\sx-show\s*=/.test(head)
            ? 'show'
            : within
              ? within.either
                  ? 'either'
                  : 'if'
              : templates.includes('if')
                ? 'if'
                : null;
        const { line, column } = position(source, at);

        insertions.push({
            at: at + 1 + tag.length,
            text:
                ` ${attribute}="${name}:${line}:${column}"` +
                (loop ? ` ${LOOP_ATTRIBUTE}` : '') +
                (when ? ` ${WHEN_ATTRIBUTE}="${when}"` : ''),
        });
    }

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

// Read a Blade view as the tokens the stamp needs: each element's start
// tag ("tag"), end tags ("end"), and the directives that open, branch and
// close blocks.
function bladeTokens(source) {
    const tokens = [];
    let at = 0;

    while (at < source.length) {
        const next = source
            .slice(at)
            .search(/\{\{--|\{!!|\{\{|<!--|<\?php|<\/?[A-Za-z]|@/);

        if (next < 0) {
            break;
        }

        at += next;

        if (source.startsWith('{{--', at)) {
            at = after(source, '--}}', at);
        } else if (source.startsWith('{!!', at)) {
            at = after(source, '!!}', at);
        } else if (source.startsWith('{{', at)) {
            at = after(source, '}}', at);
        } else if (source.startsWith('<!--', at)) {
            at = after(source, '-->', at);
        } else if (source.startsWith('<?php', at)) {
            at = after(source, '?>', at);
        } else if (source.startsWith('</', at)) {
            const end = /^<\/([A-Za-z][\w.:-]*)/.exec(source.slice(at));
            tokens.push({ type: 'end', tag: end[1] });
            at = after(source, '>', at);
        } else if (source[at] === '<') {
            const tag = /^<([A-Za-z][\w.:-]*)/.exec(source.slice(at))[1];
            const end = tagEnd(source, at + 1 + tag.length);
            tokens.push({ type: 'tag', tag, at, head: source.slice(at, end) });
            at = end;

            // What a script or style holds is not markup.
            if (
                /^(script|style)$/i.test(tag) &&
                !source.slice(at - 2, at).startsWith('/')
            ) {
                const close = source
                    .slice(at)
                    .search(new RegExp(`</${tag}\\s*>`, 'i'));
                at = close < 0 ? source.length : at + close;
            }
        } else {
            at = bladeDirective(source, at, tokens);
        }
    }

    return tokens;
}

// Read the directive at "@", add the token it makes, and return where it
// ends. "@@" is an escaped "@", and an "@" inside a word (an email) is not
// a directive.
function bladeDirective(source, at, tokens) {
    if (source[at + 1] === '@' || /\w/.test(source[at - 1] ?? '')) {
        return at + 2;
    }

    const name = /^@([A-Za-z]\w*)/.exec(source.slice(at))?.[1];

    if (!name) {
        return at + 1;
    }

    let end = at + 1 + name.length;
    const opening = /^\s*\(/.exec(source.slice(end));
    let argument = '';

    if (opening) {
        const close = balanced(source, end + opening[0].length - 1);
        argument = source.slice(end + opening[0].length, close - 1);
        end = close;
    }

    if (BLADE_RAW.has(name) && !(name === 'php' && opening)) {
        return after(source, `@end${name}`, end);
    }

    if (BLADE_LOOPS.has(name)) {
        tokens.push({ type: 'open', kind: 'loop' });
    } else if (name === 'empty' && !opening) {
        tokens.push({ type: 'branch' });
    } else if (BLADE_WHENS.has(name)) {
        tokens.push({ type: 'open', kind: 'when' });
    } else if (
        BLADE_BLOCKS.has(name) ||
        (name === 'section' && !/,/.test(argument))
    ) {
        tokens.push({ type: 'open', kind: 'block' });
    } else if (BLADE_BRANCHES.has(name)) {
        tokens.push({ type: 'branch' });
    } else if (
        name.startsWith('end') ||
        ['show', 'stop', 'overwrite', 'append'].includes(name)
    ) {
        tokens.push({ type: 'close' });
    }

    return end;
}

// Find the end of a start tag: just after its ">", stepping over quoted
// values, echoes and directives with arguments, where a ">" may be part of
// PHP ("$user->name").
function tagEnd(source, at) {
    while (at < source.length) {
        const character = source[at];

        if (character === '>') {
            return at + 1;
        }

        if (character === '"' || character === "'") {
            at = after(source, character, at + 1);
        } else if (source.startsWith('{{', at)) {
            at = after(source, '}}', at);
        } else if (source.startsWith('{!!', at)) {
            at = after(source, '!!}', at);
        } else if (character === '@' && /^@\w+\s*\(/.test(source.slice(at))) {
            at = balanced(source, source.indexOf('(', at));
        } else {
            at++;
        }
    }

    return source.length;
}

// Get the place just after the ")" that closes the "(" at "at", stepping
// over quoted strings.
function balanced(source, at) {
    let depth = 0;

    for (let index = at; index < source.length; index++) {
        const character = source[index];

        if (character === '"' || character === "'") {
            let end = index + 1;

            while (end < source.length && source[end] !== character) {
                end += source[end] === '\\' ? 2 : 1;
            }

            index = end;
        } else if (character === '(') {
            depth++;
        } else if (character === ')' && --depth === 0) {
            return index + 1;
        }
    }

    return source.length;
}

// Get the place just after the next "text" from "at", or the end.
function after(source, text, at) {
    const found = source.indexOf(text, at);

    return found < 0 ? source.length : found + text.length;
}

// Get the line and column (both from 1) of an offset.
function position(source, at) {
    const before = source.slice(0, at);
    const lines = before.split('\n');

    return { line: lines.length, column: lines[lines.length - 1].length + 1 };
}
