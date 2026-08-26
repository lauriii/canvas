import chalk from 'chalk';
import * as p from '@clack/prompts';

import type { Result } from '../types/Result';

interface ResourceGroup {
  label: string;
  unit?: string;
  unitPlural?: string;
  order: number;
}

export interface FailureNote {
  title: string;
  lines: string[];
}

const OPERATION_ORDER = ['create', 'update', 'delete', 'skip', 'unchanged'];
const CHILD_INDENT = '  ';
const DETAIL_INDENT = '    ';

interface ReportResultsOptions {
  preview?: boolean;
  showTitle?: boolean;
  indent?: boolean;
  showSuccessHeading?: boolean;
  showFailureHeading?: boolean;
  failureStyle?: 'note' | 'inline';
}

export interface FormattedResultReport {
  lines: string[];
  failureNotes: FailureNote[];
}

export const COMMAND_RESULT_REPORT_OPTIONS = {
  showTitle: false,
  indent: false,
  failureStyle: 'inline' as const,
};

function stripAnsi(value: string): string {
  let result = '';
  for (let index = 0; index < value.length; index++) {
    if (value.charCodeAt(index) === 27 && value[index + 1] === '[') {
      index += 2;
      while (index < value.length && !/[A-Za-z]/.test(value[index])) {
        index++;
      }
      continue;
    }
    result += value[index];
  }
  return result;
}

function pluralize(count: number, singular: string, plural?: string): string {
  return count === 1 ? singular : (plural ?? `${singular}s`);
}

function normalizeOperation(value: string | undefined): string | undefined {
  if (!value) return undefined;
  const normalized = stripAnsi(value).trim().toLowerCase();
  if (!normalized) return undefined;
  if (normalized.startsWith('created') || normalized.startsWith('create')) {
    return 'create';
  }
  if (normalized.startsWith('updated') || normalized.startsWith('update')) {
    return 'update';
  }
  if (normalized.startsWith('deleted') || normalized.startsWith('delete')) {
    return 'delete';
  }
  if (normalized.startsWith('skipped') || normalized.startsWith('skip')) {
    return 'skip';
  }
  if (normalized.startsWith('unchanged')) {
    return 'unchanged';
  }
  return normalized;
}

function successHeading(result: Result, title: string): string {
  const detail = result.details?.[0]?.content;
  if (detail) {
    const normalized = stripAnsi(detail).trim();
    return normalized.length > 0 ? normalized : 'Succeeded';
  }
  return title.toLowerCase().includes('validation') ? 'Valid' : 'Succeeded';
}

function getResourceGroup(type: string): ResourceGroup {
  switch (type) {
    case 'Component':
      return { label: 'Components', order: 10 };
    case 'Page':
      return { label: 'Pages', order: 20 };
    case 'Content template':
      return { label: 'Content templates', order: 30 };
    case 'Page template':
      return { label: 'Page templates', order: 40 };
    case 'Asset':
      return { label: 'Assets', order: 15 };
    case 'Dependency':
      return { label: 'Dependencies', order: 16 };
    case 'Artifact':
      return { label: 'Artifacts', order: 17 };
    case 'Font variant':
      return {
        label: 'brand kit',
        unit: 'font variant',
        unitPlural: 'font variants',
        order: 70,
      };
    default:
      return { label: pluralize(2, type), order: 100 };
  }
}

function getResultType(result: Result, itemLabel: string): string {
  return result.itemType ?? itemLabel;
}

function formatPlanCount(
  count: number,
  operation: string | undefined,
  group: ResourceGroup,
): string {
  const countText = group.unit
    ? `${count} ${pluralize(count, group.unit, group.unitPlural)}`
    : String(count);
  return operation ? `${countText} ${operation}` : countText;
}

function formatNames(names: string[]): string {
  return names.join(', ');
}

function compareResourceGroups(a: ResourceGroup, b: ResourceGroup): number {
  return a.order - b.order || a.label.localeCompare(b.label);
}

function compareOperations(a: string, b: string): number {
  const aIndex = OPERATION_ORDER.indexOf(a);
  const bIndex = OPERATION_ORDER.indexOf(b);
  return (
    (aIndex === -1 ? OPERATION_ORDER.length : aIndex) -
      (bIndex === -1 ? OPERATION_ORDER.length : bIndex) || a.localeCompare(b)
  );
}

