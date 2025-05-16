import * as Menubar from '@radix-ui/react-menubar';
import styles from './Topbar.module.css';
import {
  Button,
  Flex,
  Grid,
  SegmentedControl,
  Tooltip,
  Box,
} from '@radix-ui/themes';
import UndoRedo from '@/components/UndoRedo';
import DropIcon from '@assets/icons/drop.svg?react';
import CMSIcon from '@assets/icons/cms.svg?react';
import ExtensionIcon from '@assets/icons/extension.svg?react';
import { EyeNoneIcon, EyeOpenIcon } from '@radix-ui/react-icons';
import { useLocation, useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import UnpublishedChanges from '@/components/review/UnpublishedChanges';
import PageInfo from '../pageInfo/PageInfo';
import ExtensionsList from '@/components/extensions/ExtensionsList';
import TopbarPopover from '@/components/topbar/menu/TopbarPopover';
import topBarStyles from '@/components/topbar/Topbar.module.css';
import DynamicComponents from '@/components/dynamicComponents/DynamicComponents';
import { getDrupalSettings } from '@/utils/drupal-globals';

const PREVIOUS_URL_STORAGE_KEY = 'XBPreviousURL';

const drupalSettings = getDrupalSettings();

const Topbar = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const isPreview = location.pathname.includes('/preview');

  let hasExtensions = false;
  if (drupalSettings && drupalSettings.xbExtension) {
    hasExtensions = Object.values(drupalSettings.xbExtension).length > 0;
  }

  function handleChangeModeClick() {
    if (isPreview) {
      navigate(`/editor`);
    } else {
      navigate(`/preview/full`);
    }
  }

  function handlePreviewWidthChange(val: 'full' | 'desktop' | 'mobile') {
    navigate(`/preview/${val}`);
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
          <Flex align="center" justify="start" gap="2">
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
            <div className={clsx(styles.verticalDivider)}></div>
            {!isPreview && <UndoRedo />}
            {hasExtensions && (
              <TopbarPopover
                tooltip="Extensions"
                trigger={
                  <Button
                    variant="ghost"
                    color="gray"
                    size="2"
                    className={clsx(topBarStyles.topBarButton)}
                    aria-label="Extensions"
                  >
                    <ExtensionIcon height="24" width="auto" />
                  </Button>
                }
              >
                <ExtensionsList />
              </TopbarPopover>
            )}
            <TopbarPopover
              tooltip="Dynamic components"
              trigger={
                <Button
                  variant="ghost"
                  color="gray"
                  size="2"
                  className={clsx(styles.topBarButton)}
                  aria-label="Dynamic components"
                >
                  <CMSIcon height="24" width="auto" />
                </Button>
              }
            >
              <DynamicComponents />
            </TopbarPopover>
          </Flex>
          <Flex align="center" justify="center" gap="2">
            <PageInfo />
          </Flex>
          <Flex align="center" justify="end" gap="2">
            {isPreview && (
              <>
                <SegmentedControl.Root
                  defaultValue="full"
                  onValueChange={handlePreviewWidthChange}
                >
                  <SegmentedControl.Item value="full">
                    Full
                  </SegmentedControl.Item>
                  <SegmentedControl.Item value="desktop">
                    Desktop
                  </SegmentedControl.Item>
                  <SegmentedControl.Item value="mobile">
                    Mobile
                  </SegmentedControl.Item>
                </SegmentedControl.Root>
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
