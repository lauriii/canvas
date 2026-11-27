import path from 'path';

import {
  findStyleLibraryCollisions,
  formatStyleLibraryDiagnostic,
  styleLibrariesEqual,
} from './style-library';

import type { ApiService } from '../../services/api';
import type { Result } from '../../types/Result';
import type { LocalStyleLibrary, StyleLibraryPayload } from './style-library';

export interface StyleLibraryPushPlanEntry {
  local: LocalStyleLibrary;
  operation: 'create' | 'update' | 'unchanged';
}

/**
 * Returns a failed result per library with local rule errors. Warnings, such
 * as options without a value, do not block a push.
 */
export function collectStyleLibraryErrors(
  libraries: LocalStyleLibrary[],
  definitions: { id: string; provider?: string; providerType?: string }[] = [],
): Result[] {
  const collisions = findStyleLibraryCollisions(libraries, definitions);
  return libraries
    .map((local): Result | null => {
      const messages = [
        ...local.diagnostics
          .filter((diagnostic) => diagnostic.severity === 'error')
          .map(formatStyleLibraryDiagnostic),
        ...(collisions.get(local) ?? []),
      ];
      return messages.length > 0
        ? {
            itemName: path.relative(process.cwd(), local.filePath),
            itemType: 'Style library',
            success: false,
            details: [{ content: messages.join('\n') }],
          }
        : null;
    })
    .filter((result): result is Result => result !== null);
}

/** Plans which local libraries to create or update. */
export function planStyleLibraryPush(
  libraries: LocalStyleLibrary[],
  remote: StyleLibraryPayload[],
): StyleLibraryPushPlanEntry[] {
  const remoteById = new Map(remote.map((library) => [library.id, library]));
  return libraries.map((local) => {
    const existing = remoteById.get(local.library.id);
    return {
      local,
      operation: !existing
        ? 'create'
        : styleLibrariesEqual(local.library, existing)
          ? 'unchanged'
          : 'update',
    };
  });
}

/**
 * Pushes planned libraries one by one. Unchanged libraries are not sent, so
 * a push right after a pull changes nothing on the site. Canvas validation
 * errors name the offending style or option in their source pointer.
 */
export async function pushStyleLibraries(
  plan: StyleLibraryPushPlanEntry[],
  apiService: Pick<ApiService, 'createStyleLibrary' | 'updateStyleLibrary'>,
): Promise<Result[]> {
  const results: Result[] = [];
  for (const { local, operation } of plan) {
    const itemName = path.basename(local.filePath);
    try {
      if (operation === 'create') {
        await apiService.createStyleLibrary(local.library);
      } else if (operation === 'update') {
        await apiService.updateStyleLibrary(local.library);
      }
      results.push({
        itemName,
        success: true,
        details: [
          {
            content:
              operation === 'create'
                ? 'Created'
                : operation === 'update'
                  ? 'Updated'
                  : 'Unchanged',
          },
        ],
      });
    } catch (error) {
      results.push({
        itemName,
        success: false,
        details: [
          { content: error instanceof Error ? error.message : String(error) },
        ],
      });
    }
  }
  return results;
}
