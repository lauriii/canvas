import { Cross2Icon } from '@radix-ui/react-icons';
import { Flex, Text, Tooltip } from '@radix-ui/themes';

import { TOOL_ICONS } from './AiToolSelector';

import type { AiTool } from '@drupal-canvas/types';

import styles from './ActiveToolPill.module.css';

interface ActiveToolPillProps {
  tool: AiTool;
  onDismiss: () => void;
}

// The active tool indicator, with a button that clears the selection. Rendered
// outside deep-chat so a selection change cannot re-render MemoDeepChat.
const ActiveToolPill = ({ tool, onDismiss }: ActiveToolPillProps) => {
  const ToolIcon = TOOL_ICONS[tool.id];
  return (
    <Flex align="center" gap="2" className={styles.pill}>
      {ToolIcon && <ToolIcon className={styles.toolIcon} />}
      <Text className={styles.toolLabel}>{tool.label}</Text>
      <Tooltip content="Remove">
        <button
          type="button"
          aria-label="Remove the selected tool"
          className={styles.dismissButton}
          onClick={onDismiss}
        >
          <Cross2Icon className={styles.dismissIcon} />
        </button>
      </Tooltip>
    </Flex>
  );
};

export default ActiveToolPill;
