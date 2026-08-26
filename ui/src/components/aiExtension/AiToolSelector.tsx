import clsx from 'clsx';
import { CheckIcon, CodeIcon, ReaderIcon } from '@radix-ui/react-icons';
import { Flex, Popover, Text } from '@radix-ui/themes';

import type { AiTool } from '@drupal-canvas/types';

import styles from './AiToolSelector.module.css';

interface AiToolSelectorProps {
  tools: AiTool[];
  open: boolean;
  onOpenChange: (open: boolean) => void;
  selectedTool: string | null;
  onSelect: (id: string | null) => void;
}

// Icon shown next to each tool's title/description; also reused by
// ActiveToolPill for the same tool once selected. Keyed by the real
// ai_agent config entity ids (see canvas_dev_ai.settings.yml).
export const TOOL_ICONS: Record<string, typeof ReaderIcon> = {
  canvas_page_builder_agent: ReaderIcon,
  canvas_component_agent: CodeIcon,
};

// The Tools menu for the dev AI chat. Its trigger lives in deep-chat's shadow
// DOM, so the popover anchors to a zero-size span in the light DOM instead
// (see AiToolSelector.module.css) and the trigger's onClick drives `open`.
// Single-select: choosing the active tool again clears it.
const AiToolSelector = ({
  tools,
  open,
  onOpenChange,
  selectedTool,
  onSelect,
}: AiToolSelectorProps) => (
  <Popover.Root open={open} onOpenChange={onOpenChange}>
    <Popover.Anchor className={styles.menuAnchor} />
    <Popover.Content
      side="top"
      align="end"
      sideOffset={8}
      width="240px"
      data-testid="canvas-ai-tool-selector"
    >
      <Text className={styles.heading}>Available tools</Text>
      {tools.map((tool) => {
        const isSelected = tool.id === selectedTool;
        const ToolIcon = TOOL_ICONS[tool.id];
        return (
          <button
            key={tool.id}
            type="button"
            className={clsx(styles.toolRow, isSelected && styles.selected)}
            aria-pressed={isSelected}
            onClick={() => {
              onSelect(isSelected ? null : tool.id);
              onOpenChange(false);
            }}
          >
            <Flex align="center" gap="2">
              {ToolIcon && <ToolIcon className={styles.toolIcon} />}
              <Flex direction="column" align="start" gap="1">
                <Text className={styles.toolLabel}>{tool.label}</Text>
                <Text className={styles.toolDescription}>
                  {tool.description}
                </Text>
              </Flex>
            </Flex>
            {isSelected && <CheckIcon className={styles.checkIcon} />}
          </button>
        );
      })}
    </Popover.Content>
  </Popover.Root>
);

export default AiToolSelector;
