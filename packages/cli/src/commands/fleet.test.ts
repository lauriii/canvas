import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { Command } from 'commander';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import { writeChangeset } from '../lib/fleet';
import { createSiteApiService } from '../lib/fleet-site';
import {
  changesetCommand,
  defaultCredentialsEnv,
  fleetCommand,
  libraryCommand,
} from './fleet';

import type { Changeset, FleetFile, LibraryFile } from '../lib/fleet';

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
  discoverCanvasProject: vi.fn(async () => ({
    components: [{ name: 'hero' }, { name: 'card' }],
    warnings: [],
  })),
}));

vi.mock('../config', () => ({
  getConfig: vi.fn(() => ({
    componentDir: 'src/components',
    userAgent: '',
    includeBrandKit: false,
  })),
  getDefaultScope: vi.fn(() => 'scope'),
}));

vi.mock('../lib/fleet-site', () => ({ createSiteApiService: vi.fn() }));

let root: string;
let previousCwd: string;

async function run(...args: string[]): Promise<void> {
  const program = new Command();
  program.exitOverride();
  libraryCommand(program);
  fleetCommand(program);
  changesetCommand(program);
  await program.parseAsync(['node', 'canvas', ...args]);
}

function readJson<T>(name: string): T {
  return JSON.parse(fs.readFileSync(path.join(root, name), 'utf-8')) as T;
}

describe('fleet scaffolding commands', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.exitCode = undefined;
    previousCwd = process.cwd();
    root = fs.mkdtempSync(path.join(os.tmpdir(), 'canvas-fleet-cmd-'));
    process.chdir(root);
  });

  afterEach(() => {
    process.chdir(previousCwd);
    fs.rmSync(root, { recursive: true, force: true });
  });

  it('scaffolds a library manifest from the discovered components', async () => {
    await run('library', 'init', '--name', '@acme/lib');
    const library = readJson<LibraryFile>('canvas.library.json');
    expect(library).toMatchObject({
      name: '@acme/lib',
      version: '0.1.0',
      components: ['card', 'hero'],
      includes: { globalCss: true, brandKit: false },
    });
    expect(library.engines?.canvasCli).toMatch(/^>=\d/);
    expect(discoverCanvasProject).toHaveBeenCalled();
  });

  it('refuses to overwrite an existing library manifest without --force', async () => {
    await run('library', 'init');
    const before = fs.readFileSync(
      path.join(root, 'canvas.library.json'),
      'utf-8',
    );
    await run('library', 'init', '--name', '@acme/other');
    expect(process.exitCode).toBe(1);
    expect(
      fs.readFileSync(path.join(root, 'canvas.library.json'), 'utf-8'),
    ).toBe(before);

    process.exitCode = undefined;
    await run('library', 'init', '--name', '@acme/other', '--force');
    expect(readJson<LibraryFile>('canvas.library.json').name).toBe(
      '@acme/other',
    );
  });

  it('scaffolds an empty inventory and adds sites to groups', async () => {
    await run('fleet', 'init');
    expect(readJson<FleetFile>('canvas.fleet.json')).toStrictEqual({
      groups: {},
      sites: {},
    });

    await run(
      'fleet',
      'add',
      'marketing-eu',
      '--url',
      'https://marketing-eu.example.com',
      '--group',
      'canary',
      '--group',
      'europe',
    );
    expect(readJson<FleetFile>('canvas.fleet.json')).toStrictEqual({
      groups: { canary: ['marketing-eu'], europe: ['marketing-eu'] },
      sites: {
        'marketing-eu': {
          url: 'https://marketing-eu.example.com',
          credentialsEnv: 'CANVAS_OAUTH_MARKETING_EU',
        },
      },
    });
  });

  it('never writes a secret, only the name of the variable holding it', async () => {
    await run('fleet', 'init');
    await run(
      'fleet',
      'add',
      'brand-main',
      '--url',
      'https://brand-main.example.com',
      '--credentials-env',
      'MY_CREDENTIALS',
    );
    const raw = fs.readFileSync(path.join(root, 'canvas.fleet.json'), 'utf-8');
    expect(raw).toContain('MY_CREDENTIALS');
    expect(raw).not.toMatch(/client_secret|:.*secret/);
  });

  it('refuses to add a site that already exists', async () => {
    await run('fleet', 'init');
    await run('fleet', 'add', 'alpha', '--url', 'https://a.example.com');
    await run('fleet', 'add', 'alpha', '--url', 'https://b.example.com');
    expect(process.exitCode).toBe(1);
    expect(readJson<FleetFile>('canvas.fleet.json').sites.alpha.url).toBe(
      'https://a.example.com',
    );
  });

  it('lists sites with their groups and locked versions', async () => {
    fs.writeFileSync(
      path.join(root, 'canvas.fleet.json'),
      JSON.stringify({
        groups: { prod: ['alpha'] },
        sites: {
          alpha: { url: 'https://a.example.com' },
          beta: { url: 'https://b.example.com' },
        },
      }),
      'utf-8',
    );
    fs.writeFileSync(
      path.join(root, 'canvas.lock.json'),
      JSON.stringify({
        lockfileVersion: 1,
        generatedAt: 'now',
        sites: {
          alpha: {
            libraryVersion: '3.4.0',
            appliedAt: 'then',
            appliedBy: 'ci',
            lastRefresh: 'then',
            components: {},
          },
        },
      }),
      'utf-8',
    );

    const log = vi.spyOn(console, 'log').mockImplementation(() => {});
    await run('fleet', 'list', '--json');
    expect(JSON.parse(log.mock.calls.at(-1)![0] as string)).toStrictEqual({
      sites: [
        {
          name: 'alpha',
          url: 'https://a.example.com',
          groups: ['prod'],
          libraryVersion: '3.4.0',
          lastRefresh: 'then',
        },
        {
          name: 'beta',
          url: 'https://b.example.com',
          groups: [],
        },
      ],
    });
    log.mockRestore();
  });

  it('derives a conventional credentials variable name', () => {
    expect(defaultCredentialsEnv('marketing-eu')).toBe(
      'CANVAS_OAUTH_MARKETING_EU',
    );
    expect(defaultCredentialsEnv('brand.main 2')).toBe(
      'CANVAS_OAUTH_BRAND_MAIN_2',
    );
  });
});

