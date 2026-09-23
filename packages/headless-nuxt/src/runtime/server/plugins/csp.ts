import { getResponseHeader, setResponseHeader } from 'h3';
import {
  mergeFrameAncestors,
  resolveFrameAncestors,
} from '@drupal-canvas/headless/server';

import { getDraftData } from '../session';

import type { H3Event } from 'h3';

/**
 * The slice of the Nitro app a response-header plugin needs, typed
 * structurally: importing defineNitroPlugin (an identity helper) from
 * nitropack/runtime would make this package depend on a module it never
 * declares — nitropack arrives through Nuxt, and strict consumer installs
 * may not resolve it directly.
 */
interface NitroAppLike {
  hooks: {
    hook: (
      name: 'beforeResponse',
      handler: (event: H3Event) => void | Promise<void>,
    ) => void;
  };
}

/**
 * Merges the `frame-ancestors` directive into every response's
 * Content-Security-Policy, restricting who may embed the app.
 * Registered by the module. Merged, not set: policies the app already
 * sends (default-src, script-src, ...) are preserved — repeated header
 * values included. An application-owned frame-ancestors directive remains
 * authoritative. A response hook rather than middleware, so policies set by
 * route handlers and route rules are seen and merged instead of racing
 * on ordering.
 *
 * The shared resolver uses CANVAS_EDITOR_ORIGINS when set, or the site and
 * draft editor origins by default, always including 'self'.
 */
export default (nitroApp: NitroAppLike): void => {
  nitroApp.hooks.hook('beforeResponse', async (event) => {
    const existing = getResponseHeader(event, 'content-security-policy');
    setResponseHeader(
      event,
      'Content-Security-Policy',
      mergeFrameAncestors(
        // h3 hands repeated header fields back as an array; numbers
        // cannot occur for this header.
        Array.isArray(existing) ? existing : (existing?.toString() ?? null),
        resolveFrameAncestors(await getDraftData(event)),
      ),
    );
  });
};
