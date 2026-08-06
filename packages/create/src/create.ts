import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import chalk from 'chalk';
import spawn from 'cross-spawn';
import { rimraf } from 'rimraf';
import * as p from '@clack/prompts';

import { setupAgentSkills } from './lib/agent-skills-setup.js';
import detectPackageManager from './lib/detect-package-manager.js';
import { getName, getVersion } from './lib/meta-info.js';
import { moveTemplateDirectory } from './lib/move-template-directory.js';
import { writeSiteUrlEnv } from './lib/site-url.js';
import useGit from './lib/use-git.js';

import type { TaskOptions } from 'simple-git';
import type { Context } from './types/context.js';

export default async function createProject(ctx: Context) {
  const { template, projectName, siteUrl, selectedAgents, interactive } = ctx;
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

    const repositoryPath = template.repository.path;
    // Keep the clone separate when a repository directory will become the project root.
    let temporaryCloneDir: string | undefined;
    let cloneDir = projectDir;
    if (repositoryPath) {
      temporaryCloneDir = await mkdtemp(
        join(process.cwd(), '.canvas-template-'),
      );
      cloneDir = temporaryCloneDir;
    }

    try {
      await git.clone(template.repository.url, cloneDir, options);

      // Checkout commit if SHA is provided.
      const gitCloneDir = useGit(cloneDir);
      if (hasCommitSHARef) {
        await gitCloneDir.fetch('origin', template.repository.ref);
        await gitCloneDir.checkout(template.repository.ref);
      }

      if (repositoryPath) {
        await moveTemplateDirectory(cloneDir, projectDir, repositoryPath);
        await rimraf(cloneDir);
        temporaryCloneDir = undefined;
      }
    } catch (error) {
      if (temporaryCloneDir) {
        await rimraf(temporaryCloneDir);
      }
      throw error;
    }

    const gitProjectDir = useGit(projectDir);

    // Delete .git directory.
    await rimraf(join(projectDir, '.git'));

    // Update package.json name field.
    const packageJsonPath = join(projectDir, 'package.json');
    const packageJsonContent = await readFile(packageJsonPath, 'utf-8');
    const packageJson = JSON.parse(packageJsonContent);
    packageJson.name = projectName;
    await writeFile(
      packageJsonPath,
      JSON.stringify(packageJson, null, 2) + '\n',
    );

    const siteUrlEnvResult = siteUrl
      ? await writeSiteUrlEnv(projectDir, siteUrl)
      : undefined;

    s1.stop(chalk.green('Fetched template'));

    switch (siteUrlEnvResult) {
      case 'created':
        p.log.info('Created .env.');
        break;
      case 'copied-example':
        p.log.info('Created .env from .env.example.');
        break;
      case 'configured-existing':
        p.log.info('Updated .env with CANVAS_SITE_URL.');
        break;
      case 'existing-site-url':
        p.log.info('Kept existing CANVAS_SITE_URL in .env.');
        break;
      case undefined:
        p.log.warn(
          'No site URL provided. Set CANVAS_SITE_URL in .env to connect the project to your Drupal site.',
        );
        break;
    }

    await setupAgentSkills(projectDir, {
      selectedAgents,
      interactive,
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
    const repositoryPathMessage = template.repository.path
      ? `\nPath: ${template.repository.path}`
      : '';
    await gitProjectDir.commit(
      `Init project using ${getName()}@${getVersion()}\n\nTemplate repository: ${template.repository.url}\nRef: ${template.repository.ref}${repositoryPathMessage}`,
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
