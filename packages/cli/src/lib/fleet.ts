import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

import type { Component } from '../types/Component.js';

/** Root-level file declaring the library's identity, version and contents. */
export const LIBRARY_FILENAME = 'canvas.library.json';
/** Root-level committed inventory of the fleet. Contains no secrets. */
export const FLEET_FILENAME = 'canvas.fleet.json';
/** Root-level committed cache of last-known per-site state. Not truth. */
export const LOCK_FILENAME = 'canvas.lock.json';
/** Directory holding pre-push state captures, relative to the library root. */
export const CHANGESET_DIR = path.join('.canvas', 'changesets');
export const LOCKFILE_VERSION = 1;

export interface LibraryFile {
  name: string;
  version: string;
  components: string[];
  includes?: {
    globalCss?: boolean;
    brandKit?: boolean;
  };
  engines?: {
    canvasCli?: string;
  };
}

export interface FleetSite {
  url: string;
  /** Name of the environment variable holding `client_id:client_secret`. */
  credentialsEnv?: string;
  /** Stage 3. Parsed for forward compatibility; not applied yet. */
  overlay?: string;
}

export interface FleetFile {
  groups?: Record<string, string[]>;
  sites: Record<string, FleetSite>;
  /** Groups that require CI or an explicit override to target. */
  protectedGroups?: string[];
}

export interface LockComponentEntry {
  /**
   * Canvas `active_version` observed immediately after the CLI pushed this
   * component. Server-computed; the CLI cannot derive it locally.
   */
  pushedHash?: string;
  /** Canvas `active_version` observed at the last refresh. */
  observedHash?: string;
  /** Fingerprint of the source payload the CLI sent. */
  pushedSourceHash?: string;
  /** Fingerprint of the source payload the site returned at the last refresh. */
  observedSourceHash?: string;
}

export interface LockSiteEntry {
  libraryVersion: string;
  appliedAt: string;
  appliedBy: string;
  /** Git ref of the library at apply time. The merge ancestor for Stage 3. */
  appliedRef?: string;
  lastRefresh?: string;
  components: Record<string, LockComponentEntry>;
}

export interface LockFile {
  lockfileVersion: number;
  generatedAt: string;
  sites: Record<string, LockSiteEntry>;
}

export interface Changeset {
  id: string;
  site: string;
  siteUrl: string;
  capturedAt: string;
  libraryVersion: string;
  /**
   * Pre-push state of every component the apply was about to touch. `present`
   * is false for components that did not exist on the site yet.
   */
  components: Record<
    string,
    { present: boolean; version?: string; payload?: Component }
  >;
}

/**
 * Diff state of one component on one site.
 *
 * @see the five-state table in the fleet management specification.
 */
export type DriftState =
  | 'in-sync'
  | 'behind'
  | 'unknown'
  | 'diverged'
  | 'conflicted';

function readJsonFile<T>(filePath: string): T | undefined {
  if (!fs.existsSync(filePath)) {
    return undefined;
  }
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf-8')) as T;
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    throw new Error(`Invalid JSON in ${path.basename(filePath)}: ${message}`);
  }
}

function writeJsonFile(filePath: string, contents: unknown): void {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(contents, null, 2)}\n`, 'utf-8');
}

export function libraryPath(root: string = process.cwd()): string {
  return path.resolve(root, LIBRARY_FILENAME);
}

export function fleetPath(root: string = process.cwd()): string {
  return path.resolve(root, FLEET_FILENAME);
}

export function lockPath(root: string = process.cwd()): string {
  return path.resolve(root, LOCK_FILENAME);
}

export function readLibrary(root: string = process.cwd()): LibraryFile {
  const library = readJsonFile<LibraryFile>(libraryPath(root));
  if (!library) {
    throw new Error(
      `No ${LIBRARY_FILENAME} found. Run \`canvas library init\` first.`,
    );
  }
  if (typeof library.version !== 'string' || library.version.length === 0) {
    throw new Error(`${LIBRARY_FILENAME} is missing a "version".`);
  }
  return library;
}

export function readFleet(root: string = process.cwd()): FleetFile {
  const fleet = readJsonFile<FleetFile>(fleetPath(root));
  if (!fleet) {
    throw new Error(
      `No ${FLEET_FILENAME} found. Run \`canvas fleet init\` first.`,
    );
  }
  if (!fleet.sites || typeof fleet.sites !== 'object') {
    throw new Error(`${FLEET_FILENAME} is missing a "sites" object.`);
  }
  for (const [name, site] of Object.entries(fleet.sites)) {
    if (!site || typeof site.url !== 'string' || site.url.length === 0) {
      throw new Error(`Site "${name}" in ${FLEET_FILENAME} has no "url".`);
    }
  }
  for (const [group, members] of Object.entries(fleet.groups ?? {})) {
    if (!Array.isArray(members)) {
      throw new Error(
        `Group "${group}" in ${FLEET_FILENAME} must be an array of site names.`,
      );
    }
    for (const member of members) {
      if (!(member in fleet.sites)) {
        throw new Error(
          `Group "${group}" in ${FLEET_FILENAME} references unknown site "${member}".`,
        );
      }
    }
  }
  return fleet;
}

