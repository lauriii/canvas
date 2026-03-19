import React, {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  SortableContext,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';

import { useAppSelector } from '@/app/hooks';
import { toPropName } from '@/components/form/formUtil';
import { syncPropSourcesToResolvedValues } from '@/components/form/InputBehaviorsComponentPropsForm';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import { selectEditorFrameContext } from '@/features/ui/uiSlice';
import useInputUIData from '@/hooks/useInputUIData';
import { useUpdateComponentMutation } from '@/services/preview';
import { isAjaxing } from '@/utils/isAjaxing';

import type { ReactNode } from 'react';
import type { DragEndEvent } from '@dnd-kit/core';
import type {
  EvaluatedComponentModel,
  Sources,
} from '@/features/layout/layoutModelSlice';
import type { EditorFrameContext } from '@/features/ui/uiSlice';

/**
 * Reads all `target_id` inputs inside a wrapper element and returns their
 * current values together with the resolved prop name.
 */
function collectTargetIds(
  wrapper: HTMLElement,
  selectedComponent: string,
): { propName: string; targetIds: string[] } {
  const targetIds: string[] = [];
  let propName = '';
  wrapper.querySelectorAll('[name*="target_id"]').forEach((el) => {
    const formEl = el as HTMLFormElement;
    propName = formEl.name && toPropName(formEl.name, selectedComponent);
    targetIds.push(formEl.getAttribute('value') || '');
  });
  return { propName, targetIds };
}

/**
 * Builds the `patchComponent` payload for a drag-reorder operation.
 * Updates both `source[propName].value` and `resolved[propName]` to the new
 * target-id order.
 */
function buildReorderPatch(
  model: EvaluatedComponentModel,
  propName: string,
  targetIds: string[],
  editorFrameContext: EditorFrameContext,
  selectedComponent: string,
  selectedComponentType: string,
  version: string,
) {
  return {
    type: editorFrameContext,
    componentInstanceUuid: selectedComponent,
    componentType: `${selectedComponentType}@${version}`,
    model: {
      source: {
        ...model.source,
        [propName]: {
          ...model.source[propName],
          value: targetIds,
        },
      },
      resolved: {
        ...model.resolved,
        [propName]: targetIds,
      },
    },
  };
}

/**
 * Builds the `patchComponent` payload for a remove operation.
 * Clears the prop value (or resets to required defaults) and syncs sources to
 * resolved values.
 */
function buildRemovePatch(
  model: EvaluatedComponentModel,
  propName: string,
  propSourceData: any,
  componentData: any,
  editorFrameContext: EditorFrameContext,
  selectedComponent: string,
  selectedComponentType: string,
  version: string,
) {
  const isRequired = !!propSourceData?.required;

  // Deep-clone mutable copies of resolved/source so we don't mutate Redux state.
  const resolved = JSON.parse(JSON.stringify(model.resolved));
  const source: Sources = JSON.parse(JSON.stringify(model.source));

  resolved[propName] = isRequired ? propSourceData.default_values.resolved : [];
  if (!isRequired) {
    source[propName].value = [];
  }

  return {
    type: editorFrameContext,
    componentInstanceUuid: selectedComponent,
    componentType: `${selectedComponentType}@${version}`,
    model: {
      source: syncPropSourcesToResolvedValues(source, componentData, resolved),
      resolved,
    },
  };
}
interface DrupalMediaListContainerProps {
  children: ReactNode;
  onSort?: (newOrder: string[]) => void;
}

const DrupalMediaListContainer = ({
  onSort,
  children,
}: DrupalMediaListContainerProps) => {
  // Store elements with injected IDs and track their order
  const [orderedElements, setOrderedElements] = useState<React.ReactElement[]>(
    [],
  );
  const [itemIds, setItemIds] = useState<string[]>([]);
  const elementIdMapRef = useRef<Map<React.ReactElement, string>>(new Map());
  const wrapperRef = useRef<HTMLDivElement>(null);
  const inputUIData = useInputUIData();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const {
    selectedComponent,
    selectedComponentType,
    version,
    model,
    components,
  } = inputUIData || {};
  const [patchComponent] = useUpdateComponentMutation({
    fixedCacheKey: selectedComponent,
  });
  const selectedModel = useMemo(
    () => model?.[selectedComponent] || null,
    [model, selectedComponent],
  );
  // Initialize state with filtered children, cloning them with injected IDs
  useEffect(() => {
    const childrenArray = React.Children.toArray(children);
    const reactElements = childrenArray.filter(
      (child): child is React.ReactElement => React.isValidElement(child),
    );

    if (reactElements.length > 0 && orderedElements.length === 0) {
      // Clone elements and inject auto-generated IDs
      const elementsWithIds = reactElements.map((el, index) => {
        const autoId = `sortable-item-${index}-${Date.now()}`;
        elementIdMapRef.current.set(el, autoId);
        // Clone the element and inject the ID as a prop
        return React.cloneElement(el, { id: autoId, itemIndex: index } as any);
      });

      const ids = elementsWithIds.map((el) => el.props.id);

      setOrderedElements(elementsWithIds);
      setItemIds(ids);
    }
  }, [children, orderedElements.length]);

  // Final callback for when drag ends
  const updateComponentPropOrder = useCallback(
    (newOrder: string[]) => {
      onSort?.(newOrder);
      if (!wrapperRef.current) {
        return;
      }

      const isComponentForm = wrapperRef.current.closest(
        '[data-form-id="component_instance_form"]',
      );

      // In the component form, the order within the model is not determined by
      // weight, but literally the order they appear in the model. Below is
      // logic that checks the current order of target_ids in the DOM and
      // patches the model to match that new sequence.
      if (
        isComponentForm &&
        selectedModel &&
        isEvaluatedComponentModel(selectedModel)
      ) {
        setTimeout(() => {
          if (!wrapperRef.current) {
            return;
          }
          const { propName, targetIds } = collectTargetIds(
            wrapperRef.current,
            selectedComponent,
          );
          if (propName) {
            patchComponent(
              buildReorderPatch(
                selectedModel,
                propName,
                targetIds,
                editorFrameContext,
                selectedComponent,
                selectedComponentType,
                version,
              ),
            );
          }
        });
      }

      // Sync DOM weight fields to their new visual order.
      wrapperRef.current
        .querySelectorAll('[data-canvas-media-weight]')
        .forEach((el, index) => {
          el.setAttribute('value', index.toString());
        });
    },
    [
      editorFrameContext,
      onSort,
      patchComponent,
      selectedComponent,
      selectedComponentType,
      selectedModel,
      version,
    ],
  );

  // Handle sort by reordering the elements in state
  const handleSort = useCallback(
    (newOrder: string[]) => {
      setOrderedElements((prev) => {
        // Create a map of id -> element
        const elementMap = new Map<string, React.ReactElement>();
        prev.forEach((el) => {
          const id = el.props.id;
          if (id) {
            elementMap.set(id, el);
          }
        });

        // Reorder based on newOrder
        const reordered = newOrder
          .map((id) => elementMap.get(id))
          .filter((el): el is React.ReactElement => el !== undefined);
        updateComponentPropOrder(newOrder);
        return reordered;
      });

      // Update itemIds to match the new order
      setItemIds(newOrder);
    },
    [updateComponentPropOrder],
  );

  useEffect(() => {
    // When there is only one item, we need to provide special handling of that
    // item's remove button. This is because when the final item is removed,
    // the element tracking the value change is removed from the dom and thus
    // fails to communicate the change to Redux.
    const wrapper = wrapperRef.current;
    if (!wrapper) {
      return;
    }
    const isComponentForm = wrapper.closest(
      '[data-form-id="component_instance_form"]',
    );

    const handleRemove = () => {
      if (
        !wrapper ||
        !selectedModel ||
        !isEvaluatedComponentModel(selectedModel)
      ) {
        return;
      }
      const targetInput = wrapper.querySelector(
        '[name*="target_id"]',
      ) as HTMLInputElement | null;
      if (!targetInput) {
        return;
      }
      const propName = toPropName(targetInput.name, selectedComponent);
      const componentData = components?.[selectedComponentType];
      const propSourceData = (componentData as any)?.propSources?.[propName];
      if (!componentData || !propSourceData) {
        return;
      }
      const removePatch = buildRemovePatch(
        selectedModel,
        propName,
        propSourceData,
        componentData,
        editorFrameContext,
        selectedComponent,
        selectedComponentType,
        version,
      );

      setTimeout(() => {
        const interval = setInterval(() => {
          if (!isAjaxing()) {
            patchComponent(removePatch);
            clearInterval(interval);
          }
        });
      });
    };

    const handleKeyDown = (e: Event) => {
      const keyEvent = e as KeyboardEvent;
      if (keyEvent.key === 'Enter' || keyEvent.key === ' ') {
        keyEvent.preventDefault();
        handleRemove();
      }
    };

    if (itemIds.length === 1 && !!isComponentForm) {
      const removeButton = wrapper.querySelector(
        '[data-canvas-media-remove-button]',
      );
      if (removeButton) {
        removeButton.addEventListener('mousedown', handleRemove, {
          capture: true,
        });
        removeButton.addEventListener('keydown', handleKeyDown);
        return () => {
          removeButton.removeEventListener('mousedown', handleRemove, {
            capture: true,
          });
          removeButton.removeEventListener('keydown', handleKeyDown);
        };
      }
    }
  }, [
    itemIds,
    components,
    selectedComponent,
    selectedComponentType,
    selectedModel,
    version,
    editorFrameContext,
    patchComponent,
  ]);

  // Create sensors scoped to this sortable list - both pointer and keyboard
  const pointerSensor = useSensor(PointerSensor, {
    activationConstraint: {
      distance: 3,
    },
  });
  const keyboardSensor = useSensor(KeyboardSensor, {
    coordinateGetter: sortableKeyboardCoordinates,
  });
  const sensors = useSensors(pointerSensor, keyboardSensor);

  const handleLocalDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;

    if (!over || active.id === over.id) return;

    const oldIndex = itemIds.indexOf(active.id as string);
    const newIndex = itemIds.indexOf(over.id as string);

    if (oldIndex === -1 || newIndex === -1) return;

    const newOrder = [...itemIds];
    const [removed] = newOrder.splice(oldIndex, 1);
    newOrder.splice(newIndex, 0, removed);
    handleSort(newOrder);
  };

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragEnd={handleLocalDragEnd}
    >
      <SortableContext items={itemIds} strategy={verticalListSortingStrategy}>
        <div
          ref={wrapperRef}
          data-num-items={itemIds.length}
          data-sort-wrapper
          style={{ display: 'contents' }}
        >
          {orderedElements}
        </div>
      </SortableContext>
    </DndContext>
  );
};

export default DrupalMediaListContainer;
