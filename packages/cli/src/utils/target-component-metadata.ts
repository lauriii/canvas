import { CodeComponentMetadataOperationUnsupportedError } from '../services/api';

import type { ApiService } from '../services/api';
import type { Result } from '../types/Result';
import type { BuiltComponent } from './build-project';

export async function preflightCodeComponentPayloads(
  builtComponents: BuiltComponent[],
  apiService: Pick<ApiService, 'validateCodeComponentPayload'>,
): Promise<{ results: Result[]; warnings: string[] }> {
  const results: Result[] = [];
  for (const component of builtComponents) {
    try {
      await apiService.validateCodeComponentPayload(component.componentPayload);
      results.push({ itemName: component.componentName, success: true });
    } catch (error) {
      if (error instanceof CodeComponentMetadataOperationUnsupportedError) {
        return {
          results: [],
          warnings: [
            'The target does not support metadata preflight validation. Push will use per-component save-time validation, so a partial push remains possible.',
          ],
        };
      }
      results.push({
        itemName: component.componentName,
        success: false,
        details: [
          {
            heading: 'Target site validation',
            content: error instanceof Error ? error.message : String(error),
          },
        ],
      });
    }
  }
  return { results, warnings: [] };
}
