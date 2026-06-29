import chalk from 'chalk';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import { getConfig } from '../config.js';
import { createApiService, ensureAuthConfig } from '../services/api.js';
import { updateConfigFromOptions } from '../utils/command-helpers';
import { printCommandIntro } from '../utils/command-intro.js';
import {
  COMMAND_RESULT_REPORT_OPTIONS,
  reportResults,
  splitFailedResultsByFile,
} from '../utils/report-results.js';
import { validateContentTemplates } from '../utils/validate-content-template.js';
import { validatePages } from '../utils/validate-page.js';
import { validateRegions } from '../utils/validate-region.js';
import {
  getComponentDirectoriesToValidate,
  validateComponent,
} from '../utils/validate.js';

import type { Command } from 'commander';
import type { ApiService } from '../services/api.js';
import type { Result } from '../types/Result.js';

interface ValidateOptions {
  dir?: string;
  fix?: boolean;
}

/**
 * Command for validating local components.
 */
export function validateCommand(program: Command): void {
  program
    .command('validate')
    .description('validate local components and pages')
    .option(
      '-d, --dir <directory>',
      'Component directory to validate the components in',
    )
    .option(
      '--fix',
      'Apply available automatic fixes for linting issues',
      false,
    )
    .action(async (options: ValidateOptions) => {
      try {
        printCommandIntro('validate');

        // Update config with CLI options
        updateConfigFromOptions(options);

        let componentDirectoriesToValidate: string[] = [];
        const config = getConfig();
        const discoveryResult = await discoverCanvasProject({
          componentRoot: config.componentDir,
          pagesRoot: config.pagesDir,
          contentTemplatesRoot: config.contentTemplatesDir,
          regionsRoot: config.regionsDir,
          projectRoot: process.cwd(),
        });
        componentDirectoriesToValidate = getComponentDirectoriesToValidate(
          discoveryResult.components,
        );

        const results: Result[] = [];

        const s = p.spinner();
        s.start('Validating components');

        for (const componentDir of componentDirectoriesToValidate) {
          const result = await validateComponent(componentDir, options.fix);
          results.push({ ...result, itemType: 'Component' });
        }

        s.stop('Validated components', 0);

        if (discoveryResult && discoveryResult.pages.length > 0) {
          const pageSpinner = p.spinner();
          pageSpinner.start('Validating pages');

          const { results: pageResults } = await validatePages(discoveryResult);
          for (const result of pageResults) {
            results.push({ ...result, itemType: 'Page' });
          }

          pageSpinner.stop('Validated pages', 0);
        }

        if (discoveryResult && discoveryResult.contentTemplates.length > 0) {
          const ctSpinner = p.spinner();
          ctSpinner.start('Validating content templates');

          let apiService: ApiService | undefined;
          try {
            await ensureAuthConfig();
            apiService = await createApiService();
          } catch {
            // Auth not configured — draft validation will be skipped.
          }

          const { results: ctResults } = await validateContentTemplates(
            discoveryResult,
            apiService ? { apiService } : undefined,
          );
          for (const result of ctResults) {
            results.push({ ...result, itemType: 'Content template' });
          }

          ctSpinner.stop('Validated content templates', 0);
        }

        if (discoveryResult && discoveryResult.regions.length > 0) {
          const regionSpinner = p.spinner();
          regionSpinner.start('Validating global regions');

          const { results: regionResults } =
            await validateRegions(discoveryResult);
          for (const result of regionResults) {
            results.push({ ...result, itemType: 'Global region' });
          }

          regionSpinner.stop('Validated global regions', 0);
        }

        reportResults(
          splitFailedResultsByFile(results),
          'Validation results',
          'Item',
          COMMAND_RESULT_REPORT_OPTIONS,
        );

        const hasErrors = results.some((r) => !r.success);
        if (hasErrors) {
          p.outro(`Validation failed`);
          process.exitCode = 1;
          return;
        }

        p.outro(`Validation completed`);
      } catch (error) {
        if (error instanceof Error) {
          p.log.error(chalk.red(`Error: ${error.message}`));
        } else {
          p.log.error(chalk.red(`Unknown error: ${String(error)}`));
        }
        p.outro('Validation failed');
        process.exitCode = 1;
      }
    });
}
