import { existsSync, promises as fs, statSync } from 'node:fs';
import path from 'node:path';
import { parse } from '@babel/parser';
import * as p from '@clack/prompts';
import { ASSET_EXTENSIONS, JS_EXTENSIONS } from '@drupal-canvas/discovery';

import { DRUPAL_CANVAS_EXTERNALS } from './vite-build-config';

export type ImportCategory = 'alias' | 'third-party' | 'relative';

export interface ParsedImport {
  source: string;
  category: ImportCategory;
  isCSS: boolean;
  isSVG: boolean;
  isImage: boolean;
}

export interface AnalyzedFile {
  filePath: string;
  relativePath: string;
  imports: ParsedImport[];
}

export interface ImportAnalysisResult {
  /** All files that need to be processed (components + dependencies) */
  files: Map<string, AnalyzedFile>;
  /** CSS files that need to be included */
  cssFiles: Set<string>;
  /** Image assets that need to be copied */
  imageAssets: Set<string>;
  /** SVG files that need SVGR transformation */
  svgFiles: Set<string>;
}

export interface CollectDependenciesResult {
  thirdPartyPackages: Set<string>;
  aliasImports: Map<string, string>;
  unresolvedAliasImports: Set<string>;
}

const ALIAS_PREFIX = '@/';

function isJSFile(filePath: string): boolean {
  const ext = path.extname(filePath).toLowerCase();
  return (JS_EXTENSIONS as readonly string[]).includes(ext);
}

function isCSSFile(filePath: string): boolean {
  return path.extname(filePath).toLowerCase() === '.css';
}

function isSVGFile(filePath: string): boolean {
  return path.extname(filePath).toLowerCase() === '.svg';
}

function isImageFile(filePath: string): boolean {
  const ext = path.extname(filePath).toLowerCase();
  return (ASSET_EXTENSIONS as readonly string[]).includes(ext);
}

function categorizeImport(source: string): ImportCategory {
  if (source.startsWith(ALIAS_PREFIX)) {
    return 'alias';
  }
  if (source.startsWith('.') || source.startsWith('/')) {
    return 'relative';
  }
  return 'third-party';
}

/**
 * Resolve a path that has no extension by trying JS extensions and index files.
 */
function resolveWithExtensions(targetPath: string): string | null {
  const isDirectory = (() => {
    try {
      return statSync(targetPath).isDirectory();
    } catch {
      return false;
    }
  })();

  const candidates = isDirectory
    ? JS_EXTENSIONS.map((ext) => path.join(targetPath, `index${ext}`))
    : JS_EXTENSIONS.map((ext) => `${targetPath}${ext}`);

  for (const candidate of candidates) {
    if (existsSync(candidate)) {
      return candidate;
    }
  }

  return null;
}

/**
 * Resolve an alias import to an absolute file path.
 */
export function resolveAliasPath(
  aliasSource: string,
  aliasBaseDir: string,
): string | null {
  if (!aliasSource.startsWith(ALIAS_PREFIX)) {
    return null;
  }

  const suffix = aliasSource.slice(ALIAS_PREFIX.length);
  // aliasBaseDir is relative to project root (cwd), not scanRoot
  const unresolvedTarget = path.resolve(aliasBaseDir, suffix);

  // If the path has an extension, use it directly
  if (path.extname(unresolvedTarget)) {
    if (existsSync(unresolvedTarget)) {
      return unresolvedTarget;
    }
    return null;
  }

  return resolveWithExtensions(unresolvedTarget);
}

/**
 * Parse a JavaScript/TypeScript file and extract all imports.
 */
export async function parseFileImports(
  filePath: string,
): Promise<ParsedImport[]> {
  const content = await fs.readFile(filePath, 'utf-8');
  const ext = path.extname(filePath).toLowerCase();
  const isTypeScript = ext === '.ts' || ext === '.tsx';
  const hasJSX = ext === '.tsx' || ext === '.jsx';

  const imports: ParsedImport[] = [];

  try {
    const ast = parse(content, {
      sourceType: 'module',
      plugins: [
        ...(isTypeScript ? ['typescript' as const] : []),
        ...(hasJSX ? ['jsx' as const] : []),
      ],
    });

    for (const node of ast.program.body) {
      // Handle: import x from 'y'
      if (node.type === 'ImportDeclaration') {
        const source = node.source.value;
        const category = categorizeImport(source);
        imports.push({
          source,
          category,
          isCSS: isCSSFile(source),
          isSVG: isSVGFile(source),
          isImage: isImageFile(source),
        });
      }

      // Handle: export * from 'y' / export { x } from 'y'
      if (
        node.type === 'ExportNamedDeclaration' ||
        node.type === 'ExportAllDeclaration'
      ) {
        if (node.source) {
          const source = node.source.value;
          const category = categorizeImport(source);
          imports.push({
            source,
            category,
            isCSS: isCSSFile(source),
            isSVG: isSVGFile(source),
            isImage: isImageFile(source),
          });
        }
      }
    }
  } catch (error) {
    // If parsing fails, return empty imports
    p.log.warn(`Warning: Could not parse imports from ${filePath}: ${error}`);
  }

  return imports;
}

