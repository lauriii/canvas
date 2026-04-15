import { simpleGit } from 'simple-git';

import type { SimpleGitOptions } from 'simple-git';

// Make sure all native git cli terminal prompts are skipped.
const GIT_TERMINAL_PROMPT = 0;

type GitUnsafeOptions = NonNullable<SimpleGitOptions['unsafe']> &
  Partial<{
    allowUnsafeEditor: boolean;
    allowUnsafePager: boolean;
  }>;

// `npm exec` injects EDITOR, and many shells export PAGER. Newer simple-git
// releases reject those inherited env vars unless explicitly allowed.
const unsafe: GitUnsafeOptions = {
  allowUnsafeEditor: true,
  allowUnsafePager: true,
};

export default (baseDir?: string) => {
  const options: Partial<SimpleGitOptions> = { unsafe };
  const git =
    baseDir === undefined ? simpleGit(options) : simpleGit(baseDir, options);

  return git.env({ ...process.env, GIT_TERMINAL_PROMPT });
};
