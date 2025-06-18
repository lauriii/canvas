import type { Command } from 'commander';
import * as p from '@clack/prompts';
import chalk from 'chalk';
import { getConfig, setConfig } from '../config.js';
import { findComponentDirectories } from '../utils/find-component-directories.js';
import { buildComponent } from '../utils/build.js';
import { reportResults } from '../utils/report-results';
import type { Result } from '../types/Result.js';

interface BuildOptions {
  dir?: string;
}

/**
 * Command for building all local components.
 */
export function buildCommand(program: Command): void {
  program
    .command('build')
    .description('Build all local components')
    .option(
      '-d, --dir <directory>',
      'Component directory to build the components in',
    )
    .action(async (options: BuildOptions) => {
      p.intro('Experience Builder Component Build');

      if (options.dir) setConfig({ componentDir: options.dir });
      const config = getConfig();

      const componentDirs = await findComponentDirectories(config.componentDir);

      const componentLabelPluralized =
        componentDirs.length === 1 ? 'component' : 'components';

      const s = p.spinner();
      s.start(`Building components`);
      s.stop(
        chalk.green(
          `Processed ${componentDirs.length} ${componentLabelPluralized}`,
        ),
      );
      const results: Result[] = [];
      for (const componentDir of componentDirs) {
        results.push(await buildComponent(componentDir));
      }

      reportResults(results, 'Built components', 'Component');

      p.outro(`📦 Build completed`);
    });
}
