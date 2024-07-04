import * as Menubar from '@radix-ui/react-menubar';
import styles from './PrimaryMenubar.module.css';
import clsx from 'clsx';
import '@/global.css';
import PlusIcon from '@assets/icons/sidebar/primary/plus.svg';
import PageIcon from '@assets/icons/sidebar/primary/page.svg';
import LayersIcon from '@assets/icons/sidebar/primary/layers.svg';
import PrimarySubmenu from '@/components/sidebar/primary/PrimarySubmenu';
import ComponentIcon from '@assets/icons/sidebar/primary/component.svg';
import SectionIcon from '@assets/icons/sidebar/primary/section.svg';
import SearchPlaceholder from '@/components/sidebar/primary/SearchPlaceholder';
import TreeView from '@/features/layout/tree/TreeView';
import menuStyles from '@/components/sidebar/primary/PrimaryMenubar.module.css';
import List from '@/components/list/List';
import TooltipComponent from '@/components/Tooltip';
import type React from 'react';
import { useRef, useState } from 'react';

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
  const [currentOpenMenu, setCurrentOpenMenu] = useState('');

  // Because we preventHover on the trigger, we need to manually dispatch the pointerdown event
  // to trigger the tooltip on hover. An invisible overlay div
  // that sits on top of the trigger dispatches the pointerdown event.
  const pointerDownHandler = (
    event: React.PointerEvent<HTMLDivElement>,
    trigger: string,
  ) => {
    const e = event as unknown as Event;
    e.preventDefault();
    // Create a new PointerEvent
    const pointerEvent = new PointerEvent('pointerdown', {
      bubbles: true, // This event should bubble up
      cancelable: true, // This event can be cancelled
    });

    switch (trigger) {
      case ADD_ELEMENT_ID:
        addElementTriggerRef.current?.dispatchEvent(pointerEvent);
        if (currentOpenMenu !== ADD_ELEMENT_ID) {
          setCurrentOpenMenu(ADD_ELEMENT_ID);
        }
        break;
      case PAGES_ID:
        pagesTriggerRef.current?.dispatchEvent(pointerEvent);
        if (currentOpenMenu !== PAGES_ID) {
          setCurrentOpenMenu(PAGES_ID);
        }
        break;
      case LAYERS_ID:
        layersTriggerRef.current?.dispatchEvent(pointerEvent);
        if (currentOpenMenu !== LAYERS_ID) {
          setCurrentOpenMenu(LAYERS_ID);
        }
        break;
      default:
        break;
    }
  };

  return (
    <Menubar.Root
      className={clsx('MenubarRoot', styles.MenubarRoot)}
      value={currentOpenMenu}
      onValueChange={setCurrentOpenMenu}
      data-menu-root="primary"
    >
      <Menubar.Menu value={ADD_ELEMENT_ID}>
        <TooltipComponent content="Add element">
          <div
            onPointerDown={(e) => pointerDownHandler(e, ADD_ELEMENT_ID)}
            className={clsx('overlayForHover', styles.overlayForHover)}
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
          >
            <SearchPlaceholder />
            <PrimarySubmenu
              submenuTitle="Default components"
              leftIcon={ComponentIcon}
            >
              <List />
            </PrimarySubmenu>
            <PrimarySubmenu
              submenuTitle="Custom components"
              leftIcon={ComponentIcon}
            />
            <PrimarySubmenu
              submenuTitle="Section templates"
              leftIcon={SectionIcon}
            />
          </Menubar.Content>
        </Menubar.Portal>
      </Menubar.Menu>
      <Menubar.Menu value={PAGES_ID}>
        <TooltipComponent content="Pages">
          <div
            onPointerDown={(e) => pointerDownHandler(e, PAGES_ID)}
            className={clsx('overlayForHover', styles.overlayForHover)}
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
