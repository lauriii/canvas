import { useCallback, useEffect } from 'react';
import { useParams } from 'react-router';
import { useLocation } from 'react-router-dom';
import ExtensionIcon from '@assets/icons/extension-sm.svg?react';
import TemplateIcon from '@assets/icons/template.svg?react';
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
  hidden?: boolean;
}
interface SideMenuSeparator {
  type: 'separator';
  hidden?: boolean;
}
type SideMenuItem = SideMenuButton | SideMenuSeparator;
const { drupalSettings } = window;

interface SideMenuProps {}

export const SideMenu: React.FC<SideMenuProps> = () => {
  const activePanel = useAppSelector(selectActivePanel);
  let hasLegacyExtensions = false;
  if (drupalSettings && drupalSettings.canvasExtension) {
    hasLegacyExtensions =
      Object.values(drupalSettings.canvasExtension).length > 0;
  }
  const hasExtensions =
    drupalSettings.canvas.extensionsAvailable || hasLegacyExtensions;
  const { pathname } = useLocation();
  const params = useParams();
  const segments = pathname.split('/').filter(Boolean); // removes empty strings

  const hasActiveEditorFrame =
    (segments.includes('editor') && params.entityId !== undefined) ||
    (segments.includes('template') && params.previewEntityId !== undefined);

  const dispatch = useAppDispatch();

  useEffect(() => {
    // This use effect is a safeguard to ensure that the layers and library panels won't be shown when the editorFrame isn't loaded.
    // If there is nothing being edited then the layers and library panels are not available so close the panel.
    if (
      !hasActiveEditorFrame &&
      (activePanel === 'layers' || activePanel === 'library')
    ) {
      dispatch(unsetActivePanel());
    }
  }, [activePanel, dispatch, hasActiveEditorFrame]);

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
      hidden: !hasActiveEditorFrame,
    },
    {
      type: 'button',
      id: 'layers',
      icon: <LayersIcon />,
      label: 'Layers',
      enabled: true,
      hidden: !hasActiveEditorFrame,
    },
    { type: 'separator', hidden: !hasActiveEditorFrame },
    {
      type: 'button',
      id: 'manageLibrary',
      icon: <Component1Icon />,
      label: 'Manage library',
      enabled: true,
      hidden: false,
    },
    {
      type: 'button',
      id: 'pages',
      icon: <FileTextIcon />,
      label: 'Pages',
      enabled: true,
      hidden: false,
    },
    {
      type: 'button',
      id: 'templates',
      icon: <TemplateIcon />,
      label: 'Templates',
      enabled: true,
      hidden: false,
    },
    { type: 'separator', hidden: !hasExtensions },
    {
      type: 'button',
      id: 'extensions',
      icon: <ExtensionIcon />,
      label: 'Extensions',
      enabled: true,
      hidden: !hasExtensions,
    },
  ];

  return (
    <Flex gap="2" className={styles.sideMenu} data-testid="canvas-side-menu">
      {menuItems
        .filter((item) => !item.hidden)
        .map((item, index) =>
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
