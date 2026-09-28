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
