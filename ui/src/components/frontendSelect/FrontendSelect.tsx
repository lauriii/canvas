import { useEffect, useRef, useState } from 'react';
import { CheckIcon, ChevronDownIcon, GlobeIcon } from '@radix-ui/react-icons';
import {
  Box,
  Button,
  DropdownMenu,
  Flex,
  Spinner,
  Text,
} from '@radix-ui/themes';

import { useSyncComponentsMutation } from '@/services/headlessComponentSync';
import {
  getCanvasSettings,
  setCanvasHeadlessFrontend,
} from '@/utils/drupal-globals';

import type { HeadlessSettings } from '@drupal-canvas/types';

import styles from './FrontendSelect.module.css';

interface FrontendSelectProps {
  settings: HeadlessSettings;
}

const MINIMUM_SYNC_INDICATOR_DURATION = 2000;
const SYNC_INDICATOR_FADE_DURATION = 300;

type SyncIndicatorState = 'hidden' | 'visible' | 'fading';

const formatActiveFrontend = (frontendUrl: string) => {
  const frontend = new URL(frontendUrl);
  return `${frontend.host}${frontend.pathname === '/' ? '' : frontend.pathname}`;
};

const FrontendSelect = ({ settings }: FrontendSelectProps) => {
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const [syncIndicatorState, setSyncIndicatorState] =
    useState<SyncIndicatorState>('hidden');
  const [syncComponents, { isLoading: isSyncing }] =
    useSyncComponentsMutation();
  const lastStartedFrontend = useRef<string | undefined>(undefined);
  const syncStartedAt = useRef<number | null>(null);
  const fadeTimer = useRef<ReturnType<typeof setTimeout> | undefined>(
    undefined,
  );
  const hideTimer = useRef<ReturnType<typeof setTimeout> | undefined>(
    undefined,
  );
  const canSyncComponents =
    getCanvasSettings().canAdministerHeadlessFrontends === true;

  useEffect(() => {
    const clearTimers = () => {
      clearTimeout(fadeTimer.current);
      clearTimeout(hideTimer.current);
    };

    clearTimers();
    if (isSyncing) {
      syncStartedAt.current = Date.now();
      setSyncIndicatorState('visible');
      return clearTimers;
    }
    if (syncStartedAt.current === null) {
      return clearTimers;
    }

    const remaining = Math.max(
      0,
      MINIMUM_SYNC_INDICATOR_DURATION - (Date.now() - syncStartedAt.current),
    );
    fadeTimer.current = setTimeout(() => {
      setSyncIndicatorState('fading');
      hideTimer.current = setTimeout(() => {
        syncStartedAt.current = null;
        setSyncIndicatorState('hidden');
      }, SYNC_INDICATOR_FADE_DURATION);
    }, remaining);
    return clearTimers;
  }, [isSyncing]);

  useEffect(() => {
    if (
      !canSyncComponents ||
      lastStartedFrontend.current === settings.frontendUrl
    ) {
      return;
    }
    lastStartedFrontend.current = settings.frontendUrl;
    void syncComponents(settings.frontendUrl)
      .unwrap()
      .catch(() => {
        // The synchronization lifecycle reports failures through notifications.
      });
  }, [canSyncComponents, settings.frontendUrl, syncComponents]);

  const handleFrontendChange = (frontendUrl: string) => {
    if (isSyncing) {
      return;
    }
    setDropdownOpen(false);
    if (frontendUrl !== settings.frontendUrl) {
      setCanvasHeadlessFrontend(frontendUrl);
    }
  };

  return (
    <Flex align="center" gap="2">
      <DropdownMenu.Root
        open={isSyncing ? false : dropdownOpen}
        onOpenChange={(open) => {
          if (!isSyncing) {
            setDropdownOpen(open);
          }
        }}
      >
        <DropdownMenu.Trigger>
          <Button
            size="1"
            variant="soft"
            color="gray"
            data-testid="frontend-select-trigger"
            disabled={isSyncing}
          >
            <Flex gap="2" align="center">
              <GlobeIcon />
              <span
                className={styles.triggerLabel}
                title={settings.frontendUrl}
              >
                {formatActiveFrontend(settings.frontendUrl)}
              </span>
              <ChevronDownIcon width="16" height="16" />
            </Flex>
          </Button>
        </DropdownMenu.Trigger>
        <DropdownMenu.Content size="2" className={styles.dropdown}>
          {settings.frontends.map((frontendUrl, index) => (
            <Flex key={frontendUrl} align="center" className={styles.row}>
              <DropdownMenu.Item
                data-testid={`frontend-option-${index}`}
                disabled={isSyncing}
                onSelect={() => handleFrontendChange(frontendUrl)}
              >
                <Flex align="center" width="100%">
                  <Box className={styles.checkIconContainer}>
                    {frontendUrl === settings.frontendUrl && <CheckIcon />}
                  </Box>
                  <Text size="1" className={styles.frontendUrl}>
                    {frontendUrl}
                  </Text>
                </Flex>
              </DropdownMenu.Item>
            </Flex>
          ))}
        </DropdownMenu.Content>
      </DropdownMenu.Root>
      {syncIndicatorState !== 'hidden' && (
        <span
          className={`${styles.syncIndicator} ${
            syncIndicatorState === 'fading' ? styles.syncIndicatorFading : ''
          }`}
          aria-label="Synchronizing components"
          data-testid="frontend-sync-indicator"
        >
          <Spinner size="1" />
        </span>
      )}
    </Flex>
  );
};

export default FrontendSelect;
