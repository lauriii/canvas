import chalk from 'chalk';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import { getConfig } from '../config.js';
import { createSiteApiService, readObservedSite } from '../lib/fleet-site.js';
import {
  classifyDrift,
  globalAssetFingerprint,
  isDiverged,
  readFleet,
  readLibrary,
  readLock,
  resolveSiteCredentials,
  resolveTargets,
  sourceFingerprint,
  writeLock,
} from '../lib/fleet.js';
import { buildCanvasProject } from '../utils/build-project.js';
import { updateConfigFromOptions } from '../utils/command-helpers.js';
import { printCommandIntro } from '../utils/command-intro.js';
import { prepareGlobalAssetLibraryUpdate } from '../utils/prepare-push.js';
import { processInPool } from '../utils/request-pool.js';
import { selectDeclaredComponents } from './apply.js';
import { collectRepeatable } from './fleet.js';

import type { Command } from 'commander';
import type { ObservedSite } from '../lib/fleet-site.js';
import type { DriftState, LockFile } from '../lib/fleet.js';

/**
 * The caveat printed with every plan.
 *
 * Canvas computes a component's `active_version` server-side from the prop
 * contract, using per-site prop shape resolution the CLI has no access to, so
 * the CLI cannot derive it locally. Divergence is therefore reported from two
 * signals: the site-reported `active_version` (exact, but blind to code edits
 * that do not change the prop contract) and a fingerprint of the authored
 * source (covers code edits, but assumes the API round-trips that source
 * unchanged). Worded for operators, not for the design doc.
 */
export const PLAN_ACCURACY_NOTE = [
  'This compares what was last pushed with what each site reports now.',
  'It reliably catches prop and slot changes, and catches code edits as long as',
  'sites return code unchanged, so treat a clean plan as strong evidence rather',
  'than proof. It cannot tell you how many pages use a component.',
].join(' ');

export interface PlanOptions {
  site: string[];
  group: string[];
  all?: boolean;
  exclude: string[];
  refreshOnly?: boolean;
  refresh: boolean;
  parallelism: string;
  json?: boolean;
  dir?: string;
}

interface ComponentPlan {
  component: string;
  state: DriftState;
}

interface SitePlan {
  site: string;
  url: string;
  error?: string;
  libraryVersion?: string;
  lastRefresh?: string;
  components: ComponentPlan[];
}

const STATE_STYLE: Record<DriftState, (value: string) => string> = {
  'in-sync': chalk.dim,
  behind: chalk.cyan,
  unknown: chalk.yellow,
  diverged: chalk.red,
  conflicted: chalk.red,
};

function summarize(components: ComponentPlan[]): Record<DriftState, number> {
  const counts: Record<DriftState, number> = {
    'in-sync': 0,
    behind: 0,
    unknown: 0,
    diverged: 0,
    conflicted: 0,
  };
  for (const entry of components) {
    counts[entry.state] += 1;
  }
  return counts;
}

/**
 * Refreshes the lockfile's observed columns for one site.
 *
 * Only the observed columns. The baseline columns record what a successful
 * apply left behind, so refreshing must never touch them: otherwise looking at
 * a divergence would absorb it and the next apply would clobber the edit.
 */
function recordRefresh(
  lock: LockFile,
  siteName: string,
  observed: ObservedSite,
  componentNames: string[],
  observedGlobalHash: string | undefined,
): void {
  const entry = lock.sites[siteName];
  if (!entry) {
    return;
  }
  for (const machineName of componentNames) {
    entry.components[machineName] = {
      ...entry.components[machineName],
      observedHash: observed[machineName]?.versionHash,
      observedSourceHash: observed[machineName]?.sourceHash,
    };
  }
  if (observedGlobalHash !== undefined) {
    entry.globalAsset = {
      ...entry.globalAsset,
      observedSourceHash: observedGlobalHash,
    };
  }
  entry.lastRefresh = new Date().toISOString();
}

