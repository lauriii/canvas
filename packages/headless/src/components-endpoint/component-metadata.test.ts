import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import {
  buildComponentMetadataPayload,
  COMPONENT_METADATA_PAYLOAD_VERSION,
} from './component-metadata';

const tempDirs: string[] = [];

afterEach(async () => {
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

describe('buildComponentMetadataPayload', () => {
  it('builds a versioned payload with flattened props', async () => {
    const root = await makeProjectRoot();
    await writeComponent(root, 'hello-card');

    const payload = await buildComponentMetadataPayload({ projectRoot: root });

    expect(payload.version).toBe(COMPONENT_METADATA_PAYLOAD_VERSION);
    expect(payload.warnings).toEqual([]);
    expect(payload.components).toHaveLength(1);
    const [component] = payload.components;
    expect(component).toMatchObject({
      machineName: 'hello-card',
      name: 'Hello Card',
      status: true,
      required: ['title'],
      relativeDirectory: 'hello-card',
    });
    // The flat prop map, not discovery's props.properties nesting.
    expect(component.props.title).toMatchObject({
      type: 'string',
      title: 'Title',
    });
    expect(component.slots.content).toMatchObject({ title: 'Content' });
  });

  it('answers an empty component set for an empty project', async () => {
    const root = await makeProjectRoot();
    const payload = await buildComponentMetadataPayload({ projectRoot: root });
    expect(payload.components).toEqual([]);
  });

  it('excludes components without a JS entry, with a relativized warning', async () => {
    const root = await makeProjectRoot();
    await writeFile(
      path.join(root, 'src/components/broken/component.yml'),
      HELLO_CARD_YML,
    );

    const payload = await buildComponentMetadataPayload({ projectRoot: root });

    expect(payload.components).toEqual([]);
    const warning = payload.warnings.find((w) => w.code === 'missing_js_entry');
    expect(warning).toBeDefined();
    expect(warning?.path).toBe('src/components/broken/component.yml');
    expect(path.isAbsolute(warning?.path ?? '/')).toBe(false);
  });

  it('keeps duplicate machine names and flags them', async () => {
    const root = await makeProjectRoot();
    await writeComponent(root, 'card-a');
    await writeComponent(root, 'card-b');

    const payload = await buildComponentMetadataPayload({ projectRoot: root });

    expect(payload.components).toHaveLength(2);
    expect(
      payload.warnings.filter((w) => w.code === 'duplicate_machine_name')
        .length === 1,
    ).toBe(true);
  });

  it('honors a custom componentDir from canvas.config.json', async () => {
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

    const payload = await buildComponentMetadataPayload({ projectRoot: root });
    expect(payload.components.map((c) => c.machineName)).toEqual([
      'hello-card',
    ]);
  });

  it('rejects on malformed component metadata', async () => {
    const root = await makeProjectRoot();
    await writeComponent(
      root,
      'broken',
      'name: Broken\nprops: not-an-object\n',
    );

    await expect(
      buildComponentMetadataPayload({ projectRoot: root }),
    ).rejects.toThrow();
  });
});
