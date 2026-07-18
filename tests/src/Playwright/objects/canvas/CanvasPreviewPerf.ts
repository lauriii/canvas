import type { Page } from '@playwright/test';

/**
 * The `canvas:preview:*` performance marks the editor records.
 *
 * @see ui/src/utils/previewPerf.ts
 */
export interface PreviewPerfMark {
  name: string;
  startTime: number;
  detail?: Record<string, unknown>;
}

/**
 * Helper for asserting on the editor's preview performance marks, so specs
 * can codify latency budgets (e.g. "an optimistic structural op paints
 * without any full-render request").
 */
export class CanvasPreviewPerf {
  constructor(private readonly page: Page) {}

  /**
   * All `canvas:preview:*` marks recorded so far, prefix stripped.
   */
  async getMarks(): Promise<PreviewPerfMark[]> {
    return this.page.evaluate(() =>
      performance
        .getEntriesByType('mark')
        .filter((entry) => entry.name.startsWith('canvas:preview:'))
        .map((entry) => ({
          name: entry.name.replace('canvas:preview:', ''),
          startTime: entry.startTime,
          detail: (entry as PerformanceMark).detail ?? undefined,
        })),
    );
  }

  async getMarksByName(name: string): Promise<PreviewPerfMark[]> {
    return (await this.getMarks()).filter((mark) => mark.name === name);
  }

  /**
   * Clears recorded marks so a spec can measure one interaction in
   * isolation.
   */
  async clearMarks(): Promise<void> {
    await this.page.evaluate(() => performance.clearMarks());
  }

  /**
   * Asserts the elapsed time between the first occurrence of two marks stays
   * within a budget, returning the measured duration for reporting.
   */
  async measureBetween(startName: string, endName: string): Promise<number> {
    const marks = await this.getMarks();
    const start = marks.find((mark) => mark.name === startName);
    const end = marks.find((mark) => mark.name === endName);
    if (!start || !end) {
      throw new Error(
        `Missing preview perf marks: ${startName} → ${endName} (have: ${marks.map((mark) => mark.name).join(', ')})`,
      );
    }
    return end.startTime - start.startTime;
  }
}
