import { preventHover } from '@/components/sidebar/primary/PrimaryMenubar';
import * as Menubar from '@radix-ui/react-menubar';
import clsx from 'clsx';
import styles from '@/components/sidebar/primary/PrimaryMenubar.module.css';
import { ChevronRightIcon } from '@radix-ui/react-icons';
import type { Dispatch, SetStateAction } from 'react';

interface SubmenuTriggerInterfaceProps {
  submenuTitle: string;
  leftIcon: string;
  setOpen: Dispatch<SetStateAction<boolean>>;
}

const SubmenuTrigger = ({
  submenuTitle,
  leftIcon,
  setOpen,
}: SubmenuTriggerInterfaceProps) => {
  return (
    <Menubar.SubTrigger
      onPointerEnter={preventHover}
      onPointerMove={preventHover}
      onPointerLeave={preventHover}
      className={clsx('MenubarSubTrigger', styles.MenubarSubTrigger)}
      onClick={() => setOpen((prev: any) => !prev)}
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
    </Menubar.SubTrigger>
  );
};

export default SubmenuTrigger;
