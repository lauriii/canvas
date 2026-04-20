import { promises as fs } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import {
  discoverCanvasProject,
  resolveCanvasConfig,
} from '@drupal-canvas/discovery';
import { extractComponentPreviewMetadataFromComponentYaml } from '@drupal-canvas/vite-compat';

import { isSupportedPreviewModulePath } from '../lib/preview-runtime';
import { toPreviewManifestComponentMocks } from '../lib/spec-discovery';
import {
  buildIframeHtml,
  buildPreviewPayload,
  bundleInteractivePreview,
  formatComponentPathConstraintMessage,
  resolvePreviewRuntimeSettings,
} from './preview-payload';

import type { DiscoveryResult } from '@drupal-canvas/discovery';
import type {
  PreviewManifestComponent,
  PreviewManifestComponentMock,
  PreviewWarning,
} from '../lib/preview-contract';
import type {
  PreviewIssue,
  PreviewMode,
  PreviewRequest,
  PreviewTarget,
} from './preview-payload';

export interface PreviewBuildCliArgs {
  mode: 'component' | 'page';
  inputPath: string;
  pretty: boolean;
  outDir: string;
}

export interface PreviewBuildSummary {
  generatedHtmlCount: number;
  mockCount: number;
}

export interface PreviewBuildPayload {
  ok: boolean;
  request: PreviewRequest;
  target: PreviewTarget | null;
  renderMode: 'interactive';
  outDir: string | null;
  manifestPath: string | null;
  summary: PreviewBuildSummary | null;
  warnings: PreviewIssue[];
  errors: PreviewIssue[];
}

interface BuildPreviewArtifactOptions {
  mode: 'component' | 'page';
  inputPath: string;
  projectRoot: string;
  outDir: string;
}

interface BuildPreviewArtifactDependencies {
  buildPreviewPayload?: typeof buildPreviewPayload;
  discover?: typeof discoverCanvasProject;
  resolveConfig?: typeof resolveCanvasConfig;
  extractMetadata?: typeof extractComponentPreviewMetadataFromComponentYaml;
  bundleInteractivePreview?: typeof bundleInteractivePreview;
}

interface BundleComponentSource {
  name: string;
  jsEntryPath: string;
}

interface ManifestEntry {
  path: string;
  label: string;
}

interface PreviewBuildManifestTarget {
  id: string;
  name: string;
  projectRelativePath: string;
  hasMocks: boolean;
  mockCount: number;
}

interface PreviewBuildComponentManifest {
  version: 1;
  targetType: 'component';
  target: PreviewBuildManifestTarget;
  entries: {
    default: ManifestEntry;
    mocks: ManifestEntry[];
  };
}

interface PreviewBuildPageManifest {
  version: 1;
  targetType: 'page';
  target: PreviewBuildManifestTarget;
  entries: {
    default: ManifestEntry;
  };
}

type PreviewBuildManifest =
  | PreviewBuildComponentManifest
  | PreviewBuildPageManifest;

interface PreparedStagedHtmlFile {
  fileName: string;
  content: string;
}

function toIssue(
  code: string,
  message: string,
  issuePath?: string,
): PreviewIssue {
  return {
    code,
    message,
    ...(issuePath ? { path: issuePath } : {}),
  };
}

function toPayload(
  request: PreviewRequest,
  options: {
    ok: boolean;
    target?: PreviewTarget | null;
    outDir?: string | null;
    manifestPath?: string | null;
    summary?: PreviewBuildSummary | null;
    warnings?: PreviewIssue[];
    errors?: PreviewIssue[];
  },
): PreviewBuildPayload {
  return {
    ok: options.ok,
    request,
    target: options.target ?? null,
    renderMode: 'interactive',
    outDir: options.outDir ?? null,
    manifestPath: options.manifestPath ?? null,
    summary: options.summary ?? null,
    warnings: options.warnings ?? [],
    errors: options.errors ?? [],
  };
}

