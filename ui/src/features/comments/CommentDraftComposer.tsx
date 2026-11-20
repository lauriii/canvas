import { useLayoutEffect, useRef, useState } from 'react';
import { Button, Flex } from '@radix-ui/themes';

import { clampIntoBounds } from '@/features/comments/clampIntoBounds';
import CommentComposer from '@/features/comments/CommentComposer';
import { useCreateThreadMutation } from '@/services/comments';

import type { TypedComment } from '@/features/comments/CommentComposer';

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
  failed?: TypedComment;
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

  // A composer that would hang outside the preview is pulled back in.
  useLayoutEffect(() => {
    const element = composerRef.current;
    const bounds = element?.offsetParent?.getBoundingClientRect();
    if (!element || !bounds) {
      return;
    }
    const rect = element.getBoundingClientRect();
    // The measured box carries the nudge already applied, so that is taken
    // back out before clamping.
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

  return (
    <div
      ref={composerRef}
      className={styles.draftComposer}
      style={{
        top: `${draft.top + nudge.y}px`,
        left: `${draft.left + nudge.x}px`,
      }}
      data-testid="canvas-comment-draft-composer"
      // The layer this sits on treats a click as "place a pin here".
      onClick={(event) => event.stopPropagation()}
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          event.stopPropagation();
          onCancel();
        }
      }}
    >
      <CommentComposer
        placeholder="Add a comment…"
        ariaLabel="Add a comment"
        testId="canvas-comment-draft-input"
        errorMessage="Your comment could not be posted."
        errorTestId="canvas-comment-draft-error"
        autoFocus
        failed={draft.failed}
        post={(comment, typed) => {
          const posting = createThread({
            surfaceType,
            surfaceId,
            componentUuid: draft.componentUuid,
            offsetX: draft.offsetX,
            offsetY: draft.offsetY,
            ...comment,
          }).unwrap();
          posting.catch(() => onFailed({ ...draft, failed: typed }));
          // The thread is pinned at once, so the composer closes without
          // waiting for the server.
          onPosted();
          return posting;
        }}
      >
        {(submit) => (
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
              data-testid="canvas-comment-draft-submit"
              {...submit}
            >
              Comment
            </Button>
          </Flex>
        )}
      </CommentComposer>
    </div>
  );
};

export default CommentDraftComposer;
