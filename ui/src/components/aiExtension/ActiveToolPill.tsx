import clsx from 'clsx';
import { Cross2Icon } from '@radix-ui/react-icons';
import { Flex, Text, Tooltip } from '@radix-ui/themes';

import { TOOL_ICONS } from './AiToolSelector';

import type { AiTool } from '@drupal-canvas/types';

import styles from './ActiveToolPill.module.css';

interface ActiveToolPillProps {
  tool: AiTool;
  onDismiss: () => void;
  // Locks the pill: the Tool is fixed for the whole turn, so it cannot be
  // removed while one is in progress.
  disabled?: boolean;
}

// The active tool indicator, with a button that clears the selection. Rendered
// outside deep-chat so a selection change cannot re-render MemoDeepChat.
const ActiveToolPill = ({
  tool,
  onDismiss,
  disabled = false,
}: ActiveToolPillProps) => {
  const ToolIcon = TOOL_ICONS[tool.id];
  return (
    <Flex
      align="center"
      gap="2"
      className={clsx(styles.pill, disabled && styles.locked)}
      data-testid="canvas-ai-active-tool"
    >
      {ToolIcon && <ToolIcon className={styles.toolIcon} />}
      <Text className={styles.toolLabel}>{tool.label}</Text>
      <Tooltip content="Remove">
        <button
          type="button"
          aria-label="Remove the selected tool"
          className={styles.dismissButton}
          onClick={onDismiss}
          disabled={disabled}
        >
          <Cross2Icon className={styles.dismissIcon} />
        </button>
      </Tooltip>
    </Flex>
  );
};

export default ActiveToolPill;
