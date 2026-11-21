import { useAppSelector } from '@/app/hooks';
import PresenceAvatar from '@/features/presence/PresenceAvatar';
import {
  presenceColor,
  selectRemoteSelections,
} from '@/features/presence/presenceSlice';

import type { PresencePerson } from '@/features/presence/presenceSlice';

import styles from './Presence.module.css';

const describeSelectors = (people: PresencePerson[]): string =>
  `Selected by ${people
    .map((person) => (person.self ? 'you in another tab' : person.displayName))
    .join(', ')}`;

/**
 * Outlines a component for the other people who selected it.
 *
 * Each person gets an outline in their color, and a round avatar badge.
 */
export const RemoteSelection = ({ uuid }: { uuid: string }) => {
  const people = useAppSelector((state) => selectRemoteSelections(state)[uuid]);
  if (!people) {
    return null;
  }
  return (
    <>
      {people.map((person, index) => (
        <div
          key={person.uid}
          className={styles.remoteOutline}
          // Offset so several people on one component stay visible.
          style={{
            outlineColor: `var(--${presenceColor(person)}-9)`,
            outlineOffset: `${index * 3}px`,
          }}
        />
      ))}
      <div
        className={styles.remoteBadges}
        title={describeSelectors(people)}
        data-testid="canvas-presence-remote-selection"
      >
        {people.map((person) => (
          <PresenceAvatar key={person.uid} person={person} />
        ))}
      </div>
    </>
  );
};

/**
 * Dots on a layer row for the other people who selected that component.
 */
export const RemoteSelectionMarkers = ({ uuid }: { uuid: string }) => {
  const people = useAppSelector((state) => selectRemoteSelections(state)[uuid]);
  if (!people) {
    return null;
  }
  return (
    <span
      className={styles.markers}
      aria-label={describeSelectors(people)}
      role="img"
    >
      {people.map((person) => (
        <span
          key={person.uid}
          className={styles.marker}
          style={{ backgroundColor: `var(--${presenceColor(person)}-9)` }}
        />
      ))}
    </span>
  );
};
