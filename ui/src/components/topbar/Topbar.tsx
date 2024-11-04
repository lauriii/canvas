import * as Menubar from '@radix-ui/react-menubar';
import styles from './Topbar.module.css';
import { Button, Flex, Text } from '@radix-ui/themes';
import Panel from '@/components/Panel';
import UndoRedo from '@/components/UndoRedo';
import DropIcon from '@assets/icons/drop.svg';
import { handleNonWorkingBtn } from '@/utils/function-utils';

const Topbar = () => {
  return (
    <Menubar.Root data-testid="xb-topbar" asChild>
      <Panel className={styles.root} px="6">
        <Flex height="100%" align="center" justify="between">
          <Flex gap="5" align="center">
            <a href="/" className={styles.logo}>
              <img src={DropIcon} alt="Drupal icon" />
            </a>
            {/* @todo: Keep the <AddMenu/> code to reuse for displaying module components.*/}
            {/*   https://www.drupal.org/project/experience_builder/issues/3482393 */}
            {/*<AddMenu />*/}
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