function parsePreviewBuildArgsError(message: string): {
  ok: false;
  error: string;
} {
  return {
    ok: false,
    error: message,
  };
}

interface ParsedPreviewTargetArgs {
  mode: PreviewMode;
  inputPath: string;
  pretty: boolean;
}

function parsePreviewTargetArgs(
  argv: string[],
): { ok: true; value: ParsedPreviewTargetArgs } | { ok: false; error: string } {
  let componentPath: string | null = null;
  let pagePath: string | null = null;
  let pretty = false;

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];

    if (argument === '--component-path') {
      const next = argv[index + 1];
      if (!next || next.startsWith('--')) {
        return parsePreviewBuildArgsError(
          'Missing value for --component-path.',
        );
      }

      componentPath = next;
      index += 1;
      continue;
    }

    if (argument === '--page-path') {
      const next = argv[index + 1];
      if (!next || next.startsWith('--')) {
        return parsePreviewBuildArgsError('Missing value for --page-path.');
      }

      pagePath = next;
      index += 1;
      continue;
    }

    if (argument === '--pretty') {
      pretty = true;
      continue;
    }

    return parsePreviewBuildArgsError(`Unknown argument: ${argument}`);
  }

  const selectedCount =
    Number(componentPath !== null) + Number(pagePath !== null);
  if (selectedCount !== 1) {
    return parsePreviewBuildArgsError(
      'Provide exactly one selector: --component-path <path> or --page-path <path>.',
    );
  }

  if (componentPath) {
    return {
      ok: true,
      value: {
        mode: 'component',
        inputPath: componentPath,
        pretty,
      },
    };
  }

  return {
    ok: true,
    value: {
      mode: 'page',
      inputPath: pagePath!,
      pretty,
    },
  };
}

export function parsePreviewBuildArgs(
  argv: string[],
): { ok: true; value: PreviewBuildCliArgs } | { ok: false; error: string } {
  const forwardedArgs: string[] = [];
  let outDir: string | null = null;

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];

    if (argument === '--out-dir') {
      const next = argv[index + 1];
      if (!next || next.startsWith('--')) {
        return parsePreviewBuildArgsError('Missing value for --out-dir.');
      }

      outDir = next;
      index += 1;
      continue;
    }

    forwardedArgs.push(argument);
  }

  if (!outDir) {
    return parsePreviewBuildArgsError(
      'Missing required argument: --out-dir <path>.',
    );
  }

  const parsed = parsePreviewTargetArgs(forwardedArgs);
  if (!parsed.ok) {
    return parsePreviewBuildArgsError(parsed.error);
  }

  return {
    ok: true,
    value: {
      mode: parsed.value.mode,
      inputPath: parsed.value.inputPath,
      pretty: parsed.value.pretty,
      outDir,
    },
  };
}

export function extractInlineScript(
  html: string,
  attributeMarker: string,
): string | null {
  const pattern = new RegExp(
    `<script[^>]*${attributeMarker}[^>]*>([\\s\\S]*?)<\\/script>`,
  );
  const match = html.match(pattern);
  if (!match) {
    return null;
  }

  return match[1] ?? null;
}

function isSamePath(left: string, right: string): boolean {
  return path.resolve(left) === path.resolve(right);
}

function dedupeIssues(issues: PreviewIssue[]): PreviewIssue[] {
  const deduped = new Map<string, PreviewIssue>();

  issues.forEach((issue) => {
    const key = `${issue.code}|${issue.message}|${issue.path ?? ''}`;
    if (!deduped.has(key)) {
      deduped.set(key, issue);
    }
  });

  return [...deduped.values()];
}

function toPreviewIssue(warning: PreviewWarning): PreviewIssue {
  return {
    code: warning.code,
    message: warning.message,
    ...(warning.path ? { path: warning.path } : {}),
  };
}

