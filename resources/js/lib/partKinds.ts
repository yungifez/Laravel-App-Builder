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
