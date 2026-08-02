import { describe, expect, it } from 'vitest';

import {
  applyMention,
  getMentionQuery,
  splitBodyIntoSegments,
  toStoredBody,
} from '@/features/comments/mentionUtils';

describe('getMentionQuery', () => {
  it('detects a mention being typed', () => {
    expect(getMentionQuery('Ask @al', 7)).toEqual({ query: 'al', start: 4 });
    // The bare `@` opens the list with everybody.
    expect(getMentionQuery('Ask @', 5)).toEqual({ query: '', start: 4 });
    // At the very start of the field.
    expect(getMentionQuery('@bo', 3)).toEqual({ query: 'bo', start: 0 });
  });

  it('ignores an @ that does not start a word', () => {
    // An email address must not open the autocomplete.
    expect(getMentionQuery('mail me at bob@example', 22)).toBeNull();
  });

  it('ignores text before the caret only', () => {
    // The caret sits before the mention, so nothing is being typed into it.
    expect(getMentionQuery('Ask @alice today', 3)).toBeNull();
  });

  it('closes once the mention is finished', () => {
    expect(getMentionQuery('Ask @alice today', 16)).toBeNull();
  });
});

describe('applyMention', () => {
  it('replaces what was typed with the chosen name', () => {
    expect(applyMention('Ask @al', 4, 7, 'alice')).toEqual({
      text: 'Ask @alice ',
      caret: 11,
    });
  });

  it('keeps the text after the caret', () => {
    expect(applyMention('Ask @al to review', 4, 7, 'alice')).toEqual({
      text: 'Ask @alice  to review',
      caret: 11,
    });
  });
});

describe('toStoredBody', () => {
  it('converts only the names that were actually picked', () => {
    expect(toStoredBody('Ask @alice, not @bob', { alice: 5 })).toBe(
      'Ask @[user:5], not @bob',
    );
  });

  it('leaves a body with no mentions alone', () => {
    expect(toStoredBody('No mentions here', { alice: 5 })).toBe(
      'No mentions here',
    );
  });

  it('does not let a short name eat a longer one', () => {
    // Substituting the shorter name first would leave the rest of the longer
    // one stranded after the token.
    expect(toStoredBody('@ann and @annabel', { ann: 5, annabel: 6 })).toBe(
      '@[user:5] and @[user:6]',
    );
  });

  it('treats a name with regex characters literally', () => {
    expect(toStoredBody('Ask @a.b', { 'a.b': 7 })).toBe('Ask @[user:7]');
    expect(toStoredBody('Ask @axb', { 'a.b': 7 })).toBe('Ask @axb');
  });
});

describe('splitBodyIntoSegments', () => {
  it('splits tokens out of the surrounding text', () => {
    expect(
      splitBodyIntoSegments('Ask @[user:5] please', [
        { uid: 5, displayName: 'alice' },
      ]),
    ).toEqual([
      { text: 'Ask ' },
      { text: '@alice', mention: { uid: 5, displayName: 'alice' } },
      { text: ' please' },
    ]);
  });

  it('renders a deleted user as unknown rather than dropping them', () => {
    expect(
      splitBodyIntoSegments('Ask @[user:99]', [{ uid: 99, displayName: null }]),
    ).toEqual([
      { text: 'Ask ' },
      { text: '@Unknown user', mention: { uid: 99, displayName: null } },
    ]);
  });

  it('returns a body with no mentions as one segment', () => {
    expect(splitBodyIntoSegments('Nothing here', [])).toEqual([
      { text: 'Nothing here' },
    ]);
  });
});
