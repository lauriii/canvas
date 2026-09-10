import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { validateIconLibraryEntry, validateSvgSafety } from './icon-validate';

import type { IconLibraryEntry } from '../../config';

const BENIGN_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>';

describe('validateSvgSafety', () => {
  it('accepts a benign SVG', () => {
    expect(validateSvgSafety(BENIGN_SVG)).toEqual([]);
  });

  it('accepts fragment and relative href references', () => {
    expect(
      validateSvgSafety(
        '<svg><use href="#icon"/><use xlink:href="sprites.svg#star"/></svg>',
      ),
    ).toEqual([]);
  });

  it('rejects script elements', () => {
    const issues = validateSvgSafety('<svg><script>alert(1)</script></svg>');
    expect(issues.join('\n')).toContain('<script>');
  });

  it('rejects event handler attributes', () => {
    const issues = validateSvgSafety(
      '<svg onload="alert(1)"><path d="M0 0"/></svg>',
    );
    expect(issues.join('\n')).toContain('event handler');
  });

  it('rejects javascript: URLs', () => {
    const issues = validateSvgSafety(
      '<svg><a href="javascript:alert(1)">x</a></svg>',
    );
    expect(issues.join('\n')).toContain('javascript:');
  });

  it('rejects DOCTYPE declarations', () => {
    const issues = validateSvgSafety(
      '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "svg11.dtd"><svg/>',
    );
    expect(issues.join('\n')).toContain('DOCTYPE');
  });

  it('rejects absolute href/src URLs', () => {
    const issues = validateSvgSafety(
      '<svg><image href="https://evil.example.com/x.png"/></svg>',
    );
    expect(issues.join('\n')).toContain('https://evil.example.com/x.png');
  });

  it('rejects protocol-relative href/src URLs', () => {
    const issues = validateSvgSafety(
      '<svg><image src="//evil.example.com/x.png"/></svg>',
    );
    expect(issues.join('\n')).toContain('//evil.example.com/x.png');
  });
});

describe('validateIconLibraryEntry', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'icon-validate-test-'));
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  async function writeSvgDir(
    relativeDir: string,
    files: Record<string, string>,
  ): Promise<void> {
    const dir = path.join(tmpDir, relativeDir);
    await fs.mkdir(dir, { recursive: true });
    for (const [name, content] of Object.entries(files)) {
      await fs.writeFile(path.join(dir, name), content, 'utf-8');
    }
  }

  it('validates a declared entry with explicit fields', async () => {
    await writeSvgDir('icons/my_icons', {
      'b.svg': BENIGN_SVG,
      'a.svg': BENIGN_SVG,
      'README.txt': 'ignored',
    });

    const result = await validateIconLibraryEntry(
      { id: 'my_icons', label: 'My icons', description: 'A set' },
      tmpDir,
    );

    expect(result.id).toBe('my_icons');
    expect(result.label).toBe('My icons');
    expect(result.description).toBe('A set');
    expect(result.filesDir).toBe(path.join(tmpDir, 'icons', 'my_icons'));
    expect(result.svgFiles).toEqual(['a.svg', 'b.svg']);
  });

  it('rejects an entry without a label', async () => {
    await writeSvgDir('icons/lucide_icons', { 'arrow.svg': BENIGN_SVG });

    await expect(
      validateIconLibraryEntry(
        { id: 'lucide_icons' } as IconLibraryEntry,
        tmpDir,
      ),
    ).rejects.toThrow(/missing or empty "label"/);
  });

  it('validates an entry with an explicit label', async () => {
    await writeSvgDir('icons/lucide_icons', { 'arrow.svg': BENIGN_SVG });

    const result = await validateIconLibraryEntry(
      { id: 'lucide_icons', label: 'Lucide icons' },
      tmpDir,
    );

    expect(result.label).toBe('Lucide icons');
  });

  it('reads SVG files from the entry source directory', async () => {
    await writeSvgDir('node_modules/some-icons', { 'star.svg': BENIGN_SVG });

    const result = await validateIconLibraryEntry(
      {
        id: 'some_icons',
        label: 'Some icons',
        source: 'node_modules/some-icons',
      },
      tmpDir,
    );

    expect(result.filesDir).toBe(
      path.join(tmpDir, 'node_modules', 'some-icons'),
    );
    expect(result.svgFiles).toEqual(['star.svg']);
  });

  it('rejects a missing library directory', async () => {
    await expect(
      validateIconLibraryEntry({ id: 'missing', label: 'Missing' }, tmpDir),
    ).rejects.toThrow(/The library directory does not exist: icons\/missing/);
  });

  it('rejects an invalid library id', async () => {
    await writeSvgDir('icons/My-Icons', { 'a.svg': BENIGN_SVG });

    await expect(
      validateIconLibraryEntry(
        { id: 'My-Icons', label: 'My icons', source: 'icons/My-Icons' },
        tmpDir,
      ),
    ).rejects.toThrow(/Invalid library id "My-Icons"/);
  });

  it('rejects a library without any SVG icons', async () => {
    await writeSvgDir('icons/my_icons', { 'README.txt': 'no icons here' });

    await expect(
      validateIconLibraryEntry({ id: 'my_icons', label: 'My icons' }, tmpDir),
    ).rejects.toThrow(/contains no SVG icons/);
  });

  it('rejects invalid icon filenames', async () => {
    await writeSvgDir('icons/my_icons', {
      'ok.svg': BENIGN_SVG,
      'bad name.svg': BENIGN_SVG,
    });

    await expect(
      validateIconLibraryEntry({ id: 'my_icons', label: 'My icons' }, tmpDir),
    ).rejects.toThrow(/bad name\.svg: invalid icon filename/);
  });

  it('rejects unsafe SVG content and lists every issue', async () => {
    await writeSvgDir('icons/my_icons', {
      'evil.svg': '<svg><script>alert(1)</script></svg>',
      'sneaky.svg': '<svg onload="x()"><path d="M0 0"/></svg>',
    });

    const error = await validateIconLibraryEntry(
      { id: 'my_icons', label: 'My icons' },
      tmpDir,
    ).catch((e: unknown) => e as Error);

    expect(error).toBeInstanceOf(Error);
    expect((error as Error).message).toContain(
      'evil.svg: contains a <script> element',
    );
    expect((error as Error).message).toContain(
      'sneaky.svg: contains an event handler attribute',
    );
  });
});
