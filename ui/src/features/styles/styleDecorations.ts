/**
 * @file
 * The style decorations extension point.
 *
 * Lets an extension (for example canvas_presets) add a header above the
 * settings tabs, a marker and an action to each style row, and receive style
 * changes, without reading Canvas' DOM or replacing the tab badge.
 *
 * Exposed as `drupalSettings.canvas.styleExtensions`.
 *
 * @see docs/adr/0027-component-styles-on-the-core-style-api.md D15
 */

import { useSyncExternalStore } from 'react';

import type { StyleSelection } from '@/types/Styles';

export interface StyleRowDecoration {
  /** A short marker shown next to the row, for example "Changed". */
  marker?: { label: string; tooltip?: string };
  /** An action in the row, for example "Reset to Dark". */
  action?: { label: string; onSelect: () => void };
}

export interface StyleHeaderDecoration {
  title: string;
  description?: string;
  action?: { label: string; onSelect: () => void };
}

export type StyleChangeListener = (change: {
  uuid: string;
  styles: StyleSelection;
}) => void;

interface DecorationState {
  rows: Record<string, Record<string, StyleRowDecoration>>;
  headers: Record<string, StyleHeaderDecoration | null>;
}

let state: DecorationState = { rows: {}, headers: {} };
const listeners = new Set<() => void>();
const changeListeners = new Set<StyleChangeListener>();

function emit(next: DecorationState): void {
  state = next;
  listeners.forEach((listener) => listener());
}

export const styleExtensions = {
  /** Sets the row decorations for one instance, replacing earlier ones. */
  setStyleDecorations(
    uuid: string,
    rows: Record<string, StyleRowDecoration>,
  ): void {
    emit({ ...state, rows: { ...state.rows, [uuid]: rows } });
  },
  /** Sets or clears (`null`) the header above one instance's settings tabs. */
  setComponentFormHeader(
    uuid: string,
    header: StyleHeaderDecoration | null,
  ): void {
    emit({ ...state, headers: { ...state.headers, [uuid]: header } });
  },
  /** Subscribes to style changes made in the editor. */
  subscribeToStyleChanges(listener: StyleChangeListener): () => void {
    changeListeners.add(listener);
    return () => changeListeners.delete(listener);
  },
};

/** Notifies extensions of a style change. */
export function notifyStyleChange(uuid: string, styles: StyleSelection): void {
  changeListeners.forEach((listener) => listener({ uuid, styles }));
}

const subscribe = (listener: () => void) => {
  listeners.add(listener);
  return () => listeners.delete(listener);
};

/** Reads one instance's decorations. */
export function useStyleDecorations(uuid: string | undefined): {
  rows: Record<string, StyleRowDecoration>;
  header: StyleHeaderDecoration | null;
} {
  const current = useSyncExternalStore(subscribe, () => state);
  return {
    rows: (uuid && current.rows[uuid]) || {},
    header: (uuid && current.headers[uuid]) || null,
  };
}
