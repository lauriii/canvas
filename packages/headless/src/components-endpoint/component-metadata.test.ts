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

// The paths of the application's TypeScript configuration, which the build
// resolves `@/` imports with.
async function writeTsconfig(root: string): Promise<void> {
  await writeFile(
    path.join(root, 'tsconfig.json'),
    JSON.stringify({ compilerOptions: { paths: { '@/*': ['./src/*'] } } }),
  );
}

async function writeComponent(
  root: string,
  directory: string,
  yml: string = HELLO_CARD_YML,
  entry: string = 'export default function Component() { return null; }\n',
  entryFile: string = 'index.tsx',
): Promise<void> {
  await writeFile(
    path.join(root, 'src/components', directory, 'component.yml'),
    yml,
  );
  await writeFile(
    path.join(root, 'src/components', directory, entryFile),
    entry,
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
      translatableStrings: [],
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

  it('finds the strings the entry file translates', async () => {
    const root = await makeProjectRoot();
    await writeComponent(
      root,
      'hello-card',
      HELLO_CARD_YML,
      `import { useTranslation } from 'drupal-canvas/react';
import { t as untracked } from './helpers';
import { label } from './labels';

export default function Card({ count, name }) {
  const { t: translate, formatPlural } = useTranslation();
  const i18n = useTranslation();
  return [
    translate('Hello @name', { '@name': name }),
    formatPlural(count, '1 item', '@count items'),
    i18n.t('May', {}, { context: 'Long month name' }),
    // Dynamic calls and other functions are skipped.
    translate(label),
    translate(\`Hello\`),
    i18n.t('Hello ' + name),
    untracked('Not from useTranslation()'),
  ];
}
`,
    );
    // Translation functions imported from other modules are not followed.
    await writeFile(
      path.join(root, 'src/components/hello-card/helpers.ts'),
      'export const t = (string) => string;\n',
    );
    // The other local modules that the entry file imports are not scanned.
    await writeFile(
      path.join(root, 'src/components/hello-card/labels.ts'),
      `import { useTranslation } from 'drupal-canvas/react';
export const label = 'Label';
export const useLabel = () => useTranslation().t('In a shared module');
`,
    );

    const payload = await buildComponentMetadataPayload({ projectRoot: root });

    expect(payload.warnings).toEqual([]);
    // Sorted, as the Canvas CLI sends them.
    expect(payload.components[0]?.translatableStrings).toEqual([
      { source: '1 item', plural: '@count items' },
      { source: 'Hello @name' },
      { source: 'May', context: 'Long month name' },
    ]);
  });

  it('follows only the registered components that components import', async () => {
    const root = await makeProjectRoot();
    await writeTsconfig(root);
    const hook = "import { useTranslation } from 'drupal-canvas/react';";
    await writeComponent(
      root,
      'hello-card',
      HELLO_CARD_YML,
      `${hook}
import { useCardLabels } from '@/lib/labels';
import { useFooter } from './footer';
import Badge from '@/components/badge';
import Title from '@/components/title.js';
import Draft from '@/components/draft';
import './card.css';
import logo from '@/assets/logo.svg';
import { format } from 'date-fns';
export default function Card() { return useTranslation().t('Card'); }
`,
    );
    await writeFile(path.join(root, 'src/components/hello-card/card.css'), '');
    await writeFile(path.join(root, 'src/assets/logo.svg'), '<svg />');
    // Helpers are not scanned: components pass them translated text.
    await writeFile(
      path.join(root, 'src/lib/labels.ts'),
      `${hook}
export const useCardLabels = () => useTranslation().t('From a helper');
`,
    );
    await writeFile(
      path.join(root, 'src/components/hello-card/footer.ts'),
      `${hook}
export const useFooter = () => useTranslation().t('From a relative import');
`,
    );
    // A directory with a component.yml, which is not a registered component.
    await writeFile(
      path.join(root, 'src/components/draft/index.tsx'),
      `${hook}
export default () => useTranslation().t('Unregistered');
`,
    );
    // Components render the components they import themselves.
    await writeComponent(
      root,
      'badge',
      HELLO_CARD_YML.replace('hello-card', 'badge'),
      `${hook}
export default function Badge() { return useTranslation().t('Badge'); }
`,
    );
    // A named entry file, imported with the extension of its build output.
    await writeFile(
      path.join(root, 'src/components/title.component.yml'),
      HELLO_CARD_YML.replace('hello-card', 'title'),
    );
    await writeFile(
      path.join(root, 'src/components/title.tsx'),
      `${hook}
export default function Title() { return useTranslation().t('Title'); }
`,
    );

    const payload = await buildComponentMetadataPayload({ projectRoot: root });

    expect(payload.warnings).toEqual([]);
    const strings = Object.fromEntries(
      payload.components.map((c) => [c.machineName, c.translatableStrings]),
    );
    expect(strings).toEqual({
      'hello-card': [
        { source: 'Badge' },
        { source: 'Card' },
        { source: 'Title' },
      ],
      badge: [{ source: 'Badge' }],
      title: [{ source: 'Title' }],
    });
  });

  it('includes the strings of the components that a component renders itself', async () => {
    const root = await makeProjectRoot();
    await writeTsconfig(root);
    const hook = "import { useTranslation } from 'drupal-canvas/react';";
    // Placed on a page alone, the card brings the badge's strings along.
    await writeComponent(
      root,
      'hello-card',
      HELLO_CARD_YML,
      `${hook}
import Badge from '@/components/badge';
export default function Card() { return [useTranslation().t('Card'), Badge()]; }
`,
    );
    await writeComponent(
      root,
      'badge',
      HELLO_CARD_YML.replace('hello-card', 'badge'),
      `${hook}
import Card from '@/components/hello-card';
export default function Badge() { return useTranslation().t('Badge'); }
`,
    );

    const payload = await buildComponentMetadataPayload({ projectRoot: root });

    expect(
      Object.fromEntries(
        payload.components.map((c) => [c.machineName, c.translatableStrings]),
      ),
    ).toEqual({
      'hello-card': [{ source: 'Badge' }, { source: 'Card' }],
      // A cycle.
      badge: [{ source: 'Badge' }, { source: 'Card' }],
    });
  });

  it('resolves imports as the application does, without guessing', async () => {
    const root = await makeProjectRoot();
    const hook = "import { useTranslation } from 'drupal-canvas/react';";
    // A JavaScript project, with jsconfig.json.
    await writeFile(
      path.join(root, 'jsconfig.json'),
      JSON.stringify({ compilerOptions: { paths: { '~/*': ['./src/*'] } } }),
    );
    await writeComponent(
      root,
      'hello-card',
      HELLO_CARD_YML,
      `import Badge from '../badge';
import Title from '~/components/title';
import Unmapped from '@/components/badge';
import Shadowed from '../shadowed';
export default () => [Badge, Title];
`,
      'index.jsx',
    );
    for (const name of ['badge', 'title', 'shadowed']) {
      await writeComponent(
        root,
        name,
        HELLO_CARD_YML.replace('hello-card', name),
        `${hook}\nexport default () => useTranslation().t('${name}');\n`,
        'index.jsx',
      );
    }
    // A helper that the import of the shadowed component loads instead.
    await writeFile(
      path.join(root, 'src/components/shadowed.js'),
      `${hook}\nexport default () => useTranslation().t('helper');\n`,
    );

    const payload = await buildComponentMetadataPayload({ projectRoot: root });

    expect(
      payload.components.find(({ machineName }) => machineName === 'hello-card')
        ?.translatableStrings,
    ).toEqual([{ source: 'badge' }, { source: 'title' }]);
  });

  it('marks the strings of components that import unparsable components as unknown', async () => {
    const root = await makeProjectRoot();
    await writeTsconfig(root);
    await writeComponent(
      root,
      'hello-card',
      HELLO_CARD_YML,
      "import Broken from '@/components/broken';\nexport default () => Broken;\n",
    );
    await writeComponent(
      root,
      'broken',
      HELLO_CARD_YML.replace('hello-card', 'broken'),
      'export default function (\n',
    );
    // Missing and unparsable helpers are not scanned.
    await writeComponent(
      root,
      'with-helpers',
      HELLO_CARD_YML.replace('hello-card', 'with-helpers'),
      "import { label } from './missing';\nimport { other } from '@/lib/broken';\nexport default () => label;\n",
    );
    await writeFile(path.join(root, 'src/lib/broken.ts'), 'export const (');

    const payload = await buildComponentMetadataPayload({ projectRoot: root });

    expect(payload.warnings).toEqual([]);
    expect(
      Object.fromEntries(
        payload.components.map((c) => [c.machineName, c.translatableStrings]),
      ),
    ).toEqual({ 'hello-card': null, broken: null, 'with-helpers': [] });
  });

  it('marks the strings of entry files it cannot parse as unknown', async () => {
    const root = await makeProjectRoot();
    await writeComponent(
      root,
      'vue-card',
      HELLO_CARD_YML.replace('hello-card', 'vue-card'),
      "<template><p>{{ t('Hello') }}</p></template>\n",
      'index.vue',
    );
    await writeComponent(
      root,
      'broken-card',
      HELLO_CARD_YML.replace('hello-card', 'broken-card'),
      "import { useTranslation } from 'drupal-canvas/react';\nexport default function (\n",
    );

    const payload = await buildComponentMetadataPayload({ projectRoot: root });

    expect(payload.warnings).toEqual([]);
    expect(
      payload.components.map(({ machineName, translatableStrings }) => [
        machineName,
        translatableStrings,
      ]),
    ).toEqual(
      expect.arrayContaining([
        ['vue-card', null],
        ['broken-card', null],
      ]),
    );
  });

  it('rejects on invalid translatable strings, with their locations', async () => {
    const root = await makeProjectRoot();
    await writeComponent(
      root,
      'hello-card',
      HELLO_CARD_YML,
      `import { useTranslation } from 'drupal-canvas/react';

export default function Card() {
  const { t } = useTranslation();
  return [t(''), t('May', {}, { context: 'Month – long' })];
}
`,
    );

    const error = await buildComponentMetadataPayload({
      projectRoot: root,
    }).catch((error: unknown) => error);

    expect(error).toBeInstanceOf(Error);
    const lines = (error as Error).message.split('\n');
    expect(lines[0]).toBe('Invalid translatable strings:');
    expect(lines.slice(1)).toEqual([
      '  src/components/hello-card/index.tsx:5:13: A translatable string must not be empty.',
      '  src/components/hello-card/index.tsx:5:42: A translation context must contain only ASCII characters.',
    ]);
  });

  it('rejects on invalid translatable strings in imported components, not helpers', async () => {
    const root = await makeProjectRoot();
    await writeTsconfig(root);
    const hook = "import { useTranslation } from 'drupal-canvas/react';";
    await writeComponent(
      root,
      'hello-card',
      HELLO_CARD_YML,
      "import Badge from '@/components/badge';\nimport { useLabel } from './labels';\nexport default () => Badge;\n",
    );
    await writeFile(
      path.join(root, 'src/components/hello-card/labels.ts'),
      `${hook}
export const useLabel = () => useTranslation().t('');
`,
    );
    await writeComponent(
      root,
      'badge',
      HELLO_CARD_YML.replace('hello-card', 'badge'),
      `${hook}
export default () => useTranslation().t('');
`,
    );

    const error = await buildComponentMetadataPayload({
      projectRoot: root,
    }).catch((error: unknown) => error);

    expect(error).toBeInstanceOf(Error);
    // For the badge, and for the card that imports it.
    expect((error as Error).message.split('\n')).toEqual([
      'Invalid translatable strings:',
      '  src/components/badge/index.tsx:2:41: A translatable string must not be empty.',
      '  src/components/badge/index.tsx:2:41: A translatable string must not be empty.',
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
