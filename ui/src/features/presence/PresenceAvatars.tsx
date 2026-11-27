import { Flex, Popover, Text, Tooltip } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import PresenceAvatar from '@/features/presence/PresenceAvatar';
import { selectPresencePeople } from '@/features/presence/presenceSlice';

import type { PresencePerson } from '@/features/presence/presenceSlice';

import styles from './Presence.module.css';

const MAX_AVATARS = 4;

export const describePerson = (person: PresencePerson): string =>
  `${person.self ? 'You (another tab)' : person.displayName}, ${person.active ? 'active' : 'idle'}`;

/**
 * Scrolls to and flashes the first component a person has selected.
 *
 * Leaves your own selection, route, and preview width alone.
 */
export const jumpToPerson = (person: PresencePerson): void => {
  for (const uuid of person.selection) {
    const overlay = document.querySelector<HTMLElement>(
      `[data-canvas-drop-item="${CSS.escape(uuid)}"]`,
    );
    if (overlay) {
      overlay.scrollIntoView({ block: 'center', behavior: 'smooth' });
      overlay.dataset.presenceFlash = 'true';
      setTimeout(() => delete overlay.dataset.presenceFlash, 1500);
      return;
    }
  }
};

const PersonAvatar = ({ person }: { person: PresencePerson }) => (
  <Tooltip content={describePerson(person)}>
    <button
      type="button"
      className={styles.avatar}
      data-idle={!person.active || undefined}
      aria-label={describePerson(person)}
      onClick={() => jumpToPerson(person)}
    >
      <PresenceAvatar person={person} />
    </button>
  </Tooltip>
);

/**
 * Avatars of the other people who have the same item open.
 */
const PresenceAvatars = () => {
  const people = useAppSelector(selectPresencePeople);
  if (people.length === 0) {
    return null;
  }
  const shown = people.slice(0, MAX_AVATARS);
  const overflow = people.slice(MAX_AVATARS);
  return (
    <Flex
      align="center"
      gap="1"
      role="group"
      aria-label="Also here"
      data-testid="canvas-presence-avatars"
    >
      {shown.map((person) => (
        <PersonAvatar key={person.uid} person={person} />
      ))}
      {overflow.length > 0 && (
        <Popover.Root>
          <Popover.Trigger>
            <button
              type="button"
              className={styles.overflow}
              aria-label={`${overflow.length} more people`}
            >
              +{overflow.length}
            </button>
          </Popover.Trigger>
          <Popover.Content size="1">
            <Flex direction="column" gap="2">
              {people.map((person) => (
                <Flex key={person.uid} align="center" gap="2">
                  <PersonAvatar person={person} />
                  <Text size="1">{describePerson(person)}</Text>
                </Flex>
              ))}
            </Flex>
          </Popover.Content>
        </Popover.Root>
      )}
    </Flex>
  );
};

export default PresenceAvatars;
