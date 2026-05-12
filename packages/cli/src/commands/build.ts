import chalk from 'chalk';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import { getConfig } from '../config';
import { ensureAuthConfig } from '../services/api';
import { buildCanvasProject } from '../utils/build-project';
import { pluralize, updateConfigFromOptions } from '../utils/command-helpers';
import { reportResults } from '../utils/report-results';

import type { Command } from 'commander';

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

        if (!skipTailwind) {
          await ensureAuthConfig();
        }
        // Step 1: Discover all components
        const s1 = p.spinner();
        s1.start('Discovering components');
        const discoveryResult = await discoverCanvasProject({
          componentRoot: componentDir,
          projectRoot: process.cwd(),
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

        const s2 = p.spinner();
        s2.start(`Building ${componentLabelPluralized}`);
        const buildResult = await buildCanvasProject({
          projectRoot: process.cwd(),
          componentDir,
          aliasBaseDir,
          outputDir,
          discoveryResult,
          cleanOutputDir: true,
          buildTailwind: !skipTailwind,
          useLocalGlobalCss: true,
        });
        s2.stop(
          chalk.green(`Built ${components.length} ${componentLabelPluralized}`),
        );

        // Associate discovery warnings with component results
        const resultsWithWarnings = buildResult.componentResults.map(
          (result, index) => {
            const component = components[index];
            if (!component) {
              return result;
            }
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
          },
        );

        // Report component build results
        reportResults(resultsWithWarnings, 'Built components', 'Component');
        if (resultsWithWarnings.some((result) => !result.success)) {
          process.exit(1);
        }

        if (skipTailwind) {
          p.log.info('Skipping Tailwind CSS build');
        } else if (buildResult.tailwindResult) {
          reportResults([buildResult.tailwindResult], 'Built assets', 'Asset');
          if (!buildResult.tailwindResult.success) {
            process.exit(1);
          }
        }

        p.log.info(
          chalk.green(
            `Generated canvas-manifest.json — ${buildResult.vendorImportCount} vendor ${pluralize(buildResult.vendorImportCount, 'package')}, ${buildResult.localImportCount} local ${pluralize(buildResult.localImportCount, 'import')}`,
          ),
        );

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
