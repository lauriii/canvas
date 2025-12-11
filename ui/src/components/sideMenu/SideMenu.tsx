import { useCallback } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { useLocation } from 'react-router-dom';
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
import {
  selectActivePanel,
  setActivePanel,
  unsetActivePanel,
} from '@/features/ui/primaryPanelSlice';
import { hasPermission } from '@/utils/permissions';

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
  const dispatch = useAppDispatch();

  const { pathname } = useLocation();
  const params = useParams();
  const segments = pathname.split('/').filter(Boolean); // removes empty strings
  const hasActiveEditorFrame =
    (segments.includes('editor') && params.entityId !== undefined) ||
    (segments.includes('template') && params.previewEntityId !== undefined);

  /**
   * Navigate to previously edited item when opening layers or library panel
   */
  // const previouslyEdited = useAppSelector(selectPreviouslyEdited);
  // const navigate = useNavigate();
  // useEffect(() => {
  //   // This useEffect can immediately navigate to the previously edited item when the user clicks on the layers or library panel buttons.
  //   // however this currently causes a page reload which is a bit disruptive.
  //   if (
  //     !hasActiveEditorFrame &&
  //     (activePanel === 'layers' || activePanel === 'library') &&
  //     previouslyEdited?.path
  //   ) {
  //     console.log('navigate to editor');
  //     navigate(previouslyEdited.path);
  //   }
  // }, [activePanel, previouslyEdited, hasActiveEditorFrame, navigate]);
  /** Re-add this block once we allow navigating between entities without a page load  */

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
          ),
        )}
    </Flex>
  );
};

export default SideMenu;
