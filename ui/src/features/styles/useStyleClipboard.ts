import { useCallback } from 'react';
import { useHotkeys } from 'react-hotkeys-hook';
import { toast } from 'sonner';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import { describeSkipped, pasteStyles } from '@/features/styles/styleUtils';
import {
  useAcceptedStyles,
  useSetStyles,
  useStyleDefinitions,
  useStyleLimits,
  useStyleTranslationLock,
} from '@/features/styles/useComponentStyles';
import {
  selectSelectedComponentUuid,
  selectSelection,
} from '@/features/ui/uiSlice';

import type { ComponentNode } from '@/features/layout/layoutModelSlice';
import type { StyleSelection } from '@/types/Styles';

/** The copied styles, shared by every instance menu for the session. */
let copied: StyleSelection | null = null;

const NOTICE_STYLE = {
  width: 'auto',
  maxWidth: '360px',
  height: 'auto',
  whiteSpace: 'normal',
  textAlign: 'center',
} as const;

/** Announces a notice visually and to assistive technology. */
function announce(message: string): void {
  toast(message, { style: NOTICE_STYLE });
}

/**
 * Copy styles, Paste styles (replace), Paste and merge, and Remove all
 * styles. Paste applies to every selected instance and keeps only accepted
 * styles. Each action is one undo step.
 */
export function useStyleClipboard() {
  const layout = useAppSelector(selectLayout);
  const selection = useAppSelector(selectSelection);
  const selectedUuid = useAppSelector(selectSelectedComponentUuid);
  const { active, definitions, canEdit } = useStyleDefinitions();
  const acceptedFor = useAcceptedStyles();
  const limitsFor = useStyleLimits();
  const setStyles = useSetStyles();
  const lockedToLanguage = useStyleTranslationLock();
  const canChange = active && canEdit && lockedToLanguage === null;

  const targetsFor = useCallback(
    (uuid?: string): ComponentNode[] => {
      const uuids =
        selection.items.length > 1
          ? selection.items
          : [uuid ?? selectedUuid].filter(Boolean);
      return uuids
        .map((id) => findComponentByUuid(layout, id as string))
        .filter(
          (node): node is ComponentNode => node?.nodeType === 'component',
        );
    },
    [layout, selection.items, selectedUuid],
  );

  const copyStyles = useCallback(
    (uuid?: string) => {
      const [source] = targetsFor(uuid);
      if (!source) return;
      copied = { ...(source.styles ?? {}) };
      const count = Object.keys(copied).length;
      announce(
        count === 0
          ? 'Copied: no styles set.'
          : `Copied ${count} ${count === 1 ? 'style' : 'styles'}.`,
      );
    },
    [targetsFor],
  );

  const paste = useCallback(
    (mode: 'replace' | 'merge', uuid?: string) => {
      if (!copied || !canChange) return;
      const targets = targetsFor(uuid);
      if (targets.length === 0) return;
      const { changes, skipped } = pasteStyles(
        copied,
        targets.map((node) => ({
          uuid: node.uuid,
          styles: node.styles,
          accepted: acceptedFor(node),
          limits: limitsFor(node),
        })),
        mode,
        definitions,
      );
      setStyles(changes);
      const skippedNotice = describeSkipped(skipped, definitions, (n) =>
        n === 1 ? 'component' : 'components',
      );
      announce(
        skippedNotice ??
          `Pasted styles on ${targets.length} ${targets.length === 1 ? 'component' : 'components'}.`,
      );
    },
    [acceptedFor, limitsFor, canChange, definitions, setStyles, targetsFor],
  );

  const removeAllStyles = useCallback(
    (uuid?: string) => {
      if (!canChange) return;
      const targets = targetsFor(uuid);
      setStyles(targets.map((node) => ({ uuid: node.uuid, styles: {} })));
      announce('Removed all styles.');
    },
    [canChange, setStyles, targetsFor],
  );

  return {
    active,
    canChange,
    hasCopied: copied !== null,
    copyStyles,
    pasteStyles: (uuid?: string) => paste('replace', uuid),
    pasteAndMergeStyles: (uuid?: string) => paste('merge', uuid),
    removeAllStyles,
  };
}

/** Cmd+Option+C copies styles; Cmd+Option+V pastes them. */
export function useStyleClipboardShortcuts(): void {
  const { active, copyStyles, pasteStyles: pasteReplace } = useStyleClipboard();
  useHotkeys(
    'mod+alt+c',
    () => active && copyStyles(),
    { preventDefault: true },
    [active, copyStyles],
  );
  useHotkeys(
    'mod+alt+v',
    () => active && pasteReplace(),
    { preventDefault: true },
    [active, pasteReplace],
  );
}
