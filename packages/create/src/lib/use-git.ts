import { simpleGit } from 'simple-git';

import type { SimpleGitOptions } from 'simple-git';

// Make sure all native git cli terminal prompts are skipped.
const GIT_TERMINAL_PROMPT = 0;

type GitUnsafeOptions = NonNullable<SimpleGitOptions['unsafe']> &
  Partial<{
    allowUnsafeEditor: boolean;
    allowUnsafePager: boolean;
  }>;

// `npm exec` injects EDITOR, and many shells export PAGER or VISUAL. Newer
// simple-git releases reject those inherited env vars unless explicitly allowed.
const unsafe: GitUnsafeOptions = {
  allowUnsafeEditor: true,
  allowUnsafePager: true,
};

// Variables that move git's repository, work tree or object storage. Inherited
// from the caller, for example when run from inside a git hook, they would make
// `init`, `add` and `commit` act on a different repository than the project
// being created, so they are rejected instead of passed on.
const REPOSITORY_LOCATION_ENV = new Set([
  'GIT_DIR',
  'GIT_WORK_TREE',
  'GIT_INDEX_FILE',
  'GIT_OBJECT_DIRECTORY',
  'GIT_COMMON_DIR',
]);

// cspell:ignore ASKPASS
// Trust boundary: this is a local CLI that runs git on behalf of the person
// invoking it, so their environment (identity, certificates, proxies, LFS and
// trace settings, ...) is passed on to git as is. This is not an environment
// sandbox. simple-git 4's environment guard only accepts variables listed in
// `allowEnvironment`, so the list is derived from the same snapshot that is
// passed to git. simple-git's separate unsafe checks still apply to it: inherited
// executable and configuration overrides such as GIT_ASKPASS, GIT_SSH_COMMAND,
// GIT_CONFIG_PARAMETERS or GIT_CONFIG_COUNT are rejected, with only the editor
// and pager exceptions above.
export default (baseDir?: string) => {
  const env = { ...process.env, GIT_TERMINAL_PROMPT };
  const allowEnvironment = Object.keys(env).filter(
    (key) => !REPOSITORY_LOCATION_ENV.has(key.toUpperCase()),
  );
  const options: Partial<SimpleGitOptions> = { unsafe, allowEnvironment };
  const git =
    baseDir === undefined ? simpleGit(options) : simpleGit(baseDir, options);

  return git.env(env);
};
