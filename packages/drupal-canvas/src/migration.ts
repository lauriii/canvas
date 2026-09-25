/**
 * @file
 * Migration guidance shared by the legacy API errors, the hook warnings, and
 * the Canvas CLI's pull codemod report.
 */

import { isLegacyRuntimeSupported } from './runtime.js';

/**
 * The AI-agent migration prompt included in legacy API errors and printed by
 * the Canvas CLI for components it could not migrate automatically.
 */
export const AGENT_MIGRATION_PROMPT =
  'Replace `getPageData()`, `getSiteData()`, and `new JsonApiClient()` with ' +
  '`usePageContext()`, `useSiteContext()`, and `useJsonApiClient()` from ' +
  '`drupal-canvas/react` in function ' +
  'components or custom hooks. Call hooks unconditionally at the top level before ' +
  'any possible return; handle missing context or clients. Outside components and ' +
  'custom hooks, use SDK page context data or `getClient()` in headless server ' +
  'code; pass data or a client to browser helpers. Preserve output, types, hook ' +
  'order, and access controls. Never expose credentials.';

/** Replacement guidance per legacy API. */
export const LEGACY_API_GUIDANCE = {
  'getPageData()':
    'Use `usePageContext()` in React components, or read `page.context.page` ' +
    "from the Headless SDK's page response outside components.",
  'getSiteData()':
    'Use `useSiteContext()` in React components, or read `page.context.site` ' +
    "from the Headless SDK's page response outside components.",
  'new JsonApiClient()':
    "Use `useJsonApiClient()` in React components, or the Headless SDK's " +
    '`getClient()` in headless server code.',
} as const;

export type LegacyApiName = keyof typeof LEGACY_API_GUIDANCE;

/**
 * Builds the error message thrown when a legacy API is invoked outside Drupal
 * and Workbench.
 */
export function formatLegacyApiError(api: LegacyApiName): string {
  return (
    `[drupal-canvas] ${api} is only supported in Drupal-rendered Code ` +
    'Components and Canvas Workbench previews; it is not available in this ' +
    `environment. ${LEGACY_API_GUIDANCE[api]}\n\n` +
    `Migration prompt for AI agents: ${AGENT_MIGRATION_PROMPT}`
  );
}

/**
 * Throws the actionable migration error for a legacy API when the current
 * environment is neither Drupal nor Workbench. Importing the API is always
 * allowed; only invoking it is guarded.
 */
export function assertLegacyRuntime(api: LegacyApiName): void {
  if (!isLegacyRuntimeSupported()) {
    throw new Error(formatLegacyApiError(api));
  }
}
