import * as p from '@clack/prompts';

import { reportResults } from './report-results';
import { createProgressCallback } from './request-pool';

import type { Result } from '../types/Result';
import type { CommandSummaryResource } from './command-summary';

export interface PreparedPushResource<TPrepared> {
  index: number;
  result: TPrepared;
}

export interface PushOperationResult<TPushResult> {
  success: boolean;
  result?: TPushResult;
  error?: Error;
  index: number;
}

interface PushResourcePreparation<TPrepared, TPreparationFailure> {
  valid: Array<PreparedPushResource<TPrepared>>;
  failed: TPreparationFailure[];
}

type ReportResultsOptions = Parameters<typeof reportResults>[3];

interface PushResourcePipelineLabels {
  start: string;
  validating: string;
  preparing: string;
  pushing: string;
  done: string;
  empty?: string;
}

interface PushResourcePipelinePhases {
  validation: string;
  preparation: string;
  push: string;
}

interface PushResourcePipelineMessages {
  validation: string;
  preparation?: string;
  noValidItems: string;
  push: string;
}

interface PushResourcePipelineSummary<TPushResult> {
  label: string;
  unit?: string;
  unitPlural?: string;
  action?: string;
  count?: (
    results: Result[],
    pushResults: Array<PushOperationResult<TPushResult>>,
  ) => number;
}

interface PushResourcePipelineOptions<
  TPrepared,
  TPushResult,
  TPreparationFailure extends { index: number; error: Error },
> {
  labels: PushResourcePipelineLabels;
  phases: PushResourcePipelinePhases;
  messages: PushResourcePipelineMessages;
  itemLabel: string;
  validate?: () => Promise<Result[]>;
  markStarted: () => void;
  prepare?: () => Promise<
    PushResourcePreparation<TPrepared, TPreparationFailure>
  >;
  failOnPreparationFailures?: boolean;
  hasPushWork?: (valid: Array<PreparedPushResource<TPrepared>>) => boolean;
  push: (
    valid: Array<PreparedPushResource<TPrepared>>,
  ) => Promise<Array<PushOperationResult<TPushResult>>>;
  collectResults: (
    pushResults: Array<PushOperationResult<TPushResult>>,
    failedPreparations: TPreparationFailure[],
  ) => Result[];
  reportOptions?: ReportResultsOptions;
  summary: PushResourcePipelineSummary<TPushResult>;
}

export class PushPhaseError extends Error {
  constructor(
    public readonly phase: string,
    message: string,
    public readonly failedResults: Result[] = [],
  ) {
    super(message);
    this.name = 'PushPhaseError';
  }
}

export class ReportedPushError extends PushPhaseError {}

export function formatErrorMessage(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

export async function runPushResourcePipeline<
  TPrepared,
  TPushResult,
  TPreparationFailure extends { index: number; error: Error },
>({
  labels,
  phases,
  messages,
  itemLabel,
  validate,
  markStarted,
  prepare,
  failOnPreparationFailures = false,
  hasPushWork = (valid) => valid.length > 0,
  push,
  collectResults,
  reportOptions,
  summary,
}: PushResourcePipelineOptions<
  TPrepared,
  TPushResult,
  TPreparationFailure
>): Promise<CommandSummaryResource | undefined> {
  const spinner = p.spinner();
  spinner.start(labels.start);

  if (validate) {
    spinner.message(labels.validating);
    let validationResults: Result[];
    try {
      validationResults = await validate();
    } catch (error) {
      spinner.stop(labels.done, 2);
      throw new PushPhaseError(phases.validation, formatErrorMessage(error));
    }

    if (validationResults.some((result) => !result.success)) {
      spinner.stop(labels.done, 2);
      reportResults(
        validationResults,
        phases.validation,
        itemLabel,
        reportOptions,
      );
      throw new ReportedPushError(phases.validation, messages.validation);
    }
  }

  markStarted();

  let validResources: Array<PreparedPushResource<TPrepared>> = [];
  let failedPreparations: TPreparationFailure[] = [];
  if (prepare) {
    spinner.message(labels.preparing);

    let preparation: PushResourcePreparation<TPrepared, TPreparationFailure>;
    try {
      preparation = await prepare();
    } catch (error) {
      spinner.stop(labels.done, 2);
      throw new PushPhaseError(phases.preparation, formatErrorMessage(error));
    }

    validResources = preparation.valid;
    failedPreparations = preparation.failed;
  }

  if (failedPreparations.length > 0 && failOnPreparationFailures) {
    spinner.stop(labels.done, 2);
    const preparationResults = collectResults([], failedPreparations);
    reportResults(preparationResults, labels.done, itemLabel, reportOptions);
    throw new ReportedPushError(
      phases.preparation,
      messages.preparation ?? messages.noValidItems,
    );
  }

  if (!hasPushWork(validResources)) {
    spinner.stop(
      failedPreparations.length > 0
        ? labels.done
        : (labels.empty ?? labels.done),
      failedPreparations.length > 0 ? 2 : 0,
    );
    if (failedPreparations.length > 0) {
      const preparationResults = collectResults([], failedPreparations);
      reportResults(preparationResults, labels.done, itemLabel, reportOptions);
      throw new ReportedPushError(phases.preparation, messages.noValidItems);
    }
    return undefined;
  }

  const progress = createProgressCallback(
    spinner,
    labels.pushing,
    validResources.length,
  );
  spinner.message(labels.pushing);

  let pushResults: Array<PushOperationResult<TPushResult>>;
  try {
    pushResults = await push(validResources);
  } catch (error) {
    spinner.stop(labels.done, 2);
    throw new PushPhaseError(phases.push, formatErrorMessage(error));
  }

  for (const result of pushResults) {
    if (result.success) {
      progress();
    }
  }

  const results = collectResults(pushResults, failedPreparations);
  const hasFailures = results.some((result) => !result.success);
  spinner.stop(labels.done, hasFailures ? 2 : 0);

  reportResults(results, labels.done, itemLabel, reportOptions);
  if (hasFailures) {
    throw new ReportedPushError(phases.push, messages.push);
  }
  if (results.length === 0) {
    return undefined;
  }

  return {
    label: summary.label,
    count: summary.count ? summary.count(results, pushResults) : results.length,
    unit: summary.unit,
    unitPlural: summary.unitPlural,
    action: summary.action ?? 'pushed',
  };
}
