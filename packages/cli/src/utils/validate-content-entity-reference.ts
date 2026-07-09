import type { ApiService } from '../services/api';
import type { Metadata } from '../types/Metadata';

const CONTENT_ENTITY_REFERENCE_REF =
  'json-schema-definitions://canvas.module/content-entity-reference';

export type ContentEntityReferenceValidationApi = Pick<
  ApiService,
  'fetchPreviewEntitySuggestions' | 'previewContentEntityReferenceFields'
>;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isContentEntityReferenceProp(value: unknown): boolean {
  return isRecord(value) && value.$ref === CONTENT_ENTITY_REFERENCE_REF;
}

function getContentEntityReferencePropTarget(
  prop: unknown,
): { entityTypeId: string; bundle: string } | null {
  if (!isRecord(prop)) {
    return null;
  }
  const entityTypeId = prop['x-allowed-entity-type-id'];
  const bundle = prop['x-allowed-bundle'];
  if (typeof entityTypeId !== 'string' || typeof bundle !== 'string') {
    return null;
  }
  return {
    entityTypeId,
    bundle,
  };
}

function getPreviewableEntityFieldGroups(metadata: Metadata): Array<{
  propName: string;
  entityTypeId: string;
  bundle: string;
  expressions: string[];
}> {
  const props = metadata.props.properties ?? {};
  const entityFields = metadata.dataDependencies?.entityFields;
  if (!isRecord(entityFields)) {
    return [];
  }

  const groups: Array<{
    propName: string;
    entityTypeId: string;
    bundle: string;
    expressions: string[];
  }> = [];
  for (const [propName, expressions] of Object.entries(entityFields)) {
    if (!isContentEntityReferenceProp(props[propName])) {
      continue;
    }
    if (
      !Array.isArray(expressions) ||
      expressions.length === 0 ||
      !expressions.every(
        (expression): expression is string => typeof expression === 'string',
      )
    ) {
      continue;
    }

    const target = getContentEntityReferencePropTarget(props[propName]);
    if (!target) {
      continue;
    }
    groups.push({
      propName,
      entityTypeId: target.entityTypeId,
      bundle: target.bundle,
      expressions,
    });
  }
  return groups;
}

export async function validateContentEntityReferencePropExpressions(
  metadata: Metadata,
  apiService?: ContentEntityReferenceValidationApi,
): Promise<Array<{ heading: string; content: string }>> {
  if (!apiService) {
    return [];
  }

  const messages: string[] = [];
  const suggestionCache = new Map<string, string | null>();
  for (const group of getPreviewableEntityFieldGroups(metadata)) {
    const cacheKey = `${group.entityTypeId}:${group.bundle}`;
    if (!suggestionCache.has(cacheKey)) {
      const suggestions = await apiService.fetchPreviewEntitySuggestions(
        group.entityTypeId,
        group.bundle,
      );
      suggestionCache.set(
        cacheKey,
        suggestions.length > 0 ? suggestions[0].id : null,
      );
    }
    const previewEntityId = suggestionCache.get(cacheKey);
    if (!previewEntityId) {
      continue;
    }
    try {
      await apiService.previewContentEntityReferenceFields(
        group.entityTypeId,
        previewEntityId,
        {
          [group.propName]: group.expressions,
        },
      );
    } catch (error) {
      messages.push(
        error instanceof Error
          ? `dataDependencies.entityFields.${group.propName} failed server validation: ${error.message}`
          : `dataDependencies.entityFields.${group.propName} failed server validation: ${String(error)}`,
      );
    }
  }

  return messages.length > 0
    ? [
        {
          heading: 'Content entity reference props',
          content: messages.join('\n\n'),
        },
      ]
    : [];
}
