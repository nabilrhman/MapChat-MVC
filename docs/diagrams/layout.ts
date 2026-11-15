/**
 * Lays out and draws one diagram as an SVG, in the style README.md sets. A
 * diagram is data (diagrams.ts); this file turns it into cards on a grid, with
 * their labels and arrows.
 *
 * Every distance comes from SPACING, and every line of text is measured with
 * font-metrics.json. `diagramProblems` then checks the result, so a diagram
 * whose text overflows, whose label outgrows its cards, or whose arrow crosses
 * a card or a label fails `draw.ts --check` rather than shipping.
 */
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { ICONS } from './icons.ts'

const HERE = dirname(fileURLToPath(import.meta.url))

/** MapChat's Material 3 colours, as public/css/base.css names them. */
const COLOR = {
  backdrop: '#f2ecf4', // surface-container
  surface: '#ffffff', // surface-container-lowest
  textPrimary: '#1c1b20', // on-surface
  textSecondary: '#48454e', // on-surface-variant
  textTertiary: '#79757f', // outline
  accent: '#512da8', // primary
  chip: '#ece6ee', // surface-container-high
  absentBorder: '#cac4cf', // outline-variant
}

/** Every distance on a diagram. */
const SPACING = {
  frame: 32,
  labelWidth: 168,
  labelGutter: 24,
  /** Between cards, across and down, and between sections. */
  cardGap: 32,
  cardPadding: 20,
  iconSize: 24,
  iconToTitle: 16,
  titleToBody: 8,
  /** Between a chip row and the next, and above the first. */
  chipRowGap: 8,
  chipToText: 12,
  chipSidePadding: 5,
  stageToCards: 12,
  sectionTitleToSummary: 4,
  /** Under a title and summary set above the cards. */
  labelAboveToCards: 16,
}

const WIDTH = 960
// Square, like MapChat's cards and buttons
const CARD_RADIUS = 0
const FRAME_RADIUS = 0
const CHIP_HEIGHT = 19
const CHIP_RADIUS = 0
/** Where an arrow meets a card: level with, or under, the middle of its icon. */
const ICON_MIDDLE = SPACING.cardPadding + SPACING.iconSize / 2
/** How far an arrow stops short of the card it leaves or enters. */
const ARROW_CLEARANCE = 3

type TextStyleName = 'body' | 'stage' | 'name' | 'title' | 'chip'
type TextStyle = { fontSize: number; lineHeight: number; css: string }

const TEXT_STYLES: Record<TextStyleName, TextStyle> = {
  title: {
    fontSize: 17,
    lineHeight: 25,
    css: `font: 700 17px Montserrat, system-ui, sans-serif; fill: ${COLOR.accent};`,
  },
  name: {
    fontSize: 14,
    lineHeight: 20,
    css: `font: 700 14px Montserrat, system-ui, sans-serif; fill: ${COLOR.textPrimary};`,
  },
  body: {
    fontSize: 14,
    lineHeight: 20.3,
    css: `font: 400 14px Montserrat, system-ui, sans-serif; fill: ${COLOR.textSecondary};`,
  },
  stage: {
    fontSize: 12,
    lineHeight: 20,
    css: `font: 600 12px Montserrat, system-ui, sans-serif; fill: ${COLOR.textTertiary}; letter-spacing: 0.72px;`,
  },
  chip: {
    fontSize: 12,
    lineHeight: 20,
    css: `font: 700 12px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; fill: ${COLOR.textPrimary};`,
  },
}
const STAGE_LETTER_SPACING = 0.72

type FontMetrics = { ascent: number; descent: number; advances: Record<string, number> }
const FONT_METRICS = JSON.parse(readFileSync(join(HERE, 'font-metrics.json'), 'utf8')) as Record<
  TextStyleName,
  FontMetrics
>

/** A code name, then what it is, on one line of a card. */
export type ChipRow = { code: string; text: string }

