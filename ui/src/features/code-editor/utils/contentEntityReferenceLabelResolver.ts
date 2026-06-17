import { useEffect, useMemo, useState } from 'react';

import { useAppDispatch } from '@/app/hooks';
import {
  contentEntityReferenceApi,
  getFieldsHref,
} from '@/services/contentEntityReferenceApi';

import type { AppDispatch } from '@/app/store';
import type { ContentEntityReferencePropState } from '@/features/code-editor/dialogs/ContentRelationshipDialog';
import type {
  ContentEntityReferenceEntityTypes,
  ContentEntityReferenceField,
} from '@/services/contentEntityReferenceApi';

const LABEL_SEPARATOR = ' → ';
const MAX_RESOLUTION_DEPTH = 8;

type ContentRelationshipField =
  ContentEntityReferencePropState['fields'][number];

export function rowReferenceKey(
  ancestorKeys: string[],
  fieldName: string,
): string {
  const parent = ancestorKeys[ancestorKeys.length - 1];
  return parent ? `${parent}/${fieldName}` : fieldName;
}

export function joinLabelChain(segments: string[]): string {
  return segments.join(LABEL_SEPARATOR);
}

function getExpressionFallbackFields(
  expressions: string[],
): ContentRelationshipField[] {
  return expressions.map((expression) => ({
    expressions: [expression],
    label: expression,
    referenceChain: [],
  }));
}

async function fetchFields(
  dispatch: AppDispatch,
  href: string,
): Promise<ContentEntityReferenceField[] | undefined> {
  const promise = dispatch(
    contentEntityReferenceApi.endpoints.listFields.initiate({ href }),
  );
  try {
    const { data } = await promise;
    return data;
  } finally {
    promise.unsubscribe();
  }
}

async function resolveExpressionFieldsFromHref({
  href,
  ancestorLabels,
  ancestorKeys,
  pending,
  resolved,
  dispatch,
  isCancelled,
  depth,
}: {
  href: string;
  ancestorLabels: string[];
  ancestorKeys: string[];
  pending: Set<string>;
  resolved: Map<string, ContentRelationshipField>;
  dispatch: AppDispatch;
  isCancelled: () => boolean;
  depth: number;
}): Promise<void> {
  if (isCancelled() || pending.size === 0 || depth > MAX_RESOLUTION_DEPTH) {
    return;
  }

  const fields = await fetchFields(dispatch, href);
  if (isCancelled() || !fields) return;

  for (const field of fields) {
    const rowKey = rowReferenceKey(ancestorKeys, field.name);
    const fieldLabels = [...ancestorLabels, field.label];

    for (const property of field.properties) {
      if (!pending.has(property.expression)) continue;
      resolved.set(property.expression, {
        expressions: [property.expression],
        label: joinLabelChain([...fieldLabels, property.label]),
        referenceChain: ancestorKeys,
      });
      pending.delete(property.expression);
    }

    if (pending.size === 0) return;

    for (const [bundleId, targetBundle] of Object.entries(
      field.targetBundles ?? {},
    )) {
      const nestedHref = getFieldsHref(targetBundle);
      if (!nestedHref) continue;
      await resolveExpressionFieldsFromHref({
        href: nestedHref,
        ancestorLabels: fieldLabels,
        ancestorKeys: [...ancestorKeys, rowKey, `${rowKey}:${bundleId}`],
        pending,
        resolved,
        dispatch,
        isCancelled,
        depth: depth + 1,
      });
      if (pending.size === 0 || isCancelled()) return;
    }
  }
}

export function useResolvedExpressionFields(
  expressions: string[],
  target: { entityType: string; bundle: string } | null,
  entityTypes: ContentEntityReferenceEntityTypes | undefined,
): ContentRelationshipField[] {
  const dispatch = useAppDispatch();
  const [resolvedFields, setResolvedFields] = useState<{
    key: string;
    fields: ContentRelationshipField[];
  } | null>(null);
  const expressionKey = useMemo(
    () => JSON.stringify(expressions),
    [expressions],
  );
  const fallbackFields = useMemo(
    () => getExpressionFallbackFields(expressions),
    [expressions],
  );

  useEffect(() => {
    if (expressions.length === 0) {
      setResolvedFields({ key: expressionKey, fields: [] });
      return;
    }
    if (!target || !entityTypes) {
      setResolvedFields(null);
      return;
    }

    const rootHref = getFieldsHref(
      entityTypes[target.entityType]?.bundles[target.bundle],
    );
    if (!rootHref) {
      setResolvedFields(null);
      return;
    }

    let cancelled = false;
    const pending = new Set(expressions);
    const resolved = new Map<string, ContentRelationshipField>();

    void resolveExpressionFieldsFromHref({
      href: rootHref,
      ancestorLabels: [],
      ancestorKeys: [],
      pending,
      resolved,
      dispatch,
      isCancelled: () => cancelled,
      depth: 0,
    }).then(() => {
      if (cancelled) return;
      setResolvedFields({
        key: expressionKey,
        fields: fallbackFields.map(
          (field) => resolved.get(field.expressions[0]) ?? field,
        ),
      });
    });

    return () => {
      cancelled = true;
    };
  }, [
    dispatch,
    entityTypes,
    expressionKey,
    expressions,
    fallbackFields,
    target,
  ]);

  return resolvedFields?.key === expressionKey
    ? resolvedFields.fields
    : fallbackFields;
}
