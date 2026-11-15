/**
 * Draw the diagrams in diagrams.ts into docs/images/, in the style README.md
 * sets. Before writing, each is checked against that style; `--check` also
 * fails when an SVG on disk isn't what the data draws, so a hand edit can't
 * stay.
 *
 *   node docs/diagrams/draw.ts           # rewrite them
 *   node docs/diagrams/draw.ts --check   # fail if one breaks the style or is stale
 */
import { readFile, writeFile } from 'node:fs/promises'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { DIAGRAMS } from './diagrams.ts'
import { diagramProblems, drawDiagram } from './layout.ts'

const IMAGES = join(resolve(dirname(fileURLToPath(import.meta.url)), '../..'), 'docs/images')
const isCheck = process.argv.includes('--check')

async function readIfThere(path: string): Promise<string | null> {
  try {
    return await readFile(path, 'utf8')
  } catch {
    return null
  }
}

const problems = DIAGRAMS.flatMap((diagram) => diagramProblems(diagram))
for (const diagram of DIAGRAMS) {
  const path = join(IMAGES, diagram.file)
  const drawn = await drawDiagram(diagram)
  if (isCheck) {
    if ((await readIfThere(path)) !== drawn) {
      problems.push(`docs/images/${diagram.file} isn't what its data draws: run node docs/diagrams/draw.ts`)
    }
  } else if (problems.length === 0) {
    await writeFile(path, drawn)
    console.log(`wrote docs/images/${diagram.file}`)
  }
}

if (problems.length > 0) {
  console.error(['Diagrams break the style (docs/diagrams/README.md):', ...problems.map((p) => `  ${p}`)].join('\n'))
  process.exit(1)
}