export type Card = {
  id: string
  /** The icon's name in icons.ts. */
  icon: keyof typeof ICONS
  /** The card's first column, from 0, and how many it spans. */
  column: number
  columnSpan?: number
  /** The card's first row in its section, from 0, and how many it spans. */
  row?: number
  rowSpan?: number
  /** A title that's a code name (a path, command or event) is drawn as a chip. */
  title: string
  isCodeTitle?: boolean
  body?: string
  chipRows?: ChipRow[]
  /** A place where nothing runs: drawn as an empty, dashed outline. */
  isAbsent?: boolean
}

export type Section = {
  title: string
  summary: string
  columns: number
  /** A small label over each column. */
  stages?: string[]
  /** Title and summary above the cards, so a five-step row gets the full width. */
  isLabelAbove?: boolean
  cards: Card[]
}

export type Arrow = { from: string; to: string }

export type Diagram = { file: string; sections: Section[]; arrows: Arrow[] }

type Box = { left: number; top: number; width: number; height: number }

/** A diagram placed on the page: every card's box, every label's box, and its height. */
type Placed = {
  height: number
  cards: { card: Card; box: Box }[]
  labels: { text: string; style: TextStyleName; box: Box }[]
}

function right(box: Box): number {
  return box.left + box.width
}

function bottom(box: Box): number {
  return box.top + box.height
}

function textWidth(text: string, style: TextStyleName): number {
  const metrics = FONT_METRICS[style]
  // A character the metrics don't list (diagram copy is plain text, so rare) counts as wide as an M.
  const fallbackAdvance = metrics.advances.M ?? 1
  const letterSpacing = style === 'stage' ? STAGE_LETTER_SPACING * text.length : 0
  let ems = 0
  for (const character of text) ems += metrics.advances[character] ?? fallbackAdvance
  return ems * TEXT_STYLES[style].fontSize + letterSpacing
}

/** The baseline of a line of text whose line box starts at `top`, as CSS centres it. */
function baseline(top: number, style: TextStyleName): number {
  const { fontSize, lineHeight } = TEXT_STYLES[style]
  const { ascent, descent } = FONT_METRICS[style]
  const contentHeight = (ascent + descent) * fontSize
  return top + (lineHeight - contentHeight) / 2 + ascent * fontSize
}

function wrapLines(text: string, width: number, style: TextStyleName): string[] {
  const lines: string[] = []
  let line = ''
  for (const word of text.split(' ')) {
    const candidate = line ? `${line} ${word}` : word
    if (line && textWidth(candidate, style) > width) {
      lines.push(line)
      line = word
    } else {
      line = candidate
    }
  }
  if (line) lines.push(line)
  return lines
}

function chipWidth(code: string): number {
  return textWidth(code, 'chip') + SPACING.chipSidePadding * 2
}

/** Where the text beside a card's chips starts, so it lines up down the card. */
function chipTextOffset(rows: ChipRow[]): number {
  return Math.max(...rows.map((row) => chipWidth(row.code))) + SPACING.chipToText
}

function innerWidth(box: Box): number {
  return box.width - SPACING.cardPadding * 2
}

/** One line of text laid out inside a card: its style, where it sits, and the width it may fill. */
type CardLine = {
  kind: 'text' | 'chip'
  text: string
  style: TextStyleName
  left: number
  top: number
  maxWidth: number
}

