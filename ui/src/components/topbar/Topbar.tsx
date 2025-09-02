import clsx from 'clsx';
import { useLocation, useNavigate } from 'react-router-dom';
import DropIcon from '@assets/icons/drop.svg?react';
import {
  CardStackPlusIcon,
  EyeNoneIcon,
  EyeOpenIcon,
  PersonIcon,
} from '@radix-ui/react-icons';
import * as Menubar from '@radix-ui/react-menubar';
import { Box, Button, Flex, Grid, Tooltip } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import AIToggleButton from '@/components/aiExtension/AiToggleButton';
import UnpublishedChanges from '@/components/review/UnpublishedChanges';
import UndoRedo from '@/components/UndoRedo';
import PreviewWidthSelector from '@/features/pagePreview/PreviewWidthSelector';
import { pageDataFormApi } from '@/services/pageDataForm';
import { getDrupalSettings } from '@/utils/drupal-globals';

import PageInfo from '../pageInfo/PageInfo';

import styles from './Topbar.module.css';

const PREVIOUS_URL_STORAGE_KEY = 'CanvasPreviousURL';

const Topbar = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const isPreview = location.pathname.includes('/preview');
  const isEditor = location.pathname.includes('/editor');
  const isSegments = location.pathname.includes('/segments');
  const dispatch = useAppDispatch();

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

  function handleChangeModeClick() {
    if (isPreview) {
      // Fetch a new version of the page data form as it has been
      // unmounted and the cached versions won't reflect any AJAX updates
      // to the form.
      dispatch(
        pageDataFormApi.util.invalidateTags([
          { type: 'PageDataForm', id: 'FORM' },
        ]),
      );
      navigate(`/editor`);
    } else {
      navigate(`/preview/full`);
    }
  }

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
          </Flex>
          <Flex align="center" justify="end" gap="2">
            {isPreview && (
              <>
                <PreviewWidthSelector />
                <Button
                  variant="outline"
                  color="blue"
                  onClick={handleChangeModeClick}
                >
                  <EyeNoneIcon /> Exit Preview
                </Button>
              </>
            )}
            {!isPreview && (
              <Button
                variant="outline"
                color="blue"
                onClick={handleChangeModeClick}
              >
                <EyeOpenIcon /> Preview
              </Button>
            )}
            <UnpublishedChanges />
          </Flex>
        </Grid>
      </Box>
    </Menubar.Root>
  );
};

export default Topbar;
