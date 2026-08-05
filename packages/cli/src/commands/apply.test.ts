import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { Command } from 'commander';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as p from '@clack/prompts';

import { readLock, sourceFingerprint } from '../lib/fleet';
import { createSiteApiService, readObservedSite } from '../lib/fleet-site';
import { buildCanvasProject } from '../utils/build-project';
import { uploadComponents } from '../utils/prepare-push';
import { applyCommand, compareVersions } from './apply';

import type { ObservedSite } from '../lib/fleet-site';
import type { Component } from '../types/Component';

vi.mock('@clack/prompts', () => ({
  intro: vi.fn(),
  outro: vi.fn(),
  cancel: vi.fn(),
  confirm: vi.fn(async () => true),
  isCancel: vi.fn(() => false),
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

vi.mock('../utils/build-project', () => ({
  buildCanvasProject: vi.fn(),
}));

vi.mock('../utils/prepare-push', () => ({
  uploadComponents: vi.fn(),
  prepareGlobalAssetLibraryUpdate: vi.fn(async () => ({
    result: { itemName: 'css', success: true },
    assetLibrary: {},
  })),
}));

vi.mock('./push', () => ({
  syncManifestArtifacts: vi.fn(async () => ({
    artifactCount: 0,
    groupedManifest: { vendor: [], local: [], shared: [], bundledSources: [] },
  })),
  updateGlobalAssetLibraryForPush: vi.fn(),
}));

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

const FLEET = {
  groups: { 'wave-1': ['alpha', 'beta'], prod: ['gamma'] },
  sites: {
    alpha: {
      url: 'https://alpha.example.com',
      credentialsEnv: 'ALPHA_CREDENTIALS',
    },
    beta: {
      url: 'https://beta.example.com',
      credentialsEnv: 'BETA_CREDENTIALS',
    },
    gamma: {
      url: 'https://gamma.example.com',
      credentialsEnv: 'GAMMA_CREDENTIALS',
    },
  },
  protectedGroups: ['prod'],
};

let root: string;
let previousCwd: string;
/** Queued `readObservedSite` responses, per site, in call order. */
let observedQueues: Record<string, ObservedSite[]>;

function writeFleetFiles(fleet: unknown = FLEET): void {
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
    JSON.stringify(fleet),
    'utf-8',
  );
}

function makeProgram(): Command {
  const program = new Command();
  program.exitOverride();
  applyCommand(program);
  return program;
}

async function run(...args: string[]): Promise<void> {
  await makeProgram().parseAsync(['node', 'canvas', 'apply', ...args]);
}

/** Site state as reported by a site that has never seen the library. */
const EMPTY: ObservedSite = {};

/** Site state matching what the CLI just pushed. */
function inSync(versionHash = 'v-hero'): ObservedSite {
  return { hero: { sourceHash: HERO_HASH, versionHash, payload: HERO } };
}

describe('applyCommand', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.exitCode = undefined;
    previousCwd = process.cwd();
    root = fs.mkdtempSync(path.join(os.tmpdir(), 'canvas-apply-'));
    process.chdir(root);
    writeFleetFiles();

    process.env.ALPHA_CREDENTIALS = 'id:secret';
    process.env.BETA_CREDENTIALS = 'id:secret';
    process.env.GAMMA_CREDENTIALS = 'id:secret';
    delete process.env.CI;
    process.env.CANVAS_APPLIED_BY = 'tester';

    observedQueues = {
      alpha: [EMPTY, inSync()],
      beta: [EMPTY, inSync()],
      gamma: [EMPTY, inSync()],
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
      async (siteName) =>
        ({
          siteName,
          signalPushStart: vi.fn(),
          signalPushComplete: vi.fn(),
          signalPushFail: vi.fn(),
        }) as never,
    );
    vi.mocked(readObservedSite).mockImplementation(async (apiService) => {
      const siteName = (apiService as unknown as { siteName: string }).siteName;
      return observedQueues[siteName].shift() ?? EMPTY;
    });
    vi.mocked(uploadComponents).mockImplementation(async (tasks) =>
      tasks.map((task) => ({
        machineName: task.machineName,
        success: true,
        operation: task.operation,
      })),
    );
  });

  afterEach(() => {
    process.chdir(previousCwd);
    fs.rmSync(root, { recursive: true, force: true });
    delete process.env.CANVAS_APPLIED_BY;
  });

  it('fans out to every site in a group and records what went where', async () => {
    await run('--group', 'wave-1', '--yes');

    expect(process.exitCode).toBeUndefined();
    expect(uploadComponents).toHaveBeenCalledTimes(2);
    const lock = readLock(root);
    expect(Object.keys(lock.sites).sort()).toStrictEqual(['alpha', 'beta']);
    expect(lock.sites.alpha).toMatchObject({
      libraryVersion: '3.4.0',
      appliedBy: 'tester',
      components: {
        hero: {
          pushedSourceHash: HERO_HASH,
          pushedHash: 'v-hero',
          observedHash: 'v-hero',
        },
      },
    });
  });

  it('creates components the site does not have and updates the ones it does', async () => {
    observedQueues.alpha = [inSync('v-old'), inSync('v-new')];
    // The site holds a different source than the library, and the lockfile has
    // no entry for it, so this is a first apply (Unknown) rather than drift.
    await run('--site', 'alpha', '--yes');
    expect(vi.mocked(uploadComponents).mock.calls[0][0]).toStrictEqual([
      expect.objectContaining({ machineName: 'hero', operation: 'update' }),
    ]);

    vi.mocked(uploadComponents).mockClear();
    fs.rmSync(path.join(root, 'canvas.lock.json'));
    observedQueues.beta = [EMPTY, inSync()];
    await run('--site', 'beta', '--yes');
    expect(vi.mocked(uploadComponents).mock.calls[0][0]).toStrictEqual([
      expect.objectContaining({ machineName: 'hero', operation: 'create' }),
    ]);
  });

  it('requires an explicit target', async () => {
    await run('--yes');
    expect(process.exitCode).toBe(1);
    expect(p.log.error).toHaveBeenCalledWith(
      expect.stringContaining('--site, --group, or --all'),
    );
    expect(uploadComponents).not.toHaveBeenCalled();
  });

  it('captures a changeset before touching a site', async () => {
    observedQueues.alpha = [inSync('v-old'), inSync('v-new')];
    await run('--site', 'alpha', '--yes');

    const dir = path.join(root, '.canvas', 'changesets');
    const files = fs.readdirSync(dir);
    expect(files).toHaveLength(1);
    const changeset = JSON.parse(
      fs.readFileSync(path.join(dir, files[0]), 'utf-8'),
    ) as {
      site: string;
      components: Record<string, { present: boolean; version?: string }>;
    };
    expect(changeset.site).toBe('alpha');
    expect(changeset.components.hero).toMatchObject({
      present: true,
      version: 'v-old',
    });
  });

  it('records components the apply created as absent in the changeset', async () => {
    await run('--site', 'alpha', '--yes');
    const dir = path.join(root, '.canvas', 'changesets');
    const changeset = JSON.parse(
      fs.readFileSync(path.join(dir, fs.readdirSync(dir)[0]), 'utf-8'),
    ) as { components: Record<string, { present: boolean }> };
    expect(changeset.components.hero.present).toBe(false);
  });

  it('skips a diverged component instead of clobbering it', async () => {
    await run('--site', 'alpha', '--yes');
    vi.mocked(uploadComponents).mockClear();

    // Somebody edited the component in the site UI since that apply.
    observedQueues.alpha = [
      {
        hero: {
          sourceHash: 'edited-on-site',
          versionHash: 'v-hero',
          payload: HERO,
        },
      },
    ];
    await run('--site', 'alpha', '--yes');

    expect(uploadComponents).not.toHaveBeenCalled();
    expect(p.log.warn).toHaveBeenCalledWith(
      expect.stringContaining('skipped hero (diverged)'),
    );
    // The drift is recorded so the next plan still sees it.
    expect(readLock(root).sites.alpha.components.hero.observedSourceHash).toBe(
      'edited-on-site',
    );
  });

  it('writes nothing when every component is already in sync', async () => {
    await run('--site', 'alpha', '--yes');
    vi.mocked(uploadComponents).mockClear();

    observedQueues.alpha = [inSync()];
    await run('--site', 'alpha', '--yes');

    expect(uploadComponents).not.toHaveBeenCalled();
    expect(process.exitCode).toBeUndefined();
  });

  it('stops after a failing site and names the untouched ones', async () => {
    vi.mocked(uploadComponents).mockImplementation(async (tasks) =>
      tasks.map((task) => ({
        machineName: task.machineName,
        success: false,
        operation: task.operation,
        error: new Error('backend exploded'),
      })),
    );

    await run('--group', 'wave-1', '--parallelism', '1', '--yes');

    expect(process.exitCode).toBe(1);
    expect(uploadComponents).toHaveBeenCalledTimes(1);
    expect(p.log.warn).toHaveBeenCalledWith(
      expect.stringContaining('Untouched: beta'),
    );
    expect(readLock(root).sites.beta).toBeUndefined();
  });

  it('keeps going with --on-error continue and still fails the run', async () => {
    vi.mocked(createSiteApiService).mockImplementation(async (siteName) => {
      if (siteName === 'alpha') {
        throw new Error('site unreachable');
      }
      return {
        siteName,
        signalPushStart: vi.fn(),
        signalPushComplete: vi.fn(),
        signalPushFail: vi.fn(),
      } as never;
    });

    await run(
      '--group',
      'wave-1',
      '--parallelism',
      '1',
      '--on-error',
      'continue',
      '--yes',
    );

    expect(process.exitCode).toBe(1);
    const lock = readLock(root);
    expect(lock.sites.alpha).toBeUndefined();
    expect(lock.sites.beta).toBeDefined();
  });

  it('refuses a protected group outside CI without the override', async () => {
    await run('--group', 'prod', '--yes');
    expect(process.exitCode).toBe(1);
    expect(p.log.error).toHaveBeenCalledWith(
      expect.stringContaining('protected group (prod)'),
    );
    expect(uploadComponents).not.toHaveBeenCalled();
  });

  it('allows a protected group with the override, loudly', async () => {
    await run('--group', 'prod', '--yes', '--i-know-what-i-am-doing');
    expect(uploadComponents).toHaveBeenCalledTimes(1);
    expect(p.log.warn).toHaveBeenCalledWith(
      expect.stringContaining('protected group(s) prod'),
    );
  });

  it('allows a protected group in CI without the override', async () => {
    process.env.CI = 'true';
    await run('--group', 'prod', '--yes');
    expect(uploadComponents).toHaveBeenCalledTimes(1);
  });

  it('refuses --to for a version the checkout does not declare', async () => {
    await run('--site', 'alpha', '--to', '3.3.0', '--yes');
    expect(process.exitCode).toBe(1);
    expect(p.log.error).toHaveBeenCalledWith(
      expect.stringContaining('check out the ref for 3.3.0'),
    );
  });

  it('warns that re-pushing older source does not revert instance pins', async () => {
    await run('--site', 'alpha', '--yes');
    writeFleetFiles();
    fs.writeFileSync(
      path.join(root, 'canvas.library.json'),
      JSON.stringify({
        name: '@acme/canvas-library',
        version: '3.3.0',
        components: ['hero'],
      }),
      'utf-8',
    );
    observedQueues.alpha = [inSync(), inSync()];

    await run('--site', 'alpha', '--to', '3.3.0', '--yes');
    expect(p.log.warn).toHaveBeenCalledWith(
      expect.stringContaining('are NOT reverted'),
    );
  });

  it('emits machine-readable output for CI', async () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => {});
    await run('--group', 'wave-1', '--json');
    const payload = JSON.parse(log.mock.calls.at(-1)![0] as string) as {
      version: string;
      outcomes: { site: string; success: boolean; pushed: string[] }[];
    };
    expect(payload.version).toBe('3.4.0');
    expect(payload.outcomes.map((outcome) => outcome.site)).toStrictEqual([
      'alpha',
      'beta',
    ]);
    expect(payload.outcomes.every((outcome) => outcome.success)).toBe(true);
    log.mockRestore();
  });

  it('fails cleanly when a site declares credentials that are not set', async () => {
    delete process.env.ALPHA_CREDENTIALS;
    await run('--site', 'alpha', '--yes');
    expect(process.exitCode).toBe(1);
    expect(p.log.message).toHaveBeenCalledWith(
      expect.stringContaining('$ALPHA_CREDENTIALS'),
    );
  });
});

describe('compareVersions', () => {
  it('orders dotted numeric versions', () => {
    expect(compareVersions('3.4.0', '3.3.0')).toBe(1);
    expect(compareVersions('3.3.0', '3.4.0')).toBe(-1);
    expect(compareVersions('3.4.0', '3.4.0')).toBe(0);
    expect(compareVersions('3.4.1', '3.4')).toBe(1);
  });
});
