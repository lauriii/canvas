import { ExclamationTriangleIcon } from '@radix-ui/react-icons';
import { Badge, Flex, Tooltip } from '@radix-ui/themes';

import type { Workspace } from '@/services/workspacesApi';

// The trigger fallback from drupalSettings only knows about the default flag,
// so everything beyond it is optional.
export type WorkspaceStatusInfo = Pick<Workspace, 'isDefault'> &
  Partial<
    Pick<
      Workspace,
      | 'statusLabel'
      | 'statusIsApproved'
      | 'statusIsInitial'
      | 'scheduledPublishAt'
      | 'scheduledPublishError'
    >
  >;

interface WorkspaceStatusBadgeProps {
  workspace: WorkspaceStatusInfo;
}

const WorkspaceStatusBadge = ({ workspace }: WorkspaceStatusBadgeProps) => {
  // The badge color keys off what the state means for publishing (its ID is
  // workflow-specific): green when approved, amber while under way, gray for
  // the initial state of a non-default workspace.
  let badge = null;
  if (workspace.scheduledPublishAt) {
    badge = (
      <Badge size="1" variant="solid" color="blue">
        Scheduled
      </Badge>
    );
  } else if (workspace.statusLabel) {
    if (workspace.statusIsApproved) {
      badge = (
        <Badge size="1" variant="solid" color="green">
          {workspace.statusLabel}
        </Badge>
      );
    } else if (!workspace.statusIsInitial) {
      badge = (
        <Badge size="1" variant="solid" color="amber">
          {workspace.statusLabel}
        </Badge>
      );
    } else if (!workspace.isDefault) {
      badge = (
        <Badge size="1" variant="solid" color="gray">
          {workspace.statusLabel}
        </Badge>
      );
    }
  }

  const errorIcon = workspace.scheduledPublishError ? (
    <Tooltip content={workspace.scheduledPublishError}>
      <ExclamationTriangleIcon
        color="var(--red-9)"
        data-testid="canvas-workspace-schedule-error"
      />
    </Tooltip>
  ) : null;

  if (!badge && !errorIcon) {
    return null;
  }

  return (
    <Flex align="center" gap="1" data-testid="canvas-workspace-status-badge">
      {badge}
      {errorIcon}
    </Flex>
  );
};

export default WorkspaceStatusBadge;
