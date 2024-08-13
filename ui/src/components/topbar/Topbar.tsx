import * as Menubar from '@radix-ui/react-menubar';
import { ChevronDownIcon } from '@radix-ui/react-icons';
import styles from './Topbar.module.css';
import clsx from 'clsx';
import { Button, Flex, Theme } from '@radix-ui/themes';
import UndoRedo from '@/components/UndoRedo';
import DropIcon from '@assets/icons/topbar/drop.svg';
import { handleNonWorkingBtn } from '@/utils/function-utils';

const Topbar = () => {
  return (
    <Theme appearance="dark">
      <Menubar.Root className={clsx('TopbarRoot', styles.TopbarRoot)}>
        <Menubar.Menu>
          <Menubar.Trigger
            className={clsx('TopbarTrigger', styles.TopbarTrigger)}
          >
            <img
              src={DropIcon}
              alt="drop icon in topbar"
              className={styles.dropIcon}
            />
            <ChevronDownIcon width={18} height={18} />
          </Menubar.Trigger>
          <Menubar.Portal>
            <Menubar.Content className="TopbarContent" align="start">
              {/* Add items here */}
            </Menubar.Content>
          </Menubar.Portal>
        </Menubar.Menu>
        <Menubar.Label className={clsx('TopbarLabel', styles.TopbarLabel)}>
          Site Name
        </Menubar.Label>
        <Flex
          gap="3"
          className={clsx('topbarBtnContainer', styles.topbarBtnContainer)}
        >
          <UndoRedo />
          <Button
            variant="outline"
            color="gray"
            highContrast
            onClick={handleNonWorkingBtn}
          >
            Share
          </Button>
          <Button
            variant="solid"
            color="gray"
            highContrast
            onClick={handleNonWorkingBtn}
          >
            Publish
          </Button>
        </Flex>
      </Menubar.Root>
    </Theme>
  );
};

export default Topbar;
