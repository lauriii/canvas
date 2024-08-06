import * as Menubar from '@radix-ui/react-menubar';
import styles from './PrimaryMenubar.module.css';
import clsx from 'clsx';
import '@/global.css';
import PlusIcon from '@assets/icons/sidebar/primary/plus.svg';
import PageIcon from '@assets/icons/sidebar/primary/page.svg';
import LayersIcon from '@assets/icons/sidebar/primary/layers.svg';
import SearchPlaceholder from '@/components/sidebar/primary/SearchPlaceholder';
import TreeView from '@/features/layout/tree/TreeView';
import TooltipComponent from '@/components/Tooltip';
import type React from 'react';
import { useRef } from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectActiveMenu,
  selectIsHidden,
  setActiveMenu,
  setInactive,
} from '@/features/ui/primaryMenuSlice';
import SecondLevelMenubar from '@/components/sidebar/primary/SecondLevelMenubar';

export const PRIMARY_MENU_ITEMS = {
  // Level one
  ADD_ELEMENT_ID: 'addElement',
  PAGES_ID: 'pages',
  LAYERS_ID: 'layers',
  // Level two
  DEFAULT_COMPONENTS_ID: 'default',
  CUSTOM_COMPONENTS_ID: 'custom',
  SECTION_ID: 'section',
};

// Radix menus open on hover by default, so we need to override that.
export const preventHover = (event: any) => {
  const e = event as Event;
  e.preventDefault();
};

const PrimaryMenubar = () => {
  const addElementTriggerRef = useRef<HTMLButtonElement>(null);
  const pagesTriggerRef = useRef<HTMLButtonElement>(null);
  const layersTriggerRef = useRef<HTMLButtonElement>(null);
  const dispatch = useAppDispatch();
  const activeMenu = useAppSelector(selectActiveMenu);
  const isHidden = useAppSelector(selectIsHidden);

  // Control what is active in primary menu since the invisible overlay used for the tooltip
  // is what receives the pointer events.
  const clickHandler = (
    event: React.MouseEvent<HTMLDivElement>,
    trigger: string,
  ) => {
    const e = event as unknown as Event;
    e.preventDefault();
    if (activeMenu === trigger) {
      dispatch(setInactive());
    } else {
      dispatch(setActiveMenu(trigger));
    }
  };

  return (
    <Menubar.Root
      className={clsx('MenubarRoot', styles.MenubarRoot)}
      value={activeMenu}
      onValueChange={setActiveMenu}
    >
      <Menubar.Menu value={PRIMARY_MENU_ITEMS.ADD_ELEMENT_ID}>
        <TooltipComponent content="Add element">
          <div
            onClick={(e) => clickHandler(e, PRIMARY_MENU_ITEMS.ADD_ELEMENT_ID)}
            className={clsx('overlayForHover', styles.overlayForHover)}
            data-hover-overlay={PRIMARY_MENU_ITEMS.ADD_ELEMENT_ID}
          ></div>
        </TooltipComponent>
        <Menubar.Trigger
          onPointerMove={preventHover}
          onPointerLeave={preventHover}
          onPointerEnter={preventHover}
          className={clsx('MenubarTrigger', styles.MenubarTrigger)}
          ref={addElementTriggerRef}
        >
          <img src={PlusIcon} alt="plus icon in menu bar" />
        </Menubar.Trigger>
        <Menubar.Portal container={document.getElementById('menuBarContainer')}>
          <Menubar.Content
            className={clsx('MenubarContent', styles.MenubarContent)}
            align="start"
            onPointerEnter={preventHover}
            onPointerLeave={preventHover}
            style={{ display: isHidden ? 'none' : 'initial' }}
          >
            <SearchPlaceholder />
            <SecondLevelMenubar />
          </Menubar.Content>
        </Menubar.Portal>
      </Menubar.Menu>
      <Menubar.Menu value={PRIMARY_MENU_ITEMS.PAGES_ID}>
        <TooltipComponent content="Pages">
          <div
            onClick={(e) => clickHandler(e, PRIMARY_MENU_ITEMS.PAGES_ID)}
            className={clsx('overlayForHover', styles.overlayForHover)}
            data-hover-overlay={PRIMARY_MENU_ITEMS.PAGES_ID}
          ></div>
        </TooltipComponent>
        <Menubar.Trigger
          className={clsx('MenubarTrigger', styles.MenubarTrigger)}
          onPointerMove={preventHover}
          onPointerLeave={preventHover}
          onPointerEnter={preventHover}
          ref={pagesTriggerRef}
        >
          <img src={PageIcon} alt="file icon in menu bar" />
        </Menubar.Trigger>
        <Menubar.Portal container={document.getElementById('menuBarContainer')}>
          <Menubar.Content
            className={clsx('MenubarContent', styles.MenubarContent)}
            align="start"
            onPointerEnter={preventHover}
            onPointerLeave={preventHover}
          >
            <Menubar.Label className={styles.MenubarLabel}>
              Placeholder
            </Menubar.Label>
          </Menubar.Content>
        </Menubar.Portal>
      </Menubar.Menu>
      <Menubar.Menu value={PRIMARY_MENU_ITEMS.LAYERS_ID}>
        <TooltipComponent content="Layers">
          <div
            onClick={(e) => clickHandler(e, PRIMARY_MENU_ITEMS.LAYERS_ID)}
            className={clsx('overlayForHover', styles.overlayForHover)}
            data-hover-overlay={PRIMARY_MENU_ITEMS.LAYERS_ID}
          ></div>
        </TooltipComponent>
        <Menubar.Trigger
          className={clsx('MenubarTrigger', styles.MenubarTrigger)}
          onPointerMove={preventHover}
          onPointerLeave={preventHover}
          onPointerEnter={preventHover}
          ref={layersTriggerRef}
        >
          <img src={LayersIcon} alt="layers icon in menu bar" />
        </Menubar.Trigger>
        <Menubar.Portal container={document.getElementById('menuBarContainer')}>
          <Menubar.Content
            className={clsx('MenubarContent', styles.MenubarContent)}
            align="start"
            onPointerEnter={preventHover}
            onPointerLeave={preventHover}
          >
            <Menubar.Label className={styles.MenubarLabel}>
              Layers
            </Menubar.Label>
            <TreeView />
          </Menubar.Content>
        </Menubar.Portal>
      </Menubar.Menu>
    </Menubar.Root>
  );
};
export default PrimaryMenubar;
