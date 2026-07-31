import { Command } from 'commander';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import { getConfig } from '../config';
import { buildCanvasProject } from '../utils/build-project';
import { buildCommand } from './build';

import type { DiscoveryResult } from '@drupal-canvas/discovery';
import type { CanvasProjectBuildResult } from '../utils/build-project';

const clackMockState = vi.hoisted(() => ({
  spinners: [] as Array<{
    start: (message?: string, code?: number) => void;
    stop: (message?: string, code?: number) => void;
    message: (message?: string) => void;
  }>,
}));

vi.mock('@clack/prompts', () => ({
  intro: vi.fn(),
  outro: vi.fn(),
  spinner: vi.fn(() => {
    const spinner = {
      start: vi.fn(),
      stop: vi.fn(),
      message: vi.fn(),
    };
    clackMockState.spinners.push(spinner);
    return spinner;
  }),
  log: {
    info: vi.fn(),
    message: vi.fn(),
    warn: vi.fn(),
  },
}));

vi.mock('@drupal-canvas/discovery', () => ({
  discoverCanvasProject: vi.fn(),
}));

vi.mock('../config', () => ({
  getConfig: vi.fn(),
}));

vi.mock('../utils/command-helpers', () => ({
  pluralize: vi.fn((count: number, singular: string, plural?: string) =>
    count === 1 ? singular : (plural ?? `${singular}s`),
  ),
  updateConfigFromOptions: vi.fn(),
}));

vi.mock('../utils/build-project', () => ({
  buildCanvasProject: vi.fn(),
}));

function makeProgram(): Command {
  const program = new Command();
  program.exitOverride();
  buildCommand(program);
  return program;
}

describe('buildCommand', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    clackMockState.spinners.length = 0;
    process.exitCode = undefined;

    vi.mocked(getConfig).mockReturnValue({
      aliasBaseDir: 'src',
      clientId: '',
      clientSecret: '',
      componentDir: 'src/components',
      contentTemplatesDir: 'content-templates',
      globalCssPath: 'src/global.css',
      includeBrandKit: false,
      includeContentTemplates: false,
      includePages: false,
      includeRegions: false,
      layoutPath: 'src/layout.jsx',
      outputDir: 'dist',
      pagesDir: 'pages',
      regionsDir: 'regions',
      scope: '',
      siteUrl: '',
      userAgent: '',
    });
  });

  it('reports all component validation failures before failing the build', async () => {
    const discoveryResult = {
      componentRoot: 'src/components',
      projectRoot: process.cwd(),
      components: [
        {
          id: 'card',
          kind: 'index',
          name: 'card',
          directory: 'src/components/card',
          relativeDirectory: 'src/components/card',
          projectRelativeDirectory: 'src/components/card',
          metadataPath: 'src/components/card/component.yml',
          jsEntryPath: 'src/components/card/index.tsx',
          cssEntryPath: null,
        },
        {
          id: 'hero',
          kind: 'index',
          name: 'hero',
          directory: 'src/components/hero',
          relativeDirectory: 'src/components/hero',
          projectRelativeDirectory: 'src/components/hero',
          metadataPath: 'src/components/hero/component.yml',
          jsEntryPath: 'src/components/hero/index.tsx',
          cssEntryPath: null,
        },
      ],
      pages: [],
      contentTemplates: [],
      regions: [],
      warnings: [],
      stats: {
        scannedFiles: 2,
        ignoredFiles: 0,
      },
    } as DiscoveryResult;
    vi.mocked(discoverCanvasProject).mockResolvedValue(discoveryResult);
    vi.mocked(buildCanvasProject).mockResolvedValue({
      discoveryResult,
      componentResults: [
        {
          itemName: 'card',
          success: false,
          details: [
            {
              heading: 'src/components/card/index.tsx',
              content:
                'Line 1, Column 1: Component must have a default export.',
            },
          ],
        },
        {
          itemName: 'hero',
          success: false,
          details: [
            {
              heading: 'src/components/hero/index.tsx',
              content: 'Line 4, Column 7: Imports must be relative.',
            },
          ],
        },
      ],
      builtComponents: [],
      manifest: {
        vendor: {},
        local: {},
        shared: [],
      },
      manifestPath: 'dist/canvas-manifest.json',
      artifactCount: 0,
      vendorImportCount: 0,
      localImportCount: 0,
      tailwindResult: {
        itemName: 'Tailwind CSS',
        itemType: 'Asset',
        success: true,
      },
      siteProvidedPackages: [],
      siteImportsVerified: false,
    } satisfies CanvasProjectBuildResult);

    await makeProgram().parseAsync(['node', 'canvas', 'build']);

    expect(process.exitCode).toBe(1);
    expect(p.log.message).toHaveBeenCalledWith(
      expect.stringContaining('src/components/card/index.tsx'),
    );
    expect(p.log.message).toHaveBeenCalledWith(
      expect.stringContaining('Component must have a default export.'),
    );
    expect(p.log.message).toHaveBeenCalledWith(
      expect.stringContaining('src/components/hero/index.tsx'),
    );
    expect(p.log.message).toHaveBeenCalledWith(
      expect.stringContaining('Imports must be relative.'),
    );
    expect(clackMockState.spinners[1]?.stop).toHaveBeenCalledWith(
      'Build failed',
      2,
    );
    expect(p.outro).toHaveBeenCalledWith('Build failed');
  });
});
