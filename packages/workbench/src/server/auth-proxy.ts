import {
  defaultTokenEndpoint,
  fetchClientCredentialsToken,
  getTokenEntry,
  OauthError,
  refreshAccessToken,
  setTokenEntry,
} from '@drupal-canvas/auth';

import type { OauthTokenResponse, SiteTokenEntry } from '@drupal-canvas/auth';
import type { ProxyOptions } from 'vite';

const WORKBENCH_DEFAULT_SCOPE = 'canvas:content_template';
const MIN_REFRESH_DELAY_MS = 30_000;

async function tryRefresh(
  fn: () => Promise<OauthTokenResponse>,
): Promise<OauthTokenResponse | null> {
  try {
    return await fn();
  } catch (error) {
    if (error instanceof OauthError) {
      console.warn(`[workbench] ${error.message}`);
    } else {
      console.warn(
        `[workbench] OAuth token refresh failed: ${error instanceof Error ? error.message : String(error)}`,
      );
    }
    return null;
  }
}

/**
 * Manages a Bearer token for the workbench dev-server proxy.
 *
 * Tokens come from one of three sources (resolution order in `start()`):
 *   1. `CANVAS_ACCESS_TOKEN` — used as a static Bearer (no refresh).
 *   2. `CANVAS_CLIENT_ID` + `CANVAS_CLIENT_SECRET` — fetched via the OAuth
 *      `client_credentials` grant; refreshed by re-fetching.
 *   3. CLI token store (`~/.config/drupal-canvas/oauth.json`) — populated by
 *      `canvas login`; refreshed via `refresh_token` grant.
 *
 * The proxy reads the current header via `getHeader()`. A timer schedules a
 * proactive refresh at 80% of the token's lifetime (when `expires_in` is
 * known); on failure it falls back to the existing token until the next
 * scheduled attempt.
 */
class TokenManager {
  private currentHeader: string | null = null;
  private refresh: (() => Promise<OauthTokenResponse | null>) | null = null;
  private timer: NodeJS.Timeout | null = null;
  private siteUrl: string | null = null;
  // Holds the latest token entry on the refresh-token path so each call to
  // `this.refresh()` uses the most recent refresh token. simple_oauth
  // rotates refresh tokens by default, so a captured-once entry would only
  // work for the first refresh.
  private tokenEntry: SiteTokenEntry | null = null;

  getHeader(): string | null {
    return this.currentHeader;
  }

  async start(siteUrl: string, env: Record<string, string>): Promise<void> {
    this.siteUrl = siteUrl;
    if (env.CANVAS_ACCESS_TOKEN) {
      this.currentHeader = `Bearer ${env.CANVAS_ACCESS_TOKEN}`;
      return;
    }
    if (env.CANVAS_CLIENT_ID && env.CANVAS_CLIENT_SECRET) {
      const scope = env.CANVAS_SCOPE || WORKBENCH_DEFAULT_SCOPE;
      const clientId = env.CANVAS_CLIENT_ID;
      const clientSecret = env.CANVAS_CLIENT_SECRET;
      const tokenEndpoint = defaultTokenEndpoint(siteUrl);
      this.refresh = () =>
        tryRefresh(() =>
          fetchClientCredentialsToken({
            tokenEndpoint,
            clientId,
            clientSecret,
            scope,
          }),
        );
      const initial = await this.refresh();
      if (initial) {
        this.applyToken(initial);
      } else {
        console.warn(
          '[workbench] OAuth token fetch failed — proxy will forward unauthenticated requests.',
        );
      }
      return;
    }
    const tokenEntry = getTokenEntry(siteUrl);
    if (tokenEntry) {
      this.tokenEntry = tokenEntry;
      this.refresh = () => {
        const entry = this.tokenEntry;
        if (!entry || !entry.refreshToken) return Promise.resolve(null);
        const refreshToken = entry.refreshToken;
        return tryRefresh(() =>
          refreshAccessToken({
            tokenEndpoint: entry.tokenEndpoint,
            refreshToken,
            clientId: entry.clientId,
          }),
        );
      };
      this.applyToken({
        accessToken: tokenEntry.accessToken,
        expiresAt: tokenEntry.expiresAt,
        refreshToken: tokenEntry.refreshToken,
      });
      if (this.shouldRefreshNow(tokenEntry.expiresAt)) {
        const refreshed = await this.refresh();
        if (refreshed) {
          this.persistRefreshedToken(refreshed);
          this.applyToken(refreshed);
        }
      }
      return;
    }
    console.warn(
      '[workbench] No auth credentials configured. Set CANVAS_ACCESS_TOKEN, ' +
        'CANVAS_CLIENT_ID + CANVAS_CLIENT_SECRET, or run `canvas login` to ' +
        'enable authenticated requests to the Drupal site.',
    );
  }

  stop(): void {
    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }
  }

  private shouldRefreshNow(expiresAt: number | undefined): boolean {
    if (!expiresAt) return false;
    return expiresAt - Date.now() < MIN_REFRESH_DELAY_MS;
  }

  private applyToken(token: OauthTokenResponse): void {
    this.currentHeader = `Bearer ${token.accessToken}`;
    this.scheduleRefresh(token.expiresAt);
  }

  private persistRefreshedToken(token: OauthTokenResponse): void {
    if (!this.tokenEntry || !this.siteUrl || !token.refreshToken) {
      return;
    }
    this.tokenEntry = {
      accessToken: token.accessToken,
      refreshToken: token.refreshToken,
      expiresAt: token.expiresAt,
      tokenEndpoint: this.tokenEntry.tokenEndpoint,
      clientId: this.tokenEntry.clientId,
    };
    try {
      setTokenEntry(this.siteUrl, this.tokenEntry);
    } catch (error) {
      console.warn(
        `[workbench] Failed to persist refreshed token: ${error instanceof Error ? error.message : String(error)}`,
      );
    }
  }

  private scheduleRefresh(expiresAt: number | undefined): void {
    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }
    if (!expiresAt || !this.refresh) return;
    const lifetime = expiresAt - Date.now();
    const delay = Math.min(
      Math.max(Math.floor(lifetime * 0.8), MIN_REFRESH_DELAY_MS),
      24 * 60 * 60 * 1000,
    );
    this.timer = setTimeout(() => {
      void (async () => {
        try {
          const next = await this.refresh!();
          if (next) {
            this.persistRefreshedToken(next);
            this.applyToken(next);
          } else {
            this.scheduleRetry();
          }
        } catch {
          this.scheduleRetry();
        }
      })();
    }, delay);
    this.timer.unref?.();
  }

  private scheduleRetry(): void {
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => {
      void (async () => {
        if (!this.refresh) return;
        const next = await this.refresh();
        if (next) {
          this.persistRefreshedToken(next);
          this.applyToken(next);
        } else {
          this.scheduleRetry();
        }
      })();
    }, MIN_REFRESH_DELAY_MS);
    this.timer.unref?.();
  }
}

export async function createAuthProxy(
  siteUrl: string,
  env: Record<string, string>,
): Promise<(target: string) => ProxyOptions> {
  const tokenManager = new TokenManager();
  await tokenManager.start(siteUrl, env);
  return (target: string): ProxyOptions => ({
    target,
    changeOrigin: true,
    configure: (proxy) => {
      proxy.on('proxyReq', (proxyReq) => {
        const header = tokenManager.getHeader();
        if (header) {
          proxyReq.setHeader('Authorization', header);
        }
      });
    },
  });
}
