import { Avatar } from '@radix-ui/themes';

import { presenceColor } from '@/features/presence/presenceSlice';

import type { PresencePerson } from '@/features/presence/presenceSlice';

/**
 * A person's round avatar, matching the avatars in the review changes list.
 */
const PresenceAvatar = ({ person }: { person: PresencePerson }) => {
  const color = presenceColor(person);
  return (
    <Avatar
      highContrast
      size="1"
      radius="full"
      fallback={person.displayName.trim().charAt(0).toUpperCase()}
      {...(person.avatar
        ? { src: person.avatar }
        : { color, style: { border: `1px solid var(--${color}-11)` } })}
    />
  );
};

export default PresenceAvatar;
