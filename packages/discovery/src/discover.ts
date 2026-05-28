import { createHash } from 'node:crypto';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { glob } from 'glob';
import ignore from 'ignore';

import { findDuplicateMachineNames, loadComponentsMetadata } from './metadata';

import type {
  DiscoveredComponent,
  DiscoveredContentTemplate,
  DiscoveredPage,
  DiscoveredRegion,
  DiscoveryOptions,
  DiscoveryResult,
  DiscoveryWarning,
} from './types';

export const JS_EXTENSIONS = ['.ts', '.tsx', '.js', '.jsx'] as const;
// @todo See if we can find better default AND make this configurable.
const ALWAYS_IGNORED_PATTERNS = [
  '**/node_modules/**',
  '**/dist/**',
  '**/.git/**',
  '**/.next/**',
  '**/.turbo/**',
  '**/coverage/**',
] as const;
const METADATA_PATTERNS = ['**/component.yml', '**/*.component.yml'] as const;
const NAMED_SUFFIX = '.component.yml';

// Normalize to POSIX-style separators for glob and ignore matching.
// Example: "components\\button\\component.yml" -> "components/button/component.yml".
function toPosixPath(value: string): string {
  return value.split(path.sep).join('/');
}

async function readGitignore(projectRoot: string) {
  const gitignorePath = path.join(projectRoot, '.gitignore');
  const matcher = ignore();

  try {
    const content = await fs.readFile(gitignorePath, 'utf-8');
    matcher.add(content);
  } catch {
    // No .gitignore in scan root.
  }

  return matcher;
}

async function fileExists(filePath: string): Promise<boolean> {
  try {
    await fs.access(filePath);
    return true;
  } catch {
    return false;
  }
}

// Creates a deterministic ID from the metadata file path.
// Example: createStableId('src/components/card/component.yml')
// -> '<40-char sha1 hex digest>'
function createStableId(metadataPath: string): string {
  return createHash('sha1').update(metadataPath).digest('hex');
}

async function getCandidateMetadataFiles(
  componentRoot: string,
): Promise<string[]> {
  const discovered = new Set<string>();

  for (const pattern of METADATA_PATTERNS) {
    const files = await glob(pattern, {
      cwd: componentRoot,
      nodir: true,
      dot: true,
      posix: true,
      ignore: [...ALWAYS_IGNORED_PATTERNS],
    });

    for (const file of files) {
      discovered.add(file);
    }
  }

  return [...discovered].sort();
}

async function getCandidatePageFiles(pagesRoot: string): Promise<string[]> {
  return glob('*.json', {
    cwd: pagesRoot,
    nodir: true,
    dot: true,
    posix: true,
    ignore: [...ALWAYS_IGNORED_PATTERNS],
  });
}

async function getCandidateContentTemplateFiles(
  contentTemplatesRoot: string,
): Promise<string[]> {
  return glob('*.json', {
    cwd: contentTemplatesRoot,
    nodir: true,
    dot: true,
    posix: true,
    ignore: [...ALWAYS_IGNORED_PATTERNS],
  });
}

async function getCandidateRegionFiles(regionsRoot: string): Promise<string[]> {
  return glob('*.json', {
    cwd: regionsRoot,
    nodir: true,
    dot: true,
    posix: true,
    ignore: [...ALWAYS_IGNORED_PATTERNS],
  });
}

function parseRegionFilename(filename: string): { region: string } | null {
  const base = filename.replace(/\.json$/, '');
  if (!/^[a-z0-9_]+$/.test(base) || base.length === 0) {
    return null;
  }
  return { region: base };
}

/**
 * Discovers code components under a scan root by pairing metadata files with
 * JavaScript entries.
 *
 * The function scans for `component.yml` and `*.component.yml`, filters matches
 * through `.gitignore`, and groups metadata by directory. When both metadata
 * styles exist in the same directory, named metadata wins and a warning is
 * emitted.
 *
 * For each active metadata file, it resolves the JavaScript entry by extension
 * precedence (`.ts`, `.tsx`, `.js`, `.jsx`) and emits warnings for missing or
 * duplicate entries. It also attaches an optional `.css` entry when present.
 *
 * Page discovery scans top-level `.json` files from `pagesRoot`, which defaults
 * to `<componentRoot>/pages`.
 *
 * Returns discovered components sorted by metadata path, along with warnings
 * and scan stats (`scannedFiles` and `ignoredFiles`).
 */