/** A card's content, top to bottom, from its top-left corner; and how tall it is. */
function cardLines(card: Card, box: Box): { lines: CardLine[]; height: number } {
  const lines: CardLine[] = []
  const left = box.left + SPACING.cardPadding
  const width = innerWidth(box)
  let top = box.top + SPACING.cardPadding + SPACING.iconSize + SPACING.iconToTitle

  const titleKind = card.isCodeTitle ? 'chip' : 'text'
  lines.push({
    kind: titleKind,
    text: card.title,
    style: card.isCodeTitle ? 'chip' : 'name',
    left,
    top,
    maxWidth: width,
  })
  top += TEXT_STYLES.name.lineHeight

  if (card.body) {
    top += SPACING.titleToBody
    for (const text of wrapLines(card.body, width, 'body')) {
      lines.push({ kind: 'text', text, style: 'body', left, top, maxWidth: width })
      top += TEXT_STYLES.body.lineHeight
    }
  }
  if (card.chipRows) {
    const textOffset = chipTextOffset(card.chipRows)
    for (const row of card.chipRows) {
      top += SPACING.chipRowGap
      lines.push({ kind: 'chip', text: row.code, style: 'chip', left, top, maxWidth: textOffset - SPACING.chipToText })
      const rowLines = wrapLines(row.text, width - textOffset, 'body')
      rowLines.forEach((text, index) => {
        const lineTop = top + index * TEXT_STYLES.body.lineHeight
        lines.push({
          kind: 'text',
          text,
          style: 'body',
          left: left + textOffset,
          top: lineTop,
          maxWidth: width - textOffset,
        })
      })
      top += Math.max(TEXT_STYLES.chip.lineHeight, rowLines.length * TEXT_STYLES.body.lineHeight)
    }
  }
  return { lines, height: top + SPACING.cardPadding - box.top }
}

function cardHeight(card: Card, width: number): number {
  return cardLines(card, { left: 0, top: 0, width, height: 0 }).height
}

/** The label column's lines: title, then the wrapped summary. */
function labelHeight(section: Section, width: number): number {
  const summaryLines = wrapLines(section.summary, width, 'body').length
  return TEXT_STYLES.title.lineHeight + SPACING.sectionTitleToSummary + summaryLines * TEXT_STYLES.body.lineHeight
}

function placeSection(section: Section, top: number, placed: Placed): number {
  const cardsLeft = section.isLabelAbove ? SPACING.frame : SPACING.frame + SPACING.labelWidth + SPACING.labelGutter
  const labelWidth = section.isLabelAbove ? WIDTH - SPACING.frame * 2 : SPACING.labelWidth
  const cardsWidth = WIDTH - SPACING.frame - cardsLeft
  const columnWidth = (cardsWidth - SPACING.cardGap * (section.columns - 1)) / section.columns
  const cardWidth = (card: Card): number => {
    const span = card.columnSpan ?? 1
    return columnWidth * span + SPACING.cardGap * (span - 1)
  }

  let cardsTop = top
  if (section.isLabelAbove) cardsTop += labelHeight(section, labelWidth) + SPACING.labelAboveToCards
  const stagesTop = cardsTop
  if (section.stages) cardsTop += TEXT_STYLES.stage.lineHeight + SPACING.stageToCards

  // Each row is as tall as its tallest one-row card; a card spanning rows stretches over them.
  const rowCount = Math.max(...section.cards.map((card) => (card.row ?? 0) + (card.rowSpan ?? 1)))
  const rowHeights = Array.from({ length: rowCount }, () => 0)
  for (const card of section.cards) {
    if ((card.rowSpan ?? 1) !== 1) continue
    const row = card.row ?? 0
    rowHeights[row] = Math.max(rowHeights[row] ?? 0, cardHeight(card, cardWidth(card)))
  }
  /** The height of `span` rows from `firstRow`, with the gaps between them; none is 0. */
  const spannedHeight = (firstRow: number, span: number): number => {
    if (span === 0) return 0
    const rowsHeight = rowHeights.slice(firstRow, firstRow + span).reduce((sum, height) => sum + height, 0)
    return rowsHeight + SPACING.cardGap * (span - 1)
  }
  for (const card of section.cards) {
    const span = card.rowSpan ?? 1
    if (span === 1) continue
    const firstRow = card.row ?? 0
    const shortfall = cardHeight(card, cardWidth(card)) - spannedHeight(firstRow, span)
    const lastRow = firstRow + span - 1
    if (shortfall > 0) rowHeights[lastRow] = (rowHeights[lastRow] ?? 0) + shortfall
  }

  for (const card of section.cards) {
    const row = card.row ?? 0
    placed.cards.push({
      card,
      box: {
        left: cardsLeft + card.column * (columnWidth + SPACING.cardGap),
        top: cardsTop + spannedHeight(0, row) + (row > 0 ? SPACING.cardGap : 0),
        width: cardWidth(card),
        height: spannedHeight(row, card.rowSpan ?? 1),
      },
    })
  }

  const labelTop = section.isLabelAbove ? top : cardsTop
  placed.labels.push({
    text: section.title,
    style: 'title',
    box: { left: SPACING.frame, top: labelTop, width: labelWidth, height: TEXT_STYLES.title.lineHeight },
  })
  const summaryTop = labelTop + TEXT_STYLES.title.lineHeight + SPACING.sectionTitleToSummary
  wrapLines(section.summary, labelWidth, 'body').forEach((text, index) => {
    const lineTop = summaryTop + index * TEXT_STYLES.body.lineHeight
    placed.labels.push({
      text,
      style: 'body',
      box: { left: SPACING.frame, top: lineTop, width: labelWidth, height: TEXT_STYLES.body.lineHeight },
    })
  })
  section.stages?.forEach((stage, column) => {
    placed.labels.push({
      text: stage.toUpperCase(),
      style: 'stage',
      box: {
        left: cardsLeft + column * (columnWidth + SPACING.cardGap),
        top: stagesTop,
        width: columnWidth,
        height: TEXT_STYLES.stage.lineHeight,
      },
    })
  })

  return cardsTop + spannedHeight(0, rowCount) - top
}

