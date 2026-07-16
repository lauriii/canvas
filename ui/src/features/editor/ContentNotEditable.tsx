import { useNavigate } from 'react-router-dom';
import { InfoCircledIcon } from '@radix-ui/react-icons';
import { Button, Callout, Flex } from '@radix-ui/themes';

import type React from 'react';

/**
 * Per-content editing: shown when the opened entity is no longer editable in
 * Canvas because its template stopped exposing slots while it was open (the
 * layout API then returns 403 "no editable component tree"). That is an expected
 * state, not a crash, so explain it calmly and offer a way back. The entity's
 * slot content is preserved (detaching a slot keeps the backing field's data).
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
