import { preventHover } from '@/utils/function-utils';
import * as Menubar from '@radix-ui/react-menubar';
import clsx from 'clsx';
import styles from '@/components/topbar/add/AddMenu.module.css';
import { ChevronRightIcon } from '@radix-ui/react-icons';
import {
  selectActiveSecondLevelMenu,
  setActiveSecondLevelMenu,
} from '@/features/ui/addMenuSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';

interface SecondLevelMenuTriggerInterfaceProps {
  submenuTitle: string;
  leftIcon: string;
  value: string;
}

const SecondLevelMenuTrigger = ({
  submenuTitle,
  leftIcon,
  value,
}: SecondLevelMenuTriggerInterfaceProps) => {
  const dispatch = useAppDispatch();
  const activeSubmenu = useAppSelector(selectActiveSecondLevelMenu);

  const onClickHandler = () => {
    if (activeSubmenu !== value) {
      dispatch(setActiveSecondLevelMenu(value));
    }
  };

  return (
    <Menubar.Trigger
      onPointerEnter={preventHover}
      onPointerMove={preventHover}
      onPointerLeave={preventHover}
      className={clsx('MenubarSubTrigger', styles.MenubarSubTrigger)}
      onClick={onClickHandler}
    >
      <div className={clsx('leftSlot', styles.leftSlot)}>
        <img src={leftIcon} alt="menu item icon" />
      </div>
      <div className={clsx('menuItemText', styles.menuItemText)}>
        {submenuTitle}
      </div>
      <div className={clsx('rightSlot', styles.rightSlot)}>
        <ChevronRightIcon />
      </div>
    </Menubar.Trigger>
  );
};

export default SecondLevelMenuTrigger;
