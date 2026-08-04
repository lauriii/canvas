import { useMemo, useState } from 'react';
import {
  CheckIcon,
  ChevronDownIcon,
  DotsVerticalIcon,
  MagnifyingGlassIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import {
  Box,
  Button,
  DropdownMenu,
  Flex,
  IconButton,
  Popover,
  ScrollArea,
  Separator,
  Text,
  TextField,
} from '@radix-ui/themes';

import Dialog from '@/components/Dialog';
import { formatScheduledDate } from '@/components/workspaces/utils';
import WorkspaceStatusBadge from '@/components/workspaces/WorkspaceStatusBadge';
import {
  useActivateWorkspaceMutation,
  useCreateWorkspaceMutation,
  useDeleteWorkspaceMutation,
  useGetWorkspacesQuery,
  useUnscheduleWorkspacePublishMutation,
} from '@/services/workspacesApi';
import { getWorkspacesSettings } from '@/utils/drupal-globals';

import type { Workspace } from '@/services/workspacesApi';

import styles from './WorkspaceSwitcher.module.css';

const WorkspaceSwitcher = () => {
  const workspacesSettings = getWorkspacesSettings();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [addDialogOpen, setAddDialogOpen] = useState(false);
  const [newLabel, setNewLabel] = useState('');
  const [deleteCandidate, setDeleteCandidate] = useState<Workspace | null>(
    null,
  );

  const { data: workspacesData, refetch } = useGetWorkspacesQuery(undefined, {
    skip: !workspacesSettings,
  });
  const [activateWorkspace, { isLoading: isActivating }] =
    useActivateWorkspaceMutation();
  const [createWorkspace, { isLoading: isCreating }] =
    useCreateWorkspaceMutation();
  const [deleteWorkspace, { isLoading: isDeleting }] =
    useDeleteWorkspaceMutation();
  const [unschedulePublish] = useUnscheduleWorkspacePublishMutation();

  const workspaces = useMemo(
    () => workspacesData?.data ?? [],
    [workspacesData],
  );
  const activeWorkspace = workspaces.find((ws) => ws.isActive) ?? null;

  const filteredWorkspaces = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) {
      return workspaces;
    }
    return workspaces.filter((ws) => ws.label.toLowerCase().includes(term));
  }, [search, workspaces]);

  if (!workspacesSettings) {
    return null;
  }

  const triggerLabel =
    activeWorkspace?.label ??
    workspacesSettings.activeWorkspace?.label ??
    'Select workspace';
  const triggerBadgeInfo =
    activeWorkspace ?? workspacesSettings.activeWorkspace;

  const handleOpenChange = (nextOpen: boolean) => {
    setOpen(nextOpen);
    if (!nextOpen) {
      setSearch('');
    }
  };

  const handleSelect = async (workspace: Workspace) => {
    if (workspace.isActive || isActivating) {
      setOpen(false);
      return;
    }
    try {
      await activateWorkspace(workspace.id).unwrap();
    } catch {
      // Errors surface through the global query error handling.
      return;
    }
    // Switching workspaces reloads the whole app on purpose: every cache and
    // editor state is scoped to the previously active workspace, and a full
    // reload is the deliberate v1 strategy to re-scope them.
    window.location.reload();
  };

  const handleAddWorkspace = async () => {
    const label = newLabel.trim();
    if (!label || isCreating || isActivating) {
      return;
    }
    try {
      const workspace = await createWorkspace({ label }).unwrap();
      await activateWorkspace(workspace.id).unwrap();
    } catch {
      // Errors surface through the global query error handling.
      return;
    }
    // See handleSelect: a full reload re-scopes the app to the new workspace.
    window.location.reload();
  };

  const handleDeleteWorkspace = async () => {
    if (!deleteCandidate || isDeleting) {
      return;
    }
    const wasActive = deleteCandidate.isActive;
    try {
      await deleteWorkspace(deleteCandidate.id).unwrap();
    } catch {
      // Errors surface through the global query error handling.
      return;
    }
    setDeleteCandidate(null);
    if (wasActive) {
      // Deleting the active workspace falls back to another one server-side,
      // so re-scope the app with a full reload.
      window.location.reload();
      return;
    }
    refetch();
  };

  const handleCancelSchedule = (workspace: Workspace) => {
    unschedulePublish(workspace.id);
  };

  const deletePendingCount = deleteCandidate?.pendingChangesCount ?? 0;

  return (
    <>
      <Popover.Root open={open} onOpenChange={handleOpenChange}>
        <Popover.Trigger>
          <Button
            color="gray"
            variant="soft"
            size="1"
            data-testid="canvas-workspace-switcher"
          >
            <Flex gap="2" align="center">
              {triggerLabel}
              {triggerBadgeInfo && (
                <WorkspaceStatusBadge workspace={triggerBadgeInfo} />
              )}
              <ChevronDownIcon />
            </Flex>
          </Button>
        </Popover.Trigger>
        <Popover.Content size="1" width="100vw" maxWidth="320px" align="center">
          <TextField.Root
            autoComplete="off"
            placeholder="Search workspaces…"
            aria-label="Search workspaces"
            size="1"
            mb="2"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          >
            <TextField.Slot>
              <MagnifyingGlassIcon height="16" width="16" />
            </TextField.Slot>
          </TextField.Root>
          <ScrollArea scrollbars="vertical" className={styles.list}>
            {filteredWorkspaces.length === 0 && (
              <Box p="2">
                <Text size="1" color="gray">
                  No workspaces found
                </Text>
              </Box>
            )}
            {filteredWorkspaces.map((workspace) => (
              <Flex
                key={workspace.id}
                className={styles.row}
                data-testid="canvas-workspace-item"
              >
                <button
                  type="button"
                  className={styles.rowButton}
                  onClick={() => handleSelect(workspace)}
                >
                  <Box className={styles.checkIconContainer}>
                    {workspace.isActive && <CheckIcon aria-label="Active" />}
                  </Box>
                  <Text size="1" className={styles.rowLabel}>
                    {workspace.label}
                  </Text>
                  <WorkspaceStatusBadge workspace={workspace} />
                  {workspace.scheduledPublishAt && (
                    <Text size="1" className={styles.scheduledDate}>
                      {formatScheduledDate(workspace.scheduledPublishAt)}
                    </Text>
                  )}
                </button>
                {((!workspace.isDefault && workspace.access.delete) ||
                  (workspace.scheduledPublishAt &&
                    workspace.access.publish)) && (
                  <DropdownMenu.Root>
                    <DropdownMenu.Trigger>
                      <IconButton
                        variant="ghost"
                        color="gray"
                        size="1"
                        aria-label={`More options for ${workspace.label}`}
                      >
                        <DotsVerticalIcon />
                      </IconButton>
                    </DropdownMenu.Trigger>
                    <DropdownMenu.Content>
                      {workspace.scheduledPublishAt &&
                        workspace.access.publish && (
                          <DropdownMenu.Item
                            onSelect={() => handleCancelSchedule(workspace)}
                          >
                            Cancel scheduled publish
                          </DropdownMenu.Item>
                        )}
                      {!workspace.isDefault && workspace.access.delete && (
                        <DropdownMenu.Item
                          color="red"
                          onSelect={() => {
                            setOpen(false);
                            setDeleteCandidate(workspace);
                          }}
                        >
                          Delete workspace
                        </DropdownMenu.Item>
                      )}
                    </DropdownMenu.Content>
                  </DropdownMenu.Root>
                )}
              </Flex>
            ))}
          </ScrollArea>
          <Separator size="4" my="2" />
          <Button
            variant="ghost"
            size="1"
            data-testid="canvas-workspace-add-button"
            onClick={() => {
              setOpen(false);
              setAddDialogOpen(true);
            }}
          >
            <PlusIcon />
            Add workspace
          </Button>
        </Popover.Content>
      </Popover.Root>
      <Dialog
        open={addDialogOpen}
        onOpenChange={(dialogOpen) => {
          setAddDialogOpen(dialogOpen);
          if (!dialogOpen) {
            setNewLabel('');
          }
        }}
        title="Add workspace"
        footer={{
          cancelText: 'Cancel',
          confirmText: 'Add workspace',
          onConfirm: handleAddWorkspace,
          isConfirmDisabled: !newLabel.trim(),
          isConfirmLoading: isCreating || isActivating,
        }}
      >
        <Flex direction="column" gap="2">
          <Text as="label" size="1" htmlFor="canvas-workspace-label">
            Label
          </Text>
          <TextField.Root
            id="canvas-workspace-label"
            data-testid="canvas-workspace-label-input"
            value={newLabel}
            onChange={(event) => setNewLabel(event.target.value)}
          />
        </Flex>
      </Dialog>
      <Dialog
        open={!!deleteCandidate}
        onOpenChange={(dialogOpen) => {
          if (!dialogOpen) {
            setDeleteCandidate(null);
          }
        }}
        title={`Delete ${deleteCandidate?.label ?? 'workspace'}`}
        description={`This will discard ${deletePendingCount} pending ${
          deletePendingCount === 1 ? 'change' : 'changes'
        }.`}
        footer={{
          cancelText: 'Cancel',
          confirmText: 'Delete workspace',
          onConfirm: handleDeleteWorkspace,
          isConfirmLoading: isDeleting,
          isDanger: true,
        }}
      />
    </>
  );
};

export default WorkspaceSwitcher;
