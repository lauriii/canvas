import { processInPool } from '../request-pool';

import type { ContentEntityReferenceField } from '../../services/api';
import type { AgentsContextProvider } from '../agents-context';

const typedDataBrowserRel =
  'https://drupal.org/project/canvas#link-rel-typed-data-browser';
const maxDepth = 4;

function collectNestedFieldJobs(
  fields: ContentEntityReferenceField[],
  visitedFieldHrefs: Set<string>,
  depth: number,
): Array<{
  entityTypeId: string;
  bundle: string;
  label: string;
  href: string;
  depth: number;
}> {
  const jobs: Array<{
    entityTypeId: string;
    bundle: string;
    label: string;
    href: string;
    depth: number;
  }> = [];
  for (const field of fields) {
    for (const [bundle, target] of Object.entries(field.targetBundles ?? {})) {
      const href = target.links?.[typedDataBrowserRel]?.href;
      if (!field.targetEntityType || !href || visitedFieldHrefs.has(href)) {
        continue;
      }
      visitedFieldHrefs.add(href);
      jobs.push({
        entityTypeId: field.targetEntityType,
        bundle,
        label: target.label,
        href,
        depth,
      });
    }
  }
  return jobs;
}

export const contentEntityReferenceExpressionsContextProvider: AgentsContextProvider =
  {
    name: 'content-entity-reference-expressions',
    description: 'Writes content-entity-reference field browser expressions.',
    aliases: ['cer-expressions'],
    default: true,
    execute: async ({ apiService, writeContextFile, writeMessage }) => {
      const contentEntityReferenceExpressions: Record<
        string,
        Record<
          string,
          Array<{
            label: string;
            href?: string;
            fields: ContentEntityReferenceField[];
          }>
        >
      > = {};
      const contentEntityReferenceEntityTypes =
        await apiService.listContentEntityReferenceEntityTypes();
      const visitedFieldHrefs = new Set<string>();
      let fieldJobs: Array<{
        entityTypeId: string;
        bundle: string;
        label: string;
        depth: number;
        href?: string;
      }> = Object.entries(contentEntityReferenceEntityTypes).flatMap(
        ([entityTypeId, entityType]) =>
          Object.entries(entityType.bundles).map(([bundle, bundleInfo]) => ({
            entityTypeId,
            bundle,
            label: bundleInfo.label,
            depth: 0,
          })),
      );
      while (fieldJobs.length > 0) {
        const currentJobs = fieldJobs;
        fieldJobs = [];
        const fieldResults = await processInPool(currentJobs, async (job) => {
          const fields = await apiService.fetchContentEntityReferenceFields(
            job.entityTypeId,
            job.bundle,
            job.href,
          );
          return { ...job, fields };
        });

        for (const result of fieldResults) {
          if (!result.success || !result.result) continue;
          const { entityTypeId, bundle, label, fields } = result.result;
          contentEntityReferenceExpressions[entityTypeId] ??= {};
          contentEntityReferenceExpressions[entityTypeId][bundle] ??= [];
          contentEntityReferenceExpressions[entityTypeId][bundle].push({
            label,
            ...(result.result.href ? { href: result.result.href } : {}),
            fields,
          });
          if (result.result.depth >= maxDepth) {
            continue;
          }
          const nestedJobs = collectNestedFieldJobs(
            fields,
            visitedFieldHrefs,
            result.result.depth + 1,
          );
          if (nestedJobs.length > 0) {
            fieldJobs.push(...nestedJobs);
          }
        }
      }

      const filePath = await writeContextFile(
        'content-entity-reference-expressions.json',
        contentEntityReferenceExpressions,
      );
      writeMessage(
        `Saved content-entity-reference field browser expressions to ${filePath}.`,
      );
    },
  };