/** True when this directory is a fleet library root. */
export function hasFleetFiles(root: string = process.cwd()): boolean {
  return fs.existsSync(fleetPath(root));
}

export function readLock(root: string = process.cwd()): LockFile {
  return (
    readJsonFile<LockFile>(lockPath(root)) ?? {
      lockfileVersion: LOCKFILE_VERSION,
      generatedAt: new Date().toISOString(),
      sites: {},
    }
  );
}

export function writeLock(lock: LockFile, root: string = process.cwd()): void {
  writeJsonFile(lockPath(root), {
    ...lock,
    lockfileVersion: LOCKFILE_VERSION,
    generatedAt: new Date().toISOString(),
  });
}

export interface TargetOptions {
  site?: string[];
  group?: string[];
  all?: boolean;
  exclude?: string[];
}

/**
 * Resolves targeting flags into an ordered, deduplicated list of site names.
 *
 * Sites are ordered by group as declared: for `--group` the order the flags
 * were given, for `--all` the order groups appear in the inventory followed by
 * any ungrouped sites.
 */
export function resolveTargets(
  fleet: FleetFile,
  options: TargetOptions,
): string[] {
  const known = new Set(Object.keys(fleet.sites));
  const groups = fleet.groups ?? {};

  for (const name of options.site ?? []) {
    if (!known.has(name)) {
      throw new Error(
        `Unknown site "${name}". Known sites: ${[...known].join(', ') || '(none)'}.`,
      );
    }
  }
  for (const name of options.group ?? []) {
    if (!(name in groups)) {
      throw new Error(
        `Unknown group "${name}". Known groups: ${Object.keys(groups).join(', ') || '(none)'}.`,
      );
    }
  }
  for (const name of options.exclude ?? []) {
    if (!known.has(name)) {
      throw new Error(
        `Unknown site "${name}" in --exclude. Known sites: ${[...known].join(', ') || '(none)'}.`,
      );
    }
  }

  const ordered: string[] = [];
  const add = (name: string) => {
    if (!ordered.includes(name)) {
      ordered.push(name);
    }
  };

  for (const group of options.group ?? []) {
    groups[group].forEach(add);
  }
  (options.site ?? []).forEach(add);
  if (options.all) {
    for (const members of Object.values(groups)) {
      members.forEach(add);
    }
    Object.keys(fleet.sites).forEach(add);
  }

  const excluded = new Set(options.exclude ?? []);
  return ordered.filter((name) => !excluded.has(name));
}

/** Groups from the inventory that contain the given site. */
export function groupsForSite(fleet: FleetFile, siteName: string): string[] {
  return Object.entries(fleet.groups ?? {})
    .filter(([, members]) => members.includes(siteName))
    .map(([group]) => group);
}

/** Protected groups among the targeted sites, per `protectedGroups`. */
export function targetedProtectedGroups(
  fleet: FleetFile,
  targets: string[],
): string[] {
  const protectedGroups = new Set(fleet.protectedGroups ?? []);
  const hit = new Set<string>();
  for (const target of targets) {
    for (const group of groupsForSite(fleet, target)) {
      if (protectedGroups.has(group)) {
        hit.add(group);
      }
    }
  }
  return [...hit];
}

export interface SiteCredentials {
  clientId: string;
  clientSecret: string;
}

/**
 * Reads a site's OAuth client credentials from the environment.
 *
 * Returns undefined when the site declares no `credentialsEnv`, in which case
 * the caller falls back to the token `canvas login` stored for that site URL.
 */
export function resolveSiteCredentials(
  siteName: string,
  site: FleetSite,
  env: NodeJS.ProcessEnv = process.env,
): SiteCredentials | undefined {
  if (!site.credentialsEnv) {
    return undefined;
  }
  const raw = env[site.credentialsEnv];
  if (!raw) {
    throw new Error(
      `Credentials for site "${siteName}" are missing: set $${site.credentialsEnv} to "client_id:client_secret".`,
    );
  }
  const separator = raw.indexOf(':');
  const clientId = separator === -1 ? '' : raw.slice(0, separator);
  const clientSecret = separator === -1 ? '' : raw.slice(separator + 1);
  if (!clientId || !clientSecret) {
    throw new Error(
      `$${site.credentialsEnv} for site "${siteName}" must be formatted "client_id:client_secret".`,
    );
  }
  return { clientId, clientSecret };
}

