import chalk from 'chalk';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import { getConfig } from '../config';
import { buildCanvasProject } from '../utils/build-project';
import {
  pluralize,
  updateConfigFromOptions,
  warnAboutSiteProvidedPackages,
} from '../utils/command-helpers';
import { printCommandIntro } from '../utils/command-intro';
import {
  COMMAND_RESULT_REPORT_OPTIONS,
  reportResults,
  splitFailedResultsByFile,
} from '../utils/report-results';

import type { Command } from 'commander';

interface BuildOptions {
  dir?: string;
  aliasBaseDir?: string;
  outputDir?: string;
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
      'Directory to scan for components (defaults to componentDir from canvas.config.json or src/components).',
    )
    .option(
      '--alias-base-dir <directory>',
      'Base directory for module resolution.',
    )
    .option('--output-dir <directory>', 'Build output directory.')
    .option('-y, --yes', 'Skip confirmation prompts')
    .action(async (options: BuildOptions) => {
      try {
        printCommandIntro('build');

        // Update config with CLI options
        updateConfigFromOptions(options);
        const { aliasBaseDir, outputDir, componentDir } = getConfig();

        // Step 1: Discover local components.
        const s1 = p.spinner();
        s1.start('Discovering components');
        const discoveryResult = await discoverCanvasProject({
          componentRoot: componentDir,
          projectRoot: process.cwd(),
        });
        const { components, warnings } = discoveryResult;
        s1.stop('Discovered components', 0);

        if (components.length === 0) {
          p.log.warn('No components found. Nothing to build.');
          p.outro('Nothing to build');
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

        const s2 = p.spinner();
        s2.start('Building components');
        let buildResult: Awaited<ReturnType<typeof buildCanvasProject>>;
        try {
          buildResult = await buildCanvasProject({
            projectRoot: process.cwd(),
            componentDir,
            aliasBaseDir,
            outputDir,
            discoveryResult,
            cleanOutputDir: true,
          });
        } catch (error) {
          s2.stop('Build failed', 2);
          throw error;
        }
        const hasComponentBuildFailures = buildResult.componentResults.some(
          (result) => !result.success,
        );
        s2.stop(
          hasComponentBuildFailures ? 'Build failed' : 'Built components',
          hasComponentBuildFailures ? 2 : 0,
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
        reportResults(
          splitFailedResultsByFile(resultsWithWarnings),
          'Built components',
          'Component',
          COMMAND_RESULT_REPORT_OPTIONS,
        );
        if (hasComponentBuildFailures) {
          p.outro('Build failed');
          process.exitCode = 1;
          return;
        }

        reportResults([buildResult.tailwindResult], 'Built assets', 'Asset', {
          ...COMMAND_RESULT_REPORT_OPTIONS,
          showTitle: true,
          showSuccessHeading: false,
        });
        if (!buildResult.tailwindResult.success) {
          p.outro('Build failed');
          process.exitCode = 1;
          return;
        }

        warnAboutSiteProvidedPackages(
          buildResult.siteProvidedPackages,
          buildResult.siteImportsVerified,
        );

        p.log.info(
          chalk.green(
            `Generated canvas-manifest.json — ${buildResult.vendorImportCount} vendor ${pluralize(buildResult.vendorImportCount, 'package')}, ${buildResult.localImportCount} local ${pluralize(buildResult.localImportCount, 'import')}`,
          ),
        );

        p.outro(chalk.bold.green('Build completed'));
      } catch (error) {
        const message =
          error instanceof Error
            ? error.message
            : `Unknown error: ${String(error)}`;
        p.log.message(
          ['Failed', `  ${chalk.red('✗')} Build failed`, `    ${message}`].join(
            '\n',
          ),
        );
        p.outro('Build failed');
        process.exitCode = 1;
      }
    });
}
