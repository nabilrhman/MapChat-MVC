/**
 * The diagrams in docs/images/, as data: draw.ts draws each in the style
 * README.md sets. Change a diagram here, never in its SVG, then run
 * `node docs/tools/diagrams/draw.ts`.
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
        summary: 'Every page, and every chat request without the socket, takes this path.',
        columns: 3,
        cards: [
          {
            id: 'index',
            icon: 'code-file',
            column: 0,
            title: 'public/index.php',
            isCodeTitle: true,
            body: 'Starts the session, kept in MySQL, and lists every route.',
          },
          {
            id: 'router',
            icon: 'routing-2',
            column: 1,
            title: 'Core\\Router',
            isCodeTitle: true,
            body: 'Picks the one action for the method and path, or answers 404 or 405.',
          },
          {
            id: 'controller',
            icon: 'settings',
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
            body: 'Shows the page, then opens the chat socket. If it can\'t, asks for new messages every second.',
          },
          {
            id: 'view',
            icon: 'window-frame',
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
      {
        title: 'Live chat',
        summary: 'One WebSocket per open chat page, nothing polling.',
        columns: 3,
        cards: [
          {
            id: 'proxy',
            icon: 'round-transfer-horizontal',
            column: 0,
            title: 'Apache /ws',
            body: 'Passes the page\'s WebSocket to the chat socket, on the site\'s own address.',
          },
          {
            id: 'hub',
            icon: 'chat-round-dots',
            column: 1,
            title: 'App\\ChatSocket\\Hub',
            isCodeTitle: true,
            body: 'Reads new messages in id order and sends each to the pages allowed to see it.',
          },
          {
            id: 'worker',
            icon: 'clock-circle',
            column: 2,
            title: 'bin/chat-worker.php',
            isCodeTitle: true,
            body: 'Posts MapChat\'s replies and the demo people\'s lines as they come due.',
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
      { from: 'browser', to: 'proxy' },
      { from: 'proxy', to: 'hub' },
      { from: 'worker', to: 'hub' },
    ],
  },
]