function toExpectedMockPaths(component: PreviewManifestComponent): string[] {
  const componentDirectory = path.dirname(component.metadataPath);
  const metadataFilename = path.basename(component.metadataPath);

  if (metadataFilename === 'component.yml') {
    return [path.join(componentDirectory, 'mocks.json')];
  }

  const namedComponentBase = metadataFilename.replace(/\.component\.yml$/, '');
  return [path.join(componentDirectory, `${namedComponentBase}.mocks.json`)];
}

function toPreviewableRegistrySources(
  discoveryResult: DiscoveryResult,
): BundleComponentSource[] {
  return discoveryResult.components
    .filter(
      (
        component,
      ): component is DiscoveryResult['components'][number] & {
        jsEntryPath: string;
      } => component.jsEntryPath !== null,
    )
    .filter((component) => isSupportedPreviewModulePath(component.jsEntryPath))
    .map((component) => ({
      name: component.name,
      jsEntryPath: component.jsEntryPath,
    }));
}

async function maybeResolveGlobalCssPath(
  projectRoot: string,
  globalCssPath: string,
): Promise<string | null> {
  const absoluteGlobalCssPath = path.resolve(projectRoot, globalCssPath);

  try {
    await fs.access(absoluteGlobalCssPath);
    return absoluteGlobalCssPath;
  } catch {
    return null;
  }
}

function toPortableManifestPath(relativePath: string): string {
  return relativePath.split(/[/\\]+/).join('/');
}

function createComponentManifestEntry(
  fileName: string,
  label: string,
): ManifestEntry {
  return {
    path: toPortableManifestPath(fileName),
    label,
  };
}

function createPageManifestEntry(fileName: string): ManifestEntry {
  return {
    path: toPortableManifestPath(fileName),
    label: 'Default',
  };
}

async function createStagingDirectory(
  outputDirectory: string,
): Promise<string> {
  const outputParent = path.dirname(outputDirectory);
  await fs.mkdir(outputParent, { recursive: true });

  return fs.mkdtemp(
    path.join(outputParent, `${path.basename(outputDirectory)}.tmp-`),
  );
}

async function publishAtomically(
  stagingDirectory: string,
  outputDirectory: string,
): Promise<void> {
  const backupDirectory = `${outputDirectory}.backup-${Date.now()}-${Math.random()
    .toString(16)
    .slice(2)}`;
  let hadExistingOutput = false;

  try {
    await fs.access(outputDirectory);
    hadExistingOutput = true;
  } catch {
    hadExistingOutput = false;
  }

  if (hadExistingOutput) {
    await fs.rename(outputDirectory, backupDirectory);
  }

  try {
    await fs.rename(stagingDirectory, outputDirectory);
  } catch (error) {
    if (hadExistingOutput) {
      await fs
        .rename(backupDirectory, outputDirectory)
        .catch(() => Promise.resolve());
    }
    throw error;
  }

  if (hadExistingOutput) {
    await fs.rm(backupDirectory, { recursive: true, force: true });
  }
}

async function loadComponentMocks(options: {
  component: PreviewManifestComponent;
  discoveryResult: DiscoveryResult;
  componentExampleProps: Record<string, unknown>;
  componentRequiredPropNames: string[];
}): Promise<{
  mocks: PreviewManifestComponentMock[];
  warnings: PreviewIssue[];
}> {
  const expectedMockPaths = toExpectedMockPaths(options.component);
  const warnings: PreviewIssue[] = [];
  const mocks: PreviewManifestComponentMock[] = [];

  for (const mockPath of expectedMockPaths) {
    let fileContent: string;
    try {
      fileContent = await fs.readFile(mockPath, 'utf-8');
    } catch {
      continue;
    }

    let parsedJson: unknown;
    try {
      parsedJson = JSON.parse(fileContent);
    } catch {
      warnings.push(
        toIssue(
          'invalid_mock_json',
          `Failed to parse mock JSON file: ${mockPath}`,
        ),
      );
      continue;
    }

    const parsed = toPreviewManifestComponentMocks(parsedJson, {
      sourcePath: mockPath,
      componentRoot: options.discoveryResult.componentRoot,
      componentName: options.component.name,
      componentNames: options.discoveryResult.components.map(
        (component) => component.name,
      ),
      componentExampleProps: options.componentExampleProps,
      componentRequiredPropNames: options.componentRequiredPropNames,
    });

    warnings.push(...parsed.warnings.map((warning) => toPreviewIssue(warning)));
    mocks.push(...parsed.mocks);
  }

  return {
    mocks,
    warnings,
  };
}

