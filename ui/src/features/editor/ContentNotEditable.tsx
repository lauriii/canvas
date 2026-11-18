import { useNavigate } from 'react-router';
import { InfoCircledIcon } from '@radix-ui/react-icons';
import { Button, Callout, Flex } from '@radix-ui/themes';

import type React from 'react';

/**
 * The Layout API's 403 reason when an entity has no editable component tree.
 *
 * @see \Drupal\canvas\Access\ComponentTreeEditAccessCheck::NO_EDITABLE_TREE_REASON
 */
export const NO_EDITABLE_TREE_REASON =
  'This entity has no editable component tree.';

/**
 * Per-content editing: shown when the template of the open entity stopped
 * exposing slots, so the Layout API returns a 403.
 */
const ContentNotEditable: React.FC = () => {
  const navigate = useNavigate();
  return (
    <Flex align="center" justify="center" width="100%">
      <Flex maxWidth="400px">
        <Callout.Root color="gray">
          <Callout.Icon>
            <InfoCircledIcon />
          </Callout.Icon>
          <Callout.Text>
            This content can no longer be edited in Canvas because its template
            no longer exposes any editable areas. Any content already entered is
            preserved and reappears if a slot is exposed again.
          </Callout.Text>
          <Button mt="2" onClick={() => navigate('/editor')}>
            Back to content
          </Button>
        </Callout.Root>
      </Flex>
    </Flex>
  );
};

export default ContentNotEditable;
