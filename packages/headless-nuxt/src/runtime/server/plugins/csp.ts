import { getResponseHeader, setResponseHeader } from 'h3';
import {
  mergeFrameAncestors,
  resolveFrameAncestors,
} from '@drupal-canvas/headless/server';

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
    hook: (name: 'beforeResponse', handler: (event: H3Event) => void) => void;
  };
}

/**
 * Merges the `frame-ancestors` directive into every response's
 * Content-Security-Policy, restricting who may embed the app — the Nitro
 * counterpart of the header withCanvas() configures for Next.js.
 * Registered by the module. Merged, not set: policies the app already
 * sends (default-src, script-src, ...) are preserved — repeated header
 * values included — and only the frame-ancestors directive is this SDK's
 * to own. A response hook rather than middleware, so policies set by
 * route handlers and route rules are seen and merged instead of racing
 * on ordering.
 *
 * The environment is read per response, not at module load, so the dev
 * server picks up .env changes the same way the rest of the SDK does; see
 * resolveFrameAncestors() for the source list rules.
 */
export default (nitroApp: NitroAppLike): void => {
  nitroApp.hooks.hook('beforeResponse', (event) => {
    const existing = getResponseHeader(event, 'content-security-policy');
    setResponseHeader(
      event,
      'Content-Security-Policy',
      mergeFrameAncestors(
        // h3 hands repeated header fields back as an array; numbers
        // cannot occur for this header.
        Array.isArray(existing) ? existing : (existing?.toString() ?? null),
        resolveFrameAncestors(),
      ),
    );
  });
};
