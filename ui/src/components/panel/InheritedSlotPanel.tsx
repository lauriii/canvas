import { useId } from 'react';
import TemplateIcon from '@assets/icons/template.svg?react';
import { Box, Button, Callout, Flex } from '@radix-ui/themes';

import useOverrideSlot from '@/hooks/useOverrideSlot';

interface InheritedSlotPanelProps {
  // The field name of the inherited exposed slot the panel acts on.
  fieldName: string;
}

/**
 * Per-content editing: the settings panel shown for an inherited exposed slot
 * (one that still shows the template's default content). It explains the
 * content is a template default and offers to **Override** it (materialize an
 * entity-owned, editable copy).
 */
const InheritedSlotPanel: React.FC<InheritedSlotPanelProps> = ({
  fieldName,
}) => {
  const overrideSlot = useOverrideSlot();
  // Links the button to the explanation, so a screen reader that lands on the
  // button also hears what overriding does.
  const descriptionId = useId();

  return (
    <Box my="5" data-testid="canvas-inherited-slot-panel">
      <Callout.Root size="1" color="gray" variant="surface">
        <Callout.Icon>
          <TemplateIcon />
        </Callout.Icon>
        {/* Reset leaked Drupal admin p margins. */}
        <Callout.Text m="0" id={descriptionId}>
          This slot shows default content from the template. Override it to
          customize it for this item.
        </Callout.Text>
      </Callout.Root>
      <Flex mt="3" justify="start">
        <Button
          size="1"
          onClick={() => overrideSlot(fieldName)}
          aria-describedby={descriptionId}
          className="canvas-button"
          data-testid="canvas-inherited-slot-override"
        >
          Override
        </Button>
      </Flex>
    </Box>
  );
};

export default InheritedSlotPanel;
