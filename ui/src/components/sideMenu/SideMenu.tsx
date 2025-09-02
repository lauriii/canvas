import { useCallback } from 'react';
import ExtensionIcon from '@assets/icons/extension_sm.svg?react';
import {
  Component1Icon,
  FileTextIcon,
  LayersIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import { Button, Flex, Tooltip } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectActivePanel,
  setActivePanel,
  unsetActivePanel,
} from '@/features/ui/primaryPanelSlice';

import styles from './SideMenu.module.css';

interface SideMenuButton {
  type: 'button';
  id: string;
  icon: React.ReactNode;
  label: string;
  enabled?: boolean;
}
interface SideMenuSeparator {
  type: 'separator';
}
type SideMenuItem = SideMenuButton | SideMenuSeparator;
const { drupalSettings } = window;

interface SideMenuProps {}

export const SideMenu: React.FC<SideMenuProps> = () => {
  const activePanel = useAppSelector(selectActivePanel);
  let hasExtensions = false;
  if (drupalSettings && drupalSettings.canvasExtension) {
    hasExtensions = Object.values(drupalSettings.canvasExtension).length > 0;
  }

  const dispatch = useAppDispatch();

  const handleMenuClick = useCallback(
    (panelId: string) => {
      if (activePanel === panelId) {
        dispatch(unsetActivePanel());
        return;
      }
      dispatch(setActivePanel(panelId));
    },
    [dispatch, activePanel],
  );

  const menuItems: SideMenuItem[] = [
    {
      type: 'button',
      id: 'library',
      icon: <PlusIcon />,
      label: 'Add',
      enabled: true,
    },
    {
      type: 'button',
      id: 'layers',
      icon: <LayersIcon />,
      label: 'Layers',
      enabled: true,
    },
    { type: 'separator' },
    {
      type: 'button',
      id: 'manageLibrary',
      icon: <Component1Icon />,
      label: 'Manage library',
      enabled: true,
    },
    { type: 'separator' },

    {
      type: 'button',
      id: 'templates',
      icon: <FileTextIcon />,
      label: 'Templates are coming soon',
      enabled: false,
    },
  ];

  if (hasExtensions) {
    menuItems.push({ type: 'separator' });
    menuItems.push({
      type: 'button',
      id: 'extensions',
      icon: <ExtensionIcon />,
      label: 'Extensions',
      enabled: true,
    });
  }

  return (
    <Flex gap="2" className={styles.sideMenu} data-testid="canvas-side-menu">
      {menuItems.map((item, index) =>
        item.type === 'separator' ? (
          <hr key={index} className={styles.separator} />
        ) : (
          <Tooltip key={item.id} content={item.label} side="right">
            <Button
              variant="ghost"
              color="gray"
              disabled={!item.enabled}
              className={`${styles.menuItem} ${item.enabled ? '' : styles.disabled} ${activePanel === item.id ? styles.active : ''}`}
              onClick={
                item.enabled ? () => handleMenuClick(item.id) : undefined
              }
              aria-label={item.label}
            >
              {item.icon}
            </Button>
          </Tooltip>
        ),
      )}
    </Flex>
  );
};

export default SideMenu;
