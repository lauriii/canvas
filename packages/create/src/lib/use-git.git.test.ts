// cspell:ignore ASKPASS CAINFO CAPATH NOSYSTEM
import { chmod, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import useGit from './use-git.js';

// Runs real git through simple-git, without mocks, so the environment guard in
// simple-git and git's own environment handling are both exercised.
describe('useGit with real git', () => {
  const originalEnv = { ...process.env };
  let testDir: string;
  let repoDir: string;

  beforeEach(async () => {
    testDir = await mkdtemp(join(tmpdir(), 'create-use-git-'));
    repoDir = join(testDir, 'repo');

    // Isolate from the developer's and CI's git configuration and identity.
    for (const key of Object.keys(process.env)) {
      if (key.toUpperCase().startsWith('GIT_') || key === 'EMAIL') {
        delete process.env[key];
      }
    }
    process.env.HOME = testDir;
    process.env.XDG_CONFIG_HOME = join(testDir, '.config');

    await useGit(testDir).init(['--initial-branch=main', 'repo']);
    const git = useGit(repoDir);
    // Refuse to guess an identity from the user name and host name, so a
    // commit only succeeds when the identity reaches git.
    await git.addConfig('user.useConfigOnly', 'true');
    await writeFile(join(repoDir, 'README.md'), '# Test');
    await git.add(['--all']);
  });

  afterEach(async () => {
    for (const key of Object.keys(process.env)) {
      if (!(key in originalEnv)) {
        delete process.env[key];
      }
    }
    Object.assign(process.env, originalEnv);
    await rm(testDir, { recursive: true, force: true });
  });

  const setIdentity = () => {
    process.env.GIT_AUTHOR_NAME = 'Env Author';
    process.env.GIT_AUTHOR_EMAIL = 'author@example.invalid';
    process.env.GIT_COMMITTER_NAME = 'Env Committer';
    process.env.GIT_COMMITTER_EMAIL = 'committer@example.invalid';
  };

  it('needs an identity to commit in the isolated environment', async () => {
    await expect(useGit(repoDir).commit('Initial commit')).rejects.toThrow(
      /identity|email/i,
    );
  });

  it('commits with an identity provided only through the environment', async () => {
    setIdentity();
    const git = useGit(repoDir);

    await git.commit('Initial commit');

    const log = await git.raw(['log', '-1', '--format=%an <%ae>|%cn <%ce>']);
    expect(log.trim()).toBe(
      'Env Author <author@example.invalid>|Env Committer <committer@example.invalid>',
    );
  });

  it('passes certificate and transport settings to git', async () => {
    setIdentity();
    process.env.GIT_SSL_CAINFO = join(testDir, 'ca.pem');
    process.env.GIT_SSL_CAPATH = join(testDir, 'certs');
    process.env.GIT_HTTP_USER_AGENT = 'canvas-create-test';
    process.env.GIT_ALLOW_PROTOCOL = 'https';

    // A hook runs with the environment of the git process that runs it.
    const envFile = join(testDir, 'hook-env');
    const hook = join(repoDir, '.git', 'hooks', 'post-commit');
    await writeFile(
      hook,
      [
        '#!/bin/sh',
        `printf '%s\\n' "$GIT_SSL_CAINFO" "$GIT_SSL_CAPATH" "$GIT_HTTP_USER_AGENT" "$GIT_ALLOW_PROTOCOL" > '${envFile}'`,
        '',
      ].join('\n'),
    );
    await chmod(hook, 0o755);

    await useGit(repoDir).commit('Initial commit');

    expect((await readFile(envFile, 'utf8')).trim().split('\n')).toEqual([
      join(testDir, 'ca.pem'),
      join(testDir, 'certs'),
      'canvas-create-test',
      'https',
    ]);
  });

  it('passes other inherited git settings to git', async () => {
    setIdentity();
    const traceFile = join(testDir, 'trace.log');
    process.env.GIT_LFS_SKIP_SMUDGE = '1';
    process.env.GIT_OPTIONAL_LOCKS = '0';
    process.env.GIT_CURL_VERBOSE = '1';
    process.env.GIT_CONFIG_NOSYSTEM = '1';
    process.env.GIT_TRACE = traceFile;

    const envFile = join(testDir, 'hook-env');
    const hook = join(repoDir, '.git', 'hooks', 'post-commit');
    await writeFile(
      hook,
      [
        '#!/bin/sh',
        `printf '%s\\n' "$GIT_LFS_SKIP_SMUDGE" "$GIT_OPTIONAL_LOCKS" "$GIT_CURL_VERBOSE" "$GIT_CONFIG_NOSYSTEM" > '${envFile}'`,
        '',
      ].join('\n'),
    );
    await chmod(hook, 0o755);

    await useGit(repoDir).commit('Initial commit');

    expect((await readFile(envFile, 'utf8')).trim().split('\n')).toEqual([
      '1',
      '0',
      '1',
      '1',
    ]);
    // git honored GIT_TRACE by writing its trace to the given file.
    expect(await readFile(traceFile, 'utf8')).toContain('git commit');
  });

  it.each([
    ['GIT_ASKPASS', { GIT_ASKPASS: '/bin/true' }],
    ['GIT_SSH_COMMAND', { GIT_SSH_COMMAND: 'ssh -o StrictHostKeyChecking=no' }],
    ['GIT_CONFIG_PARAMETERS', { GIT_CONFIG_PARAMETERS: "'core.pager'='cat'" }],
    [
      'GIT_CONFIG_COUNT',
      {
        GIT_CONFIG_COUNT: '1',
        GIT_CONFIG_KEY_0: 'core.pager',
        GIT_CONFIG_VALUE_0: 'cat',
      },
    ],
  ])('rejects the inherited unsafe override %s', async (name, vars) => {
    setIdentity();
    Object.assign(process.env, vars);

    await expect(useGit(repoDir).commit('Initial commit')).rejects.toThrow(
      new RegExp(name),
    );
  });

  it.each(['GIT_DIR', 'GIT_WORK_TREE'])(
    'rejects the inherited repository location override %s',
    async (name) => {
      setIdentity();
      process.env[name] = join(testDir, 'elsewhere');

      await expect(useGit(repoDir).commit('Initial commit')).rejects.toThrow(
        new RegExp(name),
      );
    },
  );
});
