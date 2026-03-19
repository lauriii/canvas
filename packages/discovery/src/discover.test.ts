import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import { discoverCanvasProject } from './discover';

async function makeTempDir(): Promise<string> {
  return fs.mkdtemp(path.join(os.tmpdir(), 'canvas-discovery-'));
}

async function writeFile(filePath: string, content = ''): Promise<void> {
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.writeFile(filePath, content, 'utf-8');
}

const tempDirs: string[] = [];

afterEach(async () => {
  await Promise.all(
    tempDirs.map((dir) => fs.rm(dir, { recursive: true, force: true })),
  );
  tempDirs.length = 0;
});

describe('discoverCanvasProject', () => {
  it('discovers top-level page JSON files and excludes nested page files', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    await writeFile(path.join(root, 'pages/home.json'), '{"root":"home"}');
    await writeFile(path.join(root, 'pages/about-us.json'), '{"root":"about"}');
    await writeFile(
      path.join(root, 'pages/nested/ignored.json'),
      '{"root":"ignored"}',
    );

    const result = await discoverCanvasProject({ componentRoot: root });

    expect(result.pages).toHaveLength(2);
    expect(result.pages.map((page) => page.slug)).toEqual(['about-us', 'home']);
    expect(
      result.pages.some(
        (page) => page.relativePath === 'pages/nested/ignored.json',
      ),
    ).toBe(false);
  });

  it('returns pages and components together in one discovery result', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    await writeFile(path.join(root, 'pages/home.json'), '{"root":"home"}');
    await writeFile(path.join(root, 'src/card/component.yml'), 'name: Card');
    await writeFile(
      path.join(root, 'src/card/index.tsx'),
      'export default function Card() { return null; }',
    );

    const result = await discoverCanvasProject({ componentRoot: root });

    expect(result.components).toHaveLength(1);
    expect(result.pages).toHaveLength(1);
    expect(result.pages[0].slug).toBe('home');
  });

  it('supports a dedicated pagesRoot outside the component scan root', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    await writeFile(
      path.join(root, 'components/card/component.yml'),
      'name: Card',
    );
    await writeFile(
      path.join(root, 'components/card/index.tsx'),
      'export default function Card() { return null; }',
    );
    await writeFile(
      path.join(root, 'content/pages/home.json'),
      '{"root":"home"}',
    );
    await writeFile(
      path.join(root, 'content/pages/nested/ignored.json'),
      '{"root":"ignored"}',
    );
    await writeFile(
      path.join(root, 'other-components/button/component.yml'),
      'name: Button',
    );
    await writeFile(
      path.join(root, 'other-components/button/index.tsx'),
      'export default function Button() { return null; }',
    );

    const result = await discoverCanvasProject({
      componentRoot: path.join(root, 'components'),
      pagesRoot: path.join(root, 'content/pages'),
      projectRoot: root,
    });

    expect(result.components).toHaveLength(1);
    expect(result.components[0].name).toBe('card');
    expect(result.pages).toHaveLength(1);
    expect(result.pages[0].relativePath).toBe('content/pages/home.json');
  });

  it('discovers index and named metadata variants with expected entries', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    await writeFile(path.join(root, 'src/card/component.yml'), 'name: Card');
    await writeFile(
      path.join(root, 'src/card/index.tsx'),
      'export default {};',
    );
    await writeFile(path.join(root, 'src/card/index.css'), '.card {}');

    await writeFile(
      path.join(root, 'src/hero/hero.component.yml'),
      'name: Hero',
    );
    await writeFile(path.join(root, 'src/hero/hero.ts'), 'export default {};');
    await writeFile(path.join(root, 'src/hero/hero.css'), '.hero {}');

    const result = await discoverCanvasProject({ componentRoot: root });

    expect(result.components).toHaveLength(2);

    const card = result.components.find(
      (component) => component.kind === 'index',
    );
    const hero = result.components.find(
      (component) => component.kind === 'named',
    );

    expect(
      card?.jsEntryPath?.endsWith(path.join('src', 'card', 'index.tsx')),
    ).toBe(true);
    expect(
      card?.cssEntryPath?.endsWith(path.join('src', 'card', 'index.css')),
    ).toBe(true);

    expect(hero?.name).toBe('hero');
    expect(
      hero?.jsEntryPath?.endsWith(path.join('src', 'hero', 'hero.ts')),
    ).toBe(true);
    expect(
      hero?.cssEntryPath?.endsWith(path.join('src', 'hero', 'hero.css')),
    ).toBe(true);
  });

  it('skips components with missing JavaScript entries and emits warnings', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    await writeFile(
      path.join(root, 'src/broken/component.yml'),
      'name: Broken',
    );

    const result = await discoverCanvasProject({ componentRoot: root });

    expect(result.components).toHaveLength(0);
    expect(
      result.warnings.some((warning) => warning.code === 'missing_js_entry'),
    ).toBe(true);
  });

  it('keeps components when CSS is missing', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    await writeFile(
      path.join(root, 'src/no-css/component.yml'),
      'name: No CSS',
    );
    await writeFile(
      path.join(root, 'src/no-css/index.js'),
      'export default {};',
    );

    const result = await discoverCanvasProject({ componentRoot: root });

    expect(result.components).toHaveLength(1);
    expect(result.components[0].cssEntryPath).toBeNull();
  });

  it('accepts empty array shorthand for props and slots', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    await writeFile(
      path.join(root, 'src/navigation/component.yml'),
      [
        'name: Navigation',
        'machineName: navigation',
        'status: true',
        'required: []',
        'props: []',
        'slots: []',
      ].join('\n'),
    );
    await writeFile(
      path.join(root, 'src/navigation/index.tsx'),
      'export default function Navigation() { return null; }',
    );

    const result = await discoverCanvasProject({ componentRoot: root });

    expect(result.components).toHaveLength(1);
    expect(result.components[0].name).toBe('navigation');
  });

  it('uses named metadata when both metadata formats exist in same directory', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    await writeFile(
      path.join(root, 'src/mixed/component.yml'),
      'name: Mixed Index',
    );
    await writeFile(
      path.join(root, 'src/mixed/index.ts'),
      'export default {};',
    );
    await writeFile(
      path.join(root, 'src/mixed/mixed.component.yml'),
      'name: Mixed Named',
    );
    await writeFile(
      path.join(root, 'src/mixed/mixed.tsx'),
      'export default {};',
    );

    const result = await discoverCanvasProject({ componentRoot: root });

    expect(result.components).toHaveLength(1);
    expect(result.components[0].kind).toBe('named');
    expect(
      result.warnings.some(
        (warning) => warning.code === 'conflicting_metadata',
      ),
    ).toBe(true);
  });

  it('respects .gitignore and fixed excluded directories', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    await writeFile(path.join(root, '.gitignore'), 'ignored/**\n');

    await writeFile(
      path.join(root, 'ignored/thing/component.yml'),
      'name: Ignored',
    );
    await writeFile(
      path.join(root, 'ignored/thing/index.ts'),
      'export default {};',
    );

    await writeFile(
      path.join(root, 'node_modules/pkg/component.yml'),
      'name: Ignored Node Modules',
    );
    await writeFile(
      path.join(root, 'node_modules/pkg/index.ts'),
      'export default {};',
    );

    await writeFile(path.join(root, 'src/ok/component.yml'), 'name: Ok');
    await writeFile(path.join(root, 'src/ok/index.ts'), 'export default {};');
    await writeFile(path.join(root, 'pages/home.json'), '{"root":"home"}');
    await writeFile(
      path.join(root, 'ignored/pages/hidden.json'),
      '{"root":"hidden"}',
    );

    const result = await discoverCanvasProject({ componentRoot: root });

    expect(result.components).toHaveLength(1);
    expect(result.components[0].name).toBe('ok');
    expect(result.pages).toHaveLength(1);
    expect(result.stats.ignoredFiles).toBe(1);
  });

  it('chooses JavaScript entries by precedence and emits duplicate warning', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    await writeFile(path.join(root, 'src/dup/component.yml'), 'name: Dup');
    await writeFile(path.join(root, 'src/dup/index.js'), 'export default {};');
    await writeFile(path.join(root, 'src/dup/index.ts'), 'export default {};');

    const result = await discoverCanvasProject({ componentRoot: root });

    expect(result.components).toHaveLength(1);
    expect(
      result.components[0].jsEntryPath?.endsWith(
        path.join('src', 'dup', 'index.ts'),
      ),
    ).toBe(true);
    expect(
      result.warnings.some(
        (warning) => warning.code === 'duplicate_definition',
      ),
    ).toBe(true);
  });

  it('emits warning when multiple components share the same machineName', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    await writeFile(
      path.join(root, 'src/card/component.yml'),
      'machineName: shared-name',
    );
    await writeFile(path.join(root, 'src/card/index.ts'), 'export default {};');

    await writeFile(
      path.join(root, 'src/button/component.yml'),
      'machineName: shared-name',
    );
    await writeFile(
      path.join(root, 'src/button/index.ts'),
      'export default {};',
    );

    const result = await discoverCanvasProject({ componentRoot: root });

    expect(result.components).toHaveLength(2);
    expect(
      result.warnings.some(
        (warning) => warning.code === 'duplicate_machine_name',
      ),
    ).toBe(true);
  });
});
