import fs from 'fs';
import os from 'os';
import path from 'path';

export interface SiteTokenEntry {
  accessToken: string;
  refreshToken?: string;
  /** Unix timestamp in milliseconds */
  expiresAt?: number;
  clientId: string;
  tokenEndpoint: string;
}

type TokenStore = Record<string, SiteTokenEntry>;

export function tokenStorePath(): string {
  return path.join(os.homedir(), '.config', 'drupal-canvas', 'oauth.json');
}

function readTokenStore(): TokenStore {
  const filePath = tokenStorePath();
  try {
    const raw = fs.readFileSync(filePath, 'utf-8');
    const parsed = JSON.parse(raw) as unknown;
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
      return parsed as TokenStore;
    }
  } catch {
    // Missing or corrupt file — start fresh.
  }
  return {};
}

function writeTokenStore(store: TokenStore): void {
  const filePath = tokenStorePath();
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  const tmp = filePath + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(store, null, 2) + '\n', { mode: 0o600 });
  fs.renameSync(tmp, filePath);
}

export function getTokenEntry(siteUrl: string): SiteTokenEntry | null {
  const store = readTokenStore();
  return store[normalizeUrl(siteUrl)] ?? null;
}

export function setTokenEntry(siteUrl: string, entry: SiteTokenEntry): void {
  const store = readTokenStore();
  store[normalizeUrl(siteUrl)] = entry;
  writeTokenStore(store);
}

export function removeTokenEntry(siteUrl: string): void {
  const store = readTokenStore();
  delete store[normalizeUrl(siteUrl)];
  writeTokenStore(store);
}

function normalizeUrl(url: string): string {
  return url.replace(/\/+$/, '');
}
