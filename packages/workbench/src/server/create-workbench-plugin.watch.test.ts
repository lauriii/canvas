import { promises as fs } from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { createServer } from 'vite';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createWorkbenchPlugin } from './create-workbench-plugin';

import type { AddressInfo } from 'node:net';
import type { ViteDevServer } from 'vite';
import type { PreviewManifest } from '../lib/preview-contract';
import type { WorkbenchPaths } from './paths';

const HOOK = "import { useTranslation } from 'drupal-canvas/react';";

let root: string;
let clientRoot: string;
let server: ViteDevServer;
let httpServer: http.Server;
let updates: Array<{ reloadFrameOnly: boolean; filePath: string }>;

async function writeFile(filePath: string, content: string): Promise<void> {
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.writeFile(filePath, content, 'utf8');
}

const label = (string: string) =>
  `${HOOK}\nexport const useLabel = () => useTranslation().t('${string}');`;
const badge = (string: string) =>
  `${HOOK}\nexport default () => useTranslation().t('${string}');`;

async function fetchManifest(): Promise<PreviewManifest> {
  const { port } = httpServer.address() as AddressInfo;
  const response = await fetch(
    `http://127.0.0.1:${port}/__canvas/preview-manifest`,
  );
  return (await response.json()) as PreviewManifest;
}

/**
 * Changes a file that may have changed just before.
 *
 * The watcher does not report a change that follows the last one closely.
 */
async function changeFile(filePath: string, content: string): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 200));
  await writeFile(filePath, content);
}

const strings = (manifest: PreviewManifest, name: string) =>
  manifest.components.find((component) => component.name === name)?.translation
    ?.translatableStrings;

/**
 * Waits for an update that a change to a file sends.
 *
 * Creating a file can also report a change to it, which may be sent first.
 */
async function waitForUpdate(filePath: string, reloadFrameOnly: boolean) {
  await vi.waitFor(
    () => {
      expect(updates).toContainEqual(
        expect.objectContaining({ filePath, reloadFrameOnly }),
      );
    },
    { timeout: 10_000, interval: 50 },
  );
  updates = [];
}

beforeEach(async () => {
  root = await fs.realpath(
    await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-workbench-watch-')),
  );
  // As in Workbench, Vite serves the Workbench client, not the project, so it
  // does not watch the project's files on its own.
  clientRoot = await fs.realpath(
    await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-workbench-client-')),
  );
  await writeFile(
    path.join(root, 'src/components/card/component.yml'),
    'name: Card\nmachineName: card',
  );
  await writeFile(
    path.join(root, 'src/components/card/index.tsx'),
    `import Badge from '@/components/badge';
import { useLabel } from './labels';
export default () => [Badge(), useLabel()];`,
  );
  await writeFile(
    path.join(root, 'src/components/card/labels.ts'),
    label('Helper'),
  );
  await writeFile(
    path.join(root, 'src/components/badge/component.yml'),
    'name: Badge\nmachineName: badge',
  );
  await writeFile(
    path.join(root, 'src/components/badge/index.tsx'),
    badge('Badge'),
  );
  const paths: WorkbenchPaths = {
    aliasBaseRoot: path.join(root, 'src'),
    appHtmlPath: path.join(clientRoot, 'index.html'),
    allowedFsRoots: [root, clientRoot],
    clientRoot,
    componentDiscoveryRoot: path.join(root, 'src/components'),
    contentTemplatesDiscoveryRoot: path.join(root, 'content-templates'),
    siteUrl: null,
    hostProjectRoot: root,
    packageRoot: root,
    pagesDiscoveryRoot: path.join(root, 'pages'),
    pageTemplatesDiscoveryRoot: path.join(root, 'page-templates'),
    runningInsideWorkbenchPackage: true,
    watchRoots: [path.join(root, 'src/components')],
    workbenchSourceRoot: root,
  };
  server = await createServer({
    configFile: false,
    root: clientRoot,
    logLevel: 'silent',
    appType: 'custom',
    server: { middlewareMode: true },
    plugins: [createWorkbenchPlugin(paths)],
  });
  updates = [];
  server.ws.send = ((payload: {
    type: string;
    event?: string;
    data?: { reloadFrameOnly: boolean; filePath: string };
  }) => {
    if (payload.event === 'canvas:workbench:update' && payload.data) {
      updates.push(payload.data);
    }
  }) as ViteDevServer['ws']['send'];
  httpServer = http.createServer(server.middlewares);
  await new Promise<void>((resolve) => httpServer.listen(0, resolve));
});

afterEach(async () => {
  await new Promise((resolve) => httpServer.close(resolve));
  await server.close();
  await fs.rm(root, { recursive: true, force: true });
  await fs.rm(clientRoot, { recursive: true, force: true });
});

describe('the Workbench watcher', () => {
  it('refreshes the manifest when the interface translation of components changes', async () => {
    const manifest = await fetchManifest();
    // With the strings of the components it imports, not of helpers.
    expect(strings(manifest, 'card')).toEqual([{ source: 'Badge' }]);
    const badgeEntry = path.join(root, 'src/components/badge/index.tsx');
    const labels = path.join(root, 'src/components/card/labels.ts');
    // The watcher scans the component directories first.
    await vi.waitFor(
      () => {
        const watched = server.watcher.getWatched();
        expect(watched[path.dirname(badgeEntry)]).toContain('index.tsx');
        expect(watched[path.dirname(labels)]).toContain('labels.ts');
      },
      { timeout: 10_000, interval: 50 },
    );

    // A component that another component imports.
    await changeFile(badgeEntry, badge('Changed badge'));
    await waitForUpdate(badgeEntry, false);
    expect(strings(await fetchManifest(), 'card')).toEqual([
      { source: 'Changed badge' },
    ]);

    // A change that keeps the strings only reloads the frame.
    await changeFile(badgeEntry, `${badge('Changed badge')}\n// A comment.`);
    await waitForUpdate(badgeEntry, true);

    // So does a change to a helper, whose strings are not scanned.
    await changeFile(labels, label('Changed helper'));
    await waitForUpdate(labels, true);
    expect(strings(await fetchManifest(), 'card')).toEqual([
      { source: 'Changed badge' },
    ]);

    // A component that cannot be parsed makes the strings unknown, until it
    // is fixed.
    await changeFile(badgeEntry, 'export default (;');
    await waitForUpdate(badgeEntry, false);
    expect(strings(await fetchManifest(), 'card')).toBeNull();
    await changeFile(badgeEntry, badge('Fixed badge'));
    await waitForUpdate(badgeEntry, false);
    expect(strings(await fetchManifest(), 'card')).toEqual([
      { source: 'Fixed badge' },
    ]);
  }, 60_000);
});
