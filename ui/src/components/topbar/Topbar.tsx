import * as Menubar from '@radix-ui/react-menubar';
import styles from './Topbar.module.css';
import clsx from 'clsx';
import { Button, Flex, Link, Theme } from '@radix-ui/themes';
import UndoRedo from '@/components/UndoRedo';
import DropIcon from '@assets/icons/drop.svg';
import { handleNonWorkingBtn } from '@/utils/function-utils';
import AddMenu from '@/components/topbar/add/AddMenu';
import { useAppSelector } from '@/app/hooks';
import { selectActiveMenu, setActiveMenu } from '@/features/ui/addMenuSlice';

const Topbar = () => {
  const activeMenu = useAppSelector(selectActiveMenu);

  return (
    <Theme appearance="dark">
      <Menubar.Root
        className={clsx('TopbarRoot', styles.TopbarRoot)}
        value={activeMenu}
        onValueChange={setActiveMenu}
      >
        <Flex gap="3" className={clsx(styles.buttonGroup)}>
          <Link href="/" className={clsx(styles.link)}>
            <img
              src={DropIcon}
              alt="drop icon in topbar"
              className={styles.dropIcon}
            />
          </Link>
          <AddMenu />
        </Flex>
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
