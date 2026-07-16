import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  buildComponentRegistryModule,
  resolveComponentRegistrySourcePaths,
} from './component-registry';
import { CANVAS_COMPONENTS_MODULE_ID, canvasComponentRegistry } from './vite';

const tempDirs: string[] = [];

afterEach(async () => {
  vi.useRealTimers();
  await Promise.all(
    tempDirs.map((dir) => fs.rm(dir, { recursive: true, force: true })),
  );
  tempDirs.length = 0;
});

async function makeProjectRoot(): Promise<string> {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-headless-'));
  tempDirs.push(root);
  return root;
}

async function writeFile(filePath: string, content: string): Promise<void> {
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.writeFile(filePath, content, 'utf-8');
}

const HELLO_CARD_YML = `name: Hello Card
machineName: hello-card
status: true
required:
  - title
props:
  properties:
    title:
      type: string
      title: Title
      examples:
        - Hello
slots:
  content:
    title: Content
    description: The card body.
`;

async function writeComponent(
  root: string,
  directory: string,
  yml: string = HELLO_CARD_YML,
): Promise<void> {
  await writeFile(
    path.join(root, 'src/components', directory, 'component.yml'),
    yml,
  );
  await writeFile(
    path.join(root, 'src/components', directory, 'index.tsx'),
    'export default function Component() { return null; }\n',
  );
}

describe('component implementation registry', () => {
  it('generates static imports keyed by machine name', async () => {
    const root = await makeProjectRoot();
    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify({ componentDir: 'components/canvas' }),
    );
    await writeFile(
      path.join(root, 'components/canvas/hello-card/component.yml'),
      HELLO_CARD_YML,
    );
    await writeFile(
      path.join(root, 'components/canvas/hello-card/index.tsx'),
      'export default function Component() { return null; }\n',
    );
    const modulePath = path.join(root, '.canvas/components.ts');

    const source = await buildComponentRegistryModule({
      projectRoot: root,
      modulePath,
    });

    expect(source).toContain(
      'import Component0 from "../components/canvas/hello-card/index.tsx";',
    );
    expect(source).toContain('"hello-card": Component0');
  });

  it('resolves its configured source locations', async () => {
    const root = await makeProjectRoot();
    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify({ componentDir: 'components/canvas' }),
    );
    const sourcePaths = resolveComponentRegistrySourcePaths({
      projectRoot: root,
    });

    expect(sourcePaths).toEqual({
      configPath: path.join(root, 'canvas.config.json'),
      componentRoot: path.join(root, 'components/canvas'),
    });
  });

  it('provides the generated registry through the shared Vite module', async () => {
    const root = await makeProjectRoot();
    await writeComponent(root, 'hello-card');
    const plugin = canvasComponentRegistry();
    const configResolved = plugin.configResolved as unknown as (config: {
      root: string;
    }) => void;
    configResolved({ root });
    const resolveId = plugin.resolveId as unknown as (
      id: string,
    ) => string | undefined;
    const resolvedId = resolveId(CANVAS_COMPONENTS_MODULE_ID);

    expect(resolvedId).toBe(`\0${CANVAS_COMPONENTS_MODULE_ID}`);

    const load = plugin.load as unknown as (
      id: string,
    ) => Promise<string | undefined>;
    const source = await load(resolvedId!);

    expect(source).toContain(
      `import Component0 from ${JSON.stringify(path.join(root, 'src/components/hello-card/index.tsx'))};`,
    );
    expect(source).toContain('"hello-card": Component0');
  });

  it('reloads the shared Vite module after component structure changes', async () => {
    vi.useFakeTimers();
    const root = await makeProjectRoot();
    const invalidateMain = vi.fn();
    const invalidateClient = vi.fn();
    const send = vi.fn();
    let onChange: ((event: string, filePath: string) => void) | undefined;
    const add = vi.fn();
    const plugin = canvasComponentRegistry({ projectRoot: root });

    plugin.configureServer({
      watcher: {
        add,
        on: (_event, listener) => {
          onChange = listener;
        },
        off: vi.fn(),
      },
      moduleGraph: { invalidateAll: invalidateMain },
      environments: {
        client: { moduleGraph: { invalidateAll: invalidateClient } },
      },
      ws: { send },
      httpServer: null,
    });

    expect(add).toHaveBeenCalledWith([
      path.join(root, 'canvas.config.json'),
      path.join(root, 'src/components'),
    ]);

    onChange?.('change', path.join(root, 'src/components/card/index.tsx'));
    await vi.advanceTimersByTimeAsync(50);
    expect(send).not.toHaveBeenCalled();

    onChange?.('add', path.join(root, 'src/components/card/index.tsx'));
    await vi.advanceTimersByTimeAsync(50);
    expect(invalidateMain).toHaveBeenCalledOnce();
    expect(invalidateClient).toHaveBeenCalledOnce();
    expect(send).toHaveBeenCalledWith({ type: 'full-reload' });
  });
});
