import { createSelector, createSlice } from '@reduxjs/toolkit';

import { getAvatarInitialColor } from '@/components/review/utils';

import type { PayloadAction } from '@reduxjs/toolkit';

/**
 * Who else has the same item open in the editor.
 *
 * @see \Drupal\canvas\Controller\ApiPresenceController
 */
export interface PresenceUser {
  uid: number;
  displayName: string;
  avatar: string | null;
}

export interface PresenceSession extends PresenceUser {
  clientInstanceId: string;
  // One of the current user's other tabs.
  self: boolean;
  selection: string[];
  active: boolean;
}

export interface PresenceRoster {
  sessions: PresenceSession[];
  pendingChangesBy:
    | (PresenceUser & { clientInstanceId: string | null; updated: number })
    | null;
}

/**
 * A person in the roster: their sessions on the item, merged.
 */
export interface PresencePerson extends PresenceUser {
  self: boolean;
  active: boolean;
  selection: string[];
}

/**
 * A person's color: the same one the review changes list gives them.
 */
export const presenceColor = (person: PresenceUser) =>
  getAvatarInitialColor(person.uid);

/**
 * Groups sessions by user: one person per user, active ones first.
 *
 * Your own other tabs come last, so other people are seen first.
 */
export const groupPresenceByUser = (
  sessions: PresenceSession[],
): PresencePerson[] => {
  const people = new Map<number, PresencePerson>();
  for (const session of sessions) {
    const person = people.get(session.uid);
    if (!person) {
      people.set(session.uid, {
        uid: session.uid,
        displayName: session.displayName,
        avatar: session.avatar,
        self: session.self,
        active: session.active,
        selection: [...session.selection],
      });
      continue;
    }
    person.active = person.active || session.active;
    person.selection = [
      ...new Set([...person.selection, ...session.selection]),
    ];
  }
  return [...people.values()].sort(
    (a, b) =>
      Number(a.self) - Number(b.self) || Number(b.active) - Number(a.active),
  );
};

/**
 * The person whose unsaved changes yours may conflict with, if present.
 */
export const findConcurrentEditor = (
  roster: PresenceRoster | null,
): PresenceSession | undefined => {
  const author = roster?.pendingChangesBy;
  if (!author?.clientInstanceId) {
    return undefined;
  }
  // The roster excludes this tab, so a match is always someone else.
  return roster?.sessions.find(
    (session) => session.clientInstanceId === author.clientInstanceId,
  );
};

interface PresenceSliceState {
  roster: PresenceRoster | null;
}

const initialState: PresenceSliceState = { roster: null };

export const presenceSlice = createSlice({
  name: 'presence',
  initialState,
  reducers: {
    setPresenceRoster(state, action: PayloadAction<PresenceRoster | null>) {
      state.roster = action.payload;
    },
  },
  selectors: {
    selectPresenceRoster: (state): PresenceRoster | null => state.roster,
  },
});

export const { setPresenceRoster } = presenceSlice.actions;
export const { selectPresenceRoster } = presenceSlice.selectors;

export const selectPresencePeople = createSelector(
  [selectPresenceRoster],
  (roster): PresencePerson[] => groupPresenceByUser(roster?.sessions ?? []),
);

/**
 * Other people's selections, keyed by component UUID.
 */
export const selectRemoteSelections = createSelector(
  [selectPresencePeople],
  (people): Record<string, PresencePerson[]> => {
    const byUuid: Record<string, PresencePerson[]> = {};
    for (const person of people) {
      for (const uuid of person.selection) {
        (byUuid[uuid] ??= []).push(person);
      }
    }
    return byUuid;
  },
);