export async function discoverCanvasProject(
  options: DiscoveryOptions = {},
): Promise<DiscoveryResult> {
  const componentRoot = path.resolve(options.componentRoot ?? process.cwd());
  const projectRoot = path.resolve(options.projectRoot ?? componentRoot);
  const pagesRoot = path.resolve(
    options.pagesRoot ?? path.join(componentRoot, 'pages'),
  );
  const contentTemplatesRoot = path.resolve(
    options.contentTemplatesRoot ??
      path.join(componentRoot, 'content-templates'),
  );
  const regionsRoot = path.resolve(
    options.regionsRoot ?? path.join(componentRoot, 'regions'),
  );
  const gitignoreMatcher = await readGitignore(projectRoot);

  const allCandidates = await getCandidateMetadataFiles(componentRoot);
  const pageCandidates = await getCandidatePageFiles(pagesRoot);
  const contentTemplateCandidates =
    await getCandidateContentTemplateFiles(contentTemplatesRoot);
  const regionCandidates = await getCandidateRegionFiles(regionsRoot);
  const warnings: DiscoveryWarning[] = [];
  const components: DiscoveredComponent[] = [];
  const pages: DiscoveredPage[] = [];
  const contentTemplates: DiscoveredContentTemplate[] = [];
  const regions: DiscoveredRegion[] = [];

  let ignoredFiles = 0;

  const byDirectory = new Map<string, string[]>();

  for (const candidateRelativePath of allCandidates) {
    const normalizedRelativePath = toPosixPath(candidateRelativePath);
    const absoluteCandidatePath = path.resolve(
      componentRoot,
      normalizedRelativePath,
    );
    const projectRelativePath = toPosixPath(
      path.relative(projectRoot, absoluteCandidatePath),
    );

    if (
      !projectRelativePath.startsWith('..') &&
      gitignoreMatcher.ignores(projectRelativePath)
    ) {
      ignoredFiles += 1;
      continue;
    }

    const directory = path.posix.dirname(normalizedRelativePath);
    const current = byDirectory.get(directory) ?? [];
    current.push(path.posix.basename(normalizedRelativePath));
    byDirectory.set(directory, current);
  }

  for (const pageRelativePath of pageCandidates) {
    const normalizedRelativePath = toPosixPath(pageRelativePath);
    const absolutePagePath = path.resolve(pagesRoot, normalizedRelativePath);
    const projectRelativePath = toPosixPath(
      path.relative(projectRoot, absolutePagePath),
    );

    if (
      !projectRelativePath.startsWith('..') &&
      gitignoreMatcher.ignores(projectRelativePath)
    ) {
      ignoredFiles += 1;
      continue;
    }

    const pageFilename = path.posix.basename(normalizedRelativePath);
    const slug = pageFilename.replace(/\.json$/, '');
    let uuid: string | null = null;
    try {
      const content = JSON.parse(await fs.readFile(absolutePagePath, 'utf-8'));
      if (typeof content.uuid === 'string' && content.uuid) {
        uuid = content.uuid;
      }
    } catch {
      // Skip files that can't be read/parsed.
    }
    pages.push({
      name: slug,
      slug,
      uuid,
      path: absolutePagePath,
      relativePath: projectRelativePath.startsWith('..')
        ? normalizedRelativePath
        : projectRelativePath,
    });
  }

  for (const templateRelativePath of contentTemplateCandidates) {
    const normalizedRelativePath = toPosixPath(templateRelativePath);
    const absoluteTemplatePath = path.resolve(
      contentTemplatesRoot,
      normalizedRelativePath,
    );
    const projectRelativePath = toPosixPath(
      path.relative(projectRoot, absoluteTemplatePath),
    );

    if (
      !projectRelativePath.startsWith('..') &&
      gitignoreMatcher.ignores(projectRelativePath)
    ) {
      ignoredFiles += 1;
      continue;
    }

    const templateFilename = path.posix.basename(normalizedRelativePath);
    const slug = templateFilename.replace(/\.json$/, '');
    let label: string | null = null;
    let entityTypeId: string | null = null;
    let bundle: string | null = null;
    let viewMode: string | null = null;
    try {
      const content = JSON.parse(
        await fs.readFile(absoluteTemplatePath, 'utf-8'),
      );
      if (typeof content.label === 'string' && content.label) {
        label = content.label;
      }
      if (typeof content.entityType === 'string' && content.entityType) {
        entityTypeId = content.entityType;
      }
      if (typeof content.bundle === 'string' && content.bundle) {
        bundle = content.bundle;
      }
      if (typeof content.viewMode === 'string' && content.viewMode) {
        viewMode = content.viewMode;
      }
    } catch {
      // Skip files that can't be read/parsed.
    }
    contentTemplates.push({
      name: label ?? slug,
      slug,
      label,
      entityTypeId,
      bundle,
      viewMode,
      path: absoluteTemplatePath,
      relativePath: projectRelativePath.startsWith('..')
        ? normalizedRelativePath
        : projectRelativePath,
    });
  }

  for (const regionRelativePath of regionCandidates) {
    const normalizedRelativePath = toPosixPath(regionRelativePath);
    const absoluteRegionPath = path.resolve(
      regionsRoot,
      normalizedRelativePath,
    );
    const projectRelativePath = toPosixPath(
      path.relative(projectRoot, absoluteRegionPath),
    );

    if (
      !projectRelativePath.startsWith('..') &&
      gitignoreMatcher.ignores(projectRelativePath)
    ) {
      ignoredFiles += 1;
      continue;
    }

    const filename = path.posix.basename(normalizedRelativePath);
    const parsed = parseRegionFilename(filename);
    if (!parsed) {
      continue;
    }
    regions.push({
      region: parsed.region,
      path: absoluteRegionPath,
      relativePath: projectRelativePath.startsWith('..')
        ? normalizedRelativePath
        : projectRelativePath,
    });
  }

  const sortedDirectories = [...byDirectory.keys()].sort();

  for (const relativeDirectoryRaw of sortedDirectories) {
    const metadataFilenames = (
      byDirectory.get(relativeDirectoryRaw) ?? []
    ).sort();
    const relativeDirectory =
      relativeDirectoryRaw === '.' ? '' : relativeDirectoryRaw;
    const absoluteDirectory = path.resolve(componentRoot, relativeDirectory);

    const namedMetadataFiles = metadataFilenames.filter(
      (fileName) =>
        fileName !== 'component.yml' && fileName.endsWith(NAMED_SUFFIX),
    );

    const hasIndexMetadata = metadataFilenames.includes('component.yml');

    if (hasIndexMetadata && namedMetadataFiles.length > 0) {
      warnings.push({
        code: 'conflicting_metadata',
        message:
          'Found both component.yml and *.component.yml in the same directory. Using named metadata files only.',
        path: absoluteDirectory,
      });
    }

    const activeMetadataFiles =
      namedMetadataFiles.length > 0
        ? namedMetadataFiles
        : hasIndexMetadata
          ? ['component.yml']
          : [];

    for (const metadataFilename of activeMetadataFiles) {
      const isNamedMetadata = metadataFilename.endsWith(NAMED_SUFFIX);
      const componentBaseName = isNamedMetadata
        ? metadataFilename.slice(0, -NAMED_SUFFIX.length)
        : 'index';
      const componentName = isNamedMetadata
        ? componentBaseName
        : path.basename(absoluteDirectory);

      const metadataPath = path.resolve(absoluteDirectory, metadataFilename);

      const jsCandidates = await Promise.all(
        JS_EXTENSIONS.map(async (extension) => {
          const candidatePath = path.resolve(
            absoluteDirectory,
            `${componentBaseName}${extension}`,
          );
          return {
            extension,
            candidatePath,
            exists: await fileExists(candidatePath),
          };
        }),
      );

      const existingJsCandidates = jsCandidates.filter(
        (candidate) => candidate.exists,
      );

      if (existingJsCandidates.length > 1) {
        warnings.push({
          code: 'duplicate_definition',
          message: `Multiple JavaScript entry files found for ${metadataFilename}. Using ${path.basename(existingJsCandidates[0].candidatePath)} by extension precedence.`,
          path: metadataPath,
        });
      }

      if (existingJsCandidates.length === 0) {
        warnings.push({
          code: 'missing_js_entry',
          message: `Missing JavaScript entry file for ${metadataFilename}.`,
          path: metadataPath,
        });
        continue;
      }

      const cssPath = path.resolve(
        absoluteDirectory,
        `${componentBaseName}.css`,
      );
      const cssEntryPath = (await fileExists(cssPath)) ? cssPath : null;

      components.push({
        id: createStableId(metadataPath),
        kind: isNamedMetadata ? 'named' : 'index',
        name: componentName,
        directory: absoluteDirectory,
        relativeDirectory: relativeDirectory || '.',
        projectRelativeDirectory: toPosixPath(
          path.relative(projectRoot, absoluteDirectory),
        ),
        metadataPath,
        jsEntryPath: existingJsCandidates[0].candidatePath,
        cssEntryPath,
      });
    }
  }

  components.sort((a, b) => a.metadataPath.localeCompare(b.metadataPath));
  pages.sort((a, b) => a.path.localeCompare(b.path));
  contentTemplates.sort((a, b) => a.path.localeCompare(b.path));
  regions.sort((a, b) => a.region.localeCompare(b.region));

  const result: DiscoveryResult = {
    componentRoot,
    projectRoot,
    components,
    pages,
    contentTemplates,
    regions,
    warnings,
    stats: {
      scannedFiles:
        allCandidates.length +
        pageCandidates.length +
        contentTemplateCandidates.length +
        regionCandidates.length,
      ignoredFiles,
    },
  };

  // Check for duplicate machine names across components.
  const metadata = await loadComponentsMetadata(result);
  const duplicateWarnings = findDuplicateMachineNames(components, metadata);
  warnings.push(...duplicateWarnings);

  return result;
}