function indentMultiline(value: string, prefix = DETAIL_INDENT): string[] {
  return value.split('\n').map((line) => `${prefix}${line}`);
}

function pushNoteDetails(
  lines: string[],
  result: Result,
  prefix = CHILD_INDENT,
): void {
  for (const warning of result.warnings ?? []) {
    lines.push(...indentMultiline(chalk.yellow(warning), prefix));
  }
  for (const detail of result.details ?? []) {
    if (detail.heading) {
      lines.push(...indentMultiline(`${stripAnsi(detail.heading)}:`, prefix));
    }
    lines.push(...indentMultiline(stripAnsi(detail.content), prefix));
  }
}

function failedTitleFor(group: ResourceGroup): string {
  return `Failed ${group.label.toLowerCase()}`;
}

function isFileHeading(value: string | undefined): value is string {
  if (!value) return false;
  return /\.(?:css|jsx?|json|md|scss|tsx?|ya?ml)$/.test(
    stripAnsi(value).trim().toLowerCase(),
  );
}

export function splitFailedResultsByFile(results: Result[]): Result[] {
  return results.flatMap((result) => {
    if (result.success || !result.details?.length) {
      return [result];
    }

    if (!result.details.every((detail) => isFileHeading(detail.heading))) {
      return [result];
    }

    return result.details.map((detail, index) => ({
      ...result,
      itemName: stripAnsi(detail.heading ?? result.itemName),
      details: [{ content: detail.content }],
      warnings: index === 0 ? result.warnings : undefined,
    }));
  });
}

function formatPlan(
  results: Result[],
  title: string,
  itemLabel: string,
): string[] {
  const grouped = new Map<
    string,
    { group: ResourceGroup; operations: Map<string, number>; total: number }
  >();

  for (const result of results) {
    const type = getResultType(result, itemLabel);
    const group = getResourceGroup(type);
    const entry = grouped.get(group.label) ?? {
      group,
      operations: new Map<string, number>(),
      total: 0,
    };
    const operation = normalizeOperation(result.details?.[0]?.content);
    if (operation) {
      entry.operations.set(
        operation,
        (entry.operations.get(operation) ?? 0) + 1,
      );
    }
    entry.total += 1;
    grouped.set(group.label, entry);
  }

  const lines = [chalk.bold(title)];
  const orderedGroups = [...grouped.values()].sort((a, b) =>
    compareResourceGroups(a.group, b.group),
  );
  for (const { group, operations, total } of orderedGroups) {
    const parts =
      operations.size > 0
        ? [...operations.entries()]
            .sort(([a], [b]) => compareOperations(a, b))
            .map(([operation, count]) =>
              formatPlanCount(count, operation, group),
            )
        : [formatPlanCount(total, undefined, group)];
    lines.push(`${group.label}: ${parts.join(', ')}`);
  }

  return lines;
}

export function reportResults(
  results: Result[],
  title: string,
  itemLabel = 'Component',
  // Preview mode groups planned operations instead of reporting item results.
  options: ReportResultsOptions = {},
): void {
  if (results.length === 0) return;

  const { lines, failureNotes } = formatResultReport(
    results,
    title,
    itemLabel,
    options,
  );

  if (lines.length > 0) {
    p.log.message(lines.join('\n'));
  }
  for (const note of failureNotes) {
    p.note(note.lines.join('\n'), note.title);
  }
}

