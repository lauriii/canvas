import path from 'path';
import { basename } from 'path/win32';
import { ESLint } from 'eslint';
import { required as drupalCanvasRequired } from '@drupal-canvas/eslint-config';

import type { Result } from '../types/Result';

export async function validateComponent(
  componentDir: string,
  fix: boolean = false,
): Promise<Result> {
  const eslint = new ESLint({
    overrideConfigFile: true,
    overrideConfig: drupalCanvasRequired,
    fix,
  });
  const eslintResults = await eslint.lintFiles(componentDir + '/**/*');
  if (fix) {
    await ESLint.outputFixes(eslintResults);
  }
  const success = eslintResults.every((result) => result.errorCount === 0);
  const details: { heading: string; content: string }[] = [];
  eslintResults
    .filter((result) => result.errorCount > 0)
    .forEach((result) => {
      const messages = result.messages.map(
        (msg) =>
          `Line ${msg.line}, Column ${msg.column}: ` +
          msg.message +
          (msg.ruleId ? ` (${msg.ruleId})` : ''),
      );

      details.push({
        heading: path.relative(process.cwd(), result.filePath),
        content: messages.join('\n\n'),
      });
    });

  return {
    itemName: basename(componentDir),
    success,
    details,
  };
}
