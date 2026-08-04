import { LockClosedIcon } from '@radix-ui/react-icons';
import { Avatar, Badge, Box, Button, Flex, Text } from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { getAvatarInitialColor, getTimeAgo } from '@/components/review/utils';
import { extractEntityParams } from '@/services/baseQuery';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import { useActivateWorkspaceMutation } from '@/services/workspacesApi';
import { getWorkspacesSettings } from '@/utils/drupal-globals';

import styles from './WorkspaceLockNotice.module.css';

// Warns that the current content is locked by pending changes in another
// workspace, attributing the pending change to its editor. The editor stays
// usable underneath: this is a notice, not a hard block. The layout response
// is the authoritative per-entity source (editor deep links boot through the
// entity-less route, so the boot settings only cover direct entity boots).
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

  const owner = lockedInWorkspace.owner ?? null;
  const updated = lockedInWorkspace.updated ?? null;

  return (
    <Box
      className={styles.container}
      data-testid="canvas-workspace-lock-notice"
    >
      <Flex align="center" gap="3" className={styles.surface}>
        <Flex align="center" justify="center" className={styles.iconWrap}>
          <LockClosedIcon />
        </Flex>
        <Flex align="center" gap="2" flexGrow="1" wrap="wrap">
          {owner && (
            <Avatar
              size="1"
              radius="full"
              src={owner.avatar ?? undefined}
              fallback={owner.name.charAt(0).toUpperCase()}
              color={getAvatarInitialColor(0)}
              alt={owner.name}
            />
          )}
          <Text size="2">
            {owner ? `${owner.name} has` : 'This content has'} pending changes
            in
          </Text>
          <Badge size="1" color="amber" variant="soft">
            {lockedInWorkspace.label}
          </Badge>
          {updated ? (
            <Text size="1" color="gray">
              {getTimeAgo(updated)}
            </Text>
          ) : null}
        </Flex>
        {lockedInWorkspace.canSwitch && (
          <Button size="1" loading={isLoading} onClick={handleSwitch}>
            Switch workspace
          </Button>
        )}
      </Flex>
    </Box>
  );
};

export default WorkspaceLockNotice;
