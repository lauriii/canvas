import * as Menubar from '@radix-ui/react-menubar';
import styles from './PrimaryMenubar.module.css';
import clsx from 'clsx';
import '@/global.css';
import PlusIcon from '@assets/icons/sidebar/primary/plus.svg';
import PageIcon from '@assets/icons/sidebar/primary/page.svg';
import LayersIcon from '@assets/icons/sidebar/primary/layers.svg';
import Submenu from '@/components/sidebar/primary/sub/Submenu';
import ComponentIcon from '@assets/icons/sidebar/primary/component.svg';
import SectionIcon from '@assets/icons/sidebar/primary/section.svg';
import SearchPlaceholder from '@/components/sidebar/primary/SearchPlaceholder';
import TreeView from '@/features/layout/tree/TreeView';
import menuStyles from '@/components/sidebar/primary/PrimaryMenubar.module.css';
import TooltipComponent from '@/components/Tooltip';
import type React from 'react';
import { useRef } from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectPrimaryMenuActiveMenu,
  selectPrimaryMenuHidden,
  setPrimaryMenuActiveMenu,
} from '@/features/ui/uiSlice';
import List from '@/components/list/List';

const ADD_ELEMENT_ID = 'addElement';
const PAGES_ID = 'pages';
const LAYERS_ID = 'layers';

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
  const activeMenu = useAppSelector(selectPrimaryMenuActiveMenu);
  const isHidden = useAppSelector(selectPrimaryMenuHidden);

  // Control what is active in primary menu since the invisible overlay used for the tooltip
  // is what receives the pointer events.
  const pointerDownHandler = (
    event: React.PointerEvent<HTMLDivElement>,
    trigger: string,
  ) => {
    const e = event as unknown as Event;
    e.preventDefault();
    if (activeMenu === trigger) {
      dispatch(setPrimaryMenuActiveMenu(''));
    } else {
      dispatch(setPrimaryMenuActiveMenu(trigger));
    }
  };

  return (
    <Menubar.Root
      className={clsx('MenubarRoot', styles.MenubarRoot)}
      value={activeMenu}
      onValueChange={setPrimaryMenuActiveMenu}
      data-menu-root="primary"
    >
      <Menubar.Menu value={ADD_ELEMENT_ID}>
        <TooltipComponent content="Add element">
          <div
            onPointerDown={(e) => pointerDownHandler(e, ADD_ELEMENT_ID)}
            className={clsx('overlayForHover', styles.overlayForHover)}
            data-testid={`${ADD_ELEMENT_ID}Overlay`}
          ></div>
        </TooltipComponent>
        <Menubar.Trigger
          onPointerMove={preventHover}
          onPointerLeave={preventHover}
          onPointerEnter={preventHover}
          className={clsx('MenubarTrigger', styles.MenubarTrigger)}
          ref={addElementTriggerRef}
          data-menu-trigger={ADD_ELEMENT_ID}
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
            <Submenu submenuTitle="Default components" leftIcon={ComponentIcon}>
              <List />
            </Submenu>
            <Submenu
              submenuTitle="Custom components"
              leftIcon={ComponentIcon}
            />
            <Submenu submenuTitle="Section templates" leftIcon={SectionIcon} />
          </Menubar.Content>
        </Menubar.Portal>
      </Menubar.Menu>
      <Menubar.Menu value={PAGES_ID}>
        <TooltipComponent content="Pages">
          <div
            onPointerDown={(e) => pointerDownHandler(e, PAGES_ID)}
            className={clsx('overlayForHover', styles.overlayForHover)}
            data-testid={`${PAGES_ID}Overlay`}
          ></div>
        </TooltipComponent>
        <Menubar.Trigger
          className={clsx('MenubarTrigger', styles.MenubarTrigger)}
          onPointerMove={preventHover}
          onPointerLeave={preventHover}
          onPointerEnter={preventHover}
          ref={pagesTriggerRef}
          data-menu-trigger={PAGES_ID}
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
            <Menubar.Label className={menuStyles.MenubarLabel}>
              Placeholder
            </Menubar.Label>
          </Menubar.Content>
        </Menubar.Portal>
      </Menubar.Menu>
      <Menubar.Menu value={LAYERS_ID}>
        <TooltipComponent content="Layers">
          <div
            onPointerDown={(e) => pointerDownHandler(e, LAYERS_ID)}
            className={clsx('overlayForHover', styles.overlayForHover)}
            data-testid={`${LAYERS_ID}Overlay`}
          ></div>
        </TooltipComponent>
        <Menubar.Trigger
          className={clsx('MenubarTrigger', styles.MenubarTrigger)}
          onPointerMove={preventHover}
          onPointerLeave={preventHover}
          onPointerEnter={preventHover}
          ref={layersTriggerRef}
          data-menu-trigger={LAYERS_ID}
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
            <Menubar.Label className={menuStyles.MenubarLabel}>
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
