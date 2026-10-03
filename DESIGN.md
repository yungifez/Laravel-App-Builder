# Design principles

How screens in this app look, and how to tell a good one from a bad one.

## The bar

The app looks like a record of work, not a template. An owner opens a screen
and reads it the way they read a printed report: headline, facts, proof.

If a screen could belong to any AI product, it is wrong. Purple gradients,
glowing cards, a centred hero and a grid of icon tiles say "made by a
generator". We build software people trust, so the screens must look written
by a person who cares.

---

## The look: ink on paper

### Type does the work

We use three faces. Each has one job.

- **Newsreader** (serif, `font-display`) for page titles and section
  headlines. Set it large, with tight leading. It is the voice of the page.
- **IBM Plex Sans** (`font-sans`) for everything you read or press: body
  copy, buttons, forms and lists.
- **IBM Plex Mono** (`font-mono`) for proof: checks, file names, times,
  counts and before/after values. Mono means "this is a fact the app
  recorded".

Do not add other faces. Do not use Inter, Geist or Instrument Sans.

Hierarchy comes from size and weight. It does not come from colour or boxes.
Do not set labels in all capitals. Use sentence case everywhere.

### Colour means action

The page is paper (`background`) and ink (`foreground`). Grey ink
(`muted-foreground`) is for secondary text.

Blue (`primary`) is only for things you can press or follow: buttons, links
and the focus ring. If it is blue, it does something. Do not use blue for
decoration, headings or icons that do nothing.

Status colours are for status only. Green means it passed. Red means it
failed or needs the owner. Amber means it is waiting.

Never use gradients, gradient text, glows or coloured shadows.

### Rules, not cards

Divide content with hairline rules (`border-t`, `divide-y`). Do not wrap each
item in a card with a border, a radius and a shadow.

A box is allowed when it is a real object: a screenshot, a dialog, an input or
a menu. Keep corners small (`rounded-md` or less). Shadows are for things that
float above the page, such as menus and dialogs, and stay faint.

### Left edge

Align text to the left edge. Put headline, copy and actions on one shared
edge. Centre nothing that people read.

Align every element to two or more edges. If an edge is ragged, build a new
edge. Keep dividers inside the content they divide.

---

## How screens behave

### One question per screen

Each screen answers one question for the owner. Put the answer first and the
detail after it.

### Show. Do not tell

Replace explanation with the thing itself. Show the before and after of a
change. Show the check that ran and what it found. A screenshot of the real
app is better than a sentence about it.

Do not print internal vocabulary. Print what the owner calls the thing.

### Proof is set in mono

When the app knows something for a fact, set it in mono with a small rule
above it. Examples: "Checked on a phone and a computer", "2 files
changed", a time stamp. When the app could not check something, say so in the
same style.

### Emphasis is relative

Emphasis is the difference between an element and its neighbours. One primary
button per screen. Other actions use the outline or ghost style.

### Motion is quiet

Motion shows that something moved or changed. It never decorates. Use the
`ease-settle` curve and keep durations short. Respect
`prefers-reduced-motion`. If a change makes the screen jump or surprises the
owner, change the design.

---

## Words

- Write short, plain sentences. Use the words an owner uses.
- Do not stack three short parallel sentences ("Say it. See it. Ship it.").
  Write one or two plain sentences instead.
- Every claim on a public page must be true of the product today.
- When something fails, say why. If it is our fault, say so.

---

## Checks before you call a screen done

- Look at it at 390, 820 and 1440 pixels wide, in light and dark.
- Nothing scrolls sideways.
- Every touch target is at least 44 pixels on a phone.
- Blue appears only on things that act.
- No card grid, no gradient, no glow, no all-caps label.
