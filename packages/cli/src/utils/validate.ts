import path from 'path';
import { ESLint } from 'eslint';
import { required as drupalCanvasRequired } from '@drupal-canvas/eslint-config';

import {
  CodeComponentMetadataOperationUnsupportedError,
  CodeComponentMetadataValidationUnavailableError,
} from '../services/api';
import {
  createComponentPayload,
  readComponentMetadata,
} from './process-component-files';

import type {
  DiscoveredComponent,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type { ApiService } from '../services/api';
import type { Metadata } from '../types/Metadata';
import type { Result } from '../types/Result';

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

function createValidationPayload(
  component: DiscoveredComponent,
  metadata: Metadata,
  external: boolean,
) {
  const common = {
    metadata,
    machineName: metadata.machineName,
    componentName: component.name,
    dataDependencies: {},
  };
  return external
    ? createComponentPayload({ ...common, type: 'external' })
    : createComponentPayload({
        ...common,
        code: {
          sourceCodeJs: '',
          compiledJs: '',
          sourceCodeCss: '',
          compiledCss: '',
          importedJsComponents: [],
        },
      });
}

async function validateComponent(
  component: DiscoveredComponent,
  options: {
    fix: boolean;
    external: boolean;
    remoteValidation?: Pick<ApiService, 'validateCodeComponentPayload'>;
  },
): Promise<{ result: Result; remoteUnsupported: boolean }> {
  const eslint = new ESLint({
    overrideConfigFile: true,
    overrideConfig: drupalCanvasRequired,
    fix: options.fix,
  });
  const eslintResults = await eslint.lintFiles(component.directory + '/**/*');
  if (options.fix) {
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

  const localLintIsValid = eslintResults.every(
    (result) => result.errorCount === 0,
  );
  let remoteUnsupported = false;
  try {
    const metadata = await readComponentMetadata(component.metadataPath);
    if (options.remoteValidation && localLintIsValid) {
      try {
        await options.remoteValidation.validateCodeComponentPayload(
          createValidationPayload(component, metadata, options.external),
        );
      } catch (error) {
        if (
          error instanceof CodeComponentMetadataOperationUnsupportedError ||
          error instanceof CodeComponentMetadataValidationUnavailableError
        ) {
          remoteUnsupported = true;
        } else {
          details.push({
            heading: 'Target site validation',
            content: error instanceof Error ? error.message : String(error),
          });
        }
      }
    }
  } catch (error) {
    details.push({
      heading: path.relative(process.cwd(), component.metadataPath),
      content: error instanceof Error ? error.message : String(error),
    });
  }

  const success = localLintIsValid && details.length === 0;

  return {
    result: {
      itemName: component.name,
      success,
      details,
    },
    remoteUnsupported,
  };
}

export async function validateComponents(
  discoveryResult: DiscoveryResult,
  options: {
    fix?: boolean;
    apiService?: Pick<ApiService, 'validateCodeComponentPayload'>;
    externalComponents?: boolean;
  } = {},
): Promise<{ results: Result[]; warnings: string[] }> {
  const results: Result[] = [];
  const warnings: string[] = [];
  const components = getComponentsToValidate(discoveryResult.components);
  let remoteValidation = options.apiService;
  if (components.length > 0 && !remoteValidation) {
    warnings.push(
      'No authenticated target site is available. Only local checks were completed; target acceptance was not validated.',
    );
  }

  for (const component of components) {
    const outcome = await validateComponent(component, {
      fix: options.fix ?? false,
      external: options.externalComponents ?? false,
      remoteValidation,
    });
    results.push(outcome.result);
    if (outcome.remoteUnsupported) {
      warnings.push(
        'Target metadata validation is unavailable. Only local checks were completed; target acceptance was not validated.',
      );
      remoteValidation = undefined;
    }
  }

  return { results, warnings };
}
