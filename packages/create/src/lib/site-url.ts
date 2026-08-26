import { readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

const ENV_FILENAME = '.env';
const ENV_EXAMPLE_FILENAME = '.env.example';
const ENV_VARIABLE = 'CANVAS_SITE_URL';

type WriteSiteUrlEnvResult =
  | 'created'
  | 'copied-example'
  | 'configured-existing'
  | 'existing-site-url';

type EnvLines = {
  lines: string[];
  eol: '\n' | '\r\n';
  hasFinalEol: boolean;
};

type Assignment = {
  index: number;
  prefix: string;
  value: string;
};

function isNodeError(error: unknown): error is NodeJS.ErrnoException {
  return error instanceof Error && 'code' in error;
}

export function validateSiteUrl(value: string): string | undefined {
  if (!value) return 'Canvas site URL is required';

  try {
    const url = new URL(value);
    if (
      (url.protocol === 'http:' || url.protocol === 'https:') &&
      url.hostname
    ) {
      return;
    }
  } catch {
    // Return the validation message below.
  }

  return 'Canvas site URL must be an http:// or https:// URL';
}

async function ensureEnvIsIgnored(projectDir: string): Promise<void> {
  const gitignorePath = join(projectDir, '.gitignore');
  let gitignore = '';

  try {
    gitignore = await readFile(gitignorePath, 'utf-8');
  } catch (error) {
    if (!isNodeError(error) || error.code !== 'ENOENT') {
      throw error;
    }
  }

  const ignoresEnv = gitignore
    .split(/\r?\n/)
    .some((line) => line.trim() === ENV_FILENAME);
  if (ignoresEnv) return;

  const separator =
    gitignore.length > 0 && !gitignore.endsWith('\n') ? '\n' : '';
  await writeFile(
    gitignorePath,
    `${gitignore}${separator}${ENV_FILENAME}\n`,
    'utf-8',
  );
}

async function readOptionalFile(path: string): Promise<string | undefined> {
  try {
    return await readFile(path, 'utf-8');
  } catch (error) {
    if (isNodeError(error) && error.code === 'ENOENT') {
      return;
    }
    throw error;
  }
}

function splitEnv(content: string): EnvLines {
  const eol = content.includes('\r\n') ? '\r\n' : '\n';
  const hasFinalEol = content.endsWith('\n');
  const lines = content.split(/\r?\n/);
  if (hasFinalEol) lines.pop();
  return { lines, eol, hasFinalEol };
}

function joinEnv({ lines, eol, hasFinalEol }: EnvLines): string {
  return `${lines.join(eol)}${hasFinalEol ? eol : ''}`;
}

function findAssignments(lines: string[]): Assignment[] {
  const assignmentPattern = new RegExp(
    `^(\\s*(?:export\\s+)?${ENV_VARIABLE}\\s*=\\s*)(.*)$`,
  );

  return lines.flatMap((line, index) => {
    const match = line.match(assignmentPattern);
    return match ? [{ index, prefix: match[1], value: match[2] }] : [];
  });
}

function setAssignment(
  env: EnvLines,
  siteUrl: string,
  assignment?: Assignment,
): void {
  const value = `${ENV_VARIABLE}=${JSON.stringify(siteUrl)}`;
  if (assignment) {
    env.lines[assignment.index] =
      `${assignment.prefix}${JSON.stringify(siteUrl)}`;
  } else if (env.lines.length === 1 && env.lines[0] === '') {
    env.lines[0] = value;
  } else {
    env.lines.push(value);
  }
}

function createEnvFromExample(example: string, siteUrl: string): string {
  const env = splitEnv(example);
  const assignments = findAssignments(env.lines);

  if (assignments.length > 1) {
    throw new Error(
      `${ENV_EXAMPLE_FILENAME} contains more than one ${ENV_VARIABLE} assignment`,
    );
  }

  setAssignment(env, siteUrl, assignments[0]);
  return joinEnv(env);
}

function hasConfiguredValue(assignment: Assignment): boolean {
  const value = assignment.value.trim();
  return (
    value !== '' && value !== '""' && value !== "''" && !value.startsWith('#')
  );
}

function updateExistingEnv(
  content: string,
  siteUrl: string,
): { content: string; result: WriteSiteUrlEnvResult } {
  const env = splitEnv(content);
  const assignments = findAssignments(env.lines);
  const effectiveAssignment = assignments.at(-1);

  if (effectiveAssignment && hasConfiguredValue(effectiveAssignment)) {
    return { content, result: 'existing-site-url' };
  }

  setAssignment(env, siteUrl, effectiveAssignment);
  return { content: joinEnv(env), result: 'configured-existing' };
}

export async function writeSiteUrlEnv(
  projectDir: string,
  siteUrl: string,
): Promise<WriteSiteUrlEnvResult> {
  const validationError = validateSiteUrl(siteUrl);
  if (validationError) throw new Error(validationError);

  const envPath = join(projectDir, ENV_FILENAME);
  const existingEnv = await readOptionalFile(envPath);
  if (existingEnv !== undefined) {
    const updated = updateExistingEnv(existingEnv, siteUrl);
    await ensureEnvIsIgnored(projectDir);
    if (updated.content !== existingEnv) {
      await writeFile(envPath, updated.content, 'utf-8');
    }
    return updated.result;
  }

  const example = await readOptionalFile(
    join(projectDir, ENV_EXAMPLE_FILENAME),
  );
  const envContent =
    example === undefined
      ? `${ENV_VARIABLE}=${JSON.stringify(siteUrl)}\n`
      : createEnvFromExample(example, siteUrl);

  await ensureEnvIsIgnored(projectDir);
  try {
    await writeFile(envPath, envContent, { encoding: 'utf-8', flag: 'wx' });
  } catch (error) {
    if (isNodeError(error) && error.code === 'EEXIST') {
      return writeSiteUrlEnv(projectDir, siteUrl);
    }
    throw error;
  }

  return example === undefined ? 'created' : 'copied-example';
}
