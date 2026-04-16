import { Command } from 'commander';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as p from '@clack/prompts';

import { getConfig, getDefaultScope, promptForConfig } from '../config.js';
import { removeTokenEntry, setTokenEntry } from '../lib/token-store.js';
import {
  deriveCodeChallenge,
  discoverAuth,
  exchangeCode,
  generateCodeVerifier,
  generateState,
  waitForCallback,
} from '../services/auth.js';
import { loginCommand, logoutCommand } from './login';

vi.mock('../services/auth.js');
vi.mock('../lib/token-store.js');
vi.mock('../config.js');
vi.mock('open', () => ({ default: vi.fn().mockResolvedValue(undefined) }));
vi.mock('@clack/prompts', () => ({
  intro: vi.fn(),
  outro: vi.fn(),
  spinner: vi.fn(() => ({
    start: vi.fn(),
    stop: vi.fn(),
    message: vi.fn(),
  })),
  log: {
    error: vi.fn(),
    info: vi.fn(),
    success: vi.fn(),
    warn: vi.fn(),
  },
  isCancel: vi.fn(),
  text: vi.fn(),
}));

const SITE_URL = 'https://example.com';
const AUTH_ENDPOINT = 'https://auth.example.com/oauth2/authorize';
const TOKEN_ENDPOINT = 'https://auth.example.com/oauth2/token';
const FIXED_STATE = 'abc123state456';
const FIXED_VERIFIER = 'fixed-verifier-value';
const FIXED_CHALLENGE = 'fixed-challenge-value';

function makeProgram(): Command {
  const program = new Command();
  program.exitOverride();
  loginCommand(program);
  logoutCommand(program);
  return program;
}

function setupHappyPath(overrides?: {
  scopesSupported?: string[] | undefined;
  expiresIn?: number;
}) {
  vi.mocked(getConfig).mockReturnValue({
    siteUrl: SITE_URL,
    clientId: '',
    clientSecret: '',
    scope: '',
    includePages: false,
    includeBrandKit: false,
    componentDir: '',
    deprecatedComponentDir: '',
    outputDir: '',
    pagesDir: '',
    globalCssPath: '',
    aliasBaseDir: '',
    userAgent: '',
    fonts: undefined,
  });
  vi.mocked(discoverAuth).mockResolvedValue({
    authorizationEndpoint: AUTH_ENDPOINT,
    tokenEndpoint: TOKEN_ENDPOINT,
    clientId: 'discovered-client',
    scopesSupported: overrides?.scopesSupported,
  });
  vi.mocked(generateState).mockReturnValue(FIXED_STATE);
  vi.mocked(generateCodeVerifier).mockReturnValue(FIXED_VERIFIER);
  vi.mocked(deriveCodeChallenge).mockReturnValue(FIXED_CHALLENGE);
  vi.mocked(waitForCallback).mockResolvedValue({
    code: 'the-auth-code',
    state: FIXED_STATE,
  });
  vi.mocked(exchangeCode).mockResolvedValue({
    accessToken: 'access-token',
    refreshToken: 'refresh-token',
    expiresIn: overrides?.expiresIn ?? 3600,
  });
}

beforeEach(() => {
  vi.spyOn(process, 'exit').mockImplementation((code) => {
    throw new Error(`process.exit(${String(code)})`);
  });
});

describe('logoutCommand', () => {
  it('removes token for the provided --site-url', async () => {
    vi.mocked(getConfig).mockReturnValue({
      siteUrl: SITE_URL,
    } as ReturnType<typeof getConfig>);

    const program = makeProgram();
    await program.parseAsync([
      'node',
      'canvas',
      'logout',
      '--site-url',
      SITE_URL,
    ]);

    expect(removeTokenEntry).toHaveBeenCalledWith(SITE_URL);
  });

  it('prompts for siteUrl when --site-url is not given and config has none', async () => {
    vi.mocked(getConfig).mockReturnValue({
      siteUrl: '',
    } as ReturnType<typeof getConfig>);
    vi.mocked(promptForConfig).mockResolvedValue(undefined);
    // After promptForConfig resolves, subsequent getConfig calls return the URL.
    vi.mocked(getConfig)
      .mockReturnValueOnce({ siteUrl: '' } as ReturnType<typeof getConfig>)
      .mockReturnValue({ siteUrl: SITE_URL } as ReturnType<typeof getConfig>);

    const program = makeProgram();
    await program.parseAsync(['node', 'canvas', 'logout']);

    expect(promptForConfig).toHaveBeenCalledWith('siteUrl');
    expect(removeTokenEntry).toHaveBeenCalledWith(SITE_URL);
  });
});

