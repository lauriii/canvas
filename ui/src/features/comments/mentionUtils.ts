import { escapeRegExp } from 'lodash';

import type { CommentMention } from '@/services/comments';

/** The stored form of a mention: the user's ID, never their name. */
const MENTION_TOKEN = /@\[user:(\d+)\]/g;

/**
 * What the user is currently typing after an `@`, if anything.
 *
 * Only the text before the caret matters, and only when the `@` starts a word:
 * an email address in the middle of a sentence must not open the autocomplete.
 *
 * @param text - The whole field value.
 * @param caret - The caret position within it.
 * @returns The query and where it starts, or null when not in a mention.
 */
export const getMentionQuery = (
  text: string,
  caret: number,
): { query: string; start: number } | null => {
  const before = text.slice(0, caret);
  // Letters and digits of any script: `\w` is ASCII-only, and would close the
  // list at the first accented or non-Latin character of a name.
  const match = before.match(/(^|\s)@([\p{L}\p{M}\p{N}_.-]*)$/u);
  if (!match) {
    return null;
  }
  return {
    query: match[2],
    // The `@` itself, past any whitespace the pattern also captured.
    start: caret - match[2].length - 1,
  };
};

/**
 * Replaces the mention being typed with a chosen user's name.
 *
 * @param text - The whole field value.
 * @param start - Where the `@` is.
 * @param caret - The caret position.
 * @param displayName - The chosen user's name.
 * @returns The new value and where the caret should end up.
 */
export const applyMention = (
  text: string,
  start: number,
  caret: number,
  displayName: string,
): { text: string; caret: number } => {
  const inserted = `@${displayName} `;
  return {
    text: text.slice(0, start) + inserted + text.slice(caret),
    caret: start + inserted.length,
  };
};

/**
 * Converts the names the user sees into the tokens that get stored.
 *
 * Typing is done against display names, because `@[user:123]` in a textarea is
 * unreadable. Only names the user actually picked are converted, so writing
 * "@ Monday" by hand never silently becomes a mention.
 *
 * Longer names are substituted first: without that, a user called "sam" would
 * consume the start of a mention of "samantha".
 *
 * @param text - What the user typed.
 * @param picked - Display name to user ID, for the mentions they chose.
 * @returns The body to send to the API.
 */
export const toStoredBody = (
  text: string,
  picked: Record<string, number>,
): string => {
  const names = Object.keys(picked).sort((a, b) => b.length - a.length);
  return names.reduce((body, name) => {
    // The name is user data, so it is escaped before becoming a pattern.
    const escaped = escapeRegExp(name);
    // The name must not run on into more of a word. This is a lookahead rather
    // than `\b`, which is ASCII-only and so never matches after a name ending
    // in an accented letter, a non-Latin letter or punctuation.
    return body.replace(
      new RegExp(`@${escaped}(?![\\p{L}\\p{M}\\p{N}_])`, 'gu'),
      `@[user:${picked[name]}]`,
    );
  }, text);
};

export interface CommentBodySegment {
  text: string;
  /** Set when this segment is a mention rather than plain text. */
  mention?: CommentMention;
}

/**
 * Splits a stored body into plain text and mention segments for rendering.
 *
 * @param body - The stored comment body.
 * @param mentions - The mentions the API resolved for it.
 * @returns The segments, in order.
 */
export const splitBodyIntoSegments = (
  body: string,
  mentions: CommentMention[],
): CommentBodySegment[] => {
  const byUid = new Map(mentions.map((mention) => [mention.uid, mention]));
  const segments: CommentBodySegment[] = [];
  let lastIndex = 0;
  for (const match of body.matchAll(MENTION_TOKEN)) {
    const index = match.index ?? 0;
    if (index > lastIndex) {
      segments.push({ text: body.slice(lastIndex, index) });
    }
    const uid = Number(match[1]);
    const mention = byUid.get(uid) ?? { uid, displayName: null };
    segments.push({
      text: `@${mention.displayName ?? 'Unknown user'}`,
      mention,
    });
    lastIndex = index + match[0].length;
  }
  if (lastIndex < body.length) {
    segments.push({ text: body.slice(lastIndex) });
  }
  return segments;
};

/**
 * Lists the people picked in a composer, in the shape the API reports them.
 *
 * @param picked - The picked people, as display name to user ID.
 * @returns The mentions of a comment the server has not confirmed yet.
 */
export const toMentions = (picked: Record<string, number>): CommentMention[] =>
  Object.entries(picked).map(([displayName, uid]) => ({ uid, displayName }));
