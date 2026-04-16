import chalk from 'chalk';
import { table } from 'table';
import * as p from '@clack/prompts';

import type { SpanningCellConfig } from 'table';
import type { Result } from '../types/Result';

/**
 * Report operation results in a table.
 */
export function reportResults(
  results: Result[],
  title: string,
  itemLabel = 'Component',
  // When true, omits status/summary and relabels details as "Operation" for dry-run display.
  { preview = false }: { preview?: boolean } = {},
): void {
  if (results.length === 0) return;

  // Sort by type (if present), then by name.
  results.sort(
    (a, b) =>
      (a.itemType ?? '').localeCompare(b.itemType ?? '') ||
      a.itemName.localeCompare(b.itemName),
  );

  const hasDetails = results.some(
    (r) => (r.details?.length ?? 0) > 0 || (r.warnings?.length ?? 0) > 0,
  );

  const hasTypes = results.some((r) => r.itemType != null);

  // Build column headers.
  const headers: string[] = [itemLabel];
  if (hasTypes) headers.push('Type');
  if (!preview) headers.push('Status');
  if (hasDetails) headers.push(preview ? 'Operation' : 'Details');

  // Build rows.
  const rows = results.map((r) => {
    const row: string[] = [
      r.warnings?.length ? `${r.itemName} ${chalk.yellow('⚠')}` : r.itemName,
    ];
    if (hasTypes) row.push(r.itemType ?? '');
    if (!preview) {
      row.push(r.success ? chalk.green('Success') : chalk.red('Failed'));
    }
    if (hasDetails) {
      const details = (r.details ?? []).map((d) =>
        d.heading ? `${chalk.underline(d.heading)}:\n${d.content}` : d.content,
      );
      const parts: string[] = [...(r.warnings ?? []), ...details];
      row.push(parts.join('\n\n'));
    }
    return row;
  });

  // Title row spans all columns.
  const colCount = headers.length;
  const titleRow = Array.from<string>({ length: colCount }).fill('');
  titleRow[0] = chalk.bold(title);

  const tableData: string[][] = [titleRow, headers, ...rows];
  const spanningCells: SpanningCellConfig[] = [
    { row: 0, col: 0, colSpan: colCount, alignment: 'center' },
  ];

  // Summary row (non-preview only).
  if (!preview) {
    const successful = results.filter((r) => r.success).length;
    const failed = results.filter((r) => !r.success).length;
    const succeededText =
      failed === 0
        ? chalk.green(`${successful} succeeded`)
        : `${successful} succeeded`;
    const failedText =
      failed > 0
        ? chalk.red(`${failed} failed`)
        : chalk.dim(`${failed} failed`);

    const summaryRow = Array.from<string>({ length: colCount }).fill('');
    summaryRow[0] = 'SUMMARY';
    summaryRow[colCount - 1] = `${succeededText}, ${failedText}`;

    tableData.push(summaryRow);
    spanningCells.push({
      row: results.length + 2,
      col: 0,
      colSpan: colCount - 1,
      alignment: 'right' as const,
    });
  }

  p.log.info(
    table(tableData, {
      spanningCells,
      columns: {
        0: { width: 60, wrapWord: true },
        ...(hasDetails
          ? { [headers.length - 1]: { width: 100, wrapWord: true } }
          : {}),
      },
    }),
  );
}
