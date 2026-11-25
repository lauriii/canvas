import * as p from '@clack/prompts';

import { getConfig, setConfig } from '../config';
import { createApiService, ensureAuthConfig } from '../services/api';
import { updateConfigFromOptions } from '../utils/command-helpers';
import { printCommandIntro } from '../utils/command-intro';

import type { Command } from 'commander';
import type { ApiService, FrontendRemovalResponse } from '../services/api';

interface FrontendOptions {
  siteUrl?: string;
  clientId?: string;
  clientSecret?: string;
  scope?: string;
  default?: boolean;
  yes?: boolean;
}

function authenticationOptions(command: Command): Command {
  return command
    .option('--site-url <url>', 'Canvas site URL')
    .option('--client-id <id>', 'OAuth client ID')
    .option('--client-secret <secret>', 'OAuth client secret')
    .option('--scope <scope>', 'OAuth scopes (overrides the command scope)');
}

async function connect(options: FrontendOptions): Promise<ApiService> {
  updateConfigFromOptions(options);
  setConfig({
    scope:
      options.scope ?? process.env.CANVAS_SCOPE ?? 'canvas:headless:frontends',
  });
  if (process.stdin.isTTY) {
    await ensureAuthConfig({ componentCompatibility: false });
  }
  return createApiService();
}

function reportRemoval(result: FrontendRemovalResponse): void {
  if (result.removed) {
    const promotion = result.newDefault
      ? `. ${result.newDefault} is now the default`
      : '';
    p.log.success(`Removed ${result.url}${promotion}`);
  } else {
    p.log.info(`${result.url} is not registered`);
  }
}

/** Selects from a snapshot; removing all never includes later registrations. */
async function removeSelectedFrontends(
  api: ApiService,
  options: FrontendOptions,
): Promise<void> {
  if (!process.stdin.isTTY || !process.stdout.isTTY) {
    throw new Error(
      'Selecting a frontend requires an interactive terminal. Pass a URL and --yes (-y) for noninteractive removal.',
    );
  }
  const { frontends } = await api.listFrontends();
  if (!frontends.length) {
    p.outro('No frontends registered');
    return;
  }
  const selection = await p.multiselect({
    message: 'Select frontends to remove (Space to select, Enter to continue)',
    initialValues: [],
    required: false,
    options: [
      ...frontends.map((frontend, index) => ({
        value: index,
        label: `${frontend.url}${index === 0 ? ' (default)' : ''}`,
      })),
      { value: -1, label: 'All frontends' },
    ],
  });
  if (
    p.isCancel(selection) ||
    (Array.isArray(selection) && !selection.length)
  ) {
    p.cancel('Frontend removal canceled');
    return;
  }
  const selected = frontends.filter(
    (_, index) => selection.includes(-1) || selection.includes(index),
  );
  if (!options.yes) {
    const confirmed = await p.confirm({
      message: `Remove ${selected.length === 1 ? 'the selected frontend' : `${selected.length} selected frontends`}?`,
      initialValue: false,
    });
    if (p.isCancel(confirmed) || !confirmed) {
      p.cancel('Frontend removal canceled');
      return;
    }
  }
  // Item operations preserve unrelated registrations, including concurrent adds.
  let removed = false;
  for (const frontend of selected) {
    const result = await api.removeFrontend(frontend.url);
    reportRemoval(result);
    removed = removed || result.removed;
  }
  p.outro(removed ? 'Frontend removal completed' : 'No changes made');
}

/** Registers the frontend-management commands without component workflows. */
export function headlessCommands(program: Command): void {
  authenticationOptions(
    program
      .command('headless:add <url>')
      .description('register a headless frontend, or ensure it is the default')
      .option(
        '--default',
        'Register or move this frontend to the first position',
      ),
  ).action(async (url: string, options: FrontendOptions) => {
    if (!/^https?:\/\//i.test(url)) {
      throw new Error('Frontend URL must start with http:// or https://.');
    }

    printCommandIntro('headless:add');
    const api = await connect(options);
    const result = await api.addFrontend(url, Boolean(options.default));
    if (result.changed) {
      p.log.success(
        `${result.url} ${options.default ? 'is now the default frontend' : 'was registered'}`,
      );
    } else {
      p.log.info(
        `${result.url} is already ${options.default ? 'the default frontend' : 'registered'}`,
      );
    }
    p.outro(
      result.changed ? 'Frontend registration completed' : 'No changes made',
    );
  });

  authenticationOptions(
    program
      .command('headless:remove [url]')
      .description(
        'remove a frontend, or select one or more when no URL is supplied',
      )
      .option('-y, --yes', 'Skip removal confirmation, not frontend selection'),
  ).action(async (url: string | undefined, options: FrontendOptions) => {
    printCommandIntro('headless:remove');
    if (url === undefined) {
      const api = await connect(options);
      await removeSelectedFrontends(api, options);
      return;
    }
    if (!options.yes) {
      if (!process.stdin.isTTY || !process.stdout.isTTY) {
        throw new Error(
          'Removing a frontend requires confirmation. Run again with --yes (-y).',
        );
      }
      const confirmed = await p.confirm({
        message: `Remove ${url}?`,
        initialValue: false,
      });
      if (p.isCancel(confirmed) || !confirmed) {
        p.cancel('Frontend removal canceled');
        return;
      }
    }
    const api = await connect(options);
    const result = await api.removeFrontend(url);
    reportRemoval(result);
    p.outro(result.removed ? 'Frontend removal completed' : 'No changes made');
  });

  authenticationOptions(
    program
      .command('headless:list')
      .description(
        'list headless frontends in configured order; the first is the default',
      ),
  ).action(async (options: FrontendOptions) => {
    printCommandIntro('headless:list');
    const api = await connect(options);
    const { frontends } = await api.listFrontends();
    const siteUrl = getConfig().siteUrl;
    if (!frontends.length) {
      p.outro(`No headless frontends registered for ${siteUrl}`);
      return;
    }
    p.log.info(
      `Headless frontends for ${siteUrl}\n\n${frontends.map((frontend, index) => `${frontend.url}${index === 0 ? ' (default)' : ''}`).join('\n')}`,
    );
    p.outro(
      `${frontends.length} ${frontends.length === 1 ? 'frontend' : 'frontends'} registered`,
    );
  });
}
