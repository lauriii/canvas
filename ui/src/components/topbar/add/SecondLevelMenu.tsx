import * as Menubar from '@radix-ui/react-menubar';
import clsx from 'clsx';
import styles from '@/components/topbar/add/AddMenu.module.css';
import type { ReactElement } from 'react';
import SecondLevelMenuTrigger from '@/components/topbar/add/SecondLevelMenuTrigger';
import { useAppSelector } from '@/app/hooks';
import { selectIsHidden } from '@/features/ui/addMenuSlice';

const SecondLevelMenu = (props: {
  submenuTitle: string;
  leftIcon: string;
  children?: ReactElement;
  value: string;
}) => {
  const { submenuTitle, leftIcon, children, value } = props;
  const isHidden = useAppSelector(selectIsHidden);

  return (
    <Menubar.Menu value={value}>
      <SecondLevelMenuTrigger
        submenuTitle={submenuTitle}
        leftIcon={leftIcon}
        value={value}
      />
      <Menubar.Portal container={document.getElementById('menuBarContainer')}>
        <Menubar.Content
          className={clsx('MenubarSubContent', styles.MenubarSubContent)}
          style={{ display: isHidden ? 'none' : 'initial' }}
        >
          {children}
        </Menubar.Content>
      </Menubar.Portal>
    </Menubar.Menu>
  );
};

export default SecondLevelMenu;
