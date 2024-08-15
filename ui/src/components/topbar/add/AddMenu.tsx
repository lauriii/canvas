import * as Menubar from '@radix-ui/react-menubar';
import { PlusIcon } from '@radix-ui/react-icons';
import clsx from 'clsx';
import styles from '@/components/topbar/add/AddMenu.module.css';
import { preventHover } from '@/utils/function-utils';
import SecondLevelMenubar from '@/components/topbar/add/SecondLevelMenubar';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  ADD_MENU_ITEMS,
  selectActiveMenu,
  selectIsHidden,
  setActiveSecondLevelMenu,
  setInactive,
} from '@/features/ui/addMenuSlice';

export const AddMenu = () => {
  const isHidden = useAppSelector(selectIsHidden);
  const activeMenu = useAppSelector(selectActiveMenu);
  const dispatch = useAppDispatch();

  const onClickHandler = () => {
    if (activeMenu === ADD_MENU_ITEMS.ADD_ID) {
      dispatch(setInactive());
    } else {
      // Open default components menu by default.
      dispatch(setActiveSecondLevelMenu(ADD_MENU_ITEMS.DEFAULT_COMPONENTS_ID));
    }
  };

  return (
    <Menubar.Menu value={ADD_MENU_ITEMS.ADD_ID}>
      <Menubar.Trigger
        onPointerEnter={preventHover}
        onPointerMove={preventHover}
        onPointerLeave={preventHover}
        onClick={onClickHandler}
        id="add-menu-button"
        aria-label="Open add menu"
      >
        <div className={clsx(styles.plusIconWrapper)}>
          <PlusIcon width={20} height={20} />
        </div>
      </Menubar.Trigger>
      <Menubar.Portal container={document.getElementById('menuBarContainer')}>
        <Menubar.Content
          className={clsx('MenubarContent', styles.MenubarContent)}
          align="start"
          onPointerEnter={preventHover}
          onPointerLeave={preventHover}
          style={{ display: isHidden ? 'none' : 'initial' }}
        >
          <SecondLevelMenubar />
        </Menubar.Content>
      </Menubar.Portal>
    </Menubar.Menu>
  );
};

export default AddMenu;
