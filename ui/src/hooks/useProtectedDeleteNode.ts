import { useCallback } from 'react';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { findExposedSlotsInSubtree } from '@/features/layout/exposedSlots';
import {
  deleteNode,
  selectExposedSlots,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  getDisplayNameForNode,
} from '@/features/layout/layoutUtils';
import { setDialogWithDataOpen } from '@/features/ui/dialogSlice';
import {
  EditorFrameContext,
  selectEditorFrameContext,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type { ComponentsList } from '@/types/Component';

/**
 * Deletes a component through the template editor's exposed-slot protection.
 *
 * Every deletion entry point (context menus, layers menus, keyboard) must run
 * the same check: in the template editor, deleting a component that hosts an
 * exposed slot (directly or via a descendant) warns first and detaches the
 * slot definitions via the DeleteComponentWithExposedSlots dialog instead of
 * deleting silently.
 *
 * @returns A callback that deletes the component, or opens the confirmation
 *   dialog when exposed slots are at stake. The callback returns TRUE when
 *   the dialog was opened (the deletion is deferred to it).
 */
export default function useProtectedDeleteNode() {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const exposedSlots = useAppSelector(selectExposedSlots);
  const isTemplateContext =
    useAppSelector(selectEditorFrameContext) === EditorFrameContext.TEMPLATE;
  const { data: components } = useGetComponentsQuery();

  return useCallback(
    (componentUuid: string): boolean => {
      if (isTemplateContext) {
        const componentNode = findComponentByUuid(layout, componentUuid);
        const hostedSlots = componentNode
          ? findExposedSlotsInSubtree(exposedSlots, componentNode)
          : [];
        if (componentNode && hostedSlots.length > 0) {
          dispatch(
            setDialogWithDataOpen({
              operation: 'deleteComponentWithExposedSlots',
              data: {
                componentUuid,
                componentName: getDisplayNameForNode(
                  componentNode,
                  null,
                  components as ComponentsList,
                ),
                aliases: hostedSlots.map((entry) => entry.alias),
                labels: hostedSlots.map((entry) => entry.definition.label),
              },
            }),
          );
          dispatch(unsetHoveredComponent());
          return true;
        }
      }
      dispatch(deleteNode(componentUuid));
      return false;
    },
    [isTemplateContext, layout, exposedSlots, components, dispatch],
  );
}
