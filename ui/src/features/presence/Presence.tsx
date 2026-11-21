import { useEffect, useRef, useState } from 'react';
import { InfoCircledIcon } from '@radix-ui/react-icons';
import { Callout } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import {
  findConcurrentEditor,
  selectPresencePeople,
  selectPresenceRoster,
} from '@/features/presence/presenceSlice';
import usePresence from '@/features/presence/usePresence';

import styles from './Presence.module.css';

// At most one join or leave announcement per person per minute.
const ANNOUNCE_INTERVAL_MS = 60_000;

/**
 * Announces people joining and leaving to screen readers.
 */
const PresenceAnnouncer = () => {
  const people = useAppSelector(selectPresencePeople);
  const [message, setMessage] = useState('');
  const previousRef = useRef(new Map<number, string>());
  const lastAnnouncedRef = useRef(new Map<number, number>());

  useEffect(() => {
    const current = new Map(
      people.filter((p) => !p.self).map((p) => [p.uid, p.displayName]),
    );
    const previous = previousRef.current;
    const changes: string[] = [];
    const announce = (uid: number, text: string) => {
      const last = lastAnnouncedRef.current.get(uid) ?? 0;
      if (Date.now() - last >= ANNOUNCE_INTERVAL_MS) {
        lastAnnouncedRef.current.set(uid, Date.now());
        changes.push(text);
      }
    };
    current.forEach((name, uid) => {
      if (!previous.has(uid)) {
        announce(uid, `${name} joined`);
      }
    });
    previous.forEach((name, uid) => {
      if (!current.has(uid)) {
        announce(uid, `${name} left`);
      }
    });
    previousRef.current = current;
    if (changes.length) {
      setMessage(changes.join('. '));
    }
  }, [people]);

  return (
    <div className="visually-hidden" aria-live="polite">
      {message}
    </div>
  );
};

/**
 * Keeps presence running and warns about concurrent edits.
 *
 * The avatars live in the top bar.
 *
 * @see \Drupal\canvas\Presence\PresenceManager
 */
const Presence = () => {
  usePresence();
  const editor = findConcurrentEditor(useAppSelector(selectPresenceRoster));
  return (
    <>
      <PresenceAnnouncer />
      {editor && (
        <Callout.Root
          color="amber"
          size="1"
          className={styles.notice}
          data-testid="canvas-presence-notice"
        >
          <Callout.Icon>
            <InfoCircledIcon />
          </Callout.Icon>
          <Callout.Text>
            {editor.self
              ? 'You are editing this in another tab. Changes you make here may conflict with it.'
              : `${editor.displayName} is editing this. Changes you make may conflict with theirs.`}
          </Callout.Text>
        </Callout.Root>
      )}
    </>
  );
};

export default Presence;
