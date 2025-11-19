import { readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import chalk from 'chalk';
import spawn from 'cross-spawn';
import { rimraf } from 'rimraf';
import { simpleGit } from 'simple-git';
import * as p from '@clack/prompts';

import detectPackageManager from './lib/detect-package-manager.js';
import { getName, getVersion } from './lib/meta-info.js';

import type { SimpleGit } from 'simple-git';
import type { Context } from './types/context.js';

export default async function createApp(ctx: Context) {
  const { template, appName } = ctx;

  try {
    // Step 1: Fetch initial codebase.
    const s1 = p.spinner();
    s1.start('Fetching initial codebase');

    // Clone repository.
    const git: SimpleGit = simpleGit();
    await git.clone(template.repository.url, appName, {
      '--depth': 1,
    });

    // Checkout ref.
    const gitAppDir: SimpleGit = simpleGit({
      baseDir: `${process.cwd()}/${appName}`,
    });
    await gitAppDir.checkout(template.repository.ref);

    // Delete .git directory.
    await rimraf(`${process.cwd()}/${appName}/.git`);

    // Update package.json name field.
    const packageJsonPath = join(process.cwd(), appName, 'package.json');
    const packageJsonContent = await readFile(packageJsonPath, 'utf-8');
    const packageJson = JSON.parse(packageJsonContent);
    packageJson.name = appName;
    await writeFile(
      packageJsonPath,
      JSON.stringify(packageJson, null, 2) + '\n',
    );

    s1.stop(chalk.green('Fetched initial codebase'));

    // Step 2: Install dependencies.
    const s2 = p.spinner();
    const packageManager = detectPackageManager();
    s2.start(`Installing dependencies using ${packageManager}`);

    await new Promise<void>((resolve, reject) => {
      const child = spawn(packageManager, ['install'], {
        cwd: `./${appName}`,
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
              `Package installation failed with code ${code}${stderrOutput ? `:\n${stderrOutput}` : ''}`,
            ),
          );
        } else {
          resolve();
        }
      });
    });

    s2.stop(chalk.green('Installed dependencies'));

    // Step 3: Prepare repository.
    const s3 = p.spinner();
    s3.start('Preparing your repository');

    // Initialize repository.
    await git.init(['--initial-branch=main', appName]);

    // Add first commit.
    await gitAppDir.add(['--all']);
    await gitAppDir.commit(
      `Init codebase using ${getName()}@${getVersion()}\n\nTemplate repository: ${template.repository.url}\nRef: ${template.repository.ref}`,
    );

    s3.stop(chalk.green('Prepared repository'));

    // Show next steps.
    p.note(`$ cd ${appName}\n$ ${packageManager} run dev`, 'Get started');

    p.outro('🚀 App created successfully');
  } catch (error) {
    if (error instanceof Error) {
      p.log.error(`Error: ${error.message}`);
    } else {
      p.log.error(`Unknown error: ${String(error)}`);
    }
    process.exit(1);
  }
}
