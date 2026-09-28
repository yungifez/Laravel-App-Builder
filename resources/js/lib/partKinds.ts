import { definition } from '@/lib/visualProperties';
import type { VisualProperty } from '@/types';

// What kind of part a tag is, in the plain words the parts list uses.
// The app names parts the same way (resources/preview-tools/overlay.js).
const kinds: Record<string, string> = {
    a: 'Link',
    // Inertia's link component draws an <a>.
    link: 'Link',
    button: 'Button',
    h1: 'Heading',
    h2: 'Heading',
    h3: 'Heading',
    h4: 'Heading',
    h5: 'Heading',
    h6: 'Heading',
    p: 'Text',
    span: 'Text',
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

export function kindOfTag(tag: string): string {
    return kinds[tag.toLowerCase()] ?? 'Box';
}

// The parts an owner can add after a picked part, as the markup each starts
// as. The server writes the same markup (app/VisualEditing/NewPart.php); the
// app shows it at once from here.
export const newParts = {
    text: { label: 'Text', markup: '<p>New text</p>' },
    heading: {
        label: 'Heading',
        markup: '<h2 class="text-lg font-semibold">New heading</h2>',
    },
    button: {
        label: 'Button',
        markup: '<button type="button" class="rounded-md border px-4 py-2 text-sm font-medium">Button</button>',
    },
    link: {
        label: 'Link',
        markup: '<a href="/" class="underline underline-offset-4">New link</a>',
    },
} as const;

export type NewPartKind = keyof typeof newParts;

// The parts of the design panel that change how a part looks.
export type LookSection =
    | 'layout'
    | 'size'
    | 'space'
    | 'place'
    | 'text'
    | 'fill';

// Which section each property is in, by the group it belongs to. Colours
// go with what they paint: words with the text, the rest with the fill.
export function sectionOf(property: VisualProperty): LookSection | null {
    if (property === 'text_color' || property === 'hover_text_color') {
        return 'text';
    }

    return (
        (
            {
                Layout: 'layout',
                Size: 'size',
                Space: 'space',
                Place: 'place',
                Text: 'text',
                Edges: 'fill',
                'Fill and edges': 'fill',
                Colours: 'fill',
            } as Record<string, LookSection>
        )[definition(property).group] ?? null
    );
}

// The sections a part of this kind is usually changed with, so the panel
// offers those first and keeps the rest one click away: a picture is sized
// and framed, a button is worded and filled, a box arranges what it holds.
export function sectionsFor(part: {
    tag: string;
    holds?: { parts: number; words: boolean };
    snaps?: boolean;
}): LookSection[] {
    const kind = kindOfTag(part.tag);
    const holdsParts = (part.holds?.parts ?? 0) > 0;
    const holdsWords = part.holds?.words ?? false;

    const sections: LookSection[] =
        kind === 'Picture' || kind === 'Video'
            ? ['size', 'place', 'fill']
            : kind === 'Button'
              ? ['text', 'fill', 'space']
              : kind === 'Field'
                ? ['size', 'text', 'fill', 'space']
                : kind === 'Link' ||
                    kind === 'Heading' ||
                    kind === 'Text' ||
                    kind === 'Label'
                  ? ['text', 'space']
                  : holdsParts
                    ? ['layout', 'space', 'size', 'fill']
                    : holdsWords
                      ? ['text', 'space', 'fill']
                      : ['size', 'space', 'fill'];

    // A part in a row or a grid is often put in another place there.
    return part.snaps && !sections.includes('place')
        ? [...sections, 'place']
        : sections;
}

// The sections' names as the panel heads them.
export const sectionNames: Record<LookSection, string> = {
    layout: 'Layout',
    size: 'Size',
    space: 'Space',
    place: 'Turn and move',
    text: 'Text',
    fill: 'Fill and edges',
};
