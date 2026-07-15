/**
 * Instrumentation for the `selection-to-form-interactive` measure: the time
 * from the prop panel starting to render a new selection to its prop form
 * being interactive.
 *
 * Boundary: the start mark is placed in the panel's render phase on the
 * first render for a new selection, so the measured span covers panel
 * rendering and (on the server-form path) the form endpoint round trip; it
 * excludes the selection click handling and Redux dispatch that precede the
 * panel render. On the native path the span is one React render from cached
 * metadata. The measure is tracked as a monitored metric against the
 * recorded baseline (~300 ms on the server-form path), not as a per-commit
 * gate; the deterministic CI gate is the zero-form-request assertion.
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
