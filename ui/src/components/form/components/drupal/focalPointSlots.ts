import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';

/**
 * Where an image's focal point control is rendered.
 *
 * Image widgets (media library items, default image previews) register an
 * empty element per image, keyed `<image prop>:<position>`. The focal point
 * widget paired with that image portals its control into it, so the focal
 * point is edited under the image rather than as a separate field.
 *
 * @see DrupalMediaLibraryItem
 * @see DefaultImagePreview
 * @see DrupalFocalPoint
 */
const slots = new Map<string, HTMLElement>();
const listeners = new Set<() => void>();
// Who renders into each slot: the most recent claimant. A form rebuilt by AJAX
// mounts new widgets without unmounting the old ones, whose portals would
// otherwise keep rendering into the same slot.
const claimants = new Map<string, symbol[]>();

const notify = () => listeners.forEach((listener) => listener());

export const focalPointSlotKey = (imageProp: string, position: number) =>
  `${imageProp}:${position}`;

export const registerFocalPointSlot = (
  key: string,
  element: HTMLElement | null,
) => {
  if (element) {
    if (slots.get(key) === element) {
      return;
    }
    slots.set(key, element);
  } else if (slots.has(key)) {
    slots.delete(key);
  } else {
    return;
  }
  notify();
};

const subscribe = (listener: () => void) => {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
};

/**
 * Claims the slot for a key and returns it while this caller holds it.
 *
 * @param key
 *   The slot key, or NULL to claim nothing.
 */
export const useFocalPointSlot = (key: string | null): HTMLElement | null => {
  const [id] = useState(() => Symbol('focal point slot claimant'));
  useEffect(() => {
    if (!key) {
      return;
    }
    claimants.set(key, [...(claimants.get(key) ?? []), id]);
    notify();
    return () => {
      claimants.set(
        key,
        (claimants.get(key) ?? []).filter((claimant) => claimant !== id),
      );
      notify();
    };
  }, [key, id]);
  const getSnapshot = useCallback(
    () =>
      key && claimants.get(key)?.at(-1) === id
        ? (slots.get(key) ?? null)
        : null,
    [key, id],
  );
  return useSyncExternalStore(subscribe, getSnapshot);
};

/**
 * Returns the image prop and position of a media library item's input name.
 *
 * For example `canvas_component_props[uuid][gallery][selection][1][target_id]`
 * gives `gallery`.
 */
export const imagePropFromInputName = (name: string): string | null =>
  name.match(
    /^canvas_component_props\[[^\]]+\]\[([^\]]+)\]\[selection\]/,
  )?.[1] ?? null;