export function planCommand(program: Command): void {
  program
    .command('plan')
    .description('read the fleet and report what an apply would change')
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
    .option('--all', 'Target every site in the inventory (default)')
    .option(
      '--exclude <name>',
      'Exclude a site (repeatable)',
      collectRepeatable,
      [],
    )
    .option('--refresh-only', 'Report drift without proposing a library change')
    .option('--no-refresh', 'Use the lockfile without reading the sites')
    .option('--parallelism <n>', 'Max concurrent site operations', '4')
    .option('--json', 'Emit machine-readable output')
    .option('-d, --dir <directory>', 'Component directory')
    .action(async (options: PlanOptions) => {
      try {
        if (!options.json) {
          printCommandIntro('plan');
        }
        updateConfigFromOptions(options);

        const library = readLibrary();
        const fleet = readFleet();
        const targeted =
          options.site.length > 0 || options.group.length > 0 || options.all;
        const targets = resolveTargets(fleet, {
          ...options,
          all: options.all ?? !targeted,
        });
        const lock = readLock();

        // `--refresh-only` asks whether the sites still hold what the CLI last
        // pushed, so the desired state is by definition the pushed state and no
        // build is needed.
        const includesGlobalCss = library.includes?.globalCss !== false;
        let desiredByComponent = new Map<string, string>();
        let desiredGlobalHash: string | undefined;
        if (!options.refreshOnly) {
          const { componentDir, aliasBaseDir, outputDir } = getConfig();
          const discoveryResult = await discoverCanvasProject({
            componentRoot: componentDir,
            projectRoot: process.cwd(),
          });
          const build = await buildCanvasProject({
            projectRoot: process.cwd(),
            componentDir,
            aliasBaseDir,
            outputDir,
            discoveryResult,
            cleanOutputDir: true,
            requireJsEntries: true,
          });
          if (
            build.componentResults.some((result) => !result.success) ||
            !build.tailwindResult.success
          ) {
            throw new Error('Library build failed. Cannot compute a plan.');
          }
          desiredByComponent = new Map(
            selectDeclaredComponents(
              build.builtComponents,
              library.components,
              (message) => p.log.warn(message),
            ).map((component) => [
              component.machineName,
              sourceFingerprint(component.componentPayload),
            ]),
          );
          if (includesGlobalCss) {
            desiredGlobalHash = globalAssetFingerprint(
              (await prepareGlobalAssetLibraryUpdate(outputDir, process.cwd()))
                .assetLibrary,
            );
          }
        }

        const results = await processInPool(
          targets,
          async (siteName): Promise<SitePlan> => {
            const site = fleet.sites[siteName];
            const lockEntry = lock.sites[siteName];
            const plan: SitePlan = {
              site: siteName,
              url: site.url,
              libraryVersion: lockEntry?.libraryVersion,
              lastRefresh: lockEntry?.lastRefresh,
              components: [],
            };

            let observed: ObservedSite | undefined;
            let observedGlobalHash: string | undefined;
            if (options.refresh) {
              const apiService = await createSiteApiService(
                siteName,
                site,
                resolveSiteCredentials(siteName, site),
              );
              observed = await readObservedSite(apiService);
              if (includesGlobalCss) {
                observedGlobalHash = globalAssetFingerprint(
                  await apiService.getGlobalAssetLibrary(),
                );
              }
            }

            const componentNames = [
              ...new Set([
                ...(options.refreshOnly
                  ? Object.keys(lockEntry?.components ?? {})
                  : desiredByComponent.keys()),
                ...library.components,
              ]),
            ].sort();

            for (const machineName of componentNames) {
              const locked = lockEntry?.components[machineName];
              plan.components.push({
                component: machineName,
                state: classifyDrift({
                  desiredSourceHash: options.refreshOnly
                    ? locked?.pushedSourceHash
                    : desiredByComponent.get(machineName),
                  locked,
                  refreshed: options.refresh,
                  observedSourceHash: observed?.[machineName]?.sourceHash,
                  observedHash: observed?.[machineName]?.versionHash,
                }),
              });
            }

            // Global CSS is diffed alongside the components: a release that
            // only changes it must not read as no changes.
            if (includesGlobalCss) {
              plan.components.push({
                component: 'global CSS',
                state: classifyDrift({
                  desiredSourceHash: options.refreshOnly
                    ? lockEntry?.globalAsset?.pushedSourceHash
                    : desiredGlobalHash,
                  locked: lockEntry?.globalAsset,
                  refreshed: options.refresh,
                  observedSourceHash: observedGlobalHash,
                }),
              });
            }

            if (options.refreshOnly && observed) {
              recordRefresh(
                lock,
                siteName,
                observed,
                componentNames,
                observedGlobalHash,
              );
            }
            return plan;
          },
          Math.max(1, Number.parseInt(options.parallelism, 10) || 4),
        );

        const plans: SitePlan[] = results.map((result, index) =>
          result.result
            ? result.result
            : {
                site: targets[index],
                url: fleet.sites[targets[index]].url,
                error: result.error?.message ?? 'Unknown error',
                components: [],
              },
        );

        if (options.refreshOnly && options.refresh) {
          writeLock(lock);
        }

        const failed = plans.filter((plan) => plan.error);
        const anyDiverged = plans.some((plan) =>
          plan.components.some((entry) => isDiverged(entry.state)),
        );
        const anyPending = plans.some((plan) =>
          plan.components.some(
            (entry) => entry.state === 'behind' || entry.state === 'unknown',
          ),
        );

        if (options.json) {
          console.log(
            JSON.stringify(
              {
                library: library.name,
                version: library.version,
                driftDetection: 'advisory',
                driftDetectionNotice: PLAN_ACCURACY_NOTE,
                stale: !options.refresh,
                plans,
              },
              null,
              2,
            ),
          );
        } else {
          if (!options.refresh) {
            p.log.warn(
              `STALE: --no-refresh did not read any site. Reported state is only as good as canvas.lock.json.\n${plans
                .map(
                  (plan) =>
                    `  ${plan.site}: last refreshed ${plan.lastRefresh ?? 'never'}`,
                )
                .join('\n')}`,
            );
          }
          for (const plan of plans) {
            if (plan.error) {
              p.log.error(`${plan.site}: ${plan.error}`);
              continue;
            }
            const counts = summarize(plan.components);
            const parts = (
              Object.entries(counts) as [DriftState, number][]
            ).flatMap(([state, count]) =>
              count > 0
                ? [STATE_STYLE[state](`${state} ${String(count)}`)]
                : [],
            );
            p.log.message(
              `${chalk.bold(plan.site)} ${chalk.dim(plan.libraryVersion ?? 'never applied')}\n  ${parts.join('  ')}`,
            );
            const notable = plan.components.filter(
              (entry) => entry.state !== 'in-sync',
            );
            if (notable.length > 0) {
              p.log.message(
                notable
                  .map(
                    (entry) =>
                      `  ${STATE_STYLE[entry.state](entry.state.padEnd(10))} ${entry.component}`,
                  )
                  .join('\n'),
              );
            }
          }
          p.log.info(PLAN_ACCURACY_NOTE);
          p.outro(
            failed.length > 0
              ? `Could not read ${String(failed.length)} of ${String(plans.length)} sites`
              : anyDiverged
                ? 'Divergence detected: resolve before applying'
                : anyPending
                  ? 'Changes pending'
                  : 'No changes',
          );
        }

        if (failed.length > 0) {
          process.exitCode = 1;
        } else if (anyDiverged) {
          process.exitCode = 3;
        } else if (anyPending) {
          process.exitCode = 2;
        } else {
          process.exitCode = 0;
        }
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        if (options.json) {
          console.log(JSON.stringify({ error: message }, null, 2));
        } else {
          p.log.error(message);
          p.outro('Plan failed');
        }
        process.exitCode = 1;
      }
    });
}
