import { describe, expect, it } from 'vitest';

import {
  notificationsSlice,
  setActivityCenterOpen,
} from './notificationsSlice';

describe('notificationsSlice', () => {
  it('has pageOpenedAt set in initial state', () => {
    const state = notificationsSlice.getInitialState();
    expect(state.pageOpenedAt).toBeGreaterThan(0);
    expect(typeof state.pageOpenedAt).toBe('number');
  });

  it('setActivityCenterOpen toggles the state', () => {
    const initial = notificationsSlice.getInitialState();
    expect(initial.activityCenterOpen).toBe(false);

    const opened = notificationsSlice.reducer(
      initial,
      setActivityCenterOpen(true),
    );
    expect(opened.activityCenterOpen).toBe(true);

    const closed = notificationsSlice.reducer(
      opened,
      setActivityCenterOpen(false),
    );
    expect(closed.activityCenterOpen).toBe(false);
  });
});
