---
name: anypress-website
description: Build or redesign this website step by step with its own theme - pick a kit, switch on its look, build every page as a draft from the kit's sections, write the words for this business, check, then publish on the owner's word. Use when someone asks for a new site, a redesign or a new page, rather than a small change.
---

# Building this website

Seven steps, in order. Show the owner the result of each before starting the next, unless they said to run straight through. Small changes to a finished site skip the route: read the page, change the part, check that page.

## 1. Brief: one round of questions

Read first: `get-site` and `get-styles`. Then ask, in one message, only what those didn't answer: what the business does, for whom and where; its 3 to 6 main offers; the one thing visitors should do (call, book, buy, join); its logo, colours and photos; the pages it wants; a site it likes the look of. Anything not answered gets a sensible default that you name.

## 2. Design: pick the kit

`list-pattern-categories` lists the kits (categories `anypress/kit-*`, each made for one kind of business, its label names the kit's look) and the shared sections (`anypress/blocks-*`, `anypress/pages`). `list-style-variations` lists each kit's look, the colour palettes and the font sets. Pick the kit that fits the trade, then a palette and fonts only if the owner's brand needs them.

- `preview-pattern` with a kit's home page and `variation: kit-<name>` gives a link the owner can open to see the look before anything changes.
- One accent colour does the work. One mood per site: a dark site is dark everywhere.
- Avoid the colour schemes machine-made sites reach for (near-black with orange or neon, beige with brass) unless they are the brand's own.

## 3. Photos: real ones first

The owner's own photos first, then free photos with a CC0 licence that show the kind of work this business does, never presented as its own staff or premises. Copy each in with `add-image` and give it alt text that says what it shows.

## 4. Pages: build to the kit

Switch on the look first: `apply-style-variation` with `kit-<name>` (then a palette or font set if chosen). The old look is kept as a revision. Build every page as a **draft** (`create-page`), section by section, from the kit's own patterns (`find-patterns` with the kit's category, then `anypress/pages` and `anypress/blocks-*`; `get-pattern` for the markup; `add-page-section` with `pattern`).

- Use only the theme's colour, font-size and spacing slugs (`get-styles`), never hex colours or pixel sizes, so the owner can restyle the site in the Site Editor.
- Section styles (`list-style-variations` blockStyles) go on a group as `className: is-style-<slug>`.
- Interactive blocks (`list-blocks`: carousel, popup, counter, countdown, map...) are used as their example patterns show them (`usedIn`).
- The header carries the main action as its button; every menu item opens a finished page; nothing links to `#`.

## 5. Words: written for this business

Rewrite every heading, button, price, FAQ and the about story for this business. No sample text from a pattern survives. Reviews are real ones only.

## 6. Check

Read each page back (`get-page`): no leftover sample text, no empty sections, every link and button goes somewhere, every image has alt text. Fix and read again.

## 7. Publish only on the owner's word

Share the draft links. When the owner says go, set each page's status to publish (`update-page`), update the menus (`update-menu`) and say in one line what's live.