function canonicalize(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(canonicalize);
  }
  if (value && typeof value === 'object') {
    const source = value as Record<string, unknown>;
    return Object.fromEntries(
      Object.keys(source)
        .sort()
        .map((key) => [key, canonicalize(source[key])]),
    );
  }
  return value;
}

/**
 * Fingerprints the authored source of a component.
 *
 * Compiled artifacts are deliberately excluded: the Vite and Tailwind builds
 * are not byte-reproducible, so hashing them would report drift on every run.
 * This is NOT Canvas's version hash, which is server-computed from the prop
 * contract and cannot be derived client-side.
 */
export function sourceFingerprint(component: Partial<Component>): string {
  const source = component as Record<string, unknown>;
  const sortedStrings = (value: unknown): string[] =>
    Array.isArray(value) ? [...(value as string[])].sort() : [];
  const normalized = {
    name: typeof source.name === 'string' ? source.name : '',
    status: source.status === false ? false : true,
    required: sortedStrings(source.required),
    props: source.props ?? {},
    slots: source.slots ?? {},
    sourceCodeJs:
      typeof source.sourceCodeJs === 'string' ? source.sourceCodeJs : '',
    sourceCodeCss:
      typeof source.sourceCodeCss === 'string' ? source.sourceCodeCss : '',
    importedJsComponents: sortedStrings(source.importedJsComponents),
    dataDependencies: source.dataDependencies ?? {},
  };
  return crypto
    .createHash('sha256')
    .update(JSON.stringify(canonicalize(normalized)))
    .digest('hex')
    .slice(0, 16);
}

/** Canvas `Component` config entity ID for a code component machine name. */
export function componentConfigEntityId(machineName: string): string {
  return `js.${machineName}`;
}

export interface DriftInputs {
  /** Fingerprint of the payload the CLI would push now. */
  desiredSourceHash?: string;
  locked?: LockComponentEntry;
  /** Fingerprint of the payload the site returns now. Absent when unread. */
  observedSourceHash?: string;
  /** `active_version` the site reports now. Absent when unread. */
  observedHash?: string;
}

/**
 * Classifies one component on one site into the five-state diff.
 *
 * Every comparison stays inside one domain: desired against what the CLI sent
 * (both locally computed), observed against what the site reported at the last
 * apply or refresh (both server-reported). Comparing a locally built payload
 * directly against a server-stored one would report drift for any server-side
 * normalization.
 */
export function classifyDrift(inputs: DriftInputs): DriftState {
  const { locked } = inputs;
  if (
    !locked ||
    locked.pushedSourceHash === undefined ||
    inputs.desiredSourceHash === undefined
  ) {
    return 'unknown';
  }
  const desiredEqPushed = inputs.desiredSourceHash === locked.pushedSourceHash;

  // No refresh happened: the site was not read, so divergence is undetectable.
  if (inputs.observedSourceHash === undefined) {
    return desiredEqPushed ? 'in-sync' : 'behind';
  }

  const observedEqPushed =
    inputs.observedSourceHash === locked.observedSourceHash &&
    inputs.observedHash === locked.observedHash;

  if (desiredEqPushed && observedEqPushed) {
    return 'in-sync';
  }
  if (observedEqPushed) {
    return 'behind';
  }
  if (desiredEqPushed) {
    return 'diverged';
  }
  return 'conflicted';
}

/** True for states `apply` refuses to write, per the safety rule. */
export function isDiverged(state: DriftState): boolean {
  return state === 'diverged' || state === 'conflicted';
}

export function changesetDir(root: string = process.cwd()): string {
  return path.resolve(root, CHANGESET_DIR);
}

/** Builds a filename-safe changeset ID. */
export function changesetId(siteName: string, at: Date): string {
  return `${at.toISOString().replace(/[:.]/g, '-')}-${siteName}`;
}

export function writeChangeset(
  changeset: Changeset,
  root: string = process.cwd(),
): string {
  const filePath = path.join(changesetDir(root), `${changeset.id}.json`);
  writeJsonFile(filePath, changeset);
  return filePath;
}

export function listChangesets(root: string = process.cwd()): string[] {
  const dir = changesetDir(root);
  if (!fs.existsSync(dir)) {
    return [];
  }
  return fs
    .readdirSync(dir)
    .filter((name) => name.endsWith('.json'))
    .map((name) => name.slice(0, -'.json'.length))
    .sort();
}

export function readChangeset(
  id: string,
  root: string = process.cwd(),
): Changeset {
  const changeset = readJsonFile<Changeset>(
    path.join(changesetDir(root), `${id}.json`),
  );
  if (!changeset) {
    throw new Error(`No changeset "${id}" found under ${CHANGESET_DIR}.`);
  }
  return changeset;
}
