import chalk from 'chalk';
import open from 'open';
import * as p from '@clack/prompts';

import {
  getConfig,
  getDefaultScope,
  promptForConfig,
  setConfig,
} from '../config.js';
import { removeTokenEntry, setTokenEntry } from '../lib/token-store.js';
import {
  DEFAULT_CALLBACK_PORT,
  deriveCodeChallenge,
  discoverAuth,
  exchangeCode,
  generateCodeVerifier,
  generateState,
  waitForCallback,
} from '../services/auth.js';

import type { Command } from 'commander';

interface LoginOptions {
  siteUrl?: string;
  port?: string;
  clientId?: string;
}

interface LogoutOptions {
  siteUrl?: string;
}

export function loginCommand(program: Command): void {
  program
    .command('login')
    .description(
      'Log in to a Canvas site via browser (OAuth 2.0 authorization code + PKCE)',
    )
    .option('--site-url <url>', 'Canvas site URL')
    .option(
      '--port <number>',
      `Local callback port (default: ${String(DEFAULT_CALLBACK_PORT)})`,
    )
    .option('--client-id <id>', 'OAuth client ID (overrides auto-discovery)')
    .action(async (options: LoginOptions) => {
      p.intro(chalk.bold('Drupal Canvas CLI: login'));

      // Resolve site URL.
      if (options.siteUrl) {
        setConfig({ siteUrl: options.siteUrl });
      } else if (!getConfig().siteUrl) {
        await promptForConfig('siteUrl');
      }
      const siteUrl = getConfig().siteUrl!;

      const port = options.port
        ? parseInt(options.port, 10)
        : DEFAULT_CALLBACK_PORT;
      const redirectUri = `http://localhost:${String(port)}/callback`;

      const spinner = p.spinner();

      // Discover OAuth endpoints and client ID.
      spinner.start('Discovering OAuth configuration');
      let discovered;
      try {
        discovered = await discoverAuth(siteUrl, options.clientId);
      } catch (error) {
        spinner.stop('Discovery failed');
        p.log.error(error instanceof Error ? error.message : String(error));
        process.exit(1);
      }
      spinner.stop('OAuth configuration discovered');

      // Generate PKCE and state.
      const codeVerifier = generateCodeVerifier();
      const codeChallenge = deriveCodeChallenge(codeVerifier);
      const state = generateState();

      const scope =
        discovered.scopesSupported !== undefined
          ? discovered.scopesSupported.join(' ')
          : getDefaultScope(false);

      // Build authorization URL.
      const authUrl = new URL(discovered.authorizationEndpoint);
      authUrl.searchParams.set('response_type', 'code');
      authUrl.searchParams.set('client_id', discovered.clientId);
      authUrl.searchParams.set('redirect_uri', redirectUri);
      authUrl.searchParams.set('scope', scope);
      authUrl.searchParams.set('state', state);
      authUrl.searchParams.set('code_challenge', codeChallenge);
      authUrl.searchParams.set('code_challenge_method', 'S256');

      p.log.info(
        `Opening browser for login...\nIf it does not open automatically:\n${chalk.cyan(authUrl.toString())}`,
      );

      // Start callback server before opening the browser.
      const callbackPromise = waitForCallback(port);

      try {
        await open(authUrl.toString());
      } catch {
        // open() failure is non-fatal — the user can click the URL.
      }

      spinner.start(
        `Waiting for authorization callback on port ${String(port)}`,
      );
      let callbackResult;
      try {
        callbackResult = await callbackPromise;
      } catch (error) {
        spinner.stop('Authorization failed');
        p.log.error(error instanceof Error ? error.message : String(error));
        process.exit(1);
      }

      if (callbackResult.state !== state) {
        spinner.stop('State mismatch');
        p.log.error('OAuth state mismatch — possible CSRF. Please try again.');
        process.exit(1);
      }

      // Exchange authorization code for tokens.
      spinner.message('Exchanging authorization code for tokens');
      let tokens;
      try {
        tokens = await exchangeCode({
          tokenEndpoint: discovered.tokenEndpoint,
          code: callbackResult.code,
          redirectUri,
          clientId: discovered.clientId,
          codeVerifier,
        });
      } catch (error) {
        spinner.stop('Token exchange failed');
        p.log.error(error instanceof Error ? error.message : String(error));
        process.exit(1);
      }

      // Persist tokens.
      setTokenEntry(siteUrl, {
        accessToken: tokens.accessToken,
        refreshToken: tokens.refreshToken,
        expiresAt: tokens.expiresIn
          ? Date.now() + tokens.expiresIn * 1000
          : undefined,
        clientId: discovered.clientId,
        tokenEndpoint: discovered.tokenEndpoint,
      });

      spinner.stop(chalk.green('Logged in successfully'));
      p.log.success(`Credentials stored for ${chalk.cyan(siteUrl)}`);
      p.outro('Run `canvas push` or `canvas pull` to get started.');
    });
}

export function logoutCommand(program: Command): void {
  program
    .command('logout')
    .description('Remove stored credentials for a Canvas site')
    .option('--site-url <url>', 'Canvas site URL')
    .action(async (options: LogoutOptions) => {
      p.intro(chalk.bold('Drupal Canvas CLI: logout'));

      if (options.siteUrl) {
        setConfig({ siteUrl: options.siteUrl });
      } else if (!getConfig().siteUrl) {
        await promptForConfig('siteUrl');
      }
      const siteUrl = getConfig().siteUrl!;

      removeTokenEntry(siteUrl);
      p.log.success(`Credentials removed for ${chalk.cyan(siteUrl)}`);
      p.outro('Done.');
    });
}
