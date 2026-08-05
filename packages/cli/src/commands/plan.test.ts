import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { Command } from 'commander';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as p from '@clack/prompts';

import { readLock, sourceFingerprint } from '../lib/fleet';
import { createSiteApiService, readObservedSite } from '../lib/fleet-site';
import { buildCanvasProject } from '../utils/build-project';
import { planCommand } from './plan';

import type { LockFile } from '../lib/fleet';
import type { ObservedSite } from '../lib/fleet-site';
import type { Component } from '../types/Component';

vi.mock('@clack/prompts', () => ({
  intro: vi.fn(),
  outro: vi.fn(),
  cancel: vi.fn(),
  spinner: vi.fn(() => ({ start: vi.fn(), stop: vi.fn(), message: vi.fn() })),
  log: {
    info: vi.fn(),
    message: vi.fn(),
    warn: vi.fn(),
    error: vi.fn(),
    success: vi.fn(),
  },
}));

vi.mock('@drupal-canvas/discovery', () => ({
  discoverCanvasProject: vi.fn(async () => ({ components: [], warnings: [] })),
}));

vi.mock('../config', () => ({
  getConfig: vi.fn(() => ({
    componentDir: 'src/components',
    aliasBaseDir: 'src',
    outputDir: 'dist',
    userAgent: '',
    includeBrandKit: false,
  })),
  getDefaultScope: vi.fn(() => 'scope'),
}));

vi.mock('../utils/command-helpers', () => ({
  updateConfigFromOptions: vi.fn(),
}));

vi.mock('../utils/build-project', () => ({ buildCanvasProject: vi.fn() }));

vi.mock('../lib/fleet-site', () => ({
  createSiteApiService: vi.fn(),
  readObservedSite: vi.fn(),
}));

const HERO = {
  machineName: 'hero',
  name: 'Hero',
  props: {},
  slots: {},
  required: [],
  sourceCodeJs: 'export default () => null;',
  sourceCodeCss: '.hero {}',
  importedJsComponents: [],
  dataDependencies: {},
} as unknown as Component;
const HERO_HASH = sourceFingerprint(HERO);

let root: string;
let previousCwd: string;
let observedBySite: Record<string, ObservedSite>;

function writeLockFile(lock: LockFile): void {
  fs.writeFileSync(
    path.join(root, 'canvas.lock.json'),
    JSON.stringify(lock),
    'utf-8',
  );
}

function lockWith(
  components: LockFile['sites'][string]['components'],
): LockFile {
  return {
    lockfileVersion: 1,
    generatedAt: '2026-08-05T10:22:00Z',
    sites: {
      alpha: {
        libraryVersion: '3.4.0',
        appliedAt: '2026-08-04T09:00:00Z',
        appliedBy: 'ci',
        lastRefresh: '2026-08-04T09:00:00Z',
        components,
      },
    },
  };
}

async function run(...args: string[]): Promise<void> {
  const program = new Command();
  program.exitOverride();
  planCommand(program);
  await program.parseAsync(['node', 'canvas', 'plan', ...args]);
}

