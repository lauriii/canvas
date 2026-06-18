import { useCallback } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import BrandKitIcon from '@assets/icons/brand-kit.svg?react';
import ExtensionIcon from '@assets/icons/extension-sm.svg?react';
import TemplateIcon from '@assets/icons/template.svg?react';
import {
  CodeIcon,
  FileTextIcon,
  LayersIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import { Button, Flex, Tooltip } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { unsetActiveExtension } from '@/features/extensions/extensionsSlice';
import { selectDialogOpen, setDialogClosed } from '@/features/ui/dialogSlice';
import {
  selectActivePanel,
  setActivePanel,
  unsetActivePanel,
} from '@/features/ui/primaryPanelSlice';
import { getCanvasSettings } from '@/utils/drupal-globals';
import { hasPermission } from '@/utils/permissions';

import type { PageExtension } from '@drupal-canvas/types';

import styles from './SideMenu.module.css';

interface SideMenuButton {
  type: 'button';
  id: string;
  icon: React.ReactNode;
  label: string;
  enabled?: boolean;
  hidden?: boolean;
}
interface SideMenuLink {
  type: 'link';
  id: string;
  href: string;
  icon: React.ReactNode;
  label: string;
  hidden?: boolean;
}
interface SideMenuSeparator {
  type: 'separator';
  hidden?: boolean;
}
type SideMenuItem = SideMenuButton | SideMenuLink | SideMenuSeparator;
const { drupalSettings } = window;

interface SideMenuProps {}

export const SideMenu: React.FC<SideMenuProps> = () => {
  const activePanel = useAppSelector(selectActivePanel);
  const { extension: extensionDialogOpen } = useAppSelector(selectDialogOpen);
  let hasLegacyExtensions = false;
  if (drupalSettings && drupalSettings.canvasExtension) {
    hasLegacyExtensions =
      Object.values(drupalSettings.canvasExtension).length > 0;
  }
  const hasExtensions =
    drupalSettings.canvas.extensionsAvailable || hasLegacyExtensions;
  const pageExtensions: PageExtension[] =
    getCanvasSettings()?.pageExtensions ?? [];
  const dispatch = useAppDispatch();
  const navigate = useNavigate();

  const { pathname } = useLocation();
  const params = useParams();
  const segments = pathname.split('/').filter(Boolean); // removes empty strings
  const hasActiveEditorFrame =
    (segments.includes('editor') && params.entityId !== undefined) ||
    (segments.includes('template') && params.previewEntityId !== undefined);
  const isOnExtensionPage = segments[0] === 'app';

  const closeExtension = useCallback(() => {
    if (extensionDialogOpen) {
      dispatch(setDialogClosed('extension'));
      dispatch(unsetActiveExtension());
    }
  }, [dispatch, extensionDialogOpen]);

  const handlePageExtensionClick = useCallback(() => {
    dispatch(unsetActivePanel());
    closeExtension();
  }, [dispatch, closeExtension]);

  const handleMenuClick = useCallback(
    (panelId: string) => {
      closeExtension();
      if (isOnExtensionPage) {
        dispatch(setActivePanel(panelId));
        navigate('/');
        return;
      }
      if (activePanel === panelId) {
        dispatch(unsetActivePanel());
        return;
      }
      dispatch(setActivePanel(panelId));
    },
    [dispatch, activePanel, closeExtension, isOnExtensionPage, navigate],
  );

  const menuItems: SideMenuItem[] = [
    {
      type: 'button',
      id: 'library',
      icon: <PlusIcon />,
      label: 'Library',
      enabled: true,
      hidden: false,
    },
    {
      type: 'button',
      id: 'layers',
      icon: <LayersIcon />,
      label: 'Layers',
      enabled: hasActiveEditorFrame,
      hidden: false,
    },
    { type: 'separator', hidden: false },

    {
      type: 'button',
      id: 'code',
      icon: <CodeIcon />,
      label: 'Code',
      enabled: true,
      hidden: !hasPermission('codeComponents'),
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
      hidden: !hasPermission('contentTemplates'),
    },
    {
      type: 'separator',
      hidden: !hasExtensions && pageExtensions.length === 0,
    },
    {
      type: 'button',
      id: 'extensions',
      icon: <ExtensionIcon />,
      label: 'Extensions',
      enabled: true,
      hidden: !hasExtensions,
    },
    ...pageExtensions.map(
      (ext): SideMenuLink => ({
        type: 'link',
        id: `page-ext-${ext.id}`,
        href: `/app/${ext.id}`,
        icon: ext.icon ? (
          <span
            className={styles.maskIcon}
            style={{
              maskImage: `url(${ext.icon})`,
              WebkitMaskImage: `url(${ext.icon})`,
            }}
          />
        ) : (
          <ExtensionIcon />
        ),
        label: ext.name,
        hidden: false,
      }),
    ),
    {
      type: 'button',
      id: 'brandKit',
      icon: <BrandKitIcon />,
      label: 'Brand kit',
      enabled: true,
      hidden: !hasPermission('brandKit') || !getCanvasSettings()?.devMode,
    },
  ];

  return (
    <Flex gap="2" className={styles.sideMenu} data-testid="canvas-side-menu">
      {menuItems
        .filter((item) => !item.hidden)
        .map((item, index) => {
          if (item.type === 'separator') {
            return <hr key={index} className={styles.separator} />;
          }
          if (item.type === 'link') {
            const isActive =
              pathname === item.href || pathname.startsWith(`${item.href}/`);
            return (
              <Tooltip key={item.id} content={item.label} side="right">
                <Button
                  asChild
                  variant="ghost"
                  color="gray"
                  highContrast={true}
                  className={clsx(styles.menuItem, isActive && styles.active)}
                  aria-label={item.label}
                  aria-current={isActive ? 'page' : undefined}
                >
                  <Link to={item.href} onClick={handlePageExtensionClick}>
                    {item.icon}
                  </Link>
                </Button>
              </Tooltip>
            );
          }
          return (
            <Tooltip key={item.id} content={item.label} side="right">
              <Button
                variant="ghost"
                color="gray"
                highContrast={true}
                disabled={!item.enabled}
                className={clsx(
                  styles.menuItem,
                  !item.enabled && styles.disabled,
                  activePanel === item.id && styles.active,
                )}
                onClick={
                  item.enabled ? () => handleMenuClick(item.id) : undefined
                }
                aria-label={item.label}
              >
                {item.icon}
              </Button>
            </Tooltip>
          );
        })}
    </Flex>
  );
};

export default SideMenu;
