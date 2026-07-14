import { defineEventHandler, toWebRequest } from 'h3';

import { getDraftServer } from '../session';

/**
 * In-place session renewal: POST `{assertion}`, answered with
 * `{tokenExpiresAt}`. Mounted at POST /api/draft/renew by the module.
 */
export default defineEventHandler((event) =>
  getDraftServer(event).renewDraftSession(toWebRequest(event)),
);
