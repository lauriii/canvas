import path from 'path';
import { ESLint } from 'eslint';
import { required as drupalCanvasRequired } from '@drupal-canvas/eslint-config';

import { readComponentMetadata } from './process-component-files';
import { validateContentEntityReferencePropExpressions } from './validate-content-entity-reference';

import type {
  DiscoveredComponent,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type { Result } from '../types/Result';
import type { ContentEntityReferenceValidationApi } from './validate-content-entity-reference';

function getComponentsToValidate(
  components: DiscoveredComponent[],
): DiscoveredComponent[] {
  const componentsByDirectory = new Map<string, DiscoveredComponent>();

  for (const component of components) {
    if (!componentsByDirectory.has(component.directory)) {
      componentsByDirectory.set(component.directory, component);
    }
  }

  return [...componentsByDirectory.values()];
}

async function validateComponent(
  component: DiscoveredComponent,
  fix: boolean = false,
  apiService?: ContentEntityReferenceValidationApi,
): Promise<Result> {
  const eslint = new ESLint({
    overrideConfigFile: true,
    overrideConfig: drupalCanvasRequired,
    fix,
  });
  const eslintResults = await eslint.lintFiles(component.directory + '/**/*');
  if (fix) {
    await ESLint.outputFixes(eslintResults);
  }
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

  const metadata = await readComponentMetadata(component.metadataPath);
  if (metadata) {
    details.push(
      ...(await validateContentEntityReferencePropExpressions(
        metadata,
        apiService,
      )),
    );
  }

  const success =
    eslintResults.every((result) => result.errorCount === 0) &&
    details.length === 0;

  return {
    itemName: component.name,
    success,
    details,
  };
}

export async function validateComponents(
  discoveryResult: DiscoveryResult,
  options: {
    fix?: boolean;
    apiService?: ContentEntityReferenceValidationApi;
  } = {},
): Promise<{ results: Result[] }> {
  const results: Result[] = [];

  for (const component of getComponentsToValidate(discoveryResult.components)) {
    results.push(
      await validateComponent(component, options.fix, options.apiService),
    );
  }

  return { results };
}
