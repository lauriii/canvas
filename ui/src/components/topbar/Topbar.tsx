import * as Menubar from '@radix-ui/react-menubar';
import styles from './Topbar.module.css';
import { Button, Flex, Text } from '@radix-ui/themes';
import Panel from '@/components/Panel';
import UndoRedo from '@/components/UndoRedo';
import DropIcon from '@assets/icons/drop.svg';
import { handleNonWorkingBtn } from '@/utils/function-utils';
import AddMenu from '@/components/topbar/add/AddMenu';
import { useAppSelector } from '@/app/hooks';
import { selectActiveMenu, setActiveMenu } from '@/features/ui/addMenuSlice';

const Topbar = () => {
  const activeMenu = useAppSelector(selectActiveMenu);

  return (
    <Menubar.Root
      data-testid="xb-topbar"
      value={activeMenu}
      onValueChange={setActiveMenu}
      asChild
    >
      <Panel className={styles.root} px="6">
        <Flex height="100%" align="center" justify="between">
          <Flex gap="5" align="center">
            <a href="/" className={styles.logo}>
              <img src={DropIcon} alt="Drupal icon" />
            </a>
            <AddMenu />
          </Flex>

          <Text size="2" weight="medium">
            Site name
          </Text>

          <Flex gap="4" align="center">
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
        </Flex>
      </Panel>
    </Menubar.Root>
  );
};

export default Topbar;
