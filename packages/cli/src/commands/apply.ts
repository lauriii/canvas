import { execFileSync } from 'node:child_process';
import chalk from 'chalk';
import { Option } from 'commander';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import { getConfig } from '../config.js';
import { createSiteApiService, readObservedSite } from '../lib/fleet-site.js';
import {
  changesetId,
  classifyDrift,
  isDiverged,
  readFleet,
  readLibrary,
  readLock,
  resolveSiteCredentials,
  resolveTargets,
  sourceFingerprint,
  targetedProtectedGroups,
  writeChangeset,
  writeLock,
} from '../lib/fleet.js';
import { buildCanvasProject } from '../utils/build-project.js';
import { updateConfigFromOptions } from '../utils/command-helpers.js';
import { printCommandIntro } from '../utils/command-intro.js';
import {
  prepareGlobalAssetLibraryUpdate,
  uploadComponents,
} from '../utils/prepare-push.js';
import { collectRepeatable } from './fleet.js';
import {
  syncManifestArtifacts,
  updateGlobalAssetLibraryForPush,
} from './push.js';

import type { Command } from 'commander';
import type {
  Changeset,
  FleetFile,
  LibraryFile,
  LockFile,
  LockSiteEntry,
} from '../lib/fleet.js';
import type { BuiltComponent } from '../utils/build-project.js';

export interface ApplyOptions {
  site: string[];
  group: string[];
  all?: boolean;
  exclude: string[];
  to?: string;
  parallelism: string;
  onError: 'stop' | 'continue';
  json?: boolean;
  dir?: string;
  yes?: boolean;
  iKnowWhatIAmDoing?: boolean;
}

interface SiteOutcome {
  site: string;
  url: string;
  success: boolean;
  changed: boolean;
  error?: string;
  changesetId?: string;
  pushed: string[];
  skipped: string[];
}

/** Compares two dotted numeric versions. Non-numeric parts compare as 0. */
export function compareVersions(a: string, b: string): number {
  const parts = (value: string) =>
    value.split(/[.-]/).map((part) => Number.parseInt(part, 10) || 0);
  const left = parts(a);
  const right = parts(b);
  for (let i = 0; i < Math.max(left.length, right.length); i++) {
    const diff = (left[i] ?? 0) - (right[i] ?? 0);
    if (diff !== 0) {
      return diff < 0 ? -1 : 1;
    }
  }
  return 0;
}

function isCi(env: NodeJS.ProcessEnv = process.env): boolean {
  return Boolean(env.CI);
}

