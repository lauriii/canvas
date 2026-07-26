import { useState } from 'react';
import { Button, Flex, Text } from '@radix-ui/themes';

import MentionTextArea from '@/features/comments/MentionTextArea';
import { toStoredBody } from '@/features/comments/mentionUtils';
import { useCreateThreadMutation } from '@/services/comments';

import styles from './CommentPinLayer.module.css';

export interface CommentDraft {
  /** The component the click landed on. */
  componentUuid: string;
  /** Where to draw the composer, in scaled overlay pixels. */
  top: number;
  left: number;
}

interface CommentDraftComposerProps {
  draft: CommentDraft;
  surfaceType: string;
  surfaceId: string;
  onCancel: () => void;
  onPosted: () => void;
}

/**
 * The composer that opens where the user clicked, in comment mode.
 *
 * It is deliberately tiny: one field, Enter to post, Escape to abandon. The
 * comments panel remains the full surface for reading, replying and resolving.
 */
const CommentDraftComposer = ({
  draft,
  surfaceType,
  surfaceId,
  onCancel,
  onPosted,
}: CommentDraftComposerProps) => {
  const [body, setBody] = useState('');
  const [mentions, setMentions] = useState<Record<string, number>>({});
  const [failed, setFailed] = useState(false);
  const [createThread, { isLoading }] = useCreateThreadMutation();

  const submit = async () => {
    const trimmed = body.trim();
    if (!trimmed) {
      return;
    }
    try {
      await createThread({
        surfaceType,
        surfaceId,
        componentUuid: draft.componentUuid,
        body: toStoredBody(trimmed, mentions),
      }).unwrap();
      onPosted();
    } catch {
      // The typed text is deliberately kept so it is not lost.
      setFailed(true);
    }
  };

  return (
    <div
      className={styles.draftComposer}
      style={{ top: `${draft.top}px`, left: `${draft.left}px` }}
      data-testid="canvas-comment-draft-composer"
      // The layer above treats a click on the canvas as "place a pin here",
      // and this composer sits on that layer.
      onClick={(event) => event.stopPropagation()}
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          event.stopPropagation();
          onCancel();
        }
      }}
    >
      <MentionTextArea
        value={body}
        onChange={setBody}
        onMentionPicked={(displayName, uid) =>
          setMentions((current) => ({ ...current, [displayName]: uid }))
        }
        placeholder="Add a comment…"
        ariaLabel="Add a comment"
        testId="canvas-comment-draft-input"
        // The click that opened this composer is the user asking to type, so
        // take the caret rather than making them click a second time.
        autoFocus
        onSubmit={submit}
      />
      {failed && (
        <Text size="1" color="red" data-testid="canvas-comment-draft-error">
          Your comment could not be posted.
        </Text>
      )}
      <Flex gap="2" mt="2" justify="end">
        <Button
          size="1"
          variant="soft"
          color="gray"
          onClick={onCancel}
          data-testid="canvas-comment-draft-cancel"
        >
          Cancel
        </Button>
        <Button
          size="1"
          disabled={isLoading || !body.trim()}
          onClick={submit}
          data-testid="canvas-comment-draft-submit"
        >
          Comment
        </Button>
      </Flex>
    </div>
  );
};

export default CommentDraftComposer;
