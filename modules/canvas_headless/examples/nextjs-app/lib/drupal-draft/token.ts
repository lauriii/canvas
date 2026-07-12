import { isDraftSessionExpired, type DraftData } from "./draft-data";

export interface AccessToken {
  tokenType: string;
  value: string;
}

/**
 * Returns the draft session's access token, or null when it has expired.
 *
 * The token was minted by exchanging the signed preview assertion and is
 * bound to the editor who initiated the preview. There are no refresh
 * tokens and no client credentials to fall back on — this app holds no
 * OAuth secret at all. Sessions renew by redeeming a *fresh assertion*
 * minted from the editor's live Drupal session; an expired token means
 * the session ended before a renewal happened.
 */
export function getSessionToken(draftData: DraftData): AccessToken | null {
  if (isDraftSessionExpired(draftData)) {
    return null;
  }
  return { tokenType: draftData.tokenType, value: draftData.accessToken };
}
