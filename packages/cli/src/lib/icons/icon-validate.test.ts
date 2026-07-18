import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { validateIconLibraryDir, validateSvgSafety } from './icon-validate';

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

describe('validateIconLibraryDir', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'icon-validate-test-'));
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  async function writeLibrary(
    id: string,
    manifest: unknown,
    files: Record<string, string> = {},
  ): Promise<string> {
    const dir = path.join(tmpDir, id);
    await fs.mkdir(dir, { recursive: true });
    if (manifest !== undefined) {
      await fs.writeFile(
        path.join(dir, 'manifest.json'),
        typeof manifest === 'string'
          ? manifest
          : JSON.stringify(manifest, null, 2),
        'utf-8',
      );
    }
    for (const [name, content] of Object.entries(files)) {
      await fs.writeFile(path.join(dir, name), content, 'utf-8');
    }
    return dir;
  }

  it('returns manifest and sorted svg files for a valid library', async () => {
    const dir = await writeLibrary(
      'my_icons',
      { id: 'my_icons', label: 'My icons', description: 'A set' },
      { 'b.svg': BENIGN_SVG, 'a.svg': BENIGN_SVG },
    );

    const result = await validateIconLibraryDir(dir);

    expect(result.id).toBe('my_icons');
    expect(result.manifest).toEqual({
      id: 'my_icons',
      label: 'My icons',
      description: 'A set',
    });
    expect(result.svgFiles).toEqual(['a.svg', 'b.svg']);
  });

  it('ignores non-SVG files', async () => {
    const dir = await writeLibrary(
      'my_icons',
      { id: 'my_icons', label: 'My icons' },
      { 'a.svg': BENIGN_SVG, 'README.txt': 'hello' },
    );

    const result = await validateIconLibraryDir(dir);
    expect(result.svgFiles).toEqual(['a.svg']);
  });

  it('rejects an invalid library id from the directory name', async () => {
    const dir = await writeLibrary('My-Icons', {
      id: 'My-Icons',
      label: 'My icons',
    });

    await expect(validateIconLibraryDir(dir)).rejects.toThrow(
      /Invalid library id "My-Icons"/,
    );
  });

  it('rejects a missing manifest.json', async () => {
    const dir = path.join(tmpDir, 'my_icons');
    await fs.mkdir(dir, { recursive: true });

    await expect(validateIconLibraryDir(dir)).rejects.toThrow(
      /Missing manifest\.json/,
    );
  });

  it('rejects invalid JSON in manifest.json', async () => {
    const dir = await writeLibrary('my_icons', 'not json');

    await expect(validateIconLibraryDir(dir)).rejects.toThrow(
      /Invalid JSON in manifest\.json/,
    );
  });

  it('rejects a manifest id that does not match the directory name', async () => {
    const dir = await writeLibrary('my_icons', {
      id: 'other',
      label: 'My icons',
    });

    await expect(validateIconLibraryDir(dir)).rejects.toThrow(
      /"id" must match the directory name "my_icons"/,
    );
  });

  it('rejects a manifest without a label', async () => {
    const dir = await writeLibrary('my_icons', { id: 'my_icons' });

    await expect(validateIconLibraryDir(dir)).rejects.toThrow(
      /missing a non-empty "label"/,
    );
  });

  it('rejects invalid icon filenames', async () => {
    const dir = await writeLibrary(
      'my_icons',
      { id: 'my_icons', label: 'My icons' },
      { 'bad icon.svg': BENIGN_SVG },
    );

    await expect(validateIconLibraryDir(dir)).rejects.toThrow(
      /bad icon\.svg: invalid icon filename/,
    );
  });

  it('rejects unsafe SVG content with the filename in the error', async () => {
    const dir = await writeLibrary(
      'my_icons',
      { id: 'my_icons', label: 'My icons' },
      { 'evil.svg': '<svg><script>alert(1)</script></svg>' },
    );

    await expect(validateIconLibraryDir(dir)).rejects.toThrow(
      /evil\.svg: contains a <script> element/,
    );
  });

  it('lists all errors at once', async () => {
    const dir = await writeLibrary(
      'my_icons',
      { id: 'my_icons' },
      {
        'evil.svg': '<svg onload="x()"/>',
        'bad name.svg': BENIGN_SVG,
      },
    );

    const err = await validateIconLibraryDir(dir).catch((e) => e);
    expect(err).toBeInstanceOf(Error);
    const message = (err as Error).message;
    expect(message).toContain('missing a non-empty "label"');
    expect(message).toContain('bad name.svg: invalid icon filename');
    expect(message).toContain('evil.svg: contains an event handler attribute');
  });
});
