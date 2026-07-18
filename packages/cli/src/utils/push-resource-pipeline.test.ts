import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
  ReportedPushError,
  runPushResourcePipeline,
} from './push-resource-pipeline';

const promptMocks = vi.hoisted(() => ({
  spinner: {
    start: vi.fn(),
    stop: vi.fn(),
    message: vi.fn(),
  },
  spinnerFactory: vi.fn(),
  logMessage: vi.fn(),
  note: vi.fn(),
}));

vi.mock('@clack/prompts', () => ({
  spinner: promptMocks.spinnerFactory,
  log: {
    message: promptMocks.logMessage,
  },
  note: promptMocks.note,
}));

const labels = {
  start: 'Pushing resources',
  validating: 'Validating resources',
  preparing: 'Preparing resources',
  pushing: 'Pushing resources',
  done: 'Pushed resources',
};

const phases = {
  validation: 'Resource validation failed',
  preparation: 'Resource preparation failed',
  push: 'Resource push failed',
};

const messages = {
  validation: 'Resource validation failed.',
  noValidItems: 'No valid resources to push.',
  push: 'Some resources failed to push.',
};

describe('runPushResourcePipeline', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    promptMocks.spinnerFactory.mockReturnValue(promptMocks.spinner);
  });

  it('pushes valid resources and reports preparation failures with push results', async () => {
    const push = vi.fn(
      async (valid: Array<{ index: number; result: string }>) =>
        valid.map((entry) => ({
          index: entry.index,
          success: true,
          result: entry.result,
        })),
    );

    const error = await runPushResourcePipeline({
      labels,
      phases,
      messages,
      itemLabel: 'Resource',
      validate: async () => [{ itemName: 'resources', success: true }],
      markStarted: vi.fn(),
      prepare: async () => ({
        valid: [{ index: 0, result: 'valid-resource' }],
        failed: [{ index: 1, error: new Error('Invalid JSON') }],
      }),
      push,
      collectResults: (pushResults, failedPreparations) => [
        ...pushResults.map((result) => ({
          itemName: result.result ?? 'unknown',
          success: result.success,
          details: [{ content: 'Created' }],
        })),
        ...failedPreparations.map((failure) => ({
          itemName: `resource-${failure.index}`,
          success: false,
          details: [{ content: failure.error.message }],
        })),
      ],
      reportOptions: {
        showTitle: false,
        indent: false,
        failureStyle: 'inline',
      },
      summary: {
        label: 'Resources',
        unit: 'resource',
      },
    }).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ReportedPushError);
    expect((error as ReportedPushError).phase).toBe(phases.push);
    expect(push).toHaveBeenCalledWith(
      [{ index: 0, result: 'valid-resource' }],
      { updateMessage: expect.any(Function) },
    );
    expect(promptMocks.spinner.stop).toHaveBeenCalledWith(
      'Pushed resources',
      2,
    );
    expect(promptMocks.logMessage).toHaveBeenCalledTimes(1);
  });

  it('can block push work when preparation failures are not recoverable', async () => {
    const push = vi.fn();

    const error = await runPushResourcePipeline({
      labels,
      phases,
      messages: {
        ...messages,
        preparation: 'Resource preparation failed.',
      },
      itemLabel: 'Resource',
      markStarted: vi.fn(),
      prepare: async () => ({
        valid: [{ index: 0, result: 'valid-resource' }],
        failed: [{ index: 1, error: new Error('Invalid JSON') }],
      }),
      failOnPreparationFailures: true,
      hasPushWork: () => true,
      push,
      collectResults: (_pushResults, failedPreparations) =>
        failedPreparations.map((failure) => ({
          itemName: `resource-${failure.index}`,
          success: false,
          details: [{ content: failure.error.message }],
        })),
      reportOptions: {
        showTitle: false,
        indent: false,
        failureStyle: 'inline',
      },
      summary: {
        label: 'Resources',
        unit: 'resource',
      },
    }).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ReportedPushError);
    expect((error as ReportedPushError).phase).toBe(phases.preparation);
    expect((error as ReportedPushError).message).toBe(
      'Resource preparation failed.',
    );
    expect(push).not.toHaveBeenCalled();
    expect(promptMocks.spinner.stop).toHaveBeenCalledWith(
      'Pushed resources',
      2,
    );
    expect(promptMocks.logMessage).toHaveBeenCalledTimes(1);
  });

  it('does not mark a resource as started when validation fails', async () => {
    const markStarted = vi.fn();
    const prepare = vi.fn();
    const push = vi.fn();

    const error = await runPushResourcePipeline({
      labels,
      phases,
      messages,
      itemLabel: 'Resource',
      validate: async () => [
        {
          itemName: 'invalid-resource',
          success: false,
          details: [{ content: 'Invalid field' }],
        },
      ],
      markStarted,
      prepare,
      push,
      collectResults: () => [],
      reportOptions: {
        showTitle: false,
        indent: false,
        failureStyle: 'inline',
      },
      summary: {
        label: 'Resources',
        unit: 'resource',
      },
    }).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ReportedPushError);
    expect((error as ReportedPushError).phase).toBe(phases.validation);
    expect(markStarted).not.toHaveBeenCalled();
    expect(prepare).not.toHaveBeenCalled();
    expect(push).not.toHaveBeenCalled();
  });

  it('can push work that does not require prepared local resources', async () => {
    const summary = await runPushResourcePipeline({
      labels,
      phases,
      messages,
      itemLabel: 'Resource',
      markStarted: vi.fn(),
      hasPushWork: () => true,
      push: vi.fn(async () => [
        {
          index: 0,
          success: true,
          result: 'deleted-resource',
        },
      ]),
      collectResults: (pushResults) =>
        pushResults.map((result) => ({
          itemName: result.result ?? 'unknown',
          success: result.success,
          details: [{ content: 'Deleted' }],
        })),
      reportOptions: {
        showTitle: false,
        indent: false,
        failureStyle: 'inline',
      },
      summary: {
        label: 'Resources',
        unit: 'resource',
      },
    });

    expect(summary).toEqual({
      label: 'Resources',
      count: 1,
      unit: 'resource',
      unitPlural: undefined,
      action: 'pushed',
    });
  });
});
