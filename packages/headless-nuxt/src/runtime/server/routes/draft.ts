import { defineEventHandler, toWebRequest } from 'h3';

import { getDraftServer } from '../session';

/**
 * Draft-mode activation: redeems the `?assertion=` preview URL Drupal
 * minted and redirects to the signed entry path. Mounted at GET /api/draft
 * by the module.
 */
export default defineEventHandler((event) =>
  getDraftServer(event).enableDraftMode(toWebRequest(event)),
);
