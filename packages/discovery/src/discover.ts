import { createHash } from 'node:crypto';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { glob } from 'glob';
import ignore from 'ignore';

import type {
  DiscoveredComponent,
  DiscoveredPage,
  DiscoveryOptions,
  DiscoveryResult,
  DiscoveryWarning,
} from './types';

const JS_EXTENSIONS = ['ts', 'tsx', 'js', 'jsx'] as const;
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
const PAGE_PATTERN = 'pages/*.json';

// Normalize to POSIX-style separators for glob and ignore matching.
// Example: "components\\button\\component.yml" -> "components/button/component.yml".
function toPosixPath(value: string): string {
  return value.split(path.sep).join('/');
}

async function readGitignore(scanRoot: string) {
  const gitignorePath = path.join(scanRoot, '.gitignore');
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

async function getCandidateMetadataFiles(scanRoot: string): Promise<string[]> {
  const discovered = new Set<string>();

  for (const pattern of METADATA_PATTERNS) {
    const files = await glob(pattern, {
      cwd: scanRoot,
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

async function getCandidatePageFiles(scanRoot: string): Promise<string[]> {
  return glob(PAGE_PATTERN, {
    cwd: scanRoot,
    nodir: true,
    dot: true,
    posix: true,
    ignore: [...ALWAYS_IGNORED_PATTERNS],
  });
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
 * Returns discovered components sorted by metadata path, along with warnings and
 * scan stats (`scannedFiles` and `ignoredFiles`).
 */
export async function discoverCodeComponents(
  options: DiscoveryOptions = {},
): Promise<DiscoveryResult> {
  const scanRoot = path.resolve(options.scanRoot ?? process.cwd());
  const gitignoreMatcher = await readGitignore(scanRoot);

  const allCandidates = await getCandidateMetadataFiles(scanRoot);
  const pageCandidates = await getCandidatePageFiles(scanRoot);
  const warnings: DiscoveryWarning[] = [];
  const components: DiscoveredComponent[] = [];
  const pages: DiscoveredPage[] = [];

  let ignoredFiles = 0;

  const byDirectory = new Map<string, string[]>();

  for (const candidateRelativePath of allCandidates) {
    const normalizedRelativePath = toPosixPath(candidateRelativePath);

    if (gitignoreMatcher.ignores(normalizedRelativePath)) {
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
    if (gitignoreMatcher.ignores(normalizedRelativePath)) {
      ignoredFiles += 1;
      continue;
    }

    const pageFilename = path.posix.basename(normalizedRelativePath);
    const slug = pageFilename.replace(/\.json$/, '');
    pages.push({
      name: slug,
      slug,
      path: path.resolve(scanRoot, normalizedRelativePath),
      relativePath: normalizedRelativePath,
    });
  }

  const sortedDirectories = [...byDirectory.keys()].sort();

  for (const relativeDirectoryRaw of sortedDirectories) {
    const metadataFilenames = (
      byDirectory.get(relativeDirectoryRaw) ?? []
    ).sort();
    const relativeDirectory =
      relativeDirectoryRaw === '.' ? '' : relativeDirectoryRaw;
    const absoluteDirectory = path.resolve(scanRoot, relativeDirectory);

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
            `${componentBaseName}.${extension}`,
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
        metadataPath,
        jsEntryPath: existingJsCandidates[0].candidatePath,
        cssEntryPath,
      });
    }
  }

  components.sort((a, b) => a.metadataPath.localeCompare(b.metadataPath));
  pages.sort((a, b) => a.path.localeCompare(b.path));

  return {
    scanRoot,
    components,
    pages,
    warnings,
    stats: {
      scannedFiles: allCandidates.length + pageCandidates.length,
      ignoredFiles,
    },
  };
}
