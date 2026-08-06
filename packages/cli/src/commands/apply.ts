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
  globalAssetFingerprint,
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
  DriftState,
  FleetFile,
  LibraryFile,
  LockFile,
  LockSiteEntry,
} from '../lib/fleet.js';
import type { AssetLibrary } from '../types/Component.js';
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

/**
 * Compares two semver versions by precedence.
 *
 * Only enough of semver to decide whether an apply is a downgrade: numeric
 * release parts, then the rule that a prerelease sorts before its release.
 */
export function compareVersions(a: string, b: string): number {
  const split = (value: string) => {
    const [release, ...prerelease] = value.split('-');
    return {
      release: release.split('.').map((part) => Number.parseInt(part, 10) || 0),
      prerelease: prerelease.join('-'),
    };
  };
  const left = split(a);
  const right = split(b);
  const length = Math.max(left.release.length, right.release.length);
  for (let i = 0; i < length; i++) {
    const diff = (left.release[i] ?? 0) - (right.release[i] ?? 0);
    if (diff !== 0) {
      return diff < 0 ? -1 : 1;
    }
  }
  if (left.prerelease === right.prerelease) {
    return 0;
  }
  if (left.prerelease === '') {
    return 1;
  }
  if (right.prerelease === '') {
    return -1;
  }
  return left.prerelease < right.prerelease ? -1 : 1;
}

/**
 * Restricts the build to the components the manifest declares.
 *
 * The manifest is the library's declared contents, so a component that is not
 * listed is not distributed. Both directions of a mismatch are reported: a
 * silently undistributed component is exactly the surprise this avoids.
 */
export function selectDeclaredComponents(
  builtComponents: BuiltComponent[],
  declared: string[] | undefined,
  warn: (message: string) => void,
): BuiltComponent[] {
  if (!Array.isArray(declared) || declared.length === 0) {
    return builtComponents;
  }
  const declaredSet = new Set(declared);
  const builtSet = new Set(
    builtComponents.map((component) => component.machineName),
  );
  const undeclared = [...builtSet].filter((name) => !declaredSet.has(name));
  const missing = declared.filter((name) => !builtSet.has(name));
  if (undeclared.length > 0) {
    warn(
      `Not distributing ${undeclared.join(', ')}: not listed in canvas.library.json "components". Add them there, or re-run \`canvas library init --force\`.`,
    );
  }
  if (missing.length > 0) {
    warn(
      `canvas.library.json declares ${missing.join(', ')}, which the build did not produce.`,
    );
  }
  return builtComponents.filter((component) =>
    declaredSet.has(component.machineName),
  );
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
  const includesGlobalCss = library.includes?.globalCss !== false;

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
      refreshed: true,
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

  // The global asset library is diffed like a component. Without it a release
  // that only changes global CSS would look like no changes at all.
  const desiredGlobalHash = globalAssetFingerprint(globalAssetLibraryUpdate);
  let observedGlobalAsset: AssetLibrary | undefined;
  let globalAssetState: DriftState = 'in-sync';
  if (includesGlobalCss) {
    observedGlobalAsset = await apiService.getGlobalAssetLibrary();
    globalAssetState = classifyDrift({
      desiredSourceHash: desiredGlobalHash,
      locked: lockEntry?.globalAsset,
      refreshed: true,
      observedSourceHash: globalAssetFingerprint(observedGlobalAsset),
    });
    if (isDiverged(globalAssetState)) {
      outcome.skipped.push(`global CSS (${globalAssetState})`);
    }
  }
  const pushGlobalAsset =
    includesGlobalCss &&
    !isDiverged(globalAssetState) &&
    globalAssetState !== 'in-sync';

  // The observed columns are refreshed on every apply, but the baseline columns
  // are only written by a successful push, so looking at a divergence can never
  // absorb it.
  const nextComponents: LockSiteEntry['components'] = {
    ...(lockEntry?.components ?? {}),
  };
  for (const entry of desired) {
    const machineName = entry.component.machineName;
    nextComponents[machineName] = {
      ...nextComponents[machineName],
      observedHash: observed[machineName]?.versionHash,
      observedSourceHash: observed[machineName]?.sourceHash,
    };
  }
  const nextGlobalAsset: LockSiteEntry['globalAsset'] = includesGlobalCss
    ? {
        ...lockEntry?.globalAsset,
        observedSourceHash: globalAssetFingerprint(observedGlobalAsset),
      }
    : lockEntry?.globalAsset;

  /** Writes this site's lockfile entry from the state accumulated so far. */
  const recordLockEntry = (changed: boolean) => {
    const now = new Date().toISOString();
    lock.sites[siteName] = {
      libraryVersion: changed
        ? version
        : (lockEntry?.libraryVersion ?? version),
      appliedAt: changed ? now : (lockEntry?.appliedAt ?? now),
      appliedBy: changed ? appliedBy() : (lockEntry?.appliedBy ?? appliedBy()),
      ...(changed
        ? appliedRef
          ? { appliedRef }
          : {}
        : lockEntry?.appliedRef
          ? { appliedRef: lockEntry.appliedRef }
          : {}),
      lastRefresh: now,
      components: nextComponents,
      ...(nextGlobalAsset ? { globalAsset: nextGlobalAsset } : {}),
    };
  };

  if (toUpload.length === 0 && !pushGlobalAsset) {
    recordLockEntry(false);
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
    ...(pushGlobalAsset && observedGlobalAsset
      ? { globalAsset: observedGlobalAsset }
      : {}),
  };
  writeChangeset(changeset);
  outcome.changesetId = changeset.id;

  await apiService.signalPushStart();
  let uploaded: string[] = [];
  try {
    if (toUpload.length > 0) {
      // Create and update only. Components on the site that the library does
      // not define are site-local and are never touched; deletion propagation
      // needs its own design because removing an in-use component is
      // destructive.
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
      uploaded = uploadResults
        .filter((result) => result.success)
        .map((result) => result.machineName);
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
    }

    if (pushGlobalAsset) {
      const manifestSyncResult = await syncManifestArtifacts(
        getConfig().outputDir,
        { apiService, logInfo: () => {} },
      );
      await updateGlobalAssetLibraryForPush(
        apiService,
        globalAssetLibraryUpdate,
        manifestSyncResult,
      );
      nextGlobalAsset!.pushedSourceHash = desiredGlobalHash;
    }
    await apiService.signalPushComplete();
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    await apiService.signalPushFail(message);
    outcome.error = message;
    // Record the components that did land. Leaving them out would make the next
    // plan read them as Conflicted and block a resume.
    await recordBaselines(apiService, uploaded, toUpload, nextComponents);
    if (nextGlobalAsset?.pushedSourceHash === desiredGlobalHash) {
      nextGlobalAsset.appliedSourceHash = globalAssetFingerprint(
        await apiService.getGlobalAssetLibrary().catch(() => undefined),
      );
      nextGlobalAsset.observedSourceHash = nextGlobalAsset.appliedSourceHash;
    }
    outcome.pushed = uploaded;
    recordLockEntry(uploaded.length > 0);
    return outcome;
  }

  await recordBaselines(
    apiService,
    toUpload.map(({ component }) => component.machineName),
    toUpload,
    nextComponents,
  );
  if (pushGlobalAsset && nextGlobalAsset) {
    nextGlobalAsset.appliedSourceHash = globalAssetFingerprint(
      await apiService.getGlobalAssetLibrary(),
    );
    nextGlobalAsset.observedSourceHash = nextGlobalAsset.appliedSourceHash;
  }
  outcome.pushed = toUpload.map(({ component }) => component.machineName);
  recordLockEntry(true);
  outcome.success = true;
  outcome.changed = true;
  return outcome;
}

