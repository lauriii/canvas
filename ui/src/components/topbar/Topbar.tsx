import * as Menubar from '@radix-ui/react-menubar';
import styles from './Topbar.module.css';
import { Button, Flex, SegmentedControl } from '@radix-ui/themes';
import Panel from '@/components/Panel';
import UndoRedo from '@/components/UndoRedo';
import DropIcon from '@assets/icons/drop.svg';
import { EyeNoneIcon, EyeOpenIcon } from '@radix-ui/react-icons';
import { useLocation, useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import DemoPublishButton from '@/components/DemoPublishButton';
import UnpublishedChanges from '@/components/review/UnpublishedChanges';
import PageInfo from '../pageInfo/PageInfo';

const Topbar = () => {
  const navigate = useNavigate();
  const location = useLocation();
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

  return (
    <Menubar.Root data-testid="xb-topbar" asChild>
      <Panel
        className={clsx(styles.root, {
          [styles.inPreview]: isPreview,
        })}
        px="6"
      >
        <Flex height="100%" align="center" justify="between">
          <Flex gap="5" align="center" justify="start" minWidth="18em">
            <a href="/" className={styles.logo}>
              <img src={DropIcon} alt="Drupal icon" />
            </a>
            {/* @todo: Keep the <AddMenu/> code to reuse for displaying module components.*/}
            {/*   https://www.drupal.org/project/experience_builder/issues/3482393 */}
            {/*<AddMenu />*/}
          </Flex>

          <Flex gap="5" align="center" width="full" justify="center">
            <PageInfo />
          </Flex>

          <Flex gap="4" align="center" justify="end">
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
            <DemoPublishButton />
            <UnpublishedChanges />
          </Flex>
        </Flex>
      </Panel>
    </Menubar.Root>
  );
};

export default Topbar;
