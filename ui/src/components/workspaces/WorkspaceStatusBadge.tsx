import { ExclamationTriangleIcon } from '@radix-ui/react-icons';
import { Badge, Flex, Tooltip } from '@radix-ui/themes';

import type { Workspace } from '@/services/workspacesApi';

// The trigger fallback from drupalSettings only knows about the default flag,
// so everything beyond it is optional.
export type WorkspaceStatusInfo = Pick<Workspace, 'isDefault'> &
  Partial<
    Pick<Workspace, 'status' | 'scheduledPublishAt' | 'scheduledPublishError'>
  >;

interface WorkspaceStatusBadgeProps {
  workspace: WorkspaceStatusInfo;
}

const WorkspaceStatusBadge = ({ workspace }: WorkspaceStatusBadgeProps) => {
  let badge = null;
  if (workspace.scheduledPublishAt) {
    badge = (
      <Badge size="1" variant="solid" color="blue">
        Scheduled
      </Badge>
    );
  } else if (workspace.status === 'in_review') {
    badge = (
      <Badge size="1" variant="solid" color="amber">
        In review
      </Badge>
    );
  } else if (workspace.status === 'approved') {
    badge = (
      <Badge size="1" variant="solid" color="green">
        Approved
      </Badge>
    );
  } else if (workspace.status === 'draft' && !workspace.isDefault) {
    badge = (
      <Badge size="1" variant="solid" color="gray">
        Draft
      </Badge>
    );
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