/**
 * Reads the server-assigned active versions back and records the baseline.
 *
 * The active version is the only authoritative component identity and cannot be
 * computed locally, so it has to be read after every push.
 */
async function recordBaselines(
  apiService: Awaited<ReturnType<typeof createSiteApiService>>,
  machineNames: string[],
  desired: { component: BuiltComponent; sourceHash: string }[],
  into: LockSiteEntry['components'],
): Promise<void> {
  if (machineNames.length === 0) {
    return;
  }
  const after = await readObservedSite(apiService);
  const desiredByName = new Map(
    desired.map((entry) => [entry.component.machineName, entry.sourceHash]),
  );
  for (const machineName of machineNames) {
    into[machineName] = {
      pushedSourceHash: desiredByName.get(machineName),
      pushedHash: after[machineName]?.versionHash,
      appliedSourceHash: after[machineName]?.sourceHash,
      observedSourceHash: after[machineName]?.sourceHash,
      observedHash: after[machineName]?.versionHash,
    };
  }
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
        const declaredComponents = selectDeclaredComponents(
          build.builtComponents,
          library.components,
          (message) => p.log.warn(message),
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
        // The lockfile is written as soon as each site settles, so a kill
        // mid-chunk never loses a sibling's record. Writes are serialized
        // through one promise chain because they all target the same file.
        let pendingWrite: Promise<void> = Promise.resolve();
        const persist = () => {
          pendingWrite = pendingWrite.then(() => {
            writeLock(lock);
          });
          return pendingWrite;
        };

        for (let i = 0; i < targets.length; i += parallelism) {
          const chunk = targets.slice(i, i + parallelism);
          const chunkOutcomes = await Promise.all(
            chunk.map(async (siteName): Promise<SiteOutcome> => {
              try {
                const outcome = await applyToSite(
                  siteName,
                  fleet,
                  library,
                  version,
                  declaredComponents,
                  globalAssetLibrary,
                  lock,
                  appliedRef,
                );
                await persist();
                return outcome;
              } catch (error) {
                await persist();
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
          await persist();
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
