import chalk from 'chalk';
import * as p from '@clack/prompts';
import {
  detectHeadlessSdk,
  discoverCanvasProject,
} from '@drupal-canvas/discovery';

import { getConfig } from '../config.js';
import { createApiService, isUserAuthenticated } from '../services/api.js';
import { updateConfigFromOptions } from '../utils/command-helpers';
import { printCommandIntro } from '../utils/command-intro.js';
import {
  COMMAND_RESULT_REPORT_OPTIONS,
  reportResults,
  splitFailedResultsByFile,
} from '../utils/report-results.js';
import { validateContentTemplates } from '../utils/validate-content-template.js';
import { validatePageTemplates } from '../utils/validate-page-variant.js';
import { validatePages } from '../utils/validate-page.js';
import { validateComponents } from '../utils/validate.js';
import { formatDiscoveryWarning } from './push.js';

import type { Command } from 'commander';
import type { ApiService } from '../services/api.js';
import type { Result } from '../types/Result.js';

interface ValidateOptions {
  dir?: string;
  fix?: boolean;
}

async function createOptionalValidationApiService(): Promise<
  ApiService | undefined
> {
  const config = getConfig();
  if (!config.siteUrl) {
    return undefined;
  }
  const hasClientCredentials =
    Boolean(config.clientId) &&
    Boolean(config.clientSecret) &&
    Boolean(config.scope);
  if (!isUserAuthenticated(config.siteUrl) && !hasClientCredentials) {
    return undefined;
  }
  try {
    return await createApiService();
  } catch {
    return undefined;
  }
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

        const config = getConfig();
        const headlessSdkDetected = detectHeadlessSdk(process.cwd());
        const discoveryResult = await discoverCanvasProject({
          componentRoot: config.componentDir,
          pagesRoot: config.pagesDir,
          contentTemplatesRoot: config.contentTemplatesDir,
          pageTemplatesRoot: config.pageTemplatesDir,
          projectRoot: process.cwd(),
          requireJsEntry: !headlessSdkDetected,
        });
        for (const warning of discoveryResult.warnings) {
          p.log.warn(formatDiscoveryWarning(warning));
        }
        const results: Result[] = [];
        const apiService = await createOptionalValidationApiService();
        let availablePageVariantIds: Set<string> | undefined;
        if (apiService) {
          try {
            const remotePageVariants = await apiService.listPageVariants();
            availablePageVariantIds = new Set([
              ...Object.keys(remotePageVariants),
              ...discoveryResult.pageTemplates.map((template) => template.id),
            ]);
          } catch {
            // Server unreachable or does not support page variants yet.
          }
        }

        const s = p.spinner();
        s.start('Validating components');

        const { results: componentResults, warnings: validationWarnings } =
          await validateComponents(discoveryResult, {
            fix: options.fix,
            apiService,
            externalComponents: headlessSdkDetected,
          });
        for (const warning of validationWarnings) {
          p.log.warn(warning);
        }
        for (const result of componentResults) {
          results.push({ ...result, itemType: 'Component' });
        }

        s.stop('Validated components', 0);

        if (discoveryResult && discoveryResult.pages.length > 0) {
          const pageSpinner = p.spinner();
          pageSpinner.start('Validating pages');

          const { results: pageResults } = await validatePages(
            discoveryResult,
            { availablePageVariantIds },
          );
          for (const result of pageResults) {
            results.push({ ...result, itemType: 'Page' });
          }

          pageSpinner.stop('Validated pages', 0);
        }

        if (discoveryResult && discoveryResult.contentTemplates.length > 0) {
          const ctSpinner = p.spinner();
          ctSpinner.start('Validating content templates');

          const { results: ctResults } = await validateContentTemplates(
            discoveryResult,
            apiService ? { apiService, availablePageVariantIds } : undefined,
          );
          for (const result of ctResults) {
            results.push({ ...result, itemType: 'Content template' });
          }

          ctSpinner.stop('Validated content templates', 0);
        }

        if (discoveryResult && discoveryResult.pageTemplates.length > 0) {
          const pageTemplateSpinner = p.spinner();
          pageTemplateSpinner.start('Validating page templates');

          const { results: pageTemplateResults } =
            await validatePageTemplates(discoveryResult);
          for (const result of pageTemplateResults) {
            results.push({ ...result, itemType: 'Page template' });
          }

          pageTemplateSpinner.stop('Validated page templates', 0);
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
