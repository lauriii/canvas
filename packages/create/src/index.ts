#!/usr/bin/env node
import chalk from 'chalk';
import { Command } from 'commander';
import * as p from '@clack/prompts';

import templates from '../templates.json' with { type: 'json' };
import { agentsCommand } from './agents.js';
import createProject from './create.js';
import { parseAgentSelection } from './lib/agent-selection.js';
import { printCommandIntro } from './lib/command-intro.js';
import { getDescription, getName, getVersion } from './lib/meta-info.js';
import { validateSiteUrl } from './lib/site-url.js';
import {
  DEFAULT_PROJECT_NAME,
  slugifyProjectName,
} from './lib/slugify-project-name.js';
import {
  assertUniqueTemplateIdentifiers,
  resolveTemplate,
} from './lib/template-resolution.js';
import validateName from './lib/validate-name.js';

import type { Template } from './types/template.js';

// Handle SIGINT and SIGTERM signals to terminate the Node.js process.
process.on('SIGINT', () => process.exit(0));
process.on('SIGTERM', () => process.exit(0));

interface CreateOptions {
  template?: string;
  ref?: string;
  agents?: string;
  experimentalHeadless?: boolean;
  siteName?: string;
  siteUrl?: string;
}

const program = new Command();
program
  .name(getName())
  .description(getDescription())
  .version(getVersion())
  .argument('[project-name]', 'name of the project to create')
  .option(
    '-t, --template <template>',
    'use template when scaffolding (predefined name or custom Git repository URL)',
  )
  .option(
    '-r, --ref <ref>',
    'use Git ref when cloning template repository (for example, branch name or tag)',
  )
  .option(
    '-a, --agents <agents>',
    'comma-separated list of additional agents to support, or "none" to skip compatibility symlinks',
  )
  .option(
    '--experimental-headless',
    'enable experimental headless frontend templates',
  )
  .option(
    '--site-name <name>',
    'use slugified site name as the suggested project name',
  )
  .option(
    '--site-url <url>',
    'use Canvas site URL when configuring the project',
  )
  .action(
    async (projectNameArg: string | undefined, options: CreateOptions) => {
      printCommandIntro('create');

      try {
        const interactive = Boolean(
          process.stdin.isTTY && process.stdout.isTTY,
        );
        const selectedAgents = parseAgentSelection(options.agents);
        const predefinedTemplates = templates as Template[];
        assertUniqueTemplateIdentifiers(predefinedTemplates);
        const availableTemplates = options.experimentalHeadless
          ? predefinedTemplates
          : predefinedTemplates.filter((template) => !template.experimental);

        // Validate template flag if provided.
        if (options.template) {
          const template = resolveTemplate(
            predefinedTemplates,
            options.template,
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
            predefinedTemplates.push({
              id: options.template,
              label: options.template,
              repository: {
                url: options.template,
                ref: 'HEAD',
              },
            });
          } else if (!template) {
            p.log.error(
              `Template "${options.template}" not found.\n\nAvailable templates:\n${availableTemplates
                .map((availableTemplate) => `- ${availableTemplate.id}`)
                .join('\n')}`,
            );
            process.exit(1);
          }
        }

        // Get project name from argument or prompt.
        let projectName = projectNameArg;
        if (!projectName) {
          if (!interactive) {
            p.log.error('Project name is required in a non-interactive run.');
            process.exit(1);
          }

          const name = await p.text({
            message: 'Enter the project name',
            initialValue:
              options.siteName === undefined
                ? DEFAULT_PROJECT_NAME
                : slugifyProjectName(options.siteName),
            validate: (value) => {
              if (!value) return 'Project name is required';
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

          projectName = name;
        } else {
          // Validate project name if provided as argument.
          const { valid, problems } = validateName(projectName);
          if (!valid) {
            p.log.error(`Invalid project name: ${problems.join(', ')}`);
            process.exit(1);
          }
        }

        let siteUrl = options.siteUrl ?? process.env.CANVAS_SITE_URL;
        if (!siteUrl?.trim()) {
          if (interactive) {
            const enteredSiteUrl = await p.text({
              message: 'Enter the Drupal site URL',
              validate: (value) => validateSiteUrl(value.trim()),
            });

            if (p.isCancel(enteredSiteUrl)) {
              p.cancel('Operation cancelled');
              process.exit(0);
            }

            siteUrl = enteredSiteUrl;
          } else {
            siteUrl = undefined;
          }
        }

        if (siteUrl !== undefined) {
          siteUrl = siteUrl.trim();
          const siteUrlValidationError = validateSiteUrl(siteUrl);
          if (siteUrlValidationError) {
            p.log.error(siteUrlValidationError);
            process.exit(1);
          }
        }

        // Get template from flag or prompts.
        let templateId = options.template;
        if (!templateId) {
          // If there's only one available template, use it automatically.
          if (availableTemplates.length === 1) {
            templateId = availableTemplates[0].id;
          } else {
            if (!interactive) {
              p.log.error(
                'Template is required in a non-interactive run. Use --template.',
              );
              process.exit(1);
            }

            const selected = await p.select({
              message: 'Select a template',
              options: availableTemplates.map((template) => ({
                value: template.id,
                label: template.label,
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
        const template = resolveTemplate(
          predefinedTemplates,
          templateId,
        ) as Template;

        // Set the ref if provided via flag.
        if (options.ref) {
          template.repository.ref = options.ref;
        }

        // Create the project.
        await createProject({
          template,
          projectName,
          siteUrl,
          selectedAgents,
          interactive,
        });
      } catch (error) {
        if (error instanceof Error) {
          p.log.error(`Error: ${error.message}`);
        } else {
          p.log.error(`Unknown error: ${String(error)}`);
        }
        process.exit(1);
      }
    },
  );

agentsCommand(program);

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
