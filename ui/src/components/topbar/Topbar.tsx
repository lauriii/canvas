import * as Menubar from '@radix-ui/react-menubar';
import styles from './Topbar.module.css';
import {
  Button,
  Flex,
  Grid,
  SegmentedControl,
  Tooltip,
} from '@radix-ui/themes';
import Panel from '@/components/Panel';
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
import { useAppSelector } from '@/app/hooks';
import { selectDevMode } from '@/features/configuration/configurationSlice';

const PREVIOUS_URL_STORAGE_KEY = 'XBPreviousURL';

const Topbar = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const isDevMode = useAppSelector(selectDevMode);
  const isPreview = location.pathname.includes('/preview');

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
      <Panel
        className={clsx(styles.root, {
          [styles.inPreview]: isPreview,
        })}
        px="4"
      >
        <Grid columns="3" gap="3" width="auto" height="100%">
          <Flex align="center" justify="start" gap="2">
            <Tooltip content="Exit Experience Builder">
              <Button
                asChild={true}
                variant="ghost"
                color="gray"
                size="2"
                className={clsx(styles.topBarButton)}
                data-testid="xb-back-button"
              >
                <a href={backHref} aria-labelledby="back-to-previous-label">
                  <span className="visually-hidden" id="back-to-previous-label">
                    Exit Experience Builder
                  </span>
                  <DropIcon
                    className={styles.drupalLogo}
                    height="24"
                    width="auto"
                  />
                </a>
              </Button>
            </Tooltip>
            <div className={clsx(styles.verticalDivider)}></div>
            {isDevMode && (
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
            {!isPreview && <UndoRedo />}
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
      </Panel>
    </Menubar.Root>
  );
};

export default Topbar;
