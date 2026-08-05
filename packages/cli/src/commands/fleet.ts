import fs from 'node:fs';
import path from 'node:path';
import chalk from 'chalk';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import packageJson from '../../package.json';
import { getConfig } from '../config.js';
import { createSiteApiService } from '../lib/fleet-site.js';
import {
  FLEET_FILENAME,
  fleetPath,
  groupsForSite,
  LIBRARY_FILENAME,
  libraryPath,
  listChangesets,
  readChangeset,
  readFleet,
  readLock,
  resolveSiteCredentials,
} from '../lib/fleet.js';
import { printCommandIntro } from '../utils/command-intro.js';

import type { Command } from 'commander';
import type { FleetFile, LibraryFile } from '../lib/fleet.js';

/** Commander collector for repeatable options. */
export function collectRepeatable(value: string, previous: string[]): string[] {
  return [...previous, value];
}

function writeJson(filePath: string, contents: unknown): void {
  fs.writeFileSync(filePath, `${JSON.stringify(contents, null, 2)}\n`, 'utf-8');
}

/** Derives a conventional environment variable name from a site name. */
export function defaultCredentialsEnv(siteName: string): string {
  return `CANVAS_OAUTH_${siteName.toUpperCase().replace(/[^A-Z0-9]+/g, '_')}`;
}

export function libraryCommand(program: Command): void {
  const library = program
    .command('library')
    .description('manage the component library manifest');

  library
    .command('init')
    .description(`scaffold ${LIBRARY_FILENAME} in a library repository`)
    .option('--name <name>', 'Library name')
    .option('--library-version <version>', 'Library semver version')
    .option('-d, --dir <directory>', 'Component directory')
    .option('--force', 'Overwrite an existing manifest')
    .action(
      async (options: {
        name?: string;
        libraryVersion?: string;
        dir?: string;
        force?: boolean;
      }) => {
        printCommandIntro('library init');
        const filePath = libraryPath();
        if (fs.existsSync(filePath) && !options.force) {
          p.log.error(`${LIBRARY_FILENAME} already exists. Use --force.`);
          p.outro('Nothing written');
          process.exitCode = 1;
          return;
        }

        const componentDir = options.dir ?? getConfig().componentDir;
        const { components } = await discoverCanvasProject({
          componentRoot: componentDir,
          projectRoot: process.cwd(),
        });

        const library: LibraryFile = {
          name: options.name ?? `@local/${path.basename(process.cwd())}`,
          version: options.libraryVersion ?? '0.1.0',
          components: components.map((component) => component.name).sort(),
          includes: { globalCss: true, brandKit: false },
          engines: { canvasCli: `>=${packageJson.version}` },
        };
        writeJson(filePath, library);
        p.log.success(
          `Wrote ${LIBRARY_FILENAME} (${String(library.components.length)} components)`,
        );
        p.outro('Run `canvas fleet init` next.');
      },
    );
}

