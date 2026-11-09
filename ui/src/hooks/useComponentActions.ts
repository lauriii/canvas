import { useCallback } from 'react';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  deleteNode,
  duplicateNode,
  shiftNode,
} from '@/features/layout/layoutModelSlice';
import { componentSubtreeMatchesType } from '@/features/layout/layoutUtils';
import { setDialogOpen } from '@/features/ui/dialogSlice';
import {
  selectSelectedComponentUuid,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useCopyPasteComponents from '@/hooks/useCopyPasteComponents';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { isMarkerComponentType } from '@/services/pageVariants';

import type React from 'react';
import type { ComponentNode } from '@/features/layout/layoutModelSlice';

/**
 * The actions on a component in the layout.
 *
 * Shared by the component's context menu and its toolbar in the preview.
 */
export default function useComponentActions(component: ComponentNode) {
  const dispatch = useAppDispatch();
  const { data: components } = useGetComponentsQuery();
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const { setSelectedComponent, unsetSelectedComponent } =
    useComponentSelection();
  const componentUuid = component.uuid;
  const { copySelectedComponent, pasteAfterSelectedComponent } =
    useCopyPasteComponents();
  const { navigateToCodeEditor } = useEditorNavigation();

  // Check if this is a code component
  const [componentType] = (component.type || '').split('@');
  const isCodeComponent =
    !!componentType &&
    !!components &&
    components[componentType]?.source === 'Code component';
  const isExternal = Boolean(
    componentType &&
    components?.[componentType] &&
    'type' in components[componentType] &&
    components[componentType].type === 'external',
  );
  // Markers (e.g. a page variant's "Page content" marker) are intrinsic: they
  // can only be repositioned, so every other mutating operation is hidden.
  // The same applies to any ancestor whose slots contain a marker: deleting or
  // duplicating it would delete or duplicate the marker with it.
  const isMarker = componentSubtreeMatchesType(
    component,
    isMarkerComponentType,
  );

  const deleteComponent = useCallback(
    (ev?: React.SyntheticEvent) => {
      ev?.stopPropagation();
      if (componentUuid) {
        dispatch(deleteNode(componentUuid));
        unsetSelectedComponent();
      }
      dispatch(unsetHoveredComponent());
    },
    [componentUuid, dispatch, unsetSelectedComponent],
  );

  const duplicateComponent = useCallback(
    (ev?: React.SyntheticEvent) => {
      ev?.stopPropagation();
      dispatch(unsetHoveredComponent());

      if (componentUuid) {
        dispatch(duplicateNode({ uuid: componentUuid }));
      }
    },
    [dispatch, componentUuid],
  );

  const copyComponent = useCallback(
    (ev?: React.SyntheticEvent) => {
      ev?.stopPropagation();
      dispatch(unsetHoveredComponent());

      if (componentUuid) {
        copySelectedComponent(componentUuid);
      }
    },
    [dispatch, componentUuid, copySelectedComponent],
  );

  const pasteAfterComponent = useCallback(
    (ev?: React.SyntheticEvent) => {
      ev?.stopPropagation();
      dispatch(unsetHoveredComponent());

      if (componentUuid) {
        pasteAfterSelectedComponent(componentUuid);
      }
    },
    [dispatch, componentUuid, pasteAfterSelectedComponent],
  );

  const shift = useCallback(
    (direction: 'up' | 'down', ev?: React.SyntheticEvent) => {
      ev?.stopPropagation();
      dispatch(unsetHoveredComponent());

      dispatch(shiftNode({ uuid: componentUuid, direction }));
    },
    [dispatch, componentUuid],
  );
  const moveUp = useCallback(
    (ev?: React.SyntheticEvent) => shift('up', ev),
    [shift],
  );
  const moveDown = useCallback(
    (ev?: React.SyntheticEvent) => shift('down', ev),
    [shift],
  );

  const createPattern = useCallback(
    (e?: React.SyntheticEvent) => {
      e?.stopPropagation();
      if (componentUuid !== selectedComponent) {
        setSelectedComponent(componentUuid);
      }
      dispatch(setDialogOpen('saveAsPattern'));
    },
    [componentUuid, dispatch, selectedComponent, setSelectedComponent],
  );

  const editCode = useCallback(
    (ev?: React.SyntheticEvent) => {
      ev?.stopPropagation();
      if (component.type && component.type.startsWith('js.')) {
        const machineNameAndVersion = component.type.substring(3);
        const [machineName] = machineNameAndVersion.split('@');
        navigateToCodeEditor(machineName);
      }
    },
    [component.type, navigateToCodeEditor],
  );

  return {
    components,
    isCodeComponent,
    isExternal,
    isMarker,
    deleteComponent,
    duplicateComponent,
    copyComponent,
    pasteAfterComponent,
    moveUp,
    moveDown,
    createPattern,
    editCode,
  };
}
