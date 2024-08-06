import * as Menubar from '@radix-ui/react-menubar';
import clsx from 'clsx';
import styles from '@/components/sidebar/primary/PrimaryMenubar.module.css';
import type { ReactElement } from 'react';
import SecondLevelMenuTrigger from '@/components/sidebar/primary/SecondLevelMenuTrigger';
import { useAppSelector } from '@/app/hooks';
import { selectIsHidden } from '@/features/ui/primaryMenuSlice';
import SearchPlaceholder from '@/components/sidebar/primary/SearchPlaceholder';

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
          <SearchPlaceholder />
          {children}
        </Menubar.Content>
      </Menubar.Portal>
    </Menubar.Menu>
  );
};

export default SecondLevelMenu;