export function fleetCommand(program: Command): void {
  const fleet = program
    .command('fleet')
    .description('manage the fleet inventory');

  fleet
    .command('init')
    .description(`scaffold an empty ${FLEET_FILENAME}`)
    .option('--force', 'Overwrite an existing inventory')
    .action((options: { force?: boolean }) => {
      printCommandIntro('fleet init');
      const filePath = fleetPath();
      if (fs.existsSync(filePath) && !options.force) {
        p.log.error(`${FLEET_FILENAME} already exists. Use --force.`);
        p.outro('Nothing written');
        process.exitCode = 1;
        return;
      }
      const inventory: FleetFile = { groups: {}, sites: {} };
      writeJson(filePath, inventory);
      p.log.success(`Wrote ${FLEET_FILENAME}`);
      p.outro('Run `canvas fleet add <name> --url <url>` next.');
    });

  fleet
    .command('add <name>')
    .description('add a site to the inventory')
    .requiredOption('--url <url>', 'Site URL')
    .option(
      '--credentials-env <name>',
      'Environment variable holding "client_id:client_secret"',
    )
    .option(
      '--group <name>',
      'Group to join (repeatable)',
      collectRepeatable,
      [],
    )
    .option('--overlay <path>', 'Per-site overlay directory (Stage 3)')
    .action(
      (
        name: string,
        options: {
          url: string;
          credentialsEnv?: string;
          group: string[];
          overlay?: string;
        },
      ) => {
        printCommandIntro('fleet add');
        const inventory = readFleet();
        if (inventory.sites[name]) {
          p.log.error(`Site "${name}" already exists in ${FLEET_FILENAME}.`);
          p.outro('Nothing written');
          process.exitCode = 1;
          return;
        }
        const credentialsEnv =
          options.credentialsEnv ?? defaultCredentialsEnv(name);
        inventory.sites[name] = {
          url: options.url,
          credentialsEnv,
          ...(options.overlay ? { overlay: options.overlay } : {}),
        };
        inventory.groups ??= {};
        for (const group of options.group) {
          inventory.groups[group] = [...(inventory.groups[group] ?? []), name];
        }
        writeJson(fleetPath(), inventory);
        p.log.success(
          `Added "${name}" (${options.url}) to ${FLEET_FILENAME}${
            options.group.length > 0 ? ` in ${options.group.join(', ')}` : ''
          }`,
        );
        p.outro(
          `Set $${credentialsEnv} to "client_id:client_secret" before applying.`,
        );
      },
    );

  fleet
    .command('list')
    .description('show sites, groups, and locked library versions')
    .option('--json', 'Emit machine-readable output')
    .action((options: { json?: boolean }) => {
      const inventory = readFleet();
      const lock = readLock();
      const rows = Object.entries(inventory.sites).map(([name, site]) => ({
        name,
        url: site.url,
        groups: groupsForSite(inventory, name),
        overlay: site.overlay,
        libraryVersion: lock.sites[name]?.libraryVersion,
        lastRefresh: lock.sites[name]?.lastRefresh,
      }));

      if (options.json) {
        console.log(JSON.stringify({ sites: rows }, null, 2));
        return;
      }

      printCommandIntro('fleet list');
      if (rows.length === 0) {
        p.log.warn(`No sites in ${FLEET_FILENAME}.`);
        p.outro('Run `canvas fleet add <name> --url <url>`.');
        return;
      }
      for (const row of rows) {
        const groups =
          row.groups.length > 0 ? chalk.dim(` [${row.groups.join(', ')}]`) : '';
        const version = row.libraryVersion
          ? chalk.cyan(row.libraryVersion)
          : chalk.dim('never applied');
        p.log.message(
          `${chalk.bold(row.name)}${groups}\n  ${row.url}\n  ${version}${
            row.lastRefresh ? chalk.dim(` (refreshed ${row.lastRefresh})`) : ''
          }`,
        );
      }
      p.outro(`${String(rows.length)} sites`);
    });
}

export function changesetCommand(program: Command): void {
  const changeset = program
    .command('changeset')
    .description('inspect and restore captured pre-push state');

  changeset
    .command('list')
    .description('list captured changesets')
    .option('--json', 'Emit machine-readable output')
    .action((options: { json?: boolean }) => {
      const ids = listChangesets();
      if (options.json) {
        console.log(JSON.stringify({ changesets: ids }, null, 2));
        return;
      }
      printCommandIntro('changeset list');
      if (ids.length === 0) {
        p.log.warn('No changesets captured yet.');
        p.outro('Nothing to show');
        return;
      }
      for (const id of ids) {
        p.log.message(id);
      }
      p.outro(`${String(ids.length)} changesets`);
    });

  changeset
    .command('restore <id>')
    .description('restore captured pre-push state for one site')
    .option('-y, --yes', 'Skip confirmation prompts')
    .action(async (id: string, options: { yes?: boolean }) => {
      printCommandIntro('changeset restore');
      const record = readChangeset(id);
      const inventory = readFleet();
      const site = inventory.sites[record.site];
      if (!site) {
        p.log.error(
          `Changeset "${id}" targets site "${record.site}", which is no longer in ${FLEET_FILENAME}.`,
        );
        p.outro('Nothing restored');
        process.exitCode = 1;
        return;
      }

      const restorable = Object.entries(record.components).flatMap(
        ([machineName, entry]) =>
          entry.present && entry.payload
            ? [{ machineName, payload: entry.payload }]
            : [],
      );
      const created = Object.entries(record.components)
        .filter(([, entry]) => !entry.present)
        .map(([machineName]) => machineName);

      if (!options.yes) {
        const confirmed = await p.confirm({
          message: `Restore ${String(restorable.length)} components to ${site.url}?`,
          initialValue: false,
        });
        if (p.isCancel(confirmed) || !confirmed) {
          p.cancel('Operation cancelled');
          return;
        }
      }

      const apiService = await createSiteApiService(
        record.site,
        site,
        resolveSiteCredentials(record.site, site),
      );
      let failures = 0;
      for (const { machineName, payload } of restorable) {
        try {
          await apiService.updateComponent(machineName, payload);
          p.log.success(`Restored ${machineName}`);
        } catch (error) {
          failures += 1;
          p.log.error(
            `${machineName}: ${error instanceof Error ? error.message : String(error)}`,
          );
        }
      }
      if (created.length > 0) {
        // Deleting components that pages may already reference is destructive
        // in a way a source change is not, so restore never removes them.
        p.log.warn(
          `Not removed (created by that apply, delete manually if wanted): ${created.join(', ')}`,
        );
      }
      if (failures > 0) {
        p.outro('Restore completed with failures');
        process.exitCode = 1;
        return;
      }
      p.outro('Restore completed');
    });
}
