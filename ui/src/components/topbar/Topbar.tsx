import * as Menubar from '@radix-ui/react-menubar';
import { ChevronDownIcon } from '@radix-ui/react-icons';
import styles from './Topbar.module.css';
import clsx from 'clsx';
import { Button, Flex } from '@radix-ui/themes';
import { setContextualPanelOpen } from '@/features/ui/uiSlice';
import UndoRedo from '@/components/UndoRedo';
import { useAppDispatch } from '@/app/hooks';
import DropIcon from '@assets/icons/topbar/drop.svg';

const Topbar = () => {
  const dispatch = useAppDispatch();

  return (
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
        <Button onClick={() => dispatch(setContextualPanelOpen(true))}>
          Open Right
        </Button>
        <Button variant="outline" color="gray" highContrast>
          Share
        </Button>
        <Button variant="solid" color="gray" highContrast>
          Publish
        </Button>
      </Flex>
    </Menubar.Root>
  );
};

export default Topbar;
