import { useCallback, useEffect, useRef, useState } from 'react';

import { useAppSelector } from '@/app/hooks';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import {
  EditorFrameContext,
  selectEditorFrameContext,
} from '@/features/ui/uiSlice';
import useInputUIData from '@/hooks/useInputUIData';
import useMutationObserver from '@/hooks/useMutationObserver';
import { useUpdateComponentMutation } from '@/services/preview';
import { isPropSourceComponent } from '@/types/Component';

import type { CodeComponentPropImageExample } from '@/types/CodeComponent';

import styles from './DefaultImagePreview.module.css';

/**
 * Displays a preview of the default/example image for image props that are
 * showing their default value. For optional props, includes a "Remove" button
 * to allow users to explicitly clear the default value.
 *
 * The preview renders when ALL of these conditions are met:
 * 1. The prop has a default/example image value defined
 * 2. No user-selected media exists in the widget
 * 3. The current resolved value matches the default value
 * 4. The prop source value is empty (no explicit value set by user)
 *
 * The "Remove" button only appears if the prop is optional (not required).
 * When clicked, it sets the prop's value to an empty array, signaling to the
 * backend that the user explicitly removed the default image. This prevents
 * the default from reappearing after page refresh or publish operations.
 */
interface DefaultImagePreviewProps {
  propName: string;
}

/**
 * Checks if a value is considered empty (null, undefined, empty array, or empty object).
 * Used to determine if a prop source value has been explicitly set by the user.
 *
 * For image props showing defaults, the source value can be:
 * - undefined: Component freshly instantiated, no user action taken yet
 * - null: Backend normalized an explicitly removed value
 * - []: User just removed the value via the "Remove" button
 * - {}: Edge case where an empty object might be returned
 *
 * All of these indicate "no explicit user-provided value", meaning we should
 * show the default image preview.
 */
const isEmptyValue = (value: unknown): boolean => {
  if (value === null || value === undefined) return true;
  if (Array.isArray(value) && value.length === 0) return true;
  if (typeof value === 'object' && Object.keys(value).length === 0) return true;
  return false;
};

const DefaultImagePreview = ({ propName }: DefaultImagePreviewProps) => {
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const {
    selectedComponent: selectedComponentId,
    components,
    selectedComponentType,
    version,
    model,
  } = useInputUIData();

  const [patchComponent] = useUpdateComponentMutation({
    fixedCacheKey: selectedComponentId,
  });

  const [hasMediaInWidget, setHasMediaInWidget] = useState(false);
  const fieldsetRef = useRef<Element | null>(null);

  const componentRefCallback = useCallback((node: HTMLDivElement | null) => {
    if (node) {
      const fieldset = node.closest(
        'fieldset[data-canvas-media-library-fieldset]',
      );
      fieldsetRef.current = fieldset || null;
    }
  }, []);

  const checkForMedia = useCallback(() => {
    if (!fieldsetRef.current) return;
    const mediaItems = fieldsetRef.current.querySelectorAll(
      '.js-media-library-item',
    );
    setHasMediaInWidget(mediaItems.length > 0);
  }, []);

  useEffect(() => {
    checkForMedia();
  }, [checkForMedia]);

  useMutationObserver(fieldsetRef, checkForMedia, {
    childList: true,
    subtree: true,
    attributes: false,
  });

  if (!model) {
    return null;
  }

  const selectedModel = model[selectedComponentId] || {};

  if (!isEvaluatedComponentModel(selectedModel)) {
    return null;
  }

  const componentDefinition = components?.[selectedComponentType];
  const propData = isPropSourceComponent(componentDefinition)
    ? componentDefinition.propSources?.[propName]
    : undefined;

  // Use the proper interface from CodeComponent.ts
  const defaultImageValue = propData?.default_values?.resolved as
    | CodeComponentPropImageExample
    | undefined;

  const sourceValue = selectedModel.source?.[propName]?.value;
  const resolvedValue = selectedModel.resolved?.[propName] as
    | CodeComponentPropImageExample
    | undefined;

  const hasDefaultImage = !!defaultImageValue?.src;

  const sourceIsEmpty = isEmptyValue(sourceValue);
  const resolvedMatchesDefault =
    hasDefaultImage && resolvedValue?.src === defaultImageValue?.src;

  // Determine if we should show the default image preview with remove button.
  // This is true when:
  // 1. The component prop has a default/example image defined (hasDefaultImage)
  // 2. The user hasn't explicitly set a value - the source is empty (sourceIsEmpty)
  // 3. The currently displayed (resolved) value matches the default (resolvedMatchesDefault)
  // 4. No media has been selected in the media library widget (!hasMediaInWidget)
  // This combination indicates the component is displaying its default image and
  // the user hasn't taken any action yet (no explicit value set, no media selected).
  const isShowingDefaultImage =
    hasDefaultImage &&
    sourceIsEmpty &&
    resolvedMatchesDefault &&
    !hasMediaInWidget;

  /**
   * Removes the default image by setting the prop value to an empty array.
   * This explicitly signals to the backend that the user removed the default,
   * preventing it from reappearing after save/publish operations.
   */
  const handleRemoveDefault = () => {
    if (
      !propData ||
      !editorFrameContext ||
      editorFrameContext === EditorFrameContext.NONE
    ) {
      return;
    }

    patchComponent({
      type: editorFrameContext,
      componentInstanceUuid: selectedComponentId,
      componentType: `${selectedComponentType}@${version}`,
      model: {
        source: {
          ...selectedModel.source,
          [propName]: {
            ...propData,
            value: [],
          },
        },
        resolved: {
          ...selectedModel.resolved,
          [propName]: [],
        },
      },
    });
  };

  if (!isShowingDefaultImage || !defaultImageValue) {
    return null;
  }

  const isOptional = !propData?.required;
  const imageUrl = defaultImageValue.src;
  const imageAlt = defaultImageValue.alt || 'Default';

  return (
    <div ref={componentRefCallback} className={styles.defaultImagePreview}>
      <div className={styles.defaultImageContainer}>
        <img
          src={imageUrl}
          alt={imageAlt}
          className={styles.defaultImageThumbnail}
        />
        {isOptional && (
          <button
            type="button"
            onClick={handleRemoveDefault}
            className={styles.removeDefaultButton}
            aria-label="Remove default"
            title="Remove default"
          />
        )}
        <span className={styles.defaultImageLabel}>{imageAlt}</span>
      </div>
    </div>
  );
};

export default DefaultImagePreview;
