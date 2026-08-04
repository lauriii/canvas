import { ExclamationTriangleIcon } from '@radix-ui/react-icons';
import { Box, Button, Callout, Flex } from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { extractEntityParams } from '@/services/baseQuery';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import { useActivateWorkspaceMutation } from '@/services/workspacesApi';
import { getWorkspacesSettings } from '@/utils/drupal-globals';

import styles from './WorkspaceLockNotice.module.css';

// Warns that the current content is locked by pending changes in another
// workspace. The editor stays usable underneath: this is a notice, not a
// hard block. The layout response is the authoritative per-entity source
// (editor deep links boot through the entity-less route, so the boot
// settings only cover direct entity boots).
const WorkspaceLockNotice = () => {
  // Mounted above the router, so the entity comes from the URL, exactly as
  // the API base query resolves it.
  const { entityType, entityId } = extractEntityParams(window.location.href);
  const { data: layout } = useGetPageLayoutQuery(
    entityId && entityType ? { entityId, entityType } : skipToken,
  );
  const lockedInWorkspace =
    layout?.lockedInWorkspace ?? getWorkspacesSettings()?.lockedInWorkspace;
  const [activateWorkspace, { isLoading }] = useActivateWorkspaceMutation();

  if (!lockedInWorkspace) {
    return null;
  }

  const handleSwitch = async () => {
    try {
      await activateWorkspace(lockedInWorkspace.id).unwrap();
    } catch {
      // Errors surface through the global query error handling.
      return;
    }
    // Switching workspaces reloads the whole app on purpose; see
    // WorkspaceSwitcher.
    window.location.reload();
  };

  return (
    <Box
      className={styles.container}
      data-testid="canvas-workspace-lock-notice"
    >
      <Callout.Root color="orange" size="1" variant="surface">
        <Callout.Icon>
          <ExclamationTriangleIcon />
        </Callout.Icon>
        <Flex align="center" justify="between" gap="3" flexGrow="1" wrap="wrap">
          <Callout.Text>
            This content has pending changes in the "{lockedInWorkspace.label}"
            workspace.
          </Callout.Text>
          {lockedInWorkspace.canSwitch && (
            <Button size="1" loading={isLoading} onClick={handleSwitch}>
              Switch to {lockedInWorkspace.label}
            </Button>
          )}
        </Flex>
      </Callout.Root>
    </Box>
  );
};

export default WorkspaceLockNotice;
