#!/usr/bin/env node
import chalk from 'chalk';
import { Command } from 'commander';
import * as p from '@clack/prompts';

import templates from '../templates.json' with { type: 'json' };
import { agentsCommand } from './agents.js';
import createProject from './create.js';
import { parseAgentSelection } from './lib/agent-selection.js';
import { getDescription, getName, getVersion } from './lib/meta-info.js';
import validateName from './lib/validate-name.js';

import type { Template } from './types/template.js';

// Handle SIGINT and SIGTERM signals to terminate the Node.js process.
process.on('SIGINT', () => process.exit(0));
process.on('SIGTERM', () => process.exit(0));

interface CreateOptions {
  template?: string;
  ref?: string;
  agents?: string;
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
  .action(
    async (projectNameArg: string | undefined, options: CreateOptions) => {
      p.intro(chalk.bold('Drupal Canvas Create'));

      try {
        const selectedAgents = parseAgentSelection(options.agents);

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
            p.log.error(
              `Template "${options.template}" not found.\n\nAvailable templates:\n${(
                templates as Template[]
              )
                .map((availableTemplate) => `- ${availableTemplate.id}`)
                .join('\n')}`,
            );
            process.exit(1);
          }
        }

        // Get project name from argument or prompt.
        let projectName = projectNameArg;
        if (!projectName) {
          const name = await p.text({
            message: 'Enter the project name',
            initialValue: 'my-canvas-project',
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

        // Get template from flag or prompt.
        let templateId = options.template;
        if (!templateId) {
          // If there's only one template, use it automatically.
          if ((templates as Template[]).length === 1) {
            templateId = (templates as Template[])[0].id;
          } else {
            const selected = await p.select({
              message: 'Select a template',
              options: (templates as Template[]).map((t, index) => ({
                value: t.id,
                label: index === 0 ? `${t.label} (Recommended)` : t.label,
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

        // Create the project.
        await createProject({ template, projectName, selectedAgents });
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
