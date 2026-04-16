import * as fs from 'fs';
import * as os from 'os';
import path from 'path';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
  getTokenEntry,
  removeTokenEntry,
  setTokenEntry,
  tokenStorePath,
} from './token-store';

import type { SiteTokenEntry } from './token-store';

vi.mock('fs');
vi.mock('os');

const FAKE_HOME = '/home/user';
const STORE_PATH = `${FAKE_HOME}/.config/drupal-canvas/oauth.json`;
const TMP_PATH = `${STORE_PATH}.tmp`;
const STORE_DIR = path.dirname(STORE_PATH);

const sampleEntry: SiteTokenEntry = {
  accessToken: 'abc123',
  refreshToken: 'refresh456',
  expiresAt: 9_000_000_000_000,
  clientId: 'client-id',
  tokenEndpoint: 'https://example.com/oauth/token',
};

beforeEach(() => {
  vi.mocked(os.homedir).mockReturnValue(FAKE_HOME);
});

describe('tokenStorePath', () => {
  it('returns path in home config directory', () => {
    expect(tokenStorePath()).toBe(STORE_PATH);
  });
});

describe('getTokenEntry', () => {
  it('returns entry when present', () => {
    vi.mocked(fs.readFileSync).mockReturnValue(
      JSON.stringify({ 'https://example.com': sampleEntry }),
    );
    expect(getTokenEntry('https://example.com')).toEqual(sampleEntry);
  });

  it('returns null when siteUrl is absent', () => {
    vi.mocked(fs.readFileSync).mockReturnValue(JSON.stringify({}));
    expect(getTokenEntry('https://missing.com')).toBeNull();
  });

  it('returns null when file is missing', () => {
    vi.mocked(fs.readFileSync).mockImplementation(() => {
      throw Object.assign(new Error('ENOENT'), { code: 'ENOENT' });
    });
    expect(getTokenEntry('https://example.com')).toBeNull();
  });

  it('returns null for corrupt JSON', () => {
    vi.mocked(fs.readFileSync).mockReturnValue('not-valid-json{{{');
    expect(getTokenEntry('https://example.com')).toBeNull();
  });

  it('normalizes trailing slashes on lookup', () => {
    vi.mocked(fs.readFileSync).mockReturnValue(
      JSON.stringify({ 'https://example.com': sampleEntry }),
    );
    expect(getTokenEntry('https://example.com/')).toEqual(sampleEntry);
  });
});

describe('setTokenEntry', () => {
  it('creates parent directory with recursive option', () => {
    vi.mocked(fs.readFileSync).mockReturnValue(JSON.stringify({}));
    setTokenEntry('https://example.com', sampleEntry);
    expect(fs.mkdirSync).toHaveBeenCalledWith(STORE_DIR, { recursive: true });
  });

  it('writes atomically via tmp file then rename', () => {
    vi.mocked(fs.readFileSync).mockReturnValue(JSON.stringify({}));
    setTokenEntry('https://example.com', sampleEntry);
    expect(fs.writeFileSync).toHaveBeenCalledWith(
      TMP_PATH,
      expect.any(String),
      { mode: 0o600 },
    );
    expect(fs.renameSync).toHaveBeenCalledWith(TMP_PATH, STORE_PATH);
  });

  it('keys the entry by normalized URL', () => {
    vi.mocked(fs.readFileSync).mockReturnValue(JSON.stringify({}));
    setTokenEntry('https://example.com/', sampleEntry);
    const written = vi.mocked(fs.writeFileSync).mock.calls[0][1] as string;
    const parsed = JSON.parse(written) as Record<string, unknown>;
    expect(parsed['https://example.com']).toEqual(sampleEntry);
    expect(parsed['https://example.com/']).toBeUndefined();
  });

  it('merges with existing entries', () => {
    const other: SiteTokenEntry = { ...sampleEntry, accessToken: 'other' };
    vi.mocked(fs.readFileSync).mockReturnValue(
      JSON.stringify({ 'https://other.com': other }),
    );
    setTokenEntry('https://example.com', sampleEntry);
    const written = vi.mocked(fs.writeFileSync).mock.calls[0][1] as string;
    const parsed = JSON.parse(written) as Record<string, unknown>;
    expect(parsed['https://other.com']).toEqual(other);
    expect(parsed['https://example.com']).toEqual(sampleEntry);
  });
});

describe('removeTokenEntry', () => {
  it('removes the entry for the given URL', () => {
    vi.mocked(fs.readFileSync).mockReturnValue(
      JSON.stringify({ 'https://example.com': sampleEntry }),
    );
    removeTokenEntry('https://example.com');
    const written = vi.mocked(fs.writeFileSync).mock.calls[0][1] as string;
    expect(JSON.parse(written)).not.toHaveProperty('https://example.com');
  });

  it('leaves other entries intact', () => {
    const other: SiteTokenEntry = { ...sampleEntry, accessToken: 'other' };
    vi.mocked(fs.readFileSync).mockReturnValue(
      JSON.stringify({
        'https://example.com': sampleEntry,
        'https://other.com': other,
      }),
    );
    removeTokenEntry('https://example.com');
    const written = vi.mocked(fs.writeFileSync).mock.calls[0][1] as string;
    const parsed = JSON.parse(written) as Record<string, unknown>;
    expect(parsed['https://other.com']).toEqual(other);
  });
});
