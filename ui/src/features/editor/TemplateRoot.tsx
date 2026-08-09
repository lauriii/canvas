import { useEffect, useState } from 'react';
import { useLocation, useParams } from 'react-router-dom';
import { ExclamationTriangleIcon, PlusIcon } from '@radix-ui/react-icons';
import { Button, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import {
  EditorFrameContext,
  setEditorFrameContext,
  unsetEditorFrameContext,
} from '@/features/ui/uiSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useGetContentTemplatesQuery } from '@/services/componentAndLayout';
import { getBaseUrl } from '@/utils/drupal-globals';

import styles from './TemplateRoot.module.css';

const TemplateRoot = () => {
  const { navigateToTemplateEditor } = useEditorNavigation();
  const { entityType, bundle, viewMode } = useParams();
  const dispatch = useAppDispatch();

  const { data: templatesData, isSuccess } = useGetContentTemplatesQuery();
  const location = useLocation();
  const [showNoTemplateError, setShowNoTemplateError] = useState(false);

  useEffect(() => {
    if (isSuccess && templatesData && entityType && bundle && viewMode) {
      const entityTemplates = templatesData[entityType];
      const bundleData = entityTemplates?.bundles?.[bundle];
      const viewModeData = bundleData?.viewModes?.[viewMode];
      // If there's no template created for the specified view mode, show the no template error.
      if (!viewModeData) {
        setShowNoTemplateError(true);
      } else {
        setShowNoTemplateError(false);
      }

      const suggestedEntityId = viewModeData?.suggestedPreviewEntityId;

      if (suggestedEntityId) {
        navigateToTemplateEditor(
          {
            entityType,
            bundle,
            viewMode,
            suggestedPreviewEntityId: suggestedEntityId,
          },
          {
            replace: true,
          },
        );
      }
    }
  }, [
    isSuccess,
    templatesData,
    entityType,
    bundle,
    viewMode,
    navigateToTemplateEditor,
  ]);

  useEffect(() => {
    dispatch(setEditorFrameContext(EditorFrameContext.TEMPLATE));
    return () => {
      dispatch(unsetEditorFrameContext());
    };
  }, [dispatch]);

  // Get entity type and bundle names for the button
  const getEntityInfo = () => {
    if (!templatesData || !entityType || !bundle) {
      return {
        entityLabel: Drupal.t('content'),
        bundleLabel: Drupal.t('item'),
      };
    }

    const entityTemplates = templatesData[entityType];
    const bundleData = entityTemplates?.bundles?.[bundle];

    return {
      entityLabel: entityTemplates?.label || entityType,
      bundleLabel: bundleData?.label || bundle,
    };
  };

  const { bundleLabel } = getEntityInfo();
  const baseUrl = getBaseUrl();

  const handleCreateContent = () => {
    // Navigate to Drupal's content creation page
    // Add Drupal destination to return to Canvas UI after creating content.
    const createUrl = `${baseUrl}${entityType}/add/${bundle}?destination=/canvas${location.pathname}${location.search}`;
    window.location.href = createUrl;
  };

  // Referencing another button's label. Kept out of the sentence's argument
  // list because Drupal's scanner reads the source as text, and a Drupal.t()
  // call nested inside another call's arguments is not reliably extractable.
  const addTemplateLabel = Drupal.t('Add new template');

  const NoTemplateMessage = () => {
    return (
      <>
        <ExclamationTriangleIcon width="16" height="16" />
        <Text size="1" weight="bold">
          {Drupal.t('Template for !bundle not found.', {
            '!bundle': bundleLabel,
          })}
        </Text>
        <Text size="1">
          {Drupal.t(
            'To add a template, use the !button button in Templates menu.',
            {
              '!button': addTemplateLabel,
            },
          )}
        </Text>
      </>
    );
  };

  const NoContentMessage = () => {
    return (
      <>
        <ExclamationTriangleIcon width="16" height="16" />
        <Text size="1" weight="bold">
          {Drupal.t('No preview content is available')}
        </Text>
        <Text size="1">
          {Drupal.t(
            'To build a template, you must have at least one !bundle.',
            {
              '!bundle': bundleLabel,
            },
          )}
        </Text>
        <Button onClick={handleCreateContent} size="1" variant="solid" mt="2">
          <PlusIcon /> {Drupal.t('Add new !bundle', { '!bundle': bundleLabel })}
        </Button>
      </>
    );
  };

  return (
    <>
      <Flex
        className={styles.noContentNotice}
        align="center"
        justify="center"
        direction="column"
        gap="2"
        pr="calc(var(--sidebar-left-width) + var(--side-menu-width))"
      >
        {showNoTemplateError ? <NoTemplateMessage /> : <NoContentMessage />}
      </Flex>
    </>
  );
};

export default TemplateRoot;
