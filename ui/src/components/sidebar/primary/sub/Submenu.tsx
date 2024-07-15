import * as Menubar from '@radix-ui/react-menubar';
import clsx from 'clsx';
import styles from '@/components/sidebar/primary/PrimaryMenubar.module.css';
import type { ReactElement } from 'react';
import { useState } from 'react';
import SubmenuTrigger from '@/components/sidebar/primary/sub/SubmenuTrigger';
import { useAppSelector } from '@/app/hooks';
import { selectPrimaryMenuHidden } from '@/features/ui/uiSlice';
import SearchPlaceholder from '@/components/sidebar/primary/SearchPlaceholder';

const Submenu = (props: {
  submenuTitle: string;
  leftIcon: string;
  children?: ReactElement;
}) => {
  const { submenuTitle, leftIcon, children } = props;
  const [open, setOpen] = useState(false);
  const isHidden = useAppSelector(selectPrimaryMenuHidden);

  return (
    <Menubar.Sub open={open} onOpenChange={setOpen}>
      <SubmenuTrigger
        submenuTitle={submenuTitle}
        leftIcon={leftIcon}
        setOpen={setOpen}
      />
      <Menubar.Portal container={document.getElementById('menuBarContainer')}>
        <Menubar.SubContent
          className={clsx('MenubarSubContent', styles.MenubarSubContent)}
          style={{ display: isHidden ? 'none' : 'initial' }}
        >
          <SearchPlaceholder />
          {children}
        </Menubar.SubContent>
      </Menubar.Portal>
    </Menubar.Sub>
  );
};

export default Submenu;
