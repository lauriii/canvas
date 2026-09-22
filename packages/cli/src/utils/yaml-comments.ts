/**
 * @file
 * Utilities for adding comments to YAML output, specifically for component
 * metadata to document color folder restrictions.
 */

import * as yaml from 'yaml';
import { COLOR_PROP_SCHEMA_REF } from '@drupal-canvas/discovery';

import type { ColorFolderEntry } from '../types/Component';
import type { Metadata } from '../types/Metadata';

/**
 * Serializes metadata to YAML with folder-aware comments on color props.
 *
 * For each prop with `x-canvas-color-folders`, this adds:
 * 1. Inline comments on each UUID showing the folder name
 * 2. A `commentBefore` on the `x-canvas-color-folders` key with a summary
 * 3. A document footer (if any restricted props exist) listing all color folders
 *
 * @param metadata - The component metadata to serialize
 * @param colorFolders - All color folders from the site
 * @returns YAML string with comments
 */
export function dumpMetadataWithComments(
  metadata: Metadata,
  colorFolders: ColorFolderEntry[],
): string {
  // Build helper maps
  const folderNameById = new Map<string, string>();
  for (const folder of colorFolders) {
    folderNameById.set(folder.id, folder.name);
  }

  // Serialize to YAML then parse as Document for mutation
  // Use singleQuote: true to match js-yaml output style for hex colors like '#abc123'.
  const yamlStr = yaml.stringify(metadata, { singleQuote: true });
  const doc = yaml.parseDocument(yamlStr);

  let hasRestrictedProps = false;

  // Walk props.properties to find color props with folder restrictions
  const props = doc.getIn(['props', 'properties']) as yaml.YAMLMap | undefined;
  if (props && 'items' in props) {
    for (const propPair of props.items as yaml.Pair[]) {
      const propMap = propPair.value as yaml.YAMLMap | undefined;
      if (!propMap || !('items' in propMap)) continue;

      const items = propMap.items as yaml.Pair[];

      // Check if this is a color prop
      const refPair = items.find(
        (p) => (p.key as yaml.Scalar)?.value === '$ref',
      );
      const isColorProp =
        (refPair?.value as yaml.Scalar)?.value === COLOR_PROP_SCHEMA_REF;
      if (!isColorProp) continue;

      // Find x-canvas-color-folders
      const foldersPair = items.find(
        (p) => (p.key as yaml.Scalar)?.value === 'x-canvas-color-folders',
      );
      if (!foldersPair) continue;

      hasRestrictedProps = true;

      // Find x-canvas-color-picker to determine mode
      const pickerPair = items.find(
        (p) => (p.key as yaml.Scalar)?.value === 'x-canvas-color-picker',
      );
      const pickerMode =
        (pickerPair?.value as yaml.Scalar)?.value ?? 'kit-only';

      // Get the folder UUIDs sequence
      const foldersSeq = foldersPair.value as yaml.YAMLSeq | undefined;
      if (!foldersSeq || !('items' in foldersSeq)) continue;

      // Add inline comments on each UUID
      const resolvedNames: string[] = [];
      for (const item of foldersSeq.items as yaml.Scalar[]) {
        const uuid = item.value as string;
        const name = folderNameById.get(uuid);
        if (name) {
          item.comment = ` ${name}`;
          resolvedNames.push(name);
        }
      }

      // Build the commentBefore for the key
      const freeNote =
        pickerMode === 'kit-and-free'
          ? ' Free-pick is also allowed in the UI.'
          : '';
      const restrictionSummary =
        resolvedNames.length > 0
          ? resolvedNames.join(', ')
          : 'selected folders';

      const keyNode = foldersPair.key as yaml.Scalar;
      keyNode.commentBefore = ` Any color value is accepted on push. The UI picker is restricted to: ${restrictionSummary}.${freeNote}\n To add a folder, see available color folders at the bottom of this file.`;
    }
  }

  // Add document footer if any restricted props exist
  if (hasRestrictedProps && colorFolders.length > 0) {
    const folderLines = colorFolders
      .map((f) => `   ${f.id}  ${f.name}`)
      .join('\n');
    doc.comment = ` Available color folders (append a UUID to x-canvas-color-folders on any color prop):\n${folderLines}`;
  }

  return doc.toString({ singleQuote: true });
}