export function formatResultReport(
  results: Result[],
  title: string,
  itemLabel = 'Component',
  {
    preview = false,
    showTitle = true,
    indent = true,
    showSuccessHeading = true,
    showFailureHeading = true,
    failureStyle = 'note',
  }: ReportResultsOptions = {},
): FormattedResultReport {
  if (results.length === 0) {
    return { lines: [], failureNotes: [] };
  }

  const sortedResults = [...results].sort(
    (a, b) =>
      compareResourceGroups(
        getResourceGroup(getResultType(a, itemLabel)),
        getResourceGroup(getResultType(b, itemLabel)),
      ) || a.itemName.localeCompare(b.itemName),
  );

  if (preview) {
    const lines = formatPlan(sortedResults, title, itemLabel);
    return { lines, failureNotes: [] };
  }

  const lines = showTitle ? [title] : [];
  const failureNotes: FailureNote[] = [];
  const hasMultipleTypes =
    new Set(
      sortedResults.map(
        (result) => getResourceGroup(getResultType(result, itemLabel)).label,
      ),
    ).size > 1;

  if (hasMultipleTypes) {
    const groupedResults = new Map<
      string,
      { group: ResourceGroup; results: Result[] }
    >();
    for (const result of sortedResults) {
      const group = getResourceGroup(getResultType(result, itemLabel));
      const entry = groupedResults.get(group.label) ?? { group, results: [] };
      entry.results.push(result);
      groupedResults.set(group.label, entry);
    }

    for (const { group, results: groupResults } of [
      ...groupedResults.values(),
    ].sort((a, b) => compareResourceGroups(a.group, b.group))) {
      const groupLines: string[] = [];
      pushResultLines(groupLines, groupResults, title, failureNotes, group, {
        indent,
        showSuccessHeading,
        showFailureHeading,
        failureStyle,
      });
      if (groupLines.length > 0) {
        lines.push(
          ...(lines.length > 0 ? [''] : []),
          group.label,
          ...groupLines,
        );
      }
    }

    return { lines, failureNotes };
  }

  pushResultLines(
    lines,
    sortedResults,
    title,
    failureNotes,
    getResourceGroup(getResultType(sortedResults[0], itemLabel)),
    { indent, showSuccessHeading, showFailureHeading, failureStyle },
  );

  return { lines, failureNotes };
}

function pushResultLines(
  lines: string[],
  results: Result[],
  title: string,
  failureNotes: FailureNote[],
  group: ResourceGroup,
  {
    indent,
    showSuccessHeading,
    showFailureHeading,
    failureStyle,
  }: {
    indent: boolean;
    showSuccessHeading: boolean;
    showFailureHeading: boolean;
    failureStyle: 'note' | 'inline';
  },
): void {
  const succeeded = results.filter((result) => result.success);
  const failed = dedupeFailures(results.filter((result) => !result.success));
  const childIndent = indent ? CHILD_INDENT : '';
  const detailIndent = indent ? DETAIL_INDENT : CHILD_INDENT;

  const successGroups = new Map<string, string[]>();
  for (const result of succeeded) {
    const heading = successHeading(result, title);
    const names = successGroups.get(heading) ?? [];
    names.push(result.itemName);
    successGroups.set(heading, names);
  }
  for (const [heading, names] of successGroups.entries()) {
    const namesText = formatNames(names);
    lines.push(
      showSuccessHeading
        ? `${childIndent}${heading}: ${namesText}`
        : `${childIndent}${namesText}`,
    );
  }

  const warnings = succeeded.filter(
    (result) => (result.warnings?.length ?? 0) > 0,
  );
  if (warnings.length > 0) {
    lines.push('', 'Warnings');
    for (const result of warnings) {
      lines.push(`${childIndent}${chalk.yellow('!')} ${result.itemName}`);
      for (const warning of result.warnings ?? []) {
        lines.push(...indentMultiline(warning, detailIndent));
      }
    }
  }

  if (failed.length > 0) {
    if (failureStyle === 'inline') {
      if (showFailureHeading && lines.length > 0) {
        lines.push('');
      }
      if (showFailureHeading) {
        lines.push(`${childIndent}Failed`);
      }
      for (const result of failed) {
        lines.push(`${CHILD_INDENT}${chalk.red('✗')} ${result.itemName}`);
        pushNoteDetails(lines, result, DETAIL_INDENT);
      }
    } else {
      const noteLines: string[] = [];
      for (const result of failed) {
        noteLines.push(`${chalk.red('✗')} ${result.itemName}`);
        pushNoteDetails(noteLines, result, CHILD_INDENT);
      }
      failureNotes.push({ title: failedTitleFor(group), lines: noteLines });
    }
  }
}

function dedupeFailures(failed: Result[]): Result[] {
  const seen = new Set<string>();
  const deduped: Result[] = [];

  for (const result of failed) {
    const key = JSON.stringify({
      itemName: stripAnsi(result.itemName),
      details: (result.details ?? []).map((detail) => ({
        heading: stripAnsi(detail.heading ?? ''),
        content: stripAnsi(detail.content),
      })),
      warnings: (result.warnings ?? []).map(stripAnsi),
    });
    if (!seen.has(key)) {
      seen.add(key);
      deduped.push(result);
    }
  }

  return deduped;
}