function placeDiagram(diagram: Diagram): Placed {
  const placed: Placed = { height: 0, cards: [], labels: [] }
  let top = SPACING.frame
  for (const section of diagram.sections) {
    top += placeSection(section, top, placed) + SPACING.cardGap
  }
  placed.height = top - SPACING.cardGap + SPACING.frame
  return placed
}

/** An arrow's straight path: across the gap between two cards side by side, or down between two stacked. */
type ArrowPath = { x1: number; y1: number; x2: number; y2: number }

function arrowPath(from: Box, to: Box): ArrowPath {
  const isRightward = to.left >= right(from)
  const isLeftward = right(to) <= from.left
  if (isRightward || isLeftward) {
    const y = Math.max(from.top, to.top) + ICON_MIDDLE
    if (isRightward) return { x1: right(from) + ARROW_CLEARANCE, y1: y, x2: to.left - ARROW_CLEARANCE, y2: y }
    return { x1: from.left - ARROW_CLEARANCE, y1: y, x2: right(to) + ARROW_CLEARANCE, y2: y }
  }
  const isFromInsideTarget = from.left >= to.left && from.left < right(to)
  const x = isFromInsideTarget ? from.left + ICON_MIDDLE : to.left + ICON_MIDDLE
  return { x1: x, y1: bottom(from) + ARROW_CLEARANCE, x2: x, y2: to.top - ARROW_CLEARANCE }
}

function crossesBox(path: ArrowPath, box: Box): boolean {
  const left = Math.min(path.x1, path.x2)
  const top = Math.min(path.y1, path.y2)
  return (
    left <= right(box) &&
    Math.max(path.x1, path.x2) >= box.left &&
    top <= bottom(box) &&
    Math.max(path.y1, path.y2) >= box.top
  )
}

/**
 * What breaks the style in a placed diagram: text wider than its box, a label
 * taller than the cards beside it, cards that overlap, or an arrow that misses
 * either card's icon line or crosses another card or a label.
 */