describe('planCommand', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.exitCode = undefined;
    previousCwd = process.cwd();
    root = fs.mkdtempSync(path.join(os.tmpdir(), 'canvas-plan-'));
    process.chdir(root);
    fs.writeFileSync(
      path.join(root, 'canvas.library.json'),
      JSON.stringify({
        name: '@acme/canvas-library',
        version: '3.4.0',
        components: ['hero'],
      }),
      'utf-8',
    );
    fs.writeFileSync(
      path.join(root, 'canvas.fleet.json'),
      JSON.stringify({
        sites: { alpha: { url: 'https://alpha.example.com' } },
      }),
      'utf-8',
    );

    observedBySite = {
      alpha: {
        hero: { sourceHash: HERO_HASH, versionHash: 'v1', payload: HERO },
      },
    };

    vi.mocked(buildCanvasProject).mockResolvedValue({
      builtComponents: [
        {
          machineName: 'hero',
          componentName: 'Hero',
          componentPayload: HERO,
          importedJsComponents: [],
        },
      ],
      componentResults: [{ itemName: 'hero', success: true }],
      tailwindResult: { itemName: 'Tailwind CSS', success: true },
    } as unknown as Awaited<ReturnType<typeof buildCanvasProject>>);

    vi.mocked(createSiteApiService).mockImplementation(
      async (siteName) => ({ siteName }) as never,
    );
    vi.mocked(readObservedSite).mockImplementation(
      async (apiService) =>
        observedBySite[
          (apiService as unknown as { siteName: string }).siteName
        ] ?? {},
    );
  });

  afterEach(() => {
    process.chdir(previousCwd);
    fs.rmSync(root, { recursive: true, force: true });
  });

  it('exits 0 when the fleet already runs the library', async () => {
    writeLockFile(
      lockWith({
        hero: {
          pushedHash: 'v1',
          observedHash: 'v1',
          pushedSourceHash: HERO_HASH,
          observedSourceHash: HERO_HASH,
        },
      }),
    );
    await run();
    expect(process.exitCode).toBe(0);
    expect(p.outro).toHaveBeenCalledWith('No changes');
  });

  it('exits 2 when a site is behind the library', async () => {
    writeLockFile(
      lockWith({
        hero: {
          pushedHash: 'v0',
          observedHash: 'v1',
          pushedSourceHash: 'older-source',
          observedSourceHash: HERO_HASH,
        },
      }),
    );
    await run();
    expect(process.exitCode).toBe(2);
    expect(p.outro).toHaveBeenCalledWith('Changes pending');
  });

  it('exits 2 when a site has never been applied to', async () => {
    await run();
    expect(process.exitCode).toBe(2);
  });

  it('exits 3 when a site diverged, even with other changes pending', async () => {
    writeLockFile(
      lockWith({
        hero: {
          pushedHash: 'v1',
          observedHash: 'v1',
          pushedSourceHash: 'older-source',
          observedSourceHash: 'older-source',
        },
      }),
    );
    observedBySite.alpha = {
      hero: { sourceHash: 'edited-on-site', versionHash: 'v1', payload: HERO },
    };
    await run();
    expect(process.exitCode).toBe(3);
    expect(p.outro).toHaveBeenCalledWith(
      'Divergence detected — resolve before applying',
    );
  });

  it('always labels drift detection as advisory', async () => {
    await run();
    expect(p.log.info).toHaveBeenCalledWith(
      expect.stringContaining('ADVISORY'),
    );
  });

  it('warns prominently and reads no site with --no-refresh', async () => {
    writeLockFile(
      lockWith({
        hero: {
          pushedHash: 'v1',
          observedHash: 'v1',
          pushedSourceHash: HERO_HASH,
          observedSourceHash: HERO_HASH,
        },
      }),
    );
    await run('--no-refresh');
    expect(readObservedSite).not.toHaveBeenCalled();
    expect(p.log.warn).toHaveBeenCalledWith(
      expect.stringContaining('last refreshed 2026-08-04T09:00:00Z'),
    );
    expect(process.exitCode).toBe(0);
  });

  it('refreshes the lockfile without building for --refresh-only', async () => {
    writeLockFile(
      lockWith({
        hero: {
          pushedHash: 'v1',
          observedHash: 'v1',
          pushedSourceHash: HERO_HASH,
          observedSourceHash: HERO_HASH,
        },
      }),
    );
    observedBySite.alpha = {
      hero: { sourceHash: 'edited-on-site', versionHash: 'v2', payload: HERO },
    };

    await run('--refresh-only');

    expect(buildCanvasProject).not.toHaveBeenCalled();
    expect(process.exitCode).toBe(3);
    const lock = readLock(root);
    expect(lock.sites.alpha.components.hero).toMatchObject({
      pushedSourceHash: HERO_HASH,
      observedSourceHash: 'edited-on-site',
      observedHash: 'v2',
    });
    expect(lock.sites.alpha.lastRefresh).not.toBe('2026-08-04T09:00:00Z');
  });

  it('leaves the lockfile alone on a plain plan', async () => {
    const before = lockWith({
      hero: {
        pushedHash: 'v1',
        observedHash: 'v1',
        pushedSourceHash: HERO_HASH,
        observedSourceHash: HERO_HASH,
      },
    });
    writeLockFile(before);
    observedBySite.alpha = {
      hero: { sourceHash: 'edited-on-site', versionHash: 'v2', payload: HERO },
    };
    await run();
    expect(readLock(root)).toStrictEqual(before);
  });

  it('exits 1 and names the site when a refresh fails', async () => {
    vi.mocked(createSiteApiService).mockRejectedValue(
      new Error('site unreachable'),
    );
    await run();
    expect(process.exitCode).toBe(1);
    expect(p.log.error).toHaveBeenCalledWith(
      expect.stringContaining('alpha: site unreachable'),
    );
    // A site that could not be read must never read as "No changes".
    expect(p.outro).toHaveBeenCalledWith('Could not read 1 of 1 sites');
  });

  it('emits machine-readable output carrying the advisory caveat', async () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => {});
    await run('--json');
    const payload = JSON.parse(log.mock.calls.at(-1)![0] as string) as {
      driftDetection: string;
      stale: boolean;
      plans: {
        site: string;
        components: { component: string; state: string }[];
      }[];
    };
    expect(payload.driftDetection).toBe('advisory');
    expect(payload.stale).toBe(false);
    expect(payload.plans[0].components).toStrictEqual([
      { component: 'hero', state: 'unknown' },
    ]);
    log.mockRestore();
  });

  it('honors --exclude', async () => {
    fs.writeFileSync(
      path.join(root, 'canvas.fleet.json'),
      JSON.stringify({
        sites: {
          alpha: { url: 'https://alpha.example.com' },
          beta: { url: 'https://beta.example.com' },
        },
      }),
      'utf-8',
    );
    await run('--exclude', 'beta');
    expect(createSiteApiService).toHaveBeenCalledTimes(1);
    expect(vi.mocked(createSiteApiService).mock.calls[0][0]).toBe('alpha');
  });
});
