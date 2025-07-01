import type { Command } from 'commander';
import * as p from '@clack/prompts';
import chalk from 'chalk';
import { setConfig } from '../config.js';
import { buildComponent } from '../utils/build.js';
import { reportResults } from '../utils/report-results';
import type { Result } from '../types/Result.js';
import { selectLocalComponents } from '../utils/select-local-components.js';
import { buildTailwindForComponents } from '../utils/build-tailwind';

interface BuildOptions {
  dir?: string;
  all?: boolean;
  tailwind?: boolean;
}

/**
 * Command for building all local components and Tailwind CSS.
 */
export function buildCommand(program: Command): void {
  program
    .command('build')
    .description('Build local components and Tailwind CSS')
    .option(
      '-d, --dir <directory>',
      'Component directory to build the components in',
    )
    .option('--all', 'Build all components')
    .option('--no-tailwind', 'Skip Tailwind CSS building')
    .action(async (options: BuildOptions) => {
      p.intro('Experience Builder Component Build');

      const allFlag = options.all || false;
      const skipTailwind = !options.tailwind;

      if (options.dir) setConfig({ componentDir: options.dir });

      // Select components to build
      const selectedComponents = await selectLocalComponents(
        allFlag,
        'Select components to build',
      );

      if (!selectedComponents || selectedComponents.length === 0) {
        return;
      }

      const componentLabelPluralized =
        selectedComponents.length === 1 ? 'component' : 'components';

      // Step 1: Build individual components
      const s1 = p.spinner();
      s1.start(`Building ${componentLabelPluralized}`);
      const results: Result[] = [];
      for (const componentDir of selectedComponents) {
        results.push(await buildComponent(componentDir));
      }
      s1.stop(
        chalk.green(
          `Processed ${selectedComponents.length} ${componentLabelPluralized}`,
        ),
      );

      // Report component build results
      reportResults(results, 'Built components', 'Component');
      if (skipTailwind) {
        p.log.info('Skipping Tailwind CSS build');
      } else {
        // Step 2: Build Tailwind CSS
        const s2 = p.spinner();
        s2.start('Building Tailwind CSS');
        const tailwindResult = await buildTailwindForComponents(
          selectedComponents as string[],
        );
        s2.stop(
          chalk.green(
            `Processed Tailwind CSS classes from ${selectedComponents.length} selected local ${componentLabelPluralized} and all online components`,
          ),
        );

        // Report Tailwind CSS results in separate table
        reportResults([tailwindResult], 'Built assets', 'Asset');
      }

      p.outro(`📦 Build completed`);
    });
}
