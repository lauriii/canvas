import { fetchCsrfToken } from './preview-client';
import { SYNTHETIC_ROOT_TYPE } from './preview-spec-utils';

import type { Spec } from '@json-render/core';
import type { PreviewManifestComponent } from './preview-contract';
import type { ResolvedPreviewModel } from './preview-spec-utils';

interface ContentEntityReferenceSpecResolutionJob {
  uuid: string;
  propName: string;
  target: ContentEntityReferenceTarget;
  entityId: string;
  entityFields: Record<string, string[]>;
}

export interface ContentEntityReferenceTarget {
  key: string;
  entityTypeId: string;
  bundle: string;
}

export interface ContentEntityReferencePropPreview {
  propName: string;
  target: ContentEntityReferenceTarget;
}

export interface ResolvedContentEntityReferenceFieldsResponse {
  data: Record<string, unknown>;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function getContentEntityReferencePropTarget(
  prop: unknown,
): ContentEntityReferenceTarget | null {
  if (!isRecord(prop)) {
    return null;
  }
  const entityTypeId = prop['x-allowed-entity-type-id'];
  const bundle = prop['x-allowed-bundle'];
  if (typeof entityTypeId !== 'string' || typeof bundle !== 'string') {
    return null;
  }
  const resolvedTarget = {
    entityTypeId,
    bundle,
  };
  return {
    ...resolvedTarget,
    key: `${resolvedTarget.entityTypeId}:${resolvedTarget.bundle}`,
  };
}

export function getContentEntityReferencePropPreviews(
  entityFields?: Record<string, string[]>,
  props?: Record<string, unknown>,
): ContentEntityReferencePropPreview[] {
  const previews: ContentEntityReferencePropPreview[] = [];
  const propNames = [
    ...Object.keys(props ?? {}),
    ...Object.keys(entityFields ?? {}).filter(
      (propName) => !Object.hasOwn(props ?? {}, propName),
    ),
  ];
  for (const propName of propNames) {
    const expressions = entityFields?.[propName];
    if (!expressions) {
      continue;
    }
    const target = getContentEntityReferencePropTarget(props?.[propName]);
    if (target) {
      previews.push({
        propName,
        target,
      });
    }
  }

  return previews;
}

export function groupEntityFieldsByProp(
  entityFields: Record<string, string[]> | undefined,
  props: Record<string, unknown> | undefined,
  selectedEntityIds: Record<string, string | null>,
): Array<{
  propName: string;
  target: ContentEntityReferenceTarget;
  entityId: string;
  entityFields: Record<string, string[]>;
}> {
  const groups: Array<{
    propName: string;
    target: ContentEntityReferenceTarget;
    entityId: string;
    entityFields: Record<string, string[]>;
  }> = [];
  for (const [propName, expressions] of Object.entries(entityFields ?? {})) {
    const target = getContentEntityReferencePropTarget(props?.[propName]);
    if (!target) {
      continue;
    }
    const entityId = selectedEntityIds[propName];
    if (!entityId) {
      continue;
    }
    groups.push({
      propName,
      target,
      entityId,
      entityFields: {
        [propName]: expressions,
      },
    });
  }

  return groups;
}

function getComponentTypeNames(
  component: PreviewManifestComponent,
): Set<string> {
  const names = new Set<string>([component.name, component.id]);
  if (component.name.startsWith('js.')) {
    names.add(component.name.slice(3));
  } else {
    names.add(`js.${component.name}`);
  }
  return names;
}

function getComponentByType(
  components: PreviewManifestComponent[],
  type: string,
): PreviewManifestComponent | null {
  return (
    components.find((component) =>
      getComponentTypeNames(component).has(type),
    ) ?? null
  );
}

function getContentEntityReferenceEntityId(value: unknown): string | null {
  if (!isRecord(value)) {
    return null;
  }
  const targetId = value.target_id;
  if (typeof targetId === 'string' || typeof targetId === 'number') {
    return String(targetId);
  }
  return null;
}

export function getContentEntityReferenceSpecResolutionJobs(
  spec: Spec,
  components: PreviewManifestComponent[],
): ContentEntityReferenceSpecResolutionJob[] {
  const jobs: ContentEntityReferenceSpecResolutionJob[] = [];
  for (const [uuid, element] of Object.entries(spec.elements ?? {})) {
    if (!element || element.type === SYNTHETIC_ROOT_TYPE) {
      continue;
    }
    const component = getComponentByType(components, element.type);
    if (!component || !isRecord(element.props)) {
      continue;
    }
    const entityFields = component.dataDependencies.entityFields;
    if (!entityFields) {
      continue;
    }
    for (const [propName, expressions] of Object.entries(entityFields)) {
      if (!Array.isArray(expressions) || expressions.length === 0) {
        continue;
      }
      const entityId = getContentEntityReferenceEntityId(
        element.props[propName],
      );
      if (!entityId) {
        continue;
      }
      const target = getContentEntityReferencePropTarget(
        component.props[propName],
      );
      if (!target) {
        continue;
      }
      jobs.push({
        uuid,
        propName,
        target,
        entityId,
        entityFields: {
          [propName]: expressions,
        },
      });
    }
  }
  return jobs;
}

export async function fetchResolvedContentEntityReferenceFieldsForSpec(
  spec: Spec,
  components: PreviewManifestComponent[],
  signal?: AbortSignal,
): Promise<ResolvedPreviewModel> {
  const jobs = getContentEntityReferenceSpecResolutionJobs(spec, components);
  if (jobs.length === 0) {
    return {};
  }
  const resolvedGroups = await Promise.all(
    jobs.map(async (job) => ({
      job,
      resolved: await fetchResolvedContentEntityReferenceFields(
        job.target.entityTypeId,
        job.entityId,
        job.entityFields,
        signal,
      ),
    })),
  );
  const model: ResolvedPreviewModel = {};
  for (const { job, resolved } of resolvedGroups) {
    model[job.uuid] ??= { resolved: {} };
    model[job.uuid].resolved = {
      ...(model[job.uuid].resolved ?? {}),
      [job.propName]: resolved[job.propName],
    };
  }
  return model;
}

export async function fetchResolvedContentEntityReferenceFields(
  entityTypeId: string,
  entityId: string,
  entityFields: Record<string, string[]>,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  const csrfToken = await fetchCsrfToken(signal);
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };
  if (csrfToken) {
    headers['X-CSRF-Token'] = csrfToken;
  }
  const response = await fetch(
    `/canvas/api/v0/ui/content-entity-reference/preview/${encodeURIComponent(entityTypeId)}/${encodeURIComponent(entityId)}`,
    {
      method: 'POST',
      credentials: 'include',
      headers,
      body: JSON.stringify({
        entityFields,
      }),
      ...(signal ? { signal } : {}),
    },
  );
  if (!response.ok) {
    const errorBody = (await response.json().catch(() => null)) as {
      message?: string;
    } | null;
    throw new Error(
      errorBody?.message ??
        `Content entity reference preview request failed with status ${response.status}.`,
    );
  }
  const result =
    (await response.json()) as ResolvedContentEntityReferenceFieldsResponse;
  return result.data;
}
