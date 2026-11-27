import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
  findClassesWithoutCss,
  referencesClassNameProp,
  RESERVED_STYLE_PROP_GUIDE,
  validateStyles,
} from './validate-styles';

import type { DiscoveredComponent } from '@drupal-canvas/discovery';

describe('validateStyles', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'validate-styles-'));
    await fs.mkdir(path.join(tmpDir, 'styles'));
    await fs.writeFile(
      path.join(tmpDir, 'styles', 'brand.utilities.yml'),
      [
        'tone:',
        '  label: Tone',
        '  options:',
        '    light: { label: Light, value: bg-white }',
        '    dark: { label: Dark }',
      ].join('\n'),
    );
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  async function writeComponent(
    name: string,
    yml: string,
    source: string,
  ): Promise<DiscoveredComponent> {
    const directory = path.join(tmpDir, 'components', name);
    await fs.mkdir(directory, { recursive: true });
    await fs.writeFile(path.join(directory, 'component.yml'), yml);
    await fs.writeFile(path.join(directory, 'index.jsx'), source);
    return {
      name,
      directory,
      metadataPath: path.join(directory, 'component.yml'),
      jsEntryPath: path.join(directory, 'index.jsx'),
    } as DiscoveredComponent;
  }

  it('reports reserved props, unknown styles, missing className, and style-like enums', async () => {
    const hero = await writeComponent(
      'hero',
      [
        'name: Hero',
        'machineName: hero',
        'styles: [tone, glow]',
        'props:',
        '  properties:',
        '    style:',
        '      type: string',
        '      title: Style',
        '      enum: [outline, solid]',
        '    tone:',
        '      type: string',
        '      title: Tone',
        '      enum: [light, dark]',
      ].join('\n'),
      'export default function Hero() { return <div className="hero" />; }',
    );

    const { libraryResults, componentFindings } = await validateStyles(
      { components: [hero] },
      { projectRoot: tmpDir, stylesDir: 'styles' },
    );

    expect(libraryResults).toEqual([
      expect.objectContaining({
        success: true,
        warnings: [
          expect.stringContaining(
            'Line 5, Column 11: Option "dark" of style "tone" has no value, so it sets class="dark".',
          ),
        ],
      }),
    ]);
    const findings = componentFindings.get('hero')!;
    expect(findings.errors).toEqual([
      expect.stringContaining(RESERVED_STYLE_PROP_GUIDE),
    ]);
    expect(findings.errors[0]).toContain('The prop "style" is reserved');
    expect(findings.warnings).toEqual([
      expect.stringContaining(
        'neither in styles/ nor in .canvas/styles.json: glow',
      ),
      expect.stringContaining('never references `className`'),
      expect.stringContaining(
        'The prop "tone" has the values of the style "tone"',
      ),
    ]);
  });

  it('accepts a component that applies className', async () => {
    const card = await writeComponent(
      'card',
      'name: Card\nmachineName: card\nstyles: [tone]\n',
      "import { cn } from 'drupal-canvas';\nexport default function Card({ className, style }) { return <div className={cn('card', className)} style={style} />; }",
    );
    const { componentFindings } = await validateStyles(
      { components: [card] },
      { projectRoot: tmpDir, stylesDir: 'styles' },
    );
    expect(componentFindings.size).toBe(0);
  });

  it('checks part styles and reserves the styleParts prop', async () => {
    const card = await writeComponent(
      'card',
      [
        'name: Card',
        'machineName: card',
        'styleParts:',
        '  heading:',
        '    label: Heading',
        '    styles:',
        '      tone: { options: [light] }',
        '      glow: ~',
        'props:',
        '  properties:',
        '    styleParts:',
        '      type: string',
        '      title: Style parts',
      ].join('\n'),
      'export default function Card({ styleParts }) { return <h2 className={styleParts?.heading?.className} />; }',
    );
    const { componentFindings } = await validateStyles(
      { components: [card] },
      { projectRoot: tmpDir, stylesDir: 'styles' },
    );
    const findings = componentFindings.get('card')!;
    expect(findings.errors).toEqual([
      expect.stringContaining('The prop "styleParts" is reserved'),
    ]);
    // No className hint: only parts accept styles.
    expect(findings.warnings).toEqual([
      expect.stringContaining(
        'neither in styles/ nor in .canvas/styles.json: glow',
      ),
    ]);
  });

  it('detects className prop references, not JSX attributes', () => {
    expect(referencesClassNameProp('<div className="a" />')).toBe(false);
    expect(
      referencesClassNameProp(
        '({ className }) => <div className={className} />',
      ),
    ).toBe(true);
    expect(referencesClassNameProp('props.className')).toBe(true);
  });

  it('finds option classes the Tailwind build emits no CSS for', async () => {
    expect(
      await findClassesWithoutCss(
        ['bg-white', 'bg-[var(--brand)]', 'not-a-utility', 'my-card'],
        '@import "tailwindcss";\n.my-card { color: red; }',
      ),
    ).toEqual(['not-a-utility']);
  });
});
