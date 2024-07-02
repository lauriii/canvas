import * as Menubar from '@radix-ui/react-menubar';
import clsx from 'clsx';
import styles from './PrimaryMenubar.module.css';
import { ChevronRightIcon } from '@radix-ui/react-icons';
import { preventHover } from '@/components/sidebar/primary/PrimaryMenubar';
import SearchPlaceholder from '@/components/sidebar/primary/SearchPlaceholder';
import type { ReactElement } from 'react';
import { useState } from 'react';

// @todo: Close submenu when user drags a component out of the sidebar.
const PrimarySubmenu = (props: {
  submenuTitle: string;
  leftIcon: string;
  children?: ReactElement;
}) => {
  const { submenuTitle, leftIcon, children } = props;
  const [open, setOpen] = useState(false);

  return (
    <Menubar.Sub open={open} onOpenChange={setOpen}>
      <Menubar.SubTrigger
        onPointerEnter={preventHover}
        onPointerMove={preventHover}
        onPointerLeave={preventHover}
        className={clsx('MenubarSubTrigger', styles.MenubarSubTrigger)}
      >
        <div className={clsx('leftSlot', styles.leftSlot)}>
          <img src={leftIcon} alt="" />
        </div>
        <div className={clsx('menuItemText', styles.menuItemText)}>
          {submenuTitle}
        </div>
        <div className={clsx('rightSlot', styles.rightSlot)}>
          <ChevronRightIcon />
        </div>
      </Menubar.SubTrigger>
      <Menubar.Portal>
        <Menubar.SubContent
          className={clsx('MenubarSubContent', styles.MenubarSubContent)}
        >
          <SearchPlaceholder />
          {children}
        </Menubar.SubContent>
      </Menubar.Portal>
    </Menubar.Sub>
  );
};

export default PrimarySubmenu;
