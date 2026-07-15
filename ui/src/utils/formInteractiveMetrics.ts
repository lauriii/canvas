/**
 * Instrumentation for the `selection-to-form-interactive` measure: the time
 * from selecting a component instance to its prop form being interactive.
 *
 * On the native path this is one React render from cached metadata; on the
 * server-form path it includes the form endpoint round trip. The measure is
 * tracked as a monitored metric against the recorded baseline (~300 ms on the
 * server-form path), not as a per-commit gate.
 */
const MEASURE = 'canvas:selection-to-form-interactive';
const START_MARK = `${MEASURE}:start`;

export function markSelectionStart(componentUuid: string): void {
  performance.mark(START_MARK, { detail: { componentUuid } });
}

export function measureFormInteractive(
  componentUuid: string,
  path: 'native' | 'server-form',
): void {
  const marks = performance.getEntriesByName(START_MARK, 'mark');
  const start = marks[marks.length - 1] as PerformanceMark | undefined;
  if (!start || start.detail?.componentUuid !== componentUuid) {
    return;
  }
  performance.measure(MEASURE, {
    start: START_MARK,
    detail: { componentUuid, path },
  });
  performance.clearMarks(START_MARK);
}