function currentGitRef(): string | undefined {
  try {
    return execFileSync('git', ['rev-parse', 'HEAD'], {
      encoding: 'utf-8',
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim();
  } catch {
    return undefined;
  }
}

function appliedBy(env: NodeJS.ProcessEnv = process.env): string {
  return env.CANVAS_APPLIED_BY ?? (isCi(env) ? 'ci' : (env.USER ?? 'unknown'));
}

/**
 * Applies the built library to one site.
 *
 * Diverged and conflicted components are skipped, never overwritten: `push`
 * replaces a component's source wholesale, so an unguarded fan-out would
 * destroy site-side work across the whole fleet in one command.
 */
async function applyToSite(
  siteName: string,
  fleet: FleetFile,
  library: LibraryFile,
  version: string,
  builtComponents: BuiltComponent[],
  globalAssetLibraryUpdate: Parameters<
    typeof updateGlobalAssetLibraryForPush
  >[1],
  lock: LockFile,
  appliedRef: string | undefined,
): Promise<SiteOutcome> {
  const site = fleet.sites[siteName];
  const outcome: SiteOutcome = {
    site: siteName,
    url: site.url,
    success: false,
    changed: false,
    pushed: [],
    skipped: [],
  };

  const apiService = await createSiteApiService(
    siteName,
    site,
    resolveSiteCredentials(siteName, site),
  );
  const observed = await readObservedSite(apiService);
  const lockEntry = lock.sites[siteName];

  const desired = builtComponents.map((component) => ({
    component,
    sourceHash: sourceFingerprint(component.componentPayload),
  }));

  const toUpload: typeof desired = [];
  for (const entry of desired) {
    const machineName = entry.component.machineName;
    const state = classifyDrift({
      desiredSourceHash: entry.sourceHash,
      locked: lockEntry?.components[machineName],
      observedSourceHash: observed[machineName]?.sourceHash,
      observedHash: observed[machineName]?.versionHash,
    });
    if (isDiverged(state)) {
      outcome.skipped.push(`${machineName} (${state})`);
      continue;
    }
    if (state !== 'in-sync') {
      toUpload.push(entry);
    }
  }

  const nextComponents: LockSiteEntry['components'] = {
    ...(lockEntry?.components ?? {}),
  };
  // Record what the site reports right now for every library component, so a
  // skipped divergence is still visible in the lockfile.
  for (const entry of desired) {
    const machineName = entry.component.machineName;
    nextComponents[machineName] = {
      ...nextComponents[machineName],
      observedHash: observed[machineName]?.versionHash,
      observedSourceHash: observed[machineName]?.sourceHash,
    };
  }

  if (toUpload.length === 0) {
    lock.sites[siteName] = {
      libraryVersion: lockEntry?.libraryVersion ?? version,
      appliedAt: lockEntry?.appliedAt ?? new Date().toISOString(),
      appliedBy: lockEntry?.appliedBy ?? appliedBy(),
      ...(lockEntry?.appliedRef ? { appliedRef: lockEntry.appliedRef } : {}),
      lastRefresh: new Date().toISOString(),
      components: nextComponents,
    };
    outcome.success = true;
    return outcome;
  }

  // Capture the pre-push state of everything about to be touched. This is the
  // only mechanism by which anything can later be restored.
  const changeset: Changeset = {
    id: changesetId(siteName, new Date()),
    site: siteName,
    siteUrl: site.url,
    capturedAt: new Date().toISOString(),
    libraryVersion: version,
    components: Object.fromEntries(
      toUpload.map(({ component }) => {
        const current = observed[component.machineName];
        return [
          component.machineName,
          current
            ? {
                present: true,
                version: current.versionHash,
                payload: current.payload,
              }
            : { present: false },
        ];
      }),
    ),
  };
  writeChangeset(changeset);
  outcome.changesetId = changeset.id;

  await apiService.signalPushStart();
  try {
    // Create and update only. Components on the site that the library does not
    // define are site-local and are never touched; deletion propagation needs
    // its own design because removing an in-use component is destructive.
    const uploadResults = await uploadComponents(
      toUpload.map(({ component }) => ({
        machineName: component.machineName,
        operation: observed[component.machineName] ? 'update' : 'create',
        componentPayload: component.componentPayload,
        importedJsComponents: component.importedJsComponents,
      })),
      apiService,
      () => {},
    );
    const failed = uploadResults.filter((result) => !result.success);
    if (failed.length > 0) {
      throw new Error(
        failed
          .map(
            (result) =>
              `${result.machineName}: ${result.error?.message ?? 'unknown error'}`,
          )
          .join('; '),
      );
    }

    if (library.includes?.globalCss !== false) {
      const manifestSyncResult = await syncManifestArtifacts(
        getConfig().outputDir,
        { apiService, logInfo: () => {} },
      );
      await updateGlobalAssetLibraryForPush(
        apiService,
        globalAssetLibraryUpdate,
        manifestSyncResult,
      );
    }
    await apiService.signalPushComplete();
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    await apiService.signalPushFail(message);
    outcome.error = message;
    return outcome;
  }

  // Read back the server-assigned active versions. They are the only
  // authoritative component identity and cannot be computed locally.
  const after = await readObservedSite(apiService);
  const now = new Date().toISOString();
  for (const { component, sourceHash } of toUpload) {
    const machineName = component.machineName;
    nextComponents[machineName] = {
      pushedSourceHash: sourceHash,
      pushedHash: after[machineName]?.versionHash,
      observedSourceHash: after[machineName]?.sourceHash,
      observedHash: after[machineName]?.versionHash,
    };
    outcome.pushed.push(machineName);
  }
  lock.sites[siteName] = {
    libraryVersion: version,
    appliedAt: now,
    appliedBy: appliedBy(),
    ...(appliedRef ? { appliedRef } : {}),
    lastRefresh: now,
    components: nextComponents,
  };
  outcome.success = true;
  outcome.changed = true;
  return outcome;
}

export function applyCommand(program: Command): void {
  program
    .command('apply')
    .description('push the component library to targeted fleet sites')
    .option(
      '--site <name>',
      'Target a site (repeatable)',
      collectRepeatable,
      [],
    )
    .option(
      '--group <name>',
      'Target a group (repeatable)',
      collectRepeatable,
      [],
    )
    .option('--all', 'Target every site in the inventory')
    .option(
      '--exclude <name>',
      'Exclude a site (repeatable)',
      collectRepeatable,
      [],
    )
    .option('--to <version>', 'Library version to apply')
    .option('--parallelism <n>', 'Max concurrent site operations', '4')
    .addOption(
      new Option('--on-error <mode>', 'Behavior when a site fails')
        .choices(['stop', 'continue'])
        .default('stop'),
    )
    .option('--json', 'Emit machine-readable output')
    .option('-d, --dir <directory>', 'Component directory')
    .option('-y, --yes', 'Skip confirmation prompts')
    .option(
      '--i-know-what-i-am-doing',
      'Allow targeting a protected group outside CI',
    )
    .action(async (options: ApplyOptions) => {
      try {
        if (!options.json) {
          printCommandIntro('apply');
        }
        updateConfigFromOptions(options);

        const library = readLibrary();
        const fleet = readFleet();

        if (
          options.site.length === 0 &&
          options.group.length === 0 &&
          !options.all
        ) {
          throw new Error(
            'apply requires one of --site, --group, or --all. It never targets the whole fleet implicitly.',
          );
        }
        const targets = resolveTargets(fleet, options);
        if (targets.length === 0) {
          p.log.warn('No sites matched the targeting flags.');
          p.outro('Nothing applied');
          return;
        }

        const version = options.to ?? library.version;
        if (options.to && options.to !== library.version) {
          throw new Error(
            `canvas.library.json declares version ${library.version}, not ${options.to}. The library is consumed from a git ref: check out the ref for ${options.to} and run apply there.`,
          );
        }
        if (library.includes?.brandKit) {
          p.log.warn(
            'canvas.library.json sets includes.brandKit, which fleet apply does not distribute yet (open question). Push brand kit fonts per site with `canvas push --include-brand-kit`.',
          );
        }

        const protectedHits = targetedProtectedGroups(fleet, targets);
        if (protectedHits.length > 0 && !isCi() && !options.iKnowWhatIAmDoing) {
          throw new Error(
            `Targets a protected group (${protectedHits.join(', ')}) outside CI. Run it from the pipeline, or pass --i-know-what-i-am-doing. This is a speed bump, not a control: anyone holding a site's credentials can push to it.`,
          );
        }
        if (protectedHits.length > 0 && !isCi()) {
          p.log.warn(
            `Applying to protected group(s) ${protectedHits.join(', ')} from outside CI.`,
          );
        }

        const lock = readLock();
        for (const target of targets) {
          const locked = lock.sites[target]?.libraryVersion;
          if (locked && compareVersions(locked, version) > 0) {
            p.log.warn(
              `${target} is on ${locked}; applying ${version} re-pushes older source. Component instances that already auto-upgraded are NOT reverted: instance version pins are server-side state the CLI does not control.`,
            );
          }
        }

        // Build once. The build output is site-independent; only the upload is
        // per site.
        const { componentDir, aliasBaseDir, outputDir } = getConfig();
        const discoveryResult = await discoverCanvasProject({
          componentRoot: componentDir,
          projectRoot: process.cwd(),
        });
        const buildSpinner = options.json ? undefined : p.spinner();
        buildSpinner?.start('Building library');
        const build = await buildCanvasProject({
          projectRoot: process.cwd(),
          componentDir,
          aliasBaseDir,
          outputDir,
          discoveryResult,
          cleanOutputDir: true,
          requireJsEntries: true,
        }).catch((error: unknown) => {
          buildSpinner?.stop('Build failed', 2);
          throw error;
        });
        if (
          build.componentResults.some((result) => !result.success) ||
          !build.tailwindResult.success
        ) {
          buildSpinner?.stop('Build failed', 2);
          throw new Error('Library build failed. Nothing was applied.');
        }
        buildSpinner?.stop(
          `Built ${String(build.builtComponents.length)} components`,
          0,
        );

        const globalAssetLibrary =
          library.includes?.globalCss === false
            ? undefined
            : (await prepareGlobalAssetLibraryUpdate(outputDir, process.cwd()))
                .assetLibrary;

        if (!options.yes && !options.json) {
          const confirmed = await p.confirm({
            message: `Apply ${library.name} ${version} to ${String(targets.length)} sites (${targets.join(', ')})?`,
            initialValue: true,
          });
          if (p.isCancel(confirmed) || !confirmed) {
            p.cancel('Operation cancelled');
            return;
          }
        }

        const appliedRef = currentGitRef();
        const parallelism = Math.max(
          1,
          Number.parseInt(options.parallelism, 10) || 4,
        );
        const outcomes: SiteOutcome[] = [];
        let stopped = false;

        for (let i = 0; i < targets.length; i += parallelism) {
          const chunk = targets.slice(i, i + parallelism);
          const chunkOutcomes = await Promise.all(
            chunk.map(async (siteName): Promise<SiteOutcome> => {
              try {
                return await applyToSite(
                  siteName,
                  fleet,
                  library,
                  version,
                  build.builtComponents,
                  globalAssetLibrary,
                  lock,
                  appliedRef,
                );
              } catch (error) {
                return {
                  site: siteName,
                  url: fleet.sites[siteName].url,
                  success: false,
                  changed: false,
                  error: error instanceof Error ? error.message : String(error),
                  pushed: [],
                  skipped: [],
                };
              }
            }),
          );
          outcomes.push(...chunkOutcomes);
          // The lockfile is written after every chunk so a crash never loses
          // the record of what already landed.
          writeLock(lock);
          if (
            chunkOutcomes.some((outcome) => !outcome.success) &&
            options.onError === 'stop'
          ) {
            stopped = true;
            break;
          }
        }

        const untouched = targets.filter(
          (name) => !outcomes.some((outcome) => outcome.site === name),
        );
        const failures = outcomes.filter((outcome) => !outcome.success);

        if (options.json) {
          console.log(
            JSON.stringify(
              { library: library.name, version, outcomes, untouched },
              null,
              2,
            ),
          );
        } else {
          for (const outcome of outcomes) {
            const label = outcome.success
              ? outcome.changed
                ? chalk.green('applied')
                : chalk.dim('no changes')
              : chalk.red('failed');
            const detail = outcome.success
              ? outcome.pushed.length > 0
                ? ` ${String(outcome.pushed.length)} components`
                : ''
              : ` ${outcome.error ?? ''}`;
            const restorePoint = outcome.changesetId
              ? chalk.dim(
                  `\n  restore with \`canvas changeset restore ${outcome.changesetId}\``,
                )
              : '';
            p.log.message(
              `${chalk.bold(outcome.site)}: ${label}${detail}${restorePoint}`,
            );
            if (outcome.skipped.length > 0) {
              p.log.warn(
                `${outcome.site}: skipped ${outcome.skipped.join(', ')} — run \`canvas plan --site ${outcome.site}\` to inspect.`,
              );
            }
          }
          if (stopped && untouched.length > 0) {
            p.log.warn(
              `Stopped after a failure. Untouched: ${untouched.join(', ')}. Resume with --site ${untouched.join(' --site ')}.`,
            );
          }
          p.outro(
            failures.length > 0
              ? `Applied to ${String(outcomes.length - failures.length)} of ${String(targets.length)} sites`
              : `Applied ${library.name} ${version} to ${String(outcomes.length)} sites`,
          );
        }

        if (failures.length > 0) {
          process.exitCode = 1;
        }
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        if (options.json) {
          console.log(JSON.stringify({ error: message }, null, 2));
        } else {
          p.log.error(message);
          p.outro('Apply failed');
        }
        process.exitCode = 1;
      }
    });
}