describe('changeset commands', () => {
  const changeset: Changeset = {
    id: '2026-08-05T10-22-00-000Z-alpha',
    site: 'alpha',
    siteUrl: 'https://a.example.com',
    capturedAt: '2026-08-05T10:22:00Z',
    libraryVersion: '3.4.0',
    components: {
      hero: {
        present: true,
        version: 'v0',
        payload: {
          machineName: 'hero',
          sourceCodeJs:
            "import Button from '@/components/button';\nexport default Button;",
        } as Changeset['components'][string]['payload'],
      },
      cta: { present: false },
    },
  };

  beforeEach(() => {
    vi.clearAllMocks();
    process.exitCode = undefined;
    previousCwd = process.cwd();
    root = fs.mkdtempSync(path.join(os.tmpdir(), 'canvas-changeset-'));
    process.chdir(root);
    fs.writeFileSync(
      path.join(root, 'canvas.fleet.json'),
      JSON.stringify({ sites: { alpha: { url: 'https://a.example.com' } } }),
      'utf-8',
    );
    writeChangeset(changeset, root);
  });

  afterEach(() => {
    process.chdir(previousCwd);
    fs.rmSync(root, { recursive: true, force: true });
  });

  it('lists captured changesets', async () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => {});
    await run('changeset', 'list', '--json');
    expect(JSON.parse(log.mock.calls.at(-1)![0] as string)).toStrictEqual({
      changesets: [changeset.id],
    });
    log.mockRestore();
  });

  it('restores captured components and refuses to delete ones the apply created', async () => {
    const updateComponent = vi.fn();
    vi.mocked(createSiteApiService).mockResolvedValue({
      updateComponent,
    } as never);

    await run('changeset', 'restore', changeset.id, '--yes');

    expect(updateComponent).toHaveBeenCalledTimes(1);
    // The API omits `importedJsComponents` on read but requires it on write, so
    // restore has to derive it from the captured source.
    expect(updateComponent).toHaveBeenCalledWith('hero', {
      machineName: 'hero',
      sourceCodeJs:
        "import Button from '@/components/button';\nexport default Button;",
      importedJsComponents: ['button'],
    });
    expect(p.log.warn).toHaveBeenCalledWith(expect.stringContaining('cta'));
    expect(process.exitCode).toBeUndefined();
  });

  it('fails when the changeset targets a site no longer in the inventory', async () => {
    fs.writeFileSync(
      path.join(root, 'canvas.fleet.json'),
      JSON.stringify({ sites: {} }),
      'utf-8',
    );
    await run('changeset', 'restore', changeset.id, '--yes');
    expect(process.exitCode).toBe(1);
    expect(createSiteApiService).not.toHaveBeenCalled();
  });
});