export function diagramProblems(diagram: Diagram): string[] {
  const placed = placeDiagram(diagram)
  const problems: string[] = []
  const where = (detail: string): string => `${diagram.file}: ${detail}`

  for (const { card, box } of placed.cards) {
    for (const line of cardLines(card, box).lines) {
      const width = line.kind === 'chip' ? chipWidth(line.text) : textWidth(line.text, line.style)
      if (width > line.maxWidth) problems.push(where(`"${line.text}" in card ${card.id} is wider than its card`))
    }
  }
  for (const label of placed.labels) {
    if (textWidth(label.text, label.style) > label.box.width) problems.push(where(`label "${label.text}" is too wide`))
  }

  for (const section of diagram.sections) {
    if (section.isLabelAbove) continue
    const sectionCards = placed.cards.filter(({ card }) => section.cards.includes(card))
    const cardsTop = Math.min(...sectionCards.map(({ box }) => box.top))
    const cardsBottom = Math.max(...sectionCards.map(({ box }) => bottom(box)))
    if (cardsTop + labelHeight(section, SPACING.labelWidth) > cardsBottom) {
      problems.push(where(`the label "${section.title}" is taller than its cards: shorten the summary`))
    }
  }

  placed.cards.forEach(({ card, box }, index) => {
    for (const other of placed.cards.slice(index + 1)) {
      const isApart =
        right(box) + SPACING.cardGap <= other.box.left + 0.01 ||
        right(other.box) + SPACING.cardGap <= box.left + 0.01 ||
        bottom(box) + SPACING.cardGap <= other.box.top + 0.01 ||
        bottom(other.box) + SPACING.cardGap <= box.top + 0.01
      if (!isApart) problems.push(where(`cards ${card.id} and ${other.card.id} sit closer than one gap`))
    }
  })

  const boxById = new Map(placed.cards.map(({ card, box }) => [card.id, box]))
  for (const arrow of diagram.arrows) {
    const from = boxById.get(arrow.from)
    const to = boxById.get(arrow.to)
    if (!from || !to) {
      problems.push(where(`an arrow names a card that isn't there: ${from ? arrow.to : arrow.from}`))
      continue
    }
    const path = arrowPath(from, to)
    const isAcross = path.y1 === path.y2
    if (isAcross && (path.y1 > bottom(from) || path.y1 > bottom(to))) {
      problems.push(where(`the arrow ${arrow.from} to ${arrow.to} runs below one of its cards`))
    }
    if (!isAcross && (path.x1 > right(from) - SPACING.cardPadding || path.x1 > right(to) - SPACING.cardPadding)) {
      problems.push(where(`the arrow ${arrow.from} to ${arrow.to} misses one of its cards`))
    }
    for (const { card, box } of placed.cards) {
      if (card.id === arrow.from || card.id === arrow.to) continue
      if (crossesBox(path, box)) problems.push(where(`the arrow ${arrow.from} to ${arrow.to} crosses card ${card.id}`))
    }
    for (const label of placed.labels) {
      const inkBox = { ...label.box, width: textWidth(label.text, label.style) }
      if (crossesBox(path, inkBox))
        problems.push(where(`the arrow ${arrow.from} to ${arrow.to} crosses "${label.text}"`))
    }
  }
  return problems
}

function escapeText(text: string): string {
  return text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
}

function round(value: number): string {
  return String(Math.round(value * 100) / 100)
}

function textMarkup(text: string, style: TextStyleName, left: number, lineTop: number, extraClass = ''): string {
  const className = extraClass ? `${style} ${extraClass}` : style
  return `<text x="${round(left)}" y="${round(baseline(lineTop, style))}" class="${className}">${escapeText(text)}</text>`
}

function chipMarkup(code: string, left: number, lineTop: number): string {
  const chipTop = lineTop + (TEXT_STYLES.chip.lineHeight - CHIP_HEIGHT) / 2
  return [
    `<rect x="${round(left)}" y="${round(chipTop)}" width="${round(chipWidth(code))}" height="${CHIP_HEIGHT}" rx="${CHIP_RADIUS}" fill="${COLOR.chip}"/>`,
    // The reader's monospace varies by machine; textLength sets the code to the
    // width its chip was sized for, by spacing alone, so the padding is even.
    `<text x="${round(left + SPACING.chipSidePadding)}" y="${round(baseline(lineTop, 'chip'))}" class="chip"`,
    ` textLength="${round(textWidth(code, 'chip'))}" lengthAdjust="spacing">${escapeText(code)}</text>`,
  ].join('')
}

function iconMarkup(name: keyof typeof ICONS, left: number, top: number): string {
  return `<g transform="translate(${round(left)} ${round(top)})" fill="${COLOR.textSecondary}">${ICONS[name]}</g>`
}

