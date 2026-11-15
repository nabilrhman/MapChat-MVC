# Diagrams

The diagrams in `docs/images/` are generated, in the style of Rove's doc
diagrams, in MapChat's colours. Each diagram is data in `diagrams.ts`;
`layout.ts` lays it out and `draw.ts` writes the SVG. Change the data, never
an SVG, then run (Node 24, nothing to install):

```bash
node docs/diagrams/draw.ts
```

`node docs/diagrams/draw.ts --check` fails when a line runs past its card, a
label is taller than its cards, cards sit closer than one gap, an arrow
crosses a card or a label, or an SVG on disk isn't what its data draws.
After a change, open the SVG and read it. Every name on a diagram is checked
against the code, as the README around it is.

## Style

- 960 wide, on MapChat's `surface-container` (`#f2ecf4`), with 32px padding.
- A 168px label column on the left: the section title in Montserrat 700, 17px,
  `primary` (`#512da8`), and a one-sentence summary under it.
- White cards (`surface-container-lowest`), square, 20px padding, 32px apart.
  Inside: a 24px Material icon, filled, the title, then two or three short lines.
  A code name as a title is a monospace chip on `surface-container-high`.
- Arrows run only between neighbouring cards, dashed in `primary`. They and
  the section titles are the only colour.
- Square corners everywhere, as on MapChat's own cards and buttons.
- Montserrat, the site's font, is embedded in the SVG, since an SVG shown with
  `<img>` can't load a web font. Text wraps by the widths in
  `font-metrics.json`; after changing the font, run `measure-fonts.ts`.
- Copy is sentences with full stops, no em dashes, no typed arrows.

Montserrat is under the SIL Open Font License (`LICENSE-montserrat.txt`), and
the icons are Material Icons, as on the site, under the Apache 2.0 license
(`LICENSE-material-icons.txt`).
