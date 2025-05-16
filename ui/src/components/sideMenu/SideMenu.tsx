import { Button, Flex, Tooltip } from '@radix-ui/themes';
import { FileTextIcon, LayersIcon, PlusIcon } from '@radix-ui/react-icons';
import ExtensionIcon from '@assets/icons/extension_sm.svg?react';
import styles from './SideMenu.module.css';
import { useCallback } from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectActivePanel,
  setActivePanel,
} from '@/features/ui/primaryPanelSlice';
import { handleNonWorkingBtn } from '@/utils/function-utils';

interface SideMenuButton {
  type: 'button';
  id: string;
  icon: React.ReactNode;
  label: string;
  onClick: () => void;
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
  if (drupalSettings && drupalSettings.xbExtension) {
    hasExtensions = Object.values(drupalSettings.xbExtension).length > 0;
  }
  const dispatch = useAppDispatch();

  const handleAddClick = useCallback(() => {
    dispatch(setActivePanel('library'));
  }, [dispatch]);

  const handleLayersClick = useCallback(() => {
    dispatch(setActivePanel('layers'));
  }, [dispatch]);

  const handleTemplatesClick = useCallback(() => {
    handleNonWorkingBtn();
  }, []);

  const handleExtensionsClick = useCallback(() => {
    dispatch(setActivePanel('extensions'));
  }, [dispatch]);

  const menuItems: SideMenuItem[] = [
    {
      type: 'button',
      id: 'library',
      icon: <PlusIcon />,
      label: 'Add',
      onClick: handleAddClick,
    },
    {
      type: 'button',
      id: 'layers',
      icon: <LayersIcon />,
      label: 'Layers',
      onClick: handleLayersClick,
    },
    { type: 'separator' },
    {
      type: 'button',
      id: 'templates',
      icon: <FileTextIcon />,
      label: 'Templates',
      onClick: handleTemplatesClick,
    },
  ];

  if (hasExtensions) {
    menuItems.push({ type: 'separator' });
    menuItems.push({
      type: 'button',
      id: 'extensions',
      icon: <ExtensionIcon />,
      label: 'Extensions',
      onClick: handleExtensionsClick,
    });
  }

  return (
    <Flex gap="2" className={styles.sideMenu} data-testid="xb-side-menu">
      {menuItems.map((item, index) =>
        item.type === 'separator' ? (
          <hr key={index} className={styles.separator} />
        ) : (
          <Tooltip key={item.id} content={item.label} side="right">
            <Button
              variant="ghost"
              color="gray"
              className={`${styles.menuItem} ${activePanel === item.id ? styles.active : ''}`}
              onClick={item.onClick}
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