async function cardMarkup(card: Card, box: Box): Promise<string> {
  const parts: string[] = []
  if (card.isAbsent) {
    parts.push(
      `<rect x="${round(box.left + 0.5)}" y="${round(box.top + 0.5)}" width="${round(box.width - 1)}" height="${round(box.height - 1)}"`,
      ` rx="${CARD_RADIUS}" fill="none" stroke="${COLOR.absentBorder}" stroke-dasharray="4 3"/>`,
    )
  } else {
    parts.push(
      `<rect x="${round(box.left)}" y="${round(box.top)}" width="${round(box.width)}" height="${round(box.height)}" rx="${CARD_RADIUS}" fill="${COLOR.surface}"/>`,
    )
  }
  parts.push(iconMarkup(card.icon, box.left + SPACING.cardPadding, box.top + SPACING.cardPadding))
  for (const line of cardLines(card, box).lines) {
    if (line.kind === 'chip') {
      parts.push(chipMarkup(line.text, line.left, line.top))
    } else {
      const isAbsentTitle = card.isAbsent && line.style === 'name'
      parts.push(textMarkup(line.text, line.style, line.left, line.top, isAbsentTitle ? 'absent' : ''))
    }
  }
  return parts.join('')
}

function arrowMarkup(path: ArrowPath): string {
  const length = Math.hypot(path.x2 - path.x1, path.y2 - path.y1)
  const directionX = (path.x2 - path.x1) / length
  const directionY = (path.y2 - path.y1) / length
  // The dashes stop just short of the tip, so the chevron's point stays clean.
  const lineEndX = path.x2 - directionX * 2
  const lineEndY = path.y2 - directionY * 2
  const backX = path.x2 - directionX * 6
  const backY = path.y2 - directionY * 6
  const sideX = directionY * 4.5
  const sideY = directionX * 4.5
  return [
    `<line x1="${round(path.x1)}" y1="${round(path.y1)}" x2="${round(lineEndX)}" y2="${round(lineEndY)}"`,
    ` stroke="${COLOR.accent}" stroke-width="1.5" stroke-dasharray="4 3"/>`,
    `<path d="M${round(backX + sideX)} ${round(backY + sideY)} L${round(path.x2)} ${round(path.y2)} L${round(backX - sideX)} ${round(backY - sideY)}"`,
    ` fill="none" stroke="${COLOR.accent}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>`,
  ].join('')
}

function fontFace(family: string, file: string): string {
  const data = readFileSync(join(HERE, file)).toString('base64')
  return `@font-face { font-family: "${family}"; font-weight: 100 900; src: url(data:font/woff2;base64,${data}) format("woff2"); }`
}

function styleMarkup(): string {
  const classes = Object.entries(TEXT_STYLES).map(([name, style]) => `.${name} { ${style.css} }`)
  return [
    '<style>',
    fontFace('Montserrat', 'Montserrat-latin.woff2'),
    ...classes,
    `.absent { fill: ${COLOR.textTertiary}; }`,
    '</style>',
  ].join('\n')
}

export async function drawDiagram(diagram: Diagram): Promise<string> {
  const placed = placeDiagram(diagram)
  const cardParts: string[] = []
  for (const { card, box } of placed.cards) cardParts.push(await cardMarkup(card, box))
  const labelParts = placed.labels.map((label) => textMarkup(label.text, label.style, label.box.left, label.box.top))
  const boxById = new Map(placed.cards.map(({ card, box }) => [card.id, box]))
  const arrowParts = diagram.arrows.flatMap((arrow) => {
    const from = boxById.get(arrow.from)
    const to = boxById.get(arrow.to)
    return from && to ? [arrowMarkup(arrowPath(from, to))] : []
  })
  const height = round(placed.height)
  return [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${WIDTH}" height="${height}" viewBox="0 0 ${WIDTH} ${height}">`,
    styleMarkup(),
    `<rect width="${WIDTH}" height="${height}" rx="${FRAME_RADIUS}" fill="${COLOR.backdrop}"/>`,
    ...labelParts,
    ...cardParts,
    ...arrowParts,
    '</svg>',
    '',
  ].join('\n')
}
