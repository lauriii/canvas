import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { detectHeadlessSdk } from './detect-headless-sdk';

describe('detectHeadlessSdk', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-detect-sdk-'));
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  async function writePackageJson(contents: unknown): Promise<void> {
    await fs.writeFile(
      path.join(tmpDir, 'package.json'),
      JSON.stringify(contents),
    );
  }

  it('detects the SDK in dependencies', async () => {
    await writePackageJson({
      dependencies: { '@drupal-canvas/headless': '^0.1.0' },
    });
    expect(detectHeadlessSdk(tmpDir)).toBe(true);
  });

  it('detects the SDK in devDependencies', async () => {
    await writePackageJson({
      devDependencies: { '@drupal-canvas/headless': '^0.1.0' },
    });
    expect(detectHeadlessSdk(tmpDir)).toBe(true);
  });

  it('detects a framework SDK package', async () => {
    await writePackageJson({
      dependencies: { '@drupal-canvas/headless-next': '^0.1.0' },
    });
    expect(detectHeadlessSdk(tmpDir)).toBe(true);
  });

  it('returns false when the SDK is absent', async () => {
    await writePackageJson({ dependencies: { react: '^18.0.0' } });
    expect(detectHeadlessSdk(tmpDir)).toBe(false);
  });

  it('returns false when there is no package.json', () => {
    expect(detectHeadlessSdk(tmpDir)).toBe(false);
  });

  it('returns false when package.json is invalid JSON', async () => {
    await fs.writeFile(path.join(tmpDir, 'package.json'), '{ not json');
    expect(detectHeadlessSdk(tmpDir)).toBe(false);
  });
});
