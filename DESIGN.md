# Design principles

How screens in this app look, and how to tell a good one from a bad one.

## The bar

The app looks like a precise technical product, not a template and not a
terminal. An owner opens a screen and sees a sharp headline, plain facts and
the real product. The structure is visible, like a drawing sheet: rails,
rules and cells.

If a screen could belong to any AI product, it is wrong. Purple gradients,
glowing cards, a centred hero and a grid of icon tiles say "made by a
generator". We build software people trust, so the screens must look written
by a person who cares.

---

## The look: a technical sheet

### Type does the work

We use three faces. Each has one job.

- **Schibsted Grotesk** (`font-display`) for page titles and section
  headlines. It is set at weight 600 with tight tracking by default. Set it
  large, with tight leading. It is the voice of the page.
- **IBM Plex Sans** (`font-sans`) for everything you read or press: body
  copy, buttons, forms and lists.
- **IBM Plex Mono** (`font-mono`) only for real code: file names, commands
  and code snippets. Do not use it for labels, captions or status text. A page
  full of mono reads like a terminal dump.

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
a menu. Keep corners small (`rounded-md` on controls). Shadows are for things
that float above the page, such as menus and dialogs, and stay faint.

Show product screenshots in a bezel: a thin border around a `bg-muted` frame
with a little padding, and the picture inside with its own border.

### Public pages are tiles

Public pages are built like a product page: full-bleed tiles with thin 12px
gutters (`space-y-3 p-3`). Each tile holds one idea. It has a short headline,
one line beside or under it, and the real screen filling the rest, often
running off the bottom edge. Space goes inside a tile, never around it.

- Tiles alternate between a soft tile (`bg-muted/50`) and an inverted one
  (`bg-foreground text-background`), so each idea starts on a clear edge.
- The first tile is the box where a visitor says what they want to make.
  The headline, one line and the box are centred, as visitors expect from
  any app builder. This is the only centred block. Other tile text sits on
  the left edge, like everywhere else.
- Under the box, the real workspace plays one change and runs off the
  bottom edge of the first tile. Build it in markup, not as a picture, so
  it stays sharp and follows the theme. The same change carries through
  the tiles below it.
- Number the tiles after the first with a short label above the headline,
  such as "01 The checks", so the page reads as one sequence.
- Buttons keep the app's small radius (`rounded-md`). Do not use pills.

### Patterns that are overused now

These make a page look like every other AI product. Do not use them:

- grid or dot backgrounds, beams, spotlights, aurora or noise textures
- purple-to-blue gradients, gradient text and coloured glows
- a centred hero with a pill badge above the headline
- frosted-glass headers and panels (`backdrop-blur`)
- bento grids and rows of identical cards with an icon on top
- numbered steps, stat banners and logo marquees
- all-caps section labels and mono captions
- Inter, Geist, Space Grotesk or Instrument Serif, and a serif italic accent
  word in a sans headline
- empty verbs such as "empower", "unlock" and "transform"

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

### Proof is plain

When the app knows something for a fact, say it plainly next to the thing it
is about: "Checked on a phone and a computer", "2 files changed". When the app
could not check something, say so in the same place. Use a check or a cross
icon, not a different font.

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
- No card grid, no gradient, no glow, no all-caps label, no mono captions.
- Nothing from the overused list above.
