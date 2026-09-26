# Design contract

This is how {{ app }} looks and behaves. Read it before any change to the
interface. When something here and a request disagree, follow the request
for that change only, and keep everything else as this contract says.

## 1. Intent

The look is **{{ name }}**: {{ description }}

{{ feel }}

Every screen helps a person do one thing well. The people using this app are
busy and not technical. They should never need to learn how it works inside.

## 2. Principles

1. **Simplest first.** Show the one thing most people need. Put the rest one
   step away (a menu, a "More" link, a second page).
2. **One main action per screen.** It uses the primary colour. Everything else
   is quieter.
3. **Use what exists.** Do not add a new pattern, component or style when one
   in the app already solves the problem.
4. **Density must earn its place.** Add information only when it helps the
   person's next decision.
5. **Plain words.** Say what happens, in the person's words. Never show
   internal names, IDs, codes or technical terms.
6. **Consistency beats novelty.** The same thing looks and works the same way
   everywhere.

## 3. Tokens

Use these tokens. Never write raw colours, pixel radii or new font sizes.

- **Font:** {{ font }}. Use the `font-sans` family only.
- **Corners:** `--radius` is {{ radius }}. Use `rounded-lg` for cards and
  panels, `rounded-md` for buttons and fields, `rounded-sm` for small tags.
  Use `rounded-full` only for avatars and dots.
- **Colours:** use them as Tailwind colours: `bg-primary`,
  `text-muted-foreground`, `border-border`. Each has a light and a dark value,
  so dark mode works without extra classes.

{{ tokens }}

- **Spacing:** use the Tailwind scale only (`1, 2, 3, 4, 6, 8, 12, 16`). No
  arbitrary values such as `p-[13px]`. Related things are close (`gap-2`);
  separate groups are further apart (`gap-6` or more).
- **Type scale:** `text-sm` for body and controls, `text-xs` for secondary
  details, `text-base` to `text-2xl` for headings. Two weights on a screen at
  most (normal and `font-medium` or `font-semibold`).
- **Shadows:** at most `shadow-sm`, for things that float (menus, dialogs).
  Separate areas with the border colour instead.

## 4. Rules

### Layout

- Pages have one clear heading that says what the page is for.
- Content is at most `max-w-5xl` wide for reading and forms; tables and
  boards may use the full width.
- Mobile first. Every screen works at 360 px wide with no sideways scrolling.
  Side-by-side layouts stack on small screens.
- Use the app's existing layout and navigation. Do not add a second menu.

### Components

- Use the app's existing UI components (buttons, inputs, dialogs, cards,
  tables) before writing new markup.
- **Buttons:** one primary button per area. Other actions use the outline,
  secondary or ghost style. Labels are verbs: "Save booking", not "Submit".
- **Forms:** one column. A visible label above every field. Mark what is
  optional, not what is required. Show errors next to the field, in plain
  words, with how to fix them.
- **Tables:** for comparing many items. On small screens, show each row as a
  short card or list item instead.
- **Dialogs:** only for a short, focused task or a confirmation. Anything
  longer is its own page.

### States

Every screen and component handles all of these:

- **Empty:** say what will be here and give the one action that fills it.
- **Loading:** keep the layout still. Use skeletons or a quiet spinner in the
  button that was pressed.
- **Error:** say what went wrong and what to do next. Keep what the person
  typed.
- **Success:** confirm briefly (a toast or inline message), then get out of
  the way.
- **Disabled:** only when the reason is obvious, or say why next to it.

### Interaction

- Destructive actions (delete, cancel, remove) ask for confirmation, name
  exactly what will be lost, and use the destructive colour.
- Prefer undo to confirmation for small, reversible actions.
- Links go somewhere. Buttons do something.
- Motion is short (150–200 ms) and only shows cause and effect. Respect
  `prefers-reduced-motion`.

### Accessibility

- Text contrast is at least 4.5:1; large text and icons at least 3:1.
- Everything works with a keyboard, in a sensible order, with a visible focus
  ring (`ring` colour).
- Touch targets are at least 44 × 44 px on small screens.
- Icons that act alone have an accessible name. Images have alt text.
- Colour is never the only way to show meaning; add text or an icon.

## 5. Forbidden

{{ avoid }}

- Raw colours (`#3b82f6`, `bg-blue-500`), arbitrary sizes or new radii.
- A new pattern where an existing one works.
- More than one primary button in an area.
- Walls of text, long explanations, or instructions on how the screen works.
- Internal or technical words in the interface: IDs, status codes, field
  names, "null", "error 500".
- Gradients, glows or decoration that carry no meaning.
- Placeholder text used as the only label.
- Fixed widths or heights that break on small screens.

## 6. When unsure

Keep the existing design language. Copy the nearest screen that already works
well, and change as little as possible.