/**
 * Analyze all imports starting from entry files and recursively discover dependencies.
 */
export async function analyzeImports(
  entryFiles: string[],
  scanRoot: string,
  aliasBaseDir: string,
): Promise<ImportAnalysisResult> {
  const files = new Map<string, AnalyzedFile>();
  const cssFiles = new Set<string>();
  const imageAssets = new Set<string>();
  const svgFiles = new Set<string>();
  const visited = new Set<string>();
  const queue: string[] = [...entryFiles];

  while (queue.length > 0) {
    const filePath = queue.shift()!;

    if (visited.has(filePath)) {
      continue;
    }
    visited.add(filePath);

    if (!existsSync(filePath)) {
      continue;
    }

    // Handle different file types
    if (isCSSFile(filePath)) {
      cssFiles.add(filePath);
      continue;
    }

    if (isSVGFile(filePath)) {
      svgFiles.add(filePath);
      continue;
    }

    if (isImageFile(filePath)) {
      imageAssets.add(filePath);
      continue;
    }

    if (!isJSFile(filePath)) {
      continue;
    }

    const imports = await parseFileImports(filePath);
    const relativePath = path.relative(path.resolve(aliasBaseDir), filePath);

    files.set(filePath, {
      filePath,
      relativePath,
      imports,
    });

    // Process imports and queue alias dependencies
    for (const imp of imports) {
      if (imp.category === 'alias') {
        // Resolve the alias to an absolute path
        const resolvedPath = resolveAliasPath(imp.source, aliasBaseDir);
        if (resolvedPath && !visited.has(resolvedPath)) {
          queue.push(resolvedPath);
        }

        // Also track CSS/SVG/image imports
        if (imp.isCSS && resolvedPath) {
          cssFiles.add(resolvedPath);
        } else if (imp.isSVG && resolvedPath) {
          svgFiles.add(resolvedPath);
        } else if (imp.isImage && resolvedPath) {
          imageAssets.add(resolvedPath);
        }
      } else if (imp.category === 'relative') {
        // Resolve relative imports
        const dir = path.dirname(filePath);
        let resolvedPath: string | null = null;

        const targetPath = path.resolve(dir, imp.source);
        if (path.extname(targetPath)) {
          if (existsSync(targetPath)) {
            resolvedPath = targetPath;
          }
        } else {
          resolvedPath = resolveWithExtensions(targetPath);
        }

        if (resolvedPath && !visited.has(resolvedPath)) {
          // Only include if within the alias base directory
          const aliasRoot = path.resolve(aliasBaseDir);
          if (resolvedPath.startsWith(aliasRoot)) {
            queue.push(resolvedPath);
          }
        }

        // Track assets for relative imports too
        if (imp.isCSS && resolvedPath) {
          cssFiles.add(resolvedPath);
        } else if (imp.isSVG && resolvedPath) {
          svgFiles.add(resolvedPath);
        } else if (imp.isImage && resolvedPath) {
          imageAssets.add(resolvedPath);
        }
      }
    }
  }

  return {
    files,
    cssFiles,
    imageAssets,
    svgFiles,
  };
}

/**
 * Collect all imports from component entry files.
 * Returns third-party package names, resolved alias imports, and unresolved alias imports.
 */
export async function collectImports(
  entryFiles: string[],
  scanRoot: string,
  aliasBaseDir: string,
): Promise<CollectDependenciesResult> {
  const thirdPartyPackages = new Set<string>();
  const aliasImports = new Map<string, string>();
  const unresolvedAliasImports = new Set<string>();

  // Use analyzeImports to get all files and their imports
  const analysisResult = await analyzeImports(
    entryFiles,
    scanRoot,
    aliasBaseDir,
  );

  // Collect third-party imports from all analyzed files
  for (const [, analyzedFile] of analysisResult.files) {
    for (const imp of analyzedFile.imports) {
      if (imp.category === 'third-party' && !imp.isSVG && !imp.isImage) {
        const packageName = imp.source;
        thirdPartyPackages.add(packageName);
      }

      // Collect all alias imports (JS, CSS, SVG, images, audio, video)
      if (imp.category === 'alias') {
        // Ignore cross-component imports (@/components/*)
        // These do not need to be bundled since they are existing components
        // that get built as part of the component build process.
        if (imp.source.startsWith('@/components/')) {
          continue;
        }
        // Skip alias imports provided by Canvas's global import map.
        if (DRUPAL_CANVAS_EXTERNALS.includes(imp.source)) {
          continue;
        }
        const resolvedPath = resolveAliasPath(imp.source, aliasBaseDir);
        if (resolvedPath) {
          aliasImports.set(imp.source, resolvedPath);
        } else {
          unresolvedAliasImports.add(imp.source);
        }
      }
    }
  }

  return {
    thirdPartyPackages,
    aliasImports,
    unresolvedAliasImports,
  };
}
