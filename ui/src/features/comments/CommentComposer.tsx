import { useState } from 'react';
import { Text } from '@radix-ui/themes';

import MentionTextArea from '@/features/comments/MentionTextArea';
import { toMentions, toStoredBody } from '@/features/comments/mentionUtils';

import type { ReactNode } from 'react';
import type { CommentMention } from '@/services/comments';

/** What was typed: the text, and the people picked in it by display name. */
export interface TypedComment {
  body: string;
  mentions: Record<string, number>;
}

interface CommentComposerProps {
  placeholder: string;
  ariaLabel: string;
  testId: string;
  errorMessage: string;
  errorTestId: string;
  autoFocus?: boolean;
  /** Blocks posting, on top of an empty field. */
  disabled?: boolean;
  /** A comment the server refused, to show again with its error. */
  failed?: TypedComment;
  /** Posts the comment. A rejection puts what was typed back in the field. */
  post: (
    comment: { body: string; mentions: CommentMention[] },
    typed: TypedComment,
  ) => Promise<unknown>;
  /** Renders the row under the field, given the props of its submit button. */
  children: (submit: { disabled: boolean; onClick: () => void }) => ReactNode;
}

const EMPTY: TypedComment = { body: '', mentions: {} };

/**
 * A comment field that posts on Enter or through the submit button it is given.
 */
const CommentComposer = ({
  placeholder,
  ariaLabel,
  testId,
  errorMessage,
  errorTestId,
  autoFocus,
  disabled,
  failed,
  post,
  children,
}: CommentComposerProps) => {
  const [typed, setTyped] = useState(failed ?? EMPTY);
  const [hasError, setHasError] = useState(failed !== undefined);
  const body = typed.body.trim();
  const cannotPost = disabled || !body;

  const submit = async () => {
    // Enter submits too, and a held or repeated Enter must not post twice.
    if (cannotPost) {
      return;
    }
    const posted = { ...typed, body };
    // The comment is shown at once, so the field is emptied at once too.
    setHasError(false);
    setTyped(EMPTY);
    try {
      await post(
        {
          body: toStoredBody(body, typed.mentions),
          mentions: toMentions(typed.mentions),
        },
        posted,
      );
    } catch {
      setTyped(posted);
      setHasError(true);
    }
  };

  return (
    <>
      <MentionTextArea
        value={typed.body}
        onChange={(value) =>
          setTyped((current) => ({ ...current, body: value }))
        }
        onMentionPicked={(displayName, uid) =>
          setTyped((current) => ({
            ...current,
            mentions: { ...current.mentions, [displayName]: uid },
          }))
        }
        placeholder={placeholder}
        ariaLabel={ariaLabel}
        testId={testId}
        autoFocus={autoFocus}
        onSubmit={submit}
      />
      {hasError && (
        <Text size="1" color="red" data-testid={errorTestId}>
          {errorMessage}
        </Text>
      )}
      {children({ disabled: cannotPost, onClick: submit })}
    </>
  );
};

export default CommentComposer;
