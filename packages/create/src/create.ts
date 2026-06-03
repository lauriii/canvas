import { readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import chalk from 'chalk';
import spawn from 'cross-spawn';
import { rimraf } from 'rimraf';
import * as p from '@clack/prompts';

import { setupAgentSkills } from './lib/agent-skills-setup.js';
import detectPackageManager from './lib/detect-package-manager.js';
import { getName, getVersion } from './lib/meta-info.js';
import useGit from './lib/use-git.js';

import type { TaskOptions } from 'simple-git';
import type { Context } from './types/context.js';

export default async function createProject(ctx: Context) {
  const { template, projectName, selectedAgents } = ctx;
  const projectDir = `${process.cwd()}/${projectName}`;

  try {
    // Step 1: Fetch template.
    const s1 = p.spinner();
    s1.start('Fetching template');

    const hasCommitSHARef = /^[a-f0-9]{40}$/i.test(template.repository.ref);

    // Clone repository.
    const git = useGit();
    const options: TaskOptions = {
      '--depth': 1,
    };
    if (template.repository.ref !== 'HEAD' && !hasCommitSHARef) {
      options['--branch'] = template.repository.ref;
    }
    await git.clone(template.repository.url, projectName, options);

    // Checkout commit if SHA is provided.
    const gitProjectDir = useGit(projectDir);
    if (hasCommitSHARef) {
      await gitProjectDir.fetch('origin', template.repository.ref);
      await gitProjectDir.checkout(template.repository.ref);
    }

    // Delete .git directory.
    await rimraf(`${projectDir}/.git`);

    // Update package.json name field.
    const packageJsonPath = join(projectDir, 'package.json');
    const packageJsonContent = await readFile(packageJsonPath, 'utf-8');
    const packageJson = JSON.parse(packageJsonContent);
    packageJson.name = projectName;
    await writeFile(
      packageJsonPath,
      JSON.stringify(packageJson, null, 2) + '\n',
    );

    s1.stop(chalk.green('Fetched template'));

    await setupAgentSkills(projectDir, {
      selectedAgents,
      interactive: Boolean(process.stdin.isTTY && process.stdout.isTTY),
    });

    // Step 2: Install dependencies.
    const s2 = p.spinner();
    const packageManager = detectPackageManager();
    s2.start(`Installing dependencies with ${packageManager}`);

    await new Promise<void>((resolve, reject) => {
      const child = spawn(packageManager, ['install'], {
        cwd: `./${projectName}`,
        stdio: ['ignore', 'ignore', 'pipe'],
        env: {
          ...process.env,
          NODE_ENV: 'development',
          ADBLOCK: '1',
          DISABLE_OPENCOLLECTIVE: '1',
        },
      });
      let stderrOutput = '';
      if (child.stderr) {
        child.stderr.on('data', (data) => {
          stderrOutput += data.toString();
        });
      }
      child.on('close', (code) => {
        if (code !== 0) {
          reject(
            new Error(
              `Package installation failed with code ${code}.\nFailed command: ${packageManager} install\nWorking directory: ./${projectName}${stderrOutput ? `\n\n${stderrOutput}` : ''}`,
            ),
          );
        } else {
          resolve();
        }
      });
    });

    s2.stop(chalk.green(`Installed dependencies with ${packageManager}`));

    // Step 3: Prepare repository.
    const s3 = p.spinner();
    s3.start('Initializing Git repository');

    // Initialize repository.
    await git.init(['--initial-branch=main', projectName]);

    // Add first commit.
    await gitProjectDir.add(['--all']);
    await gitProjectDir.commit(
      `Init project using ${getName()}@${getVersion()}\n\nTemplate repository: ${template.repository.url}\nRef: ${template.repository.ref}`,
    );

    s3.stop(
      chalk.green('Initialized Git repository on main with initial commit'),
    );

    p.note(
      `Created project in ./${projectName}\n\nNext steps:\n  cd ${projectName}\n  ${packageManager} run dev`,
      'Get started',
    );

    p.outro('Canvas project created successfully.');
  } catch (error) {
    if (error instanceof Error) {
      p.log.error(`Error: ${error.message}`);
    } else {
      p.log.error(`Unknown error: ${String(error)}`);
    }
    process.exit(1);
  }
}
