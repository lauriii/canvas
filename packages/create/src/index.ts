#!/usr/bin/env node
import chalk from 'chalk';
import { Command } from 'commander';
import * as p from '@clack/prompts';

import templates from '../templates.json' with { type: 'json' };
import createApp from './create.js';
import { getDescription, getName, getVersion } from './lib/meta-info.js';
import validateName from './lib/validate-name.js';

import type { Template } from './types/template.js';

// Handle SIGINT and SIGTERM signals to terminate the Node.js process.
process.on('SIGINT', () => process.exit(0));
process.on('SIGTERM', () => process.exit(0));

interface CreateOptions {
  template?: string;
  ref?: string;
}

const program = new Command();
program
  .name(getName())
  .description(getDescription())
  .version(getVersion())
  .argument('[app-name]', 'name of the app to create')
  .option(
    '-t, --template <template>',
    'use template when scaffolding (predefined name or custom Git repository URL)',
  )
  .option(
    '-r, --ref <ref>',
    'use Git ref when cloning template repository (for example, branch name or tag)',
  )
  .action(async (appNameArg: string | undefined, options: CreateOptions) => {
    p.intro(chalk.bold('Drupal Canvas Create'));

    try {
      // Validate template flag if provided.
      if (options.template) {
        const template = (templates as Template[]).find(
          (t) => t.id === options.template,
        );
        const isCustomRepositoryUrl = [
          // Remote repositories.
          'https://',
          'http://',
          'git@',
          // Local repositories.
          '../',
          './',
          '/',
        ].some((prefix) => options.template?.startsWith(prefix));
        if (!template && isCustomRepositoryUrl) {
          templates.push({
            id: options.template,
            label: options.template,
            repository: {
              url: options.template,
              ref: 'HEAD',
            },
          });
        } else if (!template) {
          p.log.error(`Template "${options.template}" not found`);
          process.exit(1);
        }
      }

      // Get app name from argument or prompt.
      let appName = appNameArg;
      if (!appName) {
        const name = await p.text({
          message: 'Enter the app name',
          initialValue: 'my-canvas-app',
          validate: (value) => {
            if (!value) return 'App name is required';
            const { valid, problems } = validateName(value);
            if (!valid) {
              return problems.join(', ');
            }
            return;
          },
        });

        if (p.isCancel(name)) {
          p.cancel('Operation cancelled');
          process.exit(0);
        }

        appName = name;
      } else {
        // Validate app name if provided as argument.
        const { valid, problems } = validateName(appName);
        if (!valid) {
          p.log.error(`Invalid app name: ${problems.join(', ')}`);
          process.exit(1);
        }
      }

      // Get template from flag or prompt.
      let templateId = options.template;
      if (!templateId) {
        // If there's only one template, use it automatically.
        if ((templates as Template[]).length === 1) {
          templateId = (templates as Template[])[0].id;
        } else {
          const selected = await p.select({
            message: 'Select a template',
            options: (templates as Template[]).map((t) => ({
              value: t.id,
              label: t.label,
            })),
          });

          if (p.isCancel(selected)) {
            p.cancel('Operation cancelled');
            process.exit(0);
          }

          templateId = selected as string;
        }
      }

      // Find the template (already validated if provided via flag).
      const template = (templates as Template[]).find(
        (t) => t.id === templateId,
      ) as Template;

      // Set the ref if provided via flag.
      if (options.ref) {
        template.repository.ref = options.ref;
      }

      // Display the template and app name.
      p.note(`Template: ${template.label}\nApp name: ${appName}`);

      // Create the app.
      await createApp({ template, appName });
    } catch (error) {
      if (error instanceof Error) {
        p.log.error(`Error: ${error.message}`);
      } else {
        p.log.error(`Unknown error: ${String(error)}`);
      }
      process.exit(1);
    }
  });

// Handle errors.
program.showHelpAfterError();
program.showSuggestionAfterError(true);

try {
  // Parse command line arguments and execute the command.
  await program.parseAsync(process.argv);
} catch (error) {
  if (error instanceof Error) {
    console.error(chalk.red(`Error: ${error.message}`));
    process.exit(1);
  }
}
