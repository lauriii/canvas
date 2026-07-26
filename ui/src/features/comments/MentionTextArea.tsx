import { useRef, useState } from 'react';
import { Text, TextArea } from '@radix-ui/themes';

import {
  applyMention,
  getMentionQuery,
} from '@/features/comments/mentionUtils';
import useCommentSurface from '@/features/comments/useCommentSurface';
import { useGetMentionableUsersQuery } from '@/services/comments';

import type React from 'react';
import type { CommentAuthor } from '@/services/comments';

import styles from './CommentsPanel.module.css';

interface MentionTextAreaProps {
  value: string;
  onChange: (value: string) => void;
  /** Called with the display name and user ID each time one is picked. */
  onMentionPicked: (displayName: string, uid: number) => void;
  placeholder: string;
  ariaLabel: string;
  testId: string;
  autoFocus?: boolean;
  onSubmit?: () => void;
}

/**
 * A comment field whose `@` opens a list of people who can read the thread.
 *
 * Typing happens against display names rather than the `@[user:123]` tokens
 * that get stored, because the tokens are unreadable in a plain textarea. The
 * caller converts what was typed with `toStoredBody()` before posting.
 */
const MentionTextArea = ({
  value,
  onChange,
  onMentionPicked,
  placeholder,
  ariaLabel,
  testId,
  autoFocus,
  onSubmit,
}: MentionTextAreaProps) => {
  const textAreaRef = useRef<HTMLTextAreaElement>(null);
  const [mention, setMention] = useState<{
    query: string;
    start: number;
    caret: number;
  } | null>(null);
  const [highlighted, setHighlighted] = useState(0);

  // The surface is part of the request because eligibility is per surface: a
  // user who cannot view this page must not be offered on it.
  const { surfaceType, surfaceId, hasSurface } = useCommentSurface();
  const { data } = useGetMentionableUsersQuery(
    { surfaceType, surfaceId, query: mention?.query ?? '' },
    { skip: mention === null || !hasSurface },
  );
  const users = mention === null ? [] : (data?.users ?? []);

  const syncMention = (element: HTMLTextAreaElement) => {
    const caret = element.selectionStart ?? element.value.length;
    const found = getMentionQuery(element.value, caret);
    setMention(found === null ? null : { ...found, caret });
    setHighlighted(0);
  };

  const pick = (user: CommentAuthor) => {
    if (mention === null) {
      return;
    }
    const applied = applyMention(
      value,
      mention.start,
      mention.caret,
      user.displayName,
    );
    onChange(applied.text);
    onMentionPicked(user.displayName, user.uid);
    setMention(null);
    // Put the caret after the name just inserted rather than at the end.
    requestAnimationFrame(() => {
      textAreaRef.current?.focus();
      textAreaRef.current?.setSelectionRange(applied.caret, applied.caret);
    });
  };

  const handleKeyDown = (event: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (mention !== null && users.length > 0) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        setHighlighted((current) => (current + 1) % users.length);
        return;
      }
      if (event.key === 'ArrowUp') {
        event.preventDefault();
        setHighlighted(
          (current) => (current - 1 + users.length) % users.length,
        );
        return;
      }
      if (event.key === 'Enter' || event.key === 'Tab') {
        event.preventDefault();
        pick(users[highlighted]);
        return;
      }
      if (event.key === 'Escape') {
        // Only closes the list; the composer above stays open.
        event.stopPropagation();
        setMention(null);
        return;
      }
    }
    if (event.key === 'Enter' && !event.shiftKey && onSubmit) {
      event.preventDefault();
      onSubmit();
    }
  };

  return (
    <div className={styles.mentionWrapper}>
      <TextArea
        ref={textAreaRef}
        size="1"
        placeholder={placeholder}
        aria-label={ariaLabel}
        data-testid={testId}
        value={value}
        autoFocus={autoFocus}
        onChange={(event) => {
          onChange(event.target.value);
          syncMention(event.target);
        }}
        onClick={(event) => syncMention(event.currentTarget)}
        onBlur={() => {
          // Delayed so a click on an option lands before the list unmounts.
          window.setTimeout(() => setMention(null), 150);
        }}
        onKeyDown={handleKeyDown}
      />
      {mention !== null && users.length > 0 && (
        <ul
          className={styles.mentionList}
          data-testid={`${testId}-mentions`}
          role="listbox"
          aria-label="People you can mention"
        >
          {users.map((user, index) => (
            <li key={user.uid}>
              <button
                type="button"
                role="option"
                aria-selected={index === highlighted}
                className={styles.mentionOption}
                data-highlighted={index === highlighted ? 'true' : undefined}
                data-testid="canvas-comment-mention-option"
                onMouseDown={(event) => {
                  // Keeps the textarea focused so the caret survives the pick.
                  event.preventDefault();
                  pick(user);
                }}
              >
                <Text size="1">{user.displayName}</Text>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

export default MentionTextArea;