function createManifest(options: {
  mode: 'component' | 'page';
  target: PreviewTarget;
  defaultEntry: ManifestEntry;
  mockEntries: ManifestEntry[];
  mockCount: number;
}): PreviewBuildManifest {
  const hasMocks = options.mode === 'component' && options.mockCount > 0;
  const target = {
    id: options.target.id,
    name: options.target.name,
    projectRelativePath: options.target.projectRelativePath,
    hasMocks,
    mockCount: options.mockCount,
  };

  if (options.mode === 'component') {
    return {
      version: 1,
      targetType: 'component',
      target,
      entries: {
        default: options.defaultEntry,
        mocks: options.mockEntries,
      },
    };
  }

  return {
    version: 1,
    targetType: 'page',
    target,
    entries: {
      default: options.defaultEntry,
    },
  };
}

export async function buildPreviewArtifact(
  options: BuildPreviewArtifactOptions,
  dependencies: BuildPreviewArtifactDependencies = {},
): Promise<PreviewBuildPayload> {
  const request: PreviewRequest = {
    mode: options.mode,
    inputPath: options.inputPath,
    projectRoot: options.projectRoot,
  };
  const buildPayload = dependencies.buildPreviewPayload ?? buildPreviewPayload;
  const discover = dependencies.discover ?? discoverCanvasProject;
  const resolveConfig = dependencies.resolveConfig ?? resolveCanvasConfig;
  const extractMetadata =
    dependencies.extractMetadata ??
    extractComponentPreviewMetadataFromComponentYaml;
  const bundle =
    dependencies.bundleInteractivePreview ?? bundleInteractivePreview;
  const outputDirectory = path.resolve(options.projectRoot, options.outDir);
  const combinedWarnings: PreviewIssue[] = [];
  const runtimeSettings = resolvePreviewRuntimeSettings(options.projectRoot);
  let stagingDirectory: string | null = null;

  const previewPayload = await buildPayload({
    mode: options.mode,
    inputPath: options.inputPath,
    projectRoot: options.projectRoot,
  });

  if (!previewPayload.ok) {
    return toPayload(request, {
      ok: false,
      target: previewPayload.target,
      outDir: outputDirectory,
      warnings: previewPayload.warnings,
      errors: previewPayload.errors,
    });
  }

  combinedWarnings.push(...previewPayload.warnings);

  if (!previewPayload.iframeHtml) {
    return toPayload(request, {
      ok: false,
      target: previewPayload.target,
      outDir: outputDirectory,
      warnings: dedupeIssues(combinedWarnings),
      errors: [
        ...previewPayload.errors,
        toIssue(
          'artifact_build_failed',
          'Preview data payload did not include iframeHtml.',
        ),
      ],
    });
  }

  const runtimeScript = extractInlineScript(
    previewPayload.iframeHtml,
    'data-canvas-preview-runtime',
  );
  if (!runtimeScript) {
    return toPayload(request, {
      ok: false,
      target: previewPayload.target,
      outDir: outputDirectory,
      warnings: dedupeIssues(combinedWarnings),
      errors: [
        ...previewPayload.errors,
        toIssue(
          'artifact_build_failed',
          'Missing runtime script in preview iframe HTML.',
        ),
      ],
    });
  }

  if (!previewPayload.target) {
    return toPayload(request, {
      ok: false,
      outDir: outputDirectory,
      warnings: dedupeIssues(combinedWarnings),
      errors: [
        toIssue(
          'artifact_build_failed',
          'Preview data payload did not include a target.',
        ),
      ],
    });
  }

  const stagedHtmlFiles: PreparedStagedHtmlFile[] = [];
  let defaultManifestEntry: ManifestEntry | null = null;
  const mockManifestEntries: ManifestEntry[] = [];
  let mockCount = 0;

  try {
    stagingDirectory = await createStagingDirectory(outputDirectory);

    if (options.mode === 'component') {
      stagedHtmlFiles.push({
        fileName: 'component-default.html',
        content: previewPayload.iframeHtml,
      });
      defaultManifestEntry = createComponentManifestEntry(
        'component-default.html',
        'Default',
      );

      const config = resolveConfig({ hostRoot: options.projectRoot });
      const discoveryResult = await discover({
        componentRoot: path.resolve(options.projectRoot, config.componentDir),
        pagesRoot: path.resolve(options.projectRoot, config.pagesDir),
        projectRoot: options.projectRoot,
      });
      combinedWarnings.push(
        ...discoveryResult.warnings.map((warning) =>
          toIssue(warning.code, warning.message, warning.path),
        ),
      );

      const requestedPath = path.resolve(
        options.projectRoot,
        options.inputPath,
      );
      const selectedComponent = discoveryResult.components.find((candidate) =>
        isSamePath(candidate.metadataPath, requestedPath),
      );
      if (!selectedComponent) {
        throw new Error(
          formatComponentPathConstraintMessage({
            inputPath: options.inputPath,
            componentDir: config.componentDir,
          }),
        );
      }

      const selectedComponentForMocks: PreviewManifestComponent = {
        id: selectedComponent.id,
        name: selectedComponent.name,
        label: selectedComponent.name,
        relativeDirectory: selectedComponent.relativeDirectory,
        projectRelativeDirectory: selectedComponent.projectRelativeDirectory,
        metadataPath: selectedComponent.metadataPath,
        js: {
          entryPath: selectedComponent.jsEntryPath,
          url: null,
        },
        css: {
          entryPath: selectedComponent.cssEntryPath,
          url: null,
        },
        previewable: Boolean(selectedComponent.jsEntryPath),
        ineligibilityReason: null,
        exampleProps: {},
        mocks: [],
      };

      const componentMetadata = await extractMetadata(
        selectedComponent.metadataPath,
      );
      const loadedMocks = await loadComponentMocks({
        component: selectedComponentForMocks,
        discoveryResult,
        componentExampleProps: componentMetadata.exampleProps,
        componentRequiredPropNames: componentMetadata.requiredPropNames,
      });
      combinedWarnings.push(...loadedMocks.warnings);

      if (loadedMocks.mocks.length > 0) {
        const globalCssPath = await maybeResolveGlobalCssPath(
          options.projectRoot,
          config.globalCssPath,
        );
        const registrySources = toPreviewableRegistrySources(discoveryResult);
        const cssEntryPaths = [
          ...(globalCssPath ? [globalCssPath] : []),
          ...discoveryResult.components
            .filter(
              (
                component,
              ): component is DiscoveryResult['components'][number] & {
                cssEntryPath: string;
              } => component.cssEntryPath !== null,
            )
            .map((component) => component.cssEntryPath),
        ];
        const uniqueCssEntryPaths = [
          ...new Set(cssEntryPaths.map((entry) => path.resolve(entry))),
        ];

        for (
          let mockIndex = 0;
          mockIndex < loadedMocks.mocks.length;
          mockIndex += 1
        ) {
          const mock = loadedMocks.mocks[mockIndex];
          const bundleResult = await bundle({
            projectRoot: options.projectRoot,
            aliasBaseDir: config.aliasBaseDir,
            spec: mock.spec,
            componentSources: registrySources,
            cssEntryPaths: uniqueCssEntryPaths,
          });
          const mockHtml = buildIframeHtml(
            bundleResult.js,
            bundleResult.css,
            runtimeSettings,
          );
          const fileName = `component-mock-${String(mockIndex + 1).padStart(2, '0')}.html`;

          stagedHtmlFiles.push({
            fileName,
            content: mockHtml,
          });
          mockManifestEntries.push(
            createComponentManifestEntry(fileName, mock.label),
          );
        }
      }

      mockCount = loadedMocks.mocks.length;
    } else {
      stagedHtmlFiles.push({
        fileName: 'page-default.html',
        content: previewPayload.iframeHtml,
      });
      defaultManifestEntry = createPageManifestEntry('page-default.html');
    }

    await Promise.all(
      stagedHtmlFiles.map((file) =>
        fs.writeFile(
          path.join(stagingDirectory!, file.fileName),
          file.content,
          'utf-8',
        ),
      ),
    );

    if (!defaultManifestEntry) {
      throw new Error('Missing default preview manifest entry.');
    }

    const manifest = createManifest({
      mode: options.mode,
      target: previewPayload.target,
      defaultEntry: defaultManifestEntry,
      mockEntries: mockManifestEntries,
      mockCount,
    });
    const manifestPath = path.join(outputDirectory, 'manifest.json');
    await fs.writeFile(
      path.join(stagingDirectory, 'manifest.json'),
      JSON.stringify(manifest, null, 2),
      'utf-8',
    );

    await publishAtomically(stagingDirectory, outputDirectory);
    stagingDirectory = null;

    return toPayload(request, {
      ok: true,
      target: previewPayload.target,
      outDir: outputDirectory,
      manifestPath,
      summary: {
        generatedHtmlCount: stagedHtmlFiles.length,
        mockCount,
      },
      warnings: dedupeIssues(combinedWarnings),
      errors: [],
    });
  } catch (error) {
    if (stagingDirectory) {
      await fs.rm(stagingDirectory, { recursive: true, force: true });
    }

    return toPayload(request, {
      ok: false,
      target: previewPayload.target,
      outDir: outputDirectory,
      warnings: dedupeIssues(combinedWarnings),
      errors: [
        toIssue(
          'artifact_build_failed',
          error instanceof Error ? error.message : String(error),
        ),
      ],
    });
  }
}

