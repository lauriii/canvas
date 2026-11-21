import { useCallback, useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router';
import { skipToken } from '@reduxjs/toolkit/query';

import TextField from '@/components/form/components/TextField';
import { useFieldContext } from '@/components/form/contexts/FieldContext';
import { withRHF } from '@/components/form/react-hook-form/withRHF';
import { useEntityTitle } from '@/hooks/useEntityTitle';
import { a2p } from '@/local_packages/utils.js';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import { getDrupalSettings } from '@/utils/drupal-globals';

import type { Attributes } from '@/types/DrupalAttribute';
import type { transliterate as TransliterateType } from '@/types/transliterate';

import styles from './DrupalPathWidget.module.css';

const getTransliterate = (): TransliterateType => {
  const { transliterate: drupalTransliterate } = window;
  return drupalTransliterate;
};

const drupalSettings = getDrupalSettings();

// The steps are mirrored on the server for a page that is moved into a folder.
// @see \Drupal\canvas\Hook\PageHooks::moveAlias()
const getPathAlias = (titleValue: string, folderPath = '') => {
  const drupalTransliterate = getTransliterate();
  const alias = titleValue
    .toLowerCase()
    .replace(/\s+/g, '-')
    .replace(/[^\w-]+/g, '')
    .replace(/_+/g, '-')
    .replace(/--+/g, '-')
    .replace(/^-+/, '')
    .replace(/-+$/, '');

  if (alias.length) {
    const langcode = drupalSettings.langcode;
    const languageOverrides =
      drupalSettings?.transliteration_language_overrides?.[langcode];

    const replace: Record<string, string> = {};
    if (languageOverrides) {
      Object.keys(languageOverrides).forEach((key) => {
        replace[String.fromCharCode(parseInt(key, 10))] =
          languageOverrides[key];
      });
    }

    return `${folderPath}/${drupalTransliterate(alias, { replace })}`;
  }

  return '';
};

/**
 * Path widget with automatic URL alias generation from title.
 */
const DrupalPathWidget = ({
  attributes = {},
}: {
  attributes?: Attributes;
  children?: React.ReactNode;
}) => {
  const initialValue = attributes?.value || '';
  const [pathValue, setPathValue] = useState<string>(initialValue.toString());
  const [lastGeneratedPath, setLastGeneratedPath] = useState<string>('');
  const [initialTitle, setInitialTitle] = useState<string | null>(null);
  const [userHasEditedPath, setUserHasEditedPath] = useState(false);
  const [isInitialized, setIsInitialized] = useState(false);
  const fieldContext = useFieldContext();
  const ownPartRef = useRef<HTMLInputElement>(null);
  // The URL path of the folder the page is in, if that folder has one. The
  // alias of the page must start with it.
  const folderPath = String(attributes['data-canvas-folder-path'] ?? '');

  const { entityId, entityType } = useParams();
  const titleInput = useEntityTitle();
  const { data: fetchedLayout } = useGetPageLayoutQuery(
    entityId && entityType ? { entityId, entityType } : skipToken,
  );
  const isPublished = fetchedLayout?.isPublished;

  // Initialize state on first render by checking if initial path matches generated path
  useEffect(() => {
    if (isInitialized || !titleInput) return;

    setInitialTitle(titleInput);

    const generatedPathFromInitialTitle = getPathAlias(titleInput, folderPath);
    const initialPathValue = initialValue.toString();

    if (
      initialPathValue &&
      initialPathValue !== generatedPathFromInitialTitle
    ) {
      // Initial path exists but doesn't match what would be generated
      // This means it was manually customized - don't overwrite it
      setUserHasEditedPath(true);
      setLastGeneratedPath(initialPathValue);
    } else if (initialPathValue) {
      // Initial path matches generated path - treat as auto-generated
      setLastGeneratedPath(initialPathValue);
    }

    setIsInitialized(true);
  }, [titleInput, isInitialized, initialValue, folderPath]);

  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      setPathValue(e.target.value);
      // Pass through the event to trigger form state update
      fieldContext?.triggerChange(e);
    },
    [fieldContext],
  );

  const handleManualChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      // User manually edited the path - set the flag to stop auto-generation
      setUserHasEditedPath(true);
      handleChange(e);
    },
    [handleChange],
  );

  // Auto-generate path from title
  useEffect(() => {
    if (!titleInput || !isInitialized) return;

    const generatedPath = getPathAlias(titleInput, folderPath);

    // Never update if published with a path
    if (isPublished && pathValue) {
      return;
    }

    const pathMatchesGenerated = pathValue === lastGeneratedPath;
    const titleHasChanged = titleInput !== initialTitle;

    // If user has manually edited the path, check if they've changed it back to match generated
    if (userHasEditedPath) {
      // If the current path matches what we would generate, resume auto-generation
      if (pathValue === generatedPath) {
        setUserHasEditedPath(false);
      } else {
        // User has diverged from auto-generated path, don't auto-generate
        return;
      }
    }

    let shouldGenerate = false;

    if (pathMatchesGenerated && pathValue) {
      // Path matches what we generated, so keep auto-generating
      shouldGenerate = true;
    } else if (!pathValue && titleHasChanged) {
      // No path exists and title has changed from initial
      shouldGenerate = true;
    }

    if (shouldGenerate) {
      setLastGeneratedPath(generatedPath);
      const syntheticEvent = {
        target: {
          value: generatedPath,
          name: attributes.name,
        },
      } as React.ChangeEvent<HTMLInputElement>;
      handleChange(syntheticEvent);
    }
  }, [
    titleInput,
    pathValue,
    lastGeneratedPath,
    isPublished,
    initialTitle,
    userHasEditedPath,
    isInitialized,
    handleChange,
    attributes.name,
    folderPath,
  ]);

  // The folder owns the first part of the alias: it is fixed text in front of
  // the input, and the input holds the part that belongs to the page. An alias
  // that is not under the folder path is shown whole, so that it can be
  // corrected.
  if (
    folderPath &&
    (pathValue === '' ||
      pathValue === folderPath ||
      pathValue.startsWith(`${folderPath}/`))
  ) {
    const prefixId = `${attributes.id}--folder-path`;
    const ownPartAttrs = a2p(attributes, {
      'aria-describedby': prefixId,
      value: pathValue.slice(folderPath.length + 1),
      onChange: (e: React.ChangeEvent<HTMLInputElement>) => {
        const ownPart = e.target.value.replace(/^\/+/, '');
        handleManualChange({
          target: {
            // Without a part of its own, the page is the landing page of the
            // folder.
            value: ownPart ? `${folderPath}/${ownPart}` : folderPath,
            name: attributes.name,
          },
        } as React.ChangeEvent<HTMLInputElement>);
      },
    });
    return (
      <div
        className={styles.withFolderPath}
        onClick={() => ownPartRef.current?.focus()}
      >
        <span id={prefixId} className={styles.folderPath} title={folderPath}>
          {folderPath}/
        </span>
        <input
          autoComplete="off"
          {...ownPartAttrs}
          ref={ownPartRef}
          className={styles.ownPart}
        />
      </div>
    );
  }

  const processedAttrs = a2p(attributes, {
    onChange: handleManualChange,
    value: pathValue,
  });

  return <TextField attributes={processedAttrs} />;
};

export default withRHF(DrupalPathWidget);
