import { defineEventHandler } from 'h3';

import { getDraftServer } from '../session';

/**
 * Draft-mode exit. POST, not GET: exiting changes state (it clears the
 * session cookies), and a GET endpoint reached by links would be eligible
 * for prefetching — a framework or browser prefetch could silently end the
 * session. Mounted at POST /api/disable-draft by the module.
 */
export default defineEventHandler((event) =>
  getDraftServer(event).disableDraftMode(),
);
