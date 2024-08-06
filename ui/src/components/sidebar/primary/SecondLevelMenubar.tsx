import * as Menubar from '@radix-ui/react-menubar';
import {
  selectActiveSecondLevelMenu,
  setActiveSecondLevelMenu,
} from '@/features/ui/primaryMenuSlice';
import SecondLevelMenu from '@/components/sidebar/primary/SecondLevelMenu';
import ComponentIcon from '@assets/icons/sidebar/primary/component.svg';
import List from '@/components/list/List';
import SectionIcon from '@assets/icons/sidebar/primary/section.svg';
import { useAppSelector } from '@/app/hooks';
import { PRIMARY_MENU_ITEMS } from '@/components/sidebar/primary/PrimaryMenubar';

const SecondLevelMenubar = () => {
  const activeMenu = useAppSelector(selectActiveSecondLevelMenu);
  return (
    <Menubar.Root value={activeMenu} onValueChange={setActiveSecondLevelMenu}>
      <SecondLevelMenu
        value={PRIMARY_MENU_ITEMS.DEFAULT_COMPONENTS_ID}
        submenuTitle="Default components"
        leftIcon={ComponentIcon}
      >
        <List />
      </SecondLevelMenu>
      <SecondLevelMenu
        value={PRIMARY_MENU_ITEMS.CUSTOM_COMPONENTS_ID}
        submenuTitle="Custom components"
        leftIcon={ComponentIcon}
      >
        <h4>Custom components placeholder</h4>
      </SecondLevelMenu>
      <SecondLevelMenu
        value={PRIMARY_MENU_ITEMS.SECTION_ID}
        submenuTitle="Section templates"
        leftIcon={SectionIcon}
      >
        <>
          <h4>Section templates placeholder</h4>
          <i>Below options subject to change. Added for testing purposes.</i>
          <List />
        </>
      </SecondLevelMenu>
    </Menubar.Root>
  );
};

export default SecondLevelMenubar;
