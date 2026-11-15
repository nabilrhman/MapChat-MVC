/**
 * The diagrams in docs/images/, as data: draw.ts draws each in the style
 * README.md sets. Change a diagram here, never in its SVG, then run
 * `node docs/diagrams/draw.ts`.
 *
 * Every name on a diagram is a claim about the code: check it there when you
 * change one.
 */
import type { Diagram } from './layout.ts'

export const DIAGRAMS: Diagram[] = [
  {
    file: 'request-path.svg',
    sections: [
      {
        title: 'One request',
        summary: 'Every page and every poll takes this path.',
        columns: 3,
        cards: [
          {
            id: 'index',
            icon: 'file-code',
            column: 0,
            title: 'public/index.php',
            isCodeTitle: true,
            body: 'Starts the session, kept in MySQL, and lists every route.',
          },
          {
            id: 'router',
            icon: 'signpost',
            column: 1,
            title: 'Core\\Router',
            isCodeTitle: true,
            body: 'Picks the one action for the method and path, or answers 404 or 405.',
          },
          {
            id: 'controller',
            icon: 'sliders-horizontal',
            column: 2,
            title: 'Core\\Controller',
            isCodeTitle: true,
            body: 'Refuses posts from other sites, checks the login, then runs the action.',
          },
        ],
      },
      {
        title: 'The answer',
        summary: 'Read from MySQL, sent back as Twig HTML or JSON.',
        columns: 3,
        cards: [
          {
            id: 'browser',
            icon: 'monitor',
            column: 0,
            title: 'The browser',
            body: 'Shows the page, then asks for new messages every second, each a new request at the top.',
          },
          {
            id: 'view',
            icon: 'layout-template',
            column: 1,
            title: 'Core\\View',
            isCodeTitle: true,
            body: 'Twig templates that escape everything people type.',
          },
          {
            id: 'model',
            icon: 'database',
            column: 2,
            title: 'Core\\Model',
            isCodeTitle: true,
            body: 'Prepared statements on one PDO connection to MySQL.',
          },
        ],
      },
    ],
    arrows: [
      { from: 'index', to: 'router' },
      { from: 'router', to: 'controller' },
      { from: 'controller', to: 'model' },
      { from: 'model', to: 'view' },
      { from: 'view', to: 'browser' },
    ],
  },
]