export async function runPreviewBuildCli(argv: string[]): Promise<number> {
  const parsed = parsePreviewBuildArgs(argv);

  if (!parsed.ok) {
    const payload = toPayload(
      {
        mode: 'component',
        inputPath: '',
        projectRoot: process.cwd(),
      },
      {
        ok: false,
        errors: [toIssue('invalid_arguments', parsed.error)],
      },
    );
    process.stdout.write(`${JSON.stringify(payload)}\n`);
    return 1;
  }

  const payload = await buildPreviewArtifact({
    mode: parsed.value.mode,
    inputPath: parsed.value.inputPath,
    projectRoot: process.cwd(),
    outDir: parsed.value.outDir,
  });

  const serialized = parsed.value.pretty
    ? JSON.stringify(payload, null, 2)
    : JSON.stringify(payload);
  process.stdout.write(`${serialized}\n`);

  return payload.ok ? 0 : 1;
}

function isDirectExecution(): boolean {
  const executedPath = process.argv[1];
  if (!executedPath) {
    return false;
  }

  return import.meta.url === pathToFileURL(executedPath).href;
}

if (isDirectExecution()) {
  runPreviewBuildCli(process.argv.slice(2))
    .then((exitCode) => {
      process.exit(exitCode);
    })
    .catch((error: unknown) => {
      const payload = toPayload(
        {
          mode: 'component',
          inputPath: '',
          projectRoot: process.cwd(),
        },
        {
          ok: false,
          errors: [
            toIssue(
              'runtime_failure',
              error instanceof Error ? error.message : String(error),
            ),
          ],
        },
      );
      process.stdout.write(`${JSON.stringify(payload)}\n`);
      process.exit(1);
    });
}
