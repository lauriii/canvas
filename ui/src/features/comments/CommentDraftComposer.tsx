import { useLayoutEffect, useRef, useState } from 'react';
import { Button, Flex, Text } from '@radix-ui/themes';

import { clampIntoBounds } from '@/features/comments/clampIntoBounds';
import MentionTextArea from '@/features/comments/MentionTextArea';
import { toMentions, toStoredBody } from '@/features/comments/mentionUtils';
import { useCreateThreadMutation } from '@/services/comments';

import styles from './CommentPinLayer.module.css';

export interface CommentDraft {
  /** The component the click landed on. */
  componentUuid: string;
  /** Where in that component it landed, from 0 to 1 across its box. */
  offsetX: number;
  offsetY: number;
  /** Where to draw the composer, in scaled overlay pixels. */
  top: number;
  left: number;
  /** What was typed, when this is a comment the server refused. */
  failed?: { body: string; mentions: Record<string, number> };
}

interface CommentDraftComposerProps {
  draft: CommentDraft;
  surfaceType: string;
  surfaceId: string;
  onCancel: () => void;
  onPosted: () => void;
  /** Called with the draft to reopen when the server refuses the comment. */
  onFailed: (draft: CommentDraft) => void;
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
  onFailed,
}: CommentDraftComposerProps) => {
  const composerRef = useRef<HTMLDivElement>(null);
  const [nudge, setNudge] = useState({ x: 0, y: 0 });
  const [body, setBody] = useState(draft.failed?.body ?? '');
  const [mentions, setMentions] = useState<Record<string, number>>(
    draft.failed?.mentions ?? {},
  );

  // Being a few pixels off the exact point is worth far less than being
  // usable, so a composer that would hang outside the preview is pulled back.
  useLayoutEffect(() => {
    const element = composerRef.current;
    const bounds = element?.offsetParent?.getBoundingClientRect();
    if (!element || !bounds) {
      return;
    }
    const rect = element.getBoundingClientRect();
    // The measured box still carries the nudge of the previous anchor, so that
    // is taken back out first. Clamping the already-nudged box would report
    // that nothing needs correcting and drop a nudge this anchor still needs.
    setNudge((applied) =>
      clampIntoBounds(
        {
          top: rect.top - applied.y,
          left: rect.left - applied.x,
          right: rect.right - applied.x,
        },
        bounds,
      ),
    );
  }, [draft]);
  const [createThread] = useCreateThreadMutation();

  const submit = () => {
    const trimmed = body.trim();
    if (!trimmed) {
      return;
    }
    // The thread is pinned and listed the moment it is posted, so the composer
    // has done its job and closes without waiting for the server. Closing also
    // unmounts it, which is what keeps a held or repeated Enter from posting
    // twice.
    createThread({
      surfaceType,
      surfaceId,
      componentUuid: draft.componentUuid,
      offsetX: draft.offsetX,
      offsetY: draft.offsetY,
      body: toStoredBody(trimmed, mentions),
      mentions: toMentions(mentions),
    })
      .unwrap()
      // The typed text is deliberately kept so it is not lost.
      .catch(() => onFailed({ ...draft, failed: { body: trimmed, mentions } }));
    onPosted();
  };

  return (
    <div
      ref={composerRef}
      className={styles.draftComposer}
      style={{
        top: `${draft.top + nudge.y}px`,
        left: `${draft.left + nudge.x}px`,
      }}
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
      {draft.failed && (
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
          disabled={!body.trim()}
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
