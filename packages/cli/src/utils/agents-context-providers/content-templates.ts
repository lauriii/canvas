import { processInPool } from '../request-pool';

import type { AgentsContextProvider } from '../agents-context';

interface PropSourceSuggestion {
  label: string;
  source: Record<string, unknown>;
}

function flattenSuggestions(items: unknown[]): PropSourceSuggestion[] {
  const result: PropSourceSuggestion[] = [];
  for (const item of items) {
    if (!item || typeof item !== 'object') continue;
    const record = item as Record<string, unknown>;
    if (record.source && typeof record.source === 'object') {
      result.push({
        label: typeof record.label === 'string' ? record.label : '',
        source: record.source as Record<string, unknown>,
      });
    }
    if (Array.isArray(record.items)) {
      result.push(...flattenSuggestions(record.items));
    }
  }
  return result;
}

function simplifyViewModes(
  viewModes: Record<
    string,
    Record<string, Record<string, { label: string; hasTemplate: boolean }>>
  >,
): Record<string, Record<string, Record<string, string>>> {
  const result: Record<string, Record<string, Record<string, string>>> = {};
  for (const [entityType, bundles] of Object.entries(viewModes)) {
    result[entityType] = {};
    for (const [bundle, modes] of Object.entries(bundles)) {
      result[entityType][bundle] = {};
      for (const [viewMode, info] of Object.entries(modes)) {
        result[entityType][bundle][viewMode] = info.label;
      }
    }
  }
  return result;
}

export const contentTemplatesContextProvider: AgentsContextProvider = {
  name: 'content-templates',
  description: 'Writes prop source suggestions and entity view modes.',
  default: true,
  execute: async ({ apiService, writeContextFile, writeMessage }) => {
    const [viewModes, components] = await Promise.all([
      apiService.listViewModes(),
      apiService.listComponents(),
    ]);
    const componentIds = Object.keys(components).map((name) => `js.${name}`);
    const bundles: Array<{ entityTypeId: string; bundle: string }> = [];
    for (const [entityTypeId, bundleMap] of Object.entries(viewModes)) {
      for (const bundle of Object.keys(bundleMap)) {
        bundles.push({ entityTypeId, bundle });
      }
    }

    const propSources: Record<
      string,
      Record<string, Record<string, Record<string, PropSourceSuggestion[]>>>
    > = {};

    if (componentIds.length > 0 && bundles.length > 0) {
      const jobs = bundles.flatMap(({ entityTypeId, bundle }) =>
        componentIds.map((componentId) => ({
          entityTypeId,
          bundle,
          componentId,
        })),
      );

      const results = await processInPool(jobs, async (job) => {
        const raw = await apiService.fetchPropSourceSuggestions(
          job.entityTypeId,
          job.bundle,
          job.componentId,
        );
        const flattened: Record<string, PropSourceSuggestion[]> = {};
        for (const [propName, suggestions] of Object.entries(raw)) {
          const flat = flattenSuggestions(
            Array.isArray(suggestions) ? suggestions : [],
          );
          if (flat.length > 0) {
            flattened[propName] = flat;
          }
        }
        return { ...job, suggestions: flattened };
      });

      for (const result of results) {
        if (!result.success || !result.result) continue;
        const { entityTypeId, bundle, componentId, suggestions } =
          result.result;
        if (Object.keys(suggestions).length === 0) continue;
        propSources[entityTypeId] ??= {};
        propSources[entityTypeId][bundle] ??= {};
        propSources[entityTypeId][bundle][componentId] = suggestions;
      }
    }

    const propSourcesPath = await writeContextFile(
      'prop-sources.json',
      propSources,
    );
    writeMessage(
      `Saved prop source suggestions for content template component props to ${propSourcesPath}.`,
    );
    const viewModesPath = await writeContextFile(
      'view-modes.json',
      simplifyViewModes(viewModes),
    );
    writeMessage(
      `Saved content template entity view modes to ${viewModesPath}.`,
    );
  },
};
