import {
  discoverCanvasProject,
  loadComponentsMetadata,
} from '@drupal-canvas/discovery';

import { getConfig } from '../../config';

import type { ApiService } from '../../services/api';
import type { Component } from '../../types/Component';
import type { AgentsContextProvider } from '../agents-context';

export const contentEntityReferencePreviewContextProvider: AgentsContextProvider =
  {
    name: 'content-entity-reference-preview',
    description:
      'Prints resolved content-entity-reference prop preview values for one component.\nUsage: canvas agents-context cer-preview <component-id>',
    aliases: ['cer-preview'],
    default: false,
    execute: async ({ apiService, writeOutput, arguments: providerArgs }) => {
      const componentId = providerArgs[0];
      if (!componentId) {
        throw new Error(
          'The content-entity-reference-preview provider requires a component ID argument.\nUsage: canvas agents-context cer-preview <component-id>',
        );
      }
      const components = await loadLocalComponents();
      const [componentKey, component] = findComponent(components, componentId);
      if (!component) {
        throw new Error(`Component '${componentId}' was not found.`);
      }

      const preview = await resolveContentEntityReferencePreview(
        apiService,
        componentKey,
        component,
      );
      writeOutput(preview);
    },
  };

async function loadLocalComponents(): Promise<Record<string, Component>> {
  const config = getConfig();
  const discoveryResult = await discoverCanvasProject({
    componentRoot: config.componentDir,
    projectRoot: process.cwd(),
  });
  const metadata = await loadComponentsMetadata(discoveryResult);
  const components: Record<string, Component> = {};
  for (const component of metadata) {
    components[component.machineName] = {
      name: component.name,
      machineName: component.machineName,
      status: component.status,
      required: component.required,
      props: component.props.properties,
      slots: component.slots,
      sourceCodeJs: '',
      sourceCodeCss: '',
      compiledJs: '',
      compiledCss: '',
      importedJsComponents: [],
      dataDependencies: component.dataDependencies ?? {},
    };
  }
  return components;
}

function findComponent(
  components: Record<string, Component>,
  componentId: string,
): [string, Component | undefined] {
  const normalized = componentId.startsWith('js.')
    ? componentId.slice(3)
    : componentId;
  for (const [key, component] of Object.entries(components)) {
    const candidates = new Set([
      key,
      `js.${key}`,
      component.name,
      `js.${component.name}`,
      component.machineName,
      `js.${component.machineName}`,
    ]);
    const componentRecord = component as Component & {
      id?: string;
      componentId?: string;
    };
    if (componentRecord.id) {
      candidates.add(componentRecord.id);
    }
    if (componentRecord.componentId) {
      candidates.add(componentRecord.componentId);
    }
    if (candidates.has(componentId) || candidates.has(normalized)) {
      return [key, component];
    }
  }
  return [componentId, undefined];
}

async function resolveContentEntityReferencePreview(
  apiService: ApiService,
  componentKey: string,
  component: Component,
): Promise<{
  component: string;
  props: Record<
    string,
    {
      entityTypeId?: string;
      bundle?: string;
      entityId?: string;
      entityLabel?: string;
      entityFields: string[];
      value?: unknown;
      error?: string;
    }
  >;
}> {
  const entityFields = component.dataDependencies?.entityFields ?? {};
  const props: Record<
    string,
    {
      entityTypeId?: string;
      bundle?: string;
      entityId?: string;
      entityLabel?: string;
      entityFields: string[];
      value?: unknown;
      error?: string;
    }
  > = {};

  for (const [propName, expressions] of Object.entries(entityFields)) {
    if (
      !Array.isArray(expressions) ||
      expressions.length === 0 ||
      !expressions.every((expression) => typeof expression === 'string')
    ) {
      continue;
    }
    const target = getContentEntityReferencePropTarget(
      component.props?.[propName],
    );
    if (!target) {
      props[propName] = {
        entityFields: expressions,
        error:
          'Missing x-allowed-entity-type-id or x-allowed-bundle on the content-entity-reference prop metadata.',
      };
      continue;
    }
    const suggestions = await apiService.fetchPreviewEntitySuggestions(
      target.entityTypeId,
      target.bundle,
    );
    const previewEntity = suggestions[0];
    props[propName] = {
      entityTypeId: target.entityTypeId,
      bundle: target.bundle,
      entityFields: expressions,
    };
    if (!previewEntity) {
      props[propName].error = 'No preview entity suggestions were found.';
      continue;
    }
    props[propName].entityId = previewEntity.id;
    props[propName].entityLabel = previewEntity.label;
    try {
      const resolved = await apiService.previewContentEntityReferenceFields(
        target.entityTypeId,
        previewEntity.id,
        { [propName]: expressions },
      );
      props[propName].value = resolved[propName];
    } catch (error) {
      props[propName].error =
        error instanceof Error ? error.message : String(error);
    }
  }

  return {
    component: component.machineName || component.name || componentKey,
    props,
  };
}

function getContentEntityReferencePropTarget(
  prop: unknown,
): { entityTypeId: string; bundle: string } | null {
  if (!prop || typeof prop !== 'object' || Array.isArray(prop)) {
    return null;
  }
  const record = prop as Record<string, unknown>;
  const entityTypeId = record['x-allowed-entity-type-id'];
  const bundle = record['x-allowed-bundle'];
  if (typeof entityTypeId !== 'string' || typeof bundle !== 'string') {
    return null;
  }
  return { entityTypeId, bundle };
}
