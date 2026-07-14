import path from 'node:path';
import {
  discoverCanvasProject,
  JS_EXTENSIONS,
  loadComponentsMetadata,
  resolveCanvasConfig,
} from '@drupal-canvas/discovery';

import { COMPONENT_METADATA_PAYLOAD_VERSION } from './payload-version';

/**
 * Entry extensions the metadata registry accepts. Wider than the Canvas
 * build pipeline's JavaScript set: a headless app renders its own
 * components, so a framework single-file component (.astro, .vue, .svelte)
 * is as much proof of an implementation as an index.tsx — Drupal registers
 * metadata either way and never compiles the entry.
 */
export const METADATA_ENTRY_EXTENSIONS = [
  ...JS_EXTENSIONS,
  '.astro',
  '.vue',
  '.svelte',
] as const;

export { COMPONENT_METADATA_PAYLOAD_VERSION } from './payload-version';

export interface ComponentMetadataWarning {
  code: string;
  message: string;
  /** Project-root-relative path of the file the warning is about. */
  path?: string;
}

/**
 * One component, carrying the same metadata fields the Canvas CLI's push
 * payload carries (see the CLI's createComponentPayload()), minus the
 * source and compiled code fields: in the headless integration the app
 * renders its own components, so Drupal registers metadata only.
 *
 * The types are deliberately structural rather than re-exports of
 * @drupal-canvas/discovery's — the discovery types reference the Canvas UI
 * package's canonical component types, which external consumers of this
 * package cannot resolve.
 */
export interface ComponentMetadataEntry {
  machineName: string;
  name: string;
  status: boolean;
  /** Names of required props; requiredness lives outside the prop map. */
  required: string[];
  /**
   * JSON-Schema-shaped prop definitions, keyed by prop name — the exact
   * `props` map a full component create/update on the Drupal side takes.
   */
  props: Record<string, Record<string, unknown>>;
  slots: Record<
    string,
    { title: string; description?: string; examples?: string[] }
  >;
  /**
   * The component's directory relative to the component root. Diagnostic
   * context for duplicate-machine-name conflicts; no server filesystem
   * layout beyond the component tree leaks.
   */
  relativeDirectory: string;
}

export interface ComponentMetadataPayload {
  version: typeof COMPONENT_METADATA_PAYLOAD_VERSION;
  /**
   * The complete component set of the codebase. Completeness is the
   * deletion signal: a component registered earlier but absent here no
   * longer exists in the codebase.
   */
  components: ComponentMetadataEntry[];
  warnings: ComponentMetadataWarning[];
}

export interface BuildComponentMetadataOptions {
  /**
   * The app project root — the directory holding canvas.config.json, from
   * which the component directory is resolved. Default: process.cwd().
   */
  projectRoot?: string;
}

/**
 * Builds the component metadata payload for a codebase, reusing the
 * @drupal-canvas/discovery pipeline the Canvas CLI and Workbench run:
 * resolve canvas.config.json, discover component.yml files under the
 * configured component directory, and parse their metadata.
 *
 * Components without an entry file (see METADATA_ENTRY_EXTENSIONS) are
 * excluded by discovery itself (with a warning); duplicate machine names
 * are all included, each flagged by a warning — conflict policy belongs to
 * the reader. Malformed component metadata rejects, so a broken registry
 * never ships silently.
 */
export async function buildComponentMetadataPayload(
  options: BuildComponentMetadataOptions = {},
): Promise<ComponentMetadataPayload> {
  const projectRoot = path.resolve(options.projectRoot ?? process.cwd());
  const warnings: ComponentMetadataWarning[] = [];

  const relativize = (warningPath: string | undefined): string | undefined =>
    warningPath === undefined
      ? undefined
      : path.isAbsolute(warningPath)
        ? path.relative(projectRoot, warningPath)
        : warningPath;

  const config = resolveCanvasConfig({
    hostRoot: projectRoot,
    onWarning: (warning) => {
      warnings.push({ code: warning.code, message: warning.message });
    },
  });

  const componentRoot = path.resolve(projectRoot, config.componentDir);
  const pagesRoot = path.resolve(projectRoot, config.pagesDir);

  const result = await discoverCanvasProject({
    componentRoot,
    pagesRoot,
    projectRoot,
    entryExtensions: METADATA_ENTRY_EXTENSIONS,
  });
  const metadata = await loadComponentsMetadata(result);

  // result.warnings already includes the duplicate-machine-name pass
  // (discoverCanvasProject() runs findDuplicateMachineNames() itself).
  for (const warning of result.warnings) {
    warnings.push({
      code: warning.code,
      message: warning.message,
      path: relativize(warning.path),
    });
  }

  // loadComponentsMetadata() returns entries positionally parallel to the
  // discovered components.
  const components = metadata.map((componentMetadata, index) => {
    const entry: ComponentMetadataEntry = {
      machineName: componentMetadata.machineName,
      name: componentMetadata.name,
      status: componentMetadata.status,
      required: componentMetadata.required,
      // Flatten discovery's props.properties nesting (a component.yml file
      // artifact): the flat map is what component create/update on the
      // Drupal side takes.
      props: (componentMetadata.props.properties ??
        {}) as unknown as ComponentMetadataEntry['props'],
      slots: componentMetadata.slots as ComponentMetadataEntry['slots'],
      // The arrays are positionally parallel; the fallback only satisfies
      // consumers compiling with noUncheckedIndexedAccess.
      relativeDirectory: result.components[index]?.relativeDirectory ?? '',
    };
    return entry;
  });

  return {
    version: COMPONENT_METADATA_PAYLOAD_VERSION,
    components,
    warnings,
  };
}
