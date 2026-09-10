import { InfoCircledIcon, LockOpen1Icon } from '@radix-ui/react-icons';
import { Box, Button, Callout, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import PermissionCheck from '@/components/PermissionCheck';
import { overrideSlotDefaultContent } from '@/features/layout/layoutModelSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import useMatchingContentTemplate from '@/hooks/useMatchingContentTemplate';

interface LockedSlotPanelProps {
  // The backing-field alias of the locked exposed slot the panel acts on.
  alias: string;
}

/**
 * Per-content editing: the settings panel shown for a locked exposed slot (one
 * that still shows the template's default content). It explains the content is
 * a template default and offers to **Unlock** it (materialize an entity-owned,
 * editable copy) or, permission-gated, jump to the template editor to change it
 * everywhere. @see decision 8, ux-spec Phase 8 (task 11.3).
 */
const LockedSlotPanel: React.FC<LockedSlotPanelProps> = ({ alias }) => {
  const dispatch = useAppDispatch();
  const { navigateToTemplateEditor } = useEditorNavigation();
  const viewMode = useMatchingContentTemplate();

  return (
    <Box my="2" data-testid="canvas-locked-slot-panel">
      <Callout.Root size="1" color="gray" variant="surface">
        <Callout.Icon>
          <LockOpen1Icon />
        </Callout.Icon>
        <Callout.Text>
          This slot shows default content from the template. Unlock it to
          customize it for this item, or edit the template to change it
          everywhere.
        </Callout.Text>
      </Callout.Root>
      <Flex mt="3" justify="start">
        <Button
          size="1"
          onClick={() => dispatch(overrideSlotDefaultContent(alias))}
          className="canvas-button"
          data-testid="canvas-locked-slot-unlock"
        >
          <LockOpen1Icon />
          Unlock
        </Button>
      </Flex>
      <PermissionCheck
        hasPermission="contentTemplates"
        denied={
          <Callout.Root size="1" color="blue" variant="surface" mt="3">
            <Callout.Icon>
              <InfoCircledIcon />
            </Callout.Icon>
            <Callout.Text>
              You do not have permission to edit the template.
            </Callout.Text>
          </Callout.Root>
        }
      >
        <Flex mt="3" justify="start">
          <Button
            size="1"
            variant="soft"
            disabled={!viewMode}
            onClick={() => viewMode && navigateToTemplateEditor(viewMode)}
            className="canvas-button"
          >
            Edit template
          </Button>
        </Flex>
        {!viewMode && (
          <Text size="1" color="gray" as="p" mt="2">
            Open the template from the Templates list to edit it.
          </Text>
        )}
      </PermissionCheck>
    </Box>
  );
};

export default LockedSlotPanel;