describe('loginCommand', () => {
  it('calls process.exit(1) when discoverAuth fails', async () => {
    vi.mocked(getConfig).mockReturnValue({
      siteUrl: SITE_URL,
    } as ReturnType<typeof getConfig>);
    vi.mocked(discoverAuth).mockRejectedValue(
      new Error('Could not reach https://example.com'),
    );
    vi.mocked(generateState).mockReturnValue(FIXED_STATE);
    vi.mocked(generateCodeVerifier).mockReturnValue(FIXED_VERIFIER);
    vi.mocked(deriveCodeChallenge).mockReturnValue(FIXED_CHALLENGE);

    const program = makeProgram();
    await expect(
      program.parseAsync(['node', 'canvas', 'login', '--site-url', SITE_URL]),
    ).rejects.toThrow('process.exit(1)');
  });

  it('calls process.exit(1) on OAuth state mismatch', async () => {
    setupHappyPath();
    vi.mocked(waitForCallback).mockResolvedValue({
      code: 'auth-code',
      state: 'different-state',
    });

    const program = makeProgram();
    await expect(
      program.parseAsync(['node', 'canvas', 'login', '--site-url', SITE_URL]),
    ).rejects.toThrow('process.exit(1)');

    expect(p.log.error).toHaveBeenCalledWith(
      expect.stringContaining('state mismatch'),
    );
  });

  it('calls process.exit(1) when exchangeCode fails', async () => {
    setupHappyPath();
    vi.mocked(exchangeCode).mockRejectedValue(
      new Error('Token exchange failed: invalid_grant'),
    );

    const program = makeProgram();
    await expect(
      program.parseAsync(['node', 'canvas', 'login', '--site-url', SITE_URL]),
    ).rejects.toThrow('process.exit(1)');
  });

  it('stores tokens with correct shape on success', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-01-01T00:00:00Z'));
    setupHappyPath({ expiresIn: 3600 });

    const program = makeProgram();
    await program.parseAsync([
      'node',
      'canvas',
      'login',
      '--site-url',
      SITE_URL,
    ]);

    expect(setTokenEntry).toHaveBeenCalledWith(SITE_URL, {
      accessToken: 'access-token',
      refreshToken: 'refresh-token',
      expiresAt: new Date('2026-01-01T01:00:00Z').getTime(),
      clientId: 'discovered-client',
      tokenEndpoint: TOKEN_ENDPOINT,
    });

    vi.useRealTimers();
  });

  it('stores undefined expiresAt when expiresIn is absent', async () => {
    setupHappyPath();
    vi.mocked(exchangeCode).mockResolvedValue({
      accessToken: 'access-token',
      refreshToken: undefined,
      expiresIn: undefined,
    });

    const program = makeProgram();
    await program.parseAsync([
      'node',
      'canvas',
      'login',
      '--site-url',
      SITE_URL,
    ]);

    expect(setTokenEntry).toHaveBeenCalledWith(
      SITE_URL,
      expect.objectContaining({ expiresAt: undefined }),
    );
  });

  it('uses joined scopesSupported as scope in authorization URL', async () => {
    setupHappyPath({
      scopesSupported: ['canvas:js_component', 'canvas:brand_kit'],
    });

    const program = makeProgram();
    await program.parseAsync([
      'node',
      'canvas',
      'login',
      '--site-url',
      SITE_URL,
    ]);

    const logInfoCall = vi.mocked(p.log.info).mock.calls[0][0] as string;
    const authUrl = new URL(logInfoCall.match(/https?:\/\/\S+/)?.[0] ?? '');
    expect(authUrl.searchParams.get('scope')).toBe(
      'canvas:js_component canvas:brand_kit',
    );
  });

  it('falls back to getDefaultScope when scopesSupported is absent', async () => {
    setupHappyPath({ scopesSupported: undefined });
    vi.mocked(getDefaultScope).mockReturnValue(
      'canvas:js_component canvas:asset_library',
    );

    const program = makeProgram();
    await program.parseAsync([
      'node',
      'canvas',
      'login',
      '--site-url',
      SITE_URL,
    ]);

    expect(getDefaultScope).toHaveBeenCalledWith(false);
    const logInfoCall = vi.mocked(p.log.info).mock.calls[0][0] as string;
    const authUrl = new URL(logInfoCall.match(/https?:\/\/\S+/)?.[0] ?? '');
    expect(authUrl.searchParams.get('scope')).toBe(
      'canvas:js_component canvas:asset_library',
    );
  });
});
