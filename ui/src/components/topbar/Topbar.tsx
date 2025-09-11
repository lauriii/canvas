import clsx from 'clsx';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import DropIcon from '@assets/icons/drop.svg?react';
import { CardStackPlusIcon, PersonIcon } from '@radix-ui/react-icons';
import * as Menubar from '@radix-ui/react-menubar';
import { Box, Button, Flex, Grid, Tooltip } from '@radix-ui/themes';

import AIToggleButton from '@/components/aiExtension/AiToggleButton';
import PreviewControls from '@/components/PreviewControls';
import UnpublishedChanges from '@/components/review/UnpublishedChanges';
import ContentPreviewSelector from '@/components/templates/ContentPreviewSelector';
import UndoRedo from '@/components/UndoRedo';
import { useGetPreviewContentEntitiesQuery } from '@/services/componentAndLayout';
import { getDrupalSettings } from '@/utils/drupal-globals';

import PageInfo from '../pageInfo/PageInfo';

import styles from './Topbar.module.css';

const PREVIOUS_URL_STORAGE_KEY = 'CanvasPreviousURL';

const Topbar = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const { entityType, bundle, viewMode, previewEntityId } = useParams();
  const isPreview = location.pathname.includes('/preview');
  const isEditor = location.pathname.includes('/editor');
  const isSegments = location.pathname.includes('/segments');
  const isTemplateRoute = location.pathname.startsWith('/template/');

  let hasAiExtensionAvailable = false;
  let hasPersonalizeExtensionAvailable = false;

  const drupalSettings = getDrupalSettings();
  if (drupalSettings && drupalSettings.canvas.aiExtensionAvailable) {
    hasAiExtensionAvailable = true;
  }
  if (
    drupalSettings &&
    drupalSettings.canvas.personalizationExtensionAvailable
  ) {
    hasPersonalizeExtensionAvailable = true;
  }

  // Fetch preview content entities for template routes
  const { data: previewEntities = {} } = useGetPreviewContentEntitiesQuery(
    {
      entityTypeId: entityType || '',
      bundle: bundle || '',
    },
    {
      skip: !isTemplateRoute || !entityType || !bundle,
    },
  );

  // Handle preview entity selection change
  const handlePreviewEntityChange = (selectedEntityId: string) => {
    if (entityType && bundle && viewMode) {
      navigate(
        `/template/${entityType}/${bundle}/${viewMode}/${selectedEntityId}`,
      );
    }
  };

  const backHref =
    window.sessionStorage.getItem(PREVIOUS_URL_STORAGE_KEY) ?? '/';

  return (
    <Menubar.Root data-testid="canvas-topbar" asChild>
      <Box
        className={clsx(styles.root, styles.topBar, {
          [styles.inPreview]: isPreview,
        })}
        pr="4"
      >
        <Grid columns="3" gap="0" width="auto" height="100%">
          <Flex align="center" gap="2">
            <Tooltip content="Exit Drupal Canvas">
              <a
                href={backHref}
                aria-labelledby="back-to-previous-label"
                className={clsx(styles.topBarButton, styles.exitButton)}
              >
                <span className="visually-hidden" id="back-to-previous-label">
                  Exit Drupal Canvas
                </span>
                <DropIcon
                  className={styles.drupalLogo}
                  height="24"
                  width="auto"
                />
              </a>
            </Tooltip>
            {!isPreview && hasAiExtensionAvailable && (
              <>
                <div className={clsx(styles.verticalDivider)}></div>
                <AIToggleButton />
              </>
            )}
            {!isPreview && hasPersonalizeExtensionAvailable && (
              <>
                <Button
                  variant={isEditor ? 'soft' : 'ghost'}
                  color={isEditor ? 'blue' : 'gray'}
                  onClick={() => navigate('/editor')}
                >
                  <CardStackPlusIcon />
                  <span className={isEditor ? '' : 'visually-hidden'}>
                    Builder
                  </span>
                </Button>
                <Button
                  variant={isSegments ? 'soft' : 'ghost'}
                  color={isSegments ? 'blue' : 'gray'}
                  onClick={() => navigate('/segments')}
                >
                  <PersonIcon />
                  <span className={isSegments ? '' : 'visually-hidden'}>
                    Segments
                  </span>
                </Button>
              </>
            )}
            <div className={clsx(styles.verticalDivider)}></div>
            {!isPreview && (
              <>
                <UndoRedo />
              </>
            )}
          </Flex>
          <Flex align="center" justify="center" gap="2">
            <PageInfo />
            {isTemplateRoute && (
              <ContentPreviewSelector
                items={previewEntities}
                selectedItemId={previewEntityId}
                onSelectionChange={handlePreviewEntityChange}
              />
            )}
          </Flex>
          <Flex align="center" justify="end" gap="2">
            <PreviewControls isPreview={isPreview} />
            <UnpublishedChanges />
          </Flex>
        </Grid>
      </Box>
    </Menubar.Root>
  );
};

export default Topbar;
