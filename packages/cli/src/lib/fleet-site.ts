import { getTokenEntry } from '@drupal-canvas/auth';

import { getConfig, getDefaultScope } from '../config.js';
import { ApiService } from '../services/api.js';
import { componentConfigEntityId, sourceFingerprint } from './fleet.js';

import type { Component } from '../types/Component.js';
import type { FleetSite } from './fleet.js';

/** Per-component state as the site currently reports it. */
export interface ObservedComponent {
  /** Fingerprint of the authored source the site returns. */
  sourceHash: string;
  /** Canvas `active_version`, server-computed. Absent for unknown components. */
  versionHash?: string;
  /**
   * Whether anything on the site references this component. Undefined when the
   * site does not expose usage to external clients.
   */
  inUse?: boolean;
  payload: Component;
}

export type ObservedSite = Record<string, ObservedComponent>;

/**
 * Builds an API client for one fleet site.
 *
 * Credentials come from the site's `credentialsEnv`. When a site declares no
 * credentials environment variable, the token stored by `canvas login` for that
 * site URL is used instead, which is how an operator drives non-production
 * sites interactively.
 */
export async function createSiteApiService(
  siteName: string,
  site: FleetSite,
  credentials: { clientId: string; clientSecret: string } | undefined,
): Promise<ApiService> {
  const { userAgent, includeBrandKit } = getConfig();
  if (credentials) {
    return ApiService.create({
      siteUrl: site.url,
      clientId: credentials.clientId,
      clientSecret: credentials.clientSecret,
      scope: getDefaultScope(false, includeBrandKit),
      userAgent,
    });
  }

  const tokenEntry = getTokenEntry(site.url);
  if (!tokenEntry) {
    throw new Error(
      `No credentials for site "${siteName}". Set "credentialsEnv" in canvas.fleet.json, or run \`canvas login --site-url ${site.url}\`.`,
    );
  }
  return ApiService.create({
    siteUrl: site.url,
    clientId: tokenEntry.clientId,
    clientSecret: '',
    scope: '',
    userAgent,
    accessToken: tokenEntry.accessToken,
    refreshToken: tokenEntry.refreshToken,
    tokenEndpoint: tokenEntry.tokenEndpoint,
  });
}

/**
 * Reads a site's current code component state.
 *
 * Two reads: the authored source of every code component, and the active
 * version of every `Component` config entity. The latter is the only
 * authoritative identity Canvas exposes and cannot be recomputed locally.
 */
export async function readObservedSite(
  apiService: Pick<
    ApiService,
    'listComponents' | 'listComponentVersions' | 'listComponentUsage'
  >,
): Promise<ObservedSite> {
  const [components, versions, usage] = await Promise.all([
    apiService.listComponents(),
    apiService.listComponentVersions(),
    apiService.listComponentUsage(),
  ]);
  const observed: ObservedSite = {};
  for (const [machineName, payload] of Object.entries(components)) {
    const id = componentConfigEntityId(machineName);
    observed[machineName] = {
      sourceHash: sourceFingerprint(payload),
      versionHash: versions.get(id),
      inUse: usage?.get(id),
      payload,
    };
  }
  return observed;
}
