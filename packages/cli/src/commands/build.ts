import { promises as fs } from 'node:fs';
import chalk from 'chalk';
import * as p from '@clack/prompts';
import { discoverCodeComponents } from '@drupal-canvas/discovery';

import { ensureConfig, getConfig } from '../config';
import { bundleLocalAliasImports } from '../lib/build-local-import';
import { bundleVendorDependencies } from '../lib/build-vendor';
import { collectImports } from '../lib/import-analyzer';
import { buildComponent } from '../utils/build-component';
import { buildTailwindForComponents } from '../utils/build-tailwind';
import { pluralize, updateConfigFromOptions } from '../utils/command-helpers';
import { generateManifest } from '../utils/generate-manifest';
import { reportResults } from '../utils/report-results';

import type { Command } from 'commander';
import type { ImportMap } from '../lib/build-vendor';

interface BuildOptions {
  dir?: string;
  aliasBaseDir?: string;
  outputDir?: string;
  tailwind?: boolean;
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  yes?: boolean;
}

/**
 * Command for building all local components and Tailwind CSS.
 */
export function buildCommand(program: Command): void {
  program
    .command('build')
    .description('build local components and Tailwind CSS assets')
    .option(
      '-d, --dir <directory>',
      'Directory to scan for components (defaults to current working directory).',
    )
    .option(
      '--alias-base-dir <directory>',
      'Base directory for module resolution.',
    )
    .option('--output-dir <directory>', 'Build output directory.')
    .option('--no-tailwind', 'Skip Tailwind CSS building')
    .option('-y, --yes', 'Skip confirmation prompts')
    .option('--client-id <id>', 'Client ID')
    .option('--client-secret <secret>', 'Client Secret')
    .option('--site-url <url>', 'Site URL')
    .action(async (options: BuildOptions) => {
      try {
        p.intro(chalk.bold('Drupal Canvas CLI: build'));

        // Update config with CLI options
        updateConfigFromOptions(options);
        const { aliasBaseDir, outputDir, componentDir } = getConfig();

        const skipTailwind = !options.tailwind;
        // Clean output directory to remove stale builds.
        await fs.rm(outputDir, { recursive: true, force: true });

        if (!skipTailwind) {
          await ensureConfig(['siteUrl', 'clientId', 'clientSecret']);
        }
        // Step 1: Discover all components
        const s1 = p.spinner();
        s1.start('Discovering components');
        const discoveryResult = await discoverCodeComponents({
          scanRoot: componentDir,
        });
        const { components, warnings } = discoveryResult;
        s1.stop(
          chalk.green(
            `Found ${components.length} ${pluralize(components.length, 'component')}`,
          ),
        );

        if (components.length === 0) {
          p.log.warn('No components found. Nothing to build.');
          p.outro('Build complete (no components)');
          return;
        }
        if (warnings.length > 0) {
          for (const warning of warnings) {
            const location = warning.path
              ? chalk.dim(` (${warning.path})`)
              : '';
            p.log.warn(`${warning.message}${location}`);
          }
        }

        const componentLabelPluralized = pluralize(
          components.length,
          'component',
        );

        // Step 2: Analyze component imports
        const s2 = p.spinner();
        s2.start('Analyzing component imports');

        // Collect entry files from discovered components
        const entryFiles = components
          .filter((c) => c.jsEntryPath)
          .map((c) => c.jsEntryPath as string);

        // Collect third-party dependencies from all components
        const imports = await collectImports(
          entryFiles,
          componentDir,
          aliasBaseDir,
        );
        s2.stop(
          chalk.green(
            `Found ${imports.thirdPartyPackages.size} third-party ${pluralize(imports.thirdPartyPackages.size, 'package')} and ${imports.aliasImports.size} local ${pluralize(imports.aliasImports.size, 'import')}`,
          ),
        );

        if (imports.unresolvedAliasImports.size > 0) {
          const unresolved = Array.from(imports.unresolvedAliasImports).sort();
          p.log.warn(
            `Unresolved alias imports (${unresolved.length}): ${unresolved.join(', ')}`,
          );
        }

        // Step 3: Bundle third party dependencies
        let vendorImportMap: ImportMap | null = null;
        if (imports.thirdPartyPackages.size > 0) {
          const s3 = p.spinner();
          s3.start('Bundling vendor dependencies');

          const vendorResult = await bundleVendorDependencies(
            imports.thirdPartyPackages,
            componentDir,
            aliasBaseDir,
            outputDir,
          );

          if (vendorResult.success) {
            vendorImportMap = vendorResult.importMap;
            s3.stop(
              chalk.green(
                `Bundled ${vendorResult.bundledPackages.length} vendor ${pluralize(vendorResult.bundledPackages.length, 'package')} → ${outputDir}/vendor/`,
              ),
            );
          } else {
            s3.stop(chalk.yellow('⚠ Vendor bundling completed with warnings'));
            p.log.warn(`Vendor bundling error: ${vendorResult.error}`);
          }
        }

        // Step 4: Build individual components
        const s4 = p.spinner();
        s4.start(`Building ${componentLabelPluralized}`);
        const results = await Promise.all(
          components.map((c) => buildComponent(c, true, outputDir)),
        );

        s4.stop(
          chalk.green(`Built ${components.length} ${componentLabelPluralized}`),
        );

        // Associate discovery warnings with component results
        const resultsWithWarnings = results.map((result, index) => {
          const component = components[index];
          const componentWarnings = warnings
            .filter(
              (w) =>
                w.path === component.relativeDirectory ||
                w.message.includes(component.relativeDirectory),
            )
            .map((w) => w.message);

          if (componentWarnings.length > 0) {
            return {
              ...result,
              warnings: [...(result.warnings ?? []), ...componentWarnings],
            };
          }
          return result;
        });

        // Report component build results
        reportResults(resultsWithWarnings, 'Built components', 'Component');
        if (resultsWithWarnings.some((result) => !result.success)) {
          process.exit(1);
        }

        // Step 5: Build alias local imports
        const s5 = p.spinner();
        s5.start('Building alias local imports');
        const localImportResult = await bundleLocalAliasImports(
          imports.aliasImports,
          componentDir,
          aliasBaseDir,
          outputDir,
        );

        if (localImportResult.success) {
          s5.stop(
            chalk.green(
              `Bundled ${Object.keys(localImportResult.localImportMap).length} local ${pluralize(Object.keys(localImportResult.localImportMap).length, 'import')} → ${outputDir}/local/`,
            ),
          );
        } else {
          s5.stop(chalk.yellow('⚠ Local import build completed with warnings'));
          p.log.warn(`Local import build error: ${localImportResult.error}`);
        }

        // Step 6: Build Tailwind CSS
        if (skipTailwind) {
          p.log.info('Skipping Tailwind CSS build');
        } else {
          const s6 = p.spinner();
          s6.start('Building Tailwind CSS');
          const tailwindResult = await buildTailwindForComponents(
            components,
            true,
            outputDir,
          );
          s6.stop(
            chalk.green(
              `Processed Tailwind CSS classes from ${components.length} selected local ${componentLabelPluralized} and all online components`,
            ),
          );
          reportResults([tailwindResult], 'Built assets', 'Asset');
          if (!tailwindResult.success) {
            process.exit(1);
          }
        }

        // Step 7: Generate canvas-manifest.json
        const s7 = p.spinner();
        s7.start('Generating canvas-manifest.json');

        const manifestResult = await generateManifest({
          outputDir,
          vendorImportMap,
          localImportMap: localImportResult.localImportMap,
        });

        if (manifestResult.success) {
          const vendorCount = Object.keys(
            manifestResult.manifest.vendor,
          ).length;
          const localCount = Object.keys(manifestResult.manifest.local).length;
          const fontsCount = Object.keys(manifestResult.manifest.fonts).length;
          s7.stop(
            chalk.green(
              `Generated canvas-manifest.json — ${vendorCount} vendor ${pluralize(vendorCount, 'package')}, ${localCount} local ${pluralize(localCount, 'import')}, ${fontsCount} ${pluralize(fontsCount, 'font')}`,
            ),
          );
        } else {
          s7.stop(
            chalk.yellow('⚠ Manifest generation completed with warnings'),
          );
          p.log.warn(`Manifest error: ${manifestResult.error}`);
        }

        // Display manifest warnings at the end
        if (manifestResult.warnings && manifestResult.warnings.length > 0) {
          for (const warning of manifestResult.warnings) {
            p.log.warn(warning);
          }
        }

        p.outro(chalk.bold.green('📦 Build completed'));
      } catch (error) {
        if (error instanceof Error) {
          p.note(chalk.red(`Error: ${error.message}`));
        } else {
          p.note(chalk.red(`Unknown error: ${String(error)}`));
        }
        process.exit(1);
      }
    });
}
