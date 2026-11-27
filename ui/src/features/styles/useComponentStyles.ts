import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectLayout,
  selectTranslations,
  setComponentStyles,
} from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import { notifyStyleChange } from '@/features/styles/styleDecorations';
import {
  acceptedKeys,
  degradedEntries,
  PART_SEPARATOR,
} from '@/features/styles/styleUtils';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { useGetStylesQuery } from '@/services/styles';
import { getLanguages } from '@/utils/drupal-globals';

import type { ComponentNode } from '@/features/layout/layoutModelSlice';
import type {
  StyleDefinition,
  StyleLimit,
  StylePart,
  StyleSelection,
} from '@/types/Styles';

/**
 * The selection keys a component instance accepts, from the component list:
 * its own style IDs, then `part.style_id` for each named part.
 */
export function useAcceptedStyles(): (node: ComponentNode) => string[] {
  const { data: components } = useGetComponentsQuery();
  return useCallback(
    (node: ComponentNode) => {
      const [componentId] = node.type.split('@');
      const component = components?.[componentId];
      return acceptedKeys(component?.styles ?? [], component?.styleParts);
    },
    [components],
  );
}

const NO_PARTS: Record<string, StylePart> = {};

/** A component instance's named parts, from the component list. */
export function useStyleParts(): (
  node: ComponentNode,
) => Record<string, StylePart> {
  const { data: components } = useGetComponentsQuery();
  return useCallback(
    (node: ComponentNode) => {
      const [componentId] = node.type.split('@');
      return components?.[componentId]?.styleParts ?? NO_PARTS;
    },
    [components],
  );
}

const EMPTY: Record<string, string> = {};

/**
 * Each component's limits, keyed by selection key: the root's by style ID,
 * each part's by `part.style_id`.
 */
export function useStyleLimits(): (
  node: ComponentNode,
) => Record<string, StyleLimit> {
  const { data: components } = useGetComponentsQuery();
  return useCallback(
    (node: ComponentNode) => {
      const [componentId] = node.type.split('@');
      const component = components?.[componentId];
      if (!component?.styleLimits && !component?.styleParts) {
        return NO_LIMITS;
      }
      return {
        ...component.styleLimits,
        ...Object.fromEntries(
          Object.entries(component.styleParts ?? {}).flatMap(
            ([part, { limits }]) =>
              Object.entries(limits ?? {}).map(([styleId, limit]) => [
                `${part}${PART_SEPARATOR}${styleId}`,
                limit,
              ]),
          ),
        ),
      };
    },
    [components],
  );
}

const NO_LIMITS: Record<string, StyleLimit> = {};

/** Available style definitions, keyed by style ID, in join order. */
export function useStyleDefinitions(): {
  active: boolean;
  definitions: Record<string, StyleDefinition>;
  hiddenStyles: Record<string, string>;
  canEdit: boolean;
  canUseCustom: boolean;
  isLoading: boolean;
} {
  const { data, isLoading } = useGetStylesQuery();
  const definitions = useMemo(
    () => Object.fromEntries((data?.styles ?? []).map((d) => [d.id, d])),
    [data],
  );
  return {
    active: Boolean(data?.active),
    definitions,
    hiddenStyles: data?.hiddenStyles ?? EMPTY,
    canEdit: Boolean(data?.permissions.use),
    canUseCustom: Boolean(data?.permissions.custom),
    isLoading,
  };
}

/**
 * Styles are shared across translations: in a non-default translation they
 * are read-only.
 *
 * @return The default language's name when editing another translation.
 */
export function useStyleTranslationLock(): string | null {
  const [searchParams] = useSearchParams();
  const translations = useAppSelector(selectTranslations);
  const language = searchParams.get('language');
  const defaultLangcode = translations?.available?.[0];
  if (!language || !defaultLangcode || language === defaultLangcode) {
    return null;
  }
  const languages = getLanguages();
  return (
    languages.find((candidate) => candidate.id === defaultLangcode)?.name ??
    defaultLangcode
  );
}

/** Writes styles: one undo step, rapid changes to one style merged. */
export function useSetStyles() {
  const dispatch = useAppDispatch();
  return useCallback(
    (
      changes: Array<{ uuid: string; styles: StyleSelection }>,
      mergeKey?: string,
    ) => {
      dispatch(setComponentStyles({ changes, mergeKey }));
      changes.forEach(({ uuid, styles }) => notifyStyleChange(uuid, styles));
    },
    [dispatch],
  );
}

/** Everything the Style tab needs for one instance. */
export function useComponentStyles(uuid: string | undefined) {
  const layout = useAppSelector(selectLayout);
  const node = uuid ? findComponentByUuid(layout, uuid) : null;
  const component =
    node && node.nodeType === 'component' ? (node as ComponentNode) : null;
  const {
    active,
    definitions,
    hiddenStyles,
    canEdit,
    canUseCustom,
    isLoading,
  } = useStyleDefinitions();
  const acceptedFor = useAcceptedStyles();
  const limitsFor = useStyleLimits();
  const partsFor = useStyleParts();
  const accepted = component ? acceptedFor(component) : [];
  const parts = component ? partsFor(component) : NO_PARTS;
  const limits = component ? limitsFor(component) : NO_LIMITS;
  const selection = component?.styles ?? {};
  const degraded = degradedEntries(
    selection,
    definitions,
    accepted,
    hiddenStyles,
    limits,
  );
  return {
    active,
    isLoading,
    component,
    definitions,
    hiddenStyles,
    limits,
    canUseCustom,
    accepted,
    parts,
    selection,
    degraded,
    canEdit,
  };
}
