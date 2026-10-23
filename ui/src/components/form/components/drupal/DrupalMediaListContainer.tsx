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

import { useAppDispatch, useAppStore } from '@/app/hooks';
import { toPropName } from '@/components/form/react-hook-form/fields/componentFormData';
import {
  removeFieldValue,
  selectFormValues,
} from '@/features/form/formStateSlice';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import useInputUIData from '@/hooks/useInputUIData';
import { usePatchComponent, usePatchProp } from '@/services/preview';
import { isAjaxing } from '@/utils/isAjaxing';

import type { ReactNode } from 'react';
import type { DragEndEvent } from '@dnd-kit/core';
import type {
  EvaluatedComponentModel,
  Sources,
} from '@/features/layout/layoutModelSlice';

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
 * Puts a media prop's stored items in a new order of media IDs.
 *
 * Items stored as objects keep everything stored with them, such as an image's
 * focal point; IDs without a stored item are stored as is.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::FOCAL_POINT_ANNOTATION
 */
export function reorderStoredItems(
  stored: unknown,
  targetIds: string[],
): unknown[] {
  const items = Array.isArray(stored) ? [...stored] : [];
  return targetIds.map((targetId) => {
    const index = items.findIndex(
      (item) =>
        item !== null &&
        typeof item === 'object' &&
        String((item as { target_id?: unknown }).target_id) === targetId,
    );
    return index === -1 ? targetId : items.splice(index, 1)[0];
  });
}

/**
 * Builds the model payload for a remove operation.
 * Clears the removed prop's value (or resets it to the required default).
 */
function buildRemoveModel(
  model: EvaluatedComponentModel,
  propName: string,
  propSourceData: any,
) {
  const isRequired = !!propSourceData?.required;

  // Deep-clone mutable copies of resolved/source so we don't mutate Redux state.
  const resolved = JSON.parse(JSON.stringify(model.resolved));
  const source: Sources = JSON.parse(JSON.stringify(model.source));

  resolved[propName] = isRequired ? propSourceData.default_values.resolved : [];
  // Rewrite only the removed prop's source value, leaving other props' sources
  // untouched.
  source[propName] = { ...source[propName], value: resolved[propName] };

  return { source, resolved };
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
  const { selectedComponent, selectedComponentType, model, components } =
    inputUIData;
  const patchComponent = usePatchComponent();
  const patchProp = usePatchProp();
  const dispatch = useAppDispatch();
  const store = useAppStore();
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
          if (propName && isEvaluatedComponentModel(selectedModel)) {
            const value = reorderStoredItems(
              selectedModel.source[propName]?.value,
              targetIds,
            );
            patchProp(
              inputUIData,
              propName,
              { ...selectedModel.source[propName], value },
              value,
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
    [onSort, patchProp, inputUIData, selectedComponent, selectedModel],
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
      const removeModel = buildRemoveModel(
        selectedModel,
        propName,
        propSourceData,
      );

      setTimeout(() => {
        const interval = setInterval(() => {
          if (!isAjaxing()) {
            patchComponent(inputUIData, removeModel);
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
    inputUIData,
    components,
    selectedComponent,
    selectedComponentType,
    selectedModel,
    patchComponent,
  ]);

  // Removing an item rebuilds the widget with renumbered items, which leaves
  // the form state of the old last item behind; the next change would then
  // bring it back. Once the rebuild is done, forget the values of inputs that
  // no longer exist.
  useEffect(() => {
    const wrapper = wrapperRef.current;
    if (
      !wrapper ||
      !wrapper.closest('[data-form-id="component_instance_form"]')
    ) {
      return;
    }
    const pruneRemovedItems = () => {
      const formId = 'component_instance_form';
      const prefix = (
        wrapper.querySelector(
          '[name*="[selection]"]',
        ) as HTMLInputElement | null
      )?.name.replace(/\[selection\].*$/, '[selection]');
      if (!prefix) {
        return;
      }
      const stale = Object.keys(
        selectFormValues(store.getState(), formId),
      ).filter(
        (name) =>
          name.startsWith(prefix) &&
          !document.querySelector(`[name="${CSS.escape(name)}"]`),
      );
      if (stale.length) {
        dispatch(removeFieldValue({ formId, fieldName: stale }));
      }
    };
    const handleRemoveIntent = (e: Event) => {
      const isKeyboardActivation =
        e instanceof KeyboardEvent && (e.key === 'Enter' || e.key === ' ');
      if (e.type === 'keydown' && !isKeyboardActivation) {
        return;
      }
      if (
        (e.target as HTMLElement).closest('[data-canvas-media-remove-button]')
      ) {
        document.addEventListener(
          'drupalAjaxStop',
          () => setTimeout(pruneRemovedItems),
          { once: true },
        );
      }
    };
    wrapper.addEventListener('mousedown', handleRemoveIntent, {
      capture: true,
    });
    wrapper.addEventListener('keydown', handleRemoveIntent, { capture: true });
    return () => {
      wrapper.removeEventListener('mousedown', handleRemoveIntent, {
        capture: true,
      });
      wrapper.removeEventListener('keydown', handleRemoveIntent, {
        capture: true,
      });
    };
  }, [dispatch, store]);

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
