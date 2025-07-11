import * as Menubar from '@radix-ui/react-menubar';
import styles from './Topbar.module.css';
import { Button, Flex, Grid, Tooltip, Box } from '@radix-ui/themes';
import UndoRedo from '@/components/UndoRedo';
import DropIcon from '@assets/icons/drop.svg?react';
import { EyeNoneIcon, EyeOpenIcon } from '@radix-ui/react-icons';
import { useLocation, useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import UnpublishedChanges from '@/components/review/UnpublishedChanges';
import PageInfo from '../pageInfo/PageInfo';
import PreviewWidthSelector from '@/features/pagePreview/PreviewWidthSelector';
import AIToggleButton from '@/components/aiExtension/AiToggleButton';
import { getDrupalSettings } from '@/utils/drupal-globals';
import { pageDataFormApi } from '@/services/pageDataForm';
import { useAppDispatch } from '@/app/hooks';

const PREVIOUS_URL_STORAGE_KEY = 'XBPreviousURL';

const Topbar = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const isPreview = location.pathname.includes('/preview');
  const dispatch = useAppDispatch();

  let hasAiExtensionAvailable = false;

  const drupalSettings = getDrupalSettings();
  if (drupalSettings && drupalSettings.xb.aiExtensionAvailable) {
    hasAiExtensionAvailable = true;
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
    <Menubar.Root data-testid="xb-topbar" asChild>
      <Box
        className={clsx(styles.root, styles.topBar, {
          [styles.inPreview]: isPreview,
        })}
        pr="4"
      >
        <Grid columns="3" gap="0" width="auto" height="100%">
          <Flex align="center" gap="2">
            <Tooltip content="Exit Experience Builder">
              <a
                href={backHref}
                aria-labelledby="back-to-previous-label"
                className={clsx(styles.topBarButton, styles.exitButton)}
              >
                <span className="visually-hidden" id="back-to-previous-label">
                  Exit Experience Builder
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
