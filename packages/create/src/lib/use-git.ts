import { simpleGit } from 'simple-git';

// Make sure all native git cli terminal prompts are skipped.
const GIT_TERMINAL_PROMPT = 0;

export default (baseDir?: string) =>
  simpleGit(baseDir).env({ ...process.env, GIT_TERMINAL_PROMPT });
