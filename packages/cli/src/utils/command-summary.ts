import chalk from 'chalk';

import { pluralize } from './command-helpers';

const CHILD_INDENT = '  ';

export interface CommandSummaryResource {
  label: string;
  count?: number;
  unit?: string;
  unitPlural?: string;
  action: string;
}

type CommandSummaryMarker = 'success' | 'skipped';

function formatMarker(marker: CommandSummaryMarker): string {
  return marker === 'success' ? chalk.green('✓') : chalk.yellow('-');
}

export function formatCommandSummaryResource(
  resource: CommandSummaryResource,
  marker: CommandSummaryMarker,
): string {
  if (resource.count === undefined) {
    return `${formatMarker(marker)} ${resource.label} ${resource.action}`;
  }
  const unit =
    resource.count === 1
      ? (resource.unit ?? resource.label.toLowerCase())
      : (resource.unitPlural ??
        pluralize(
          resource.count,
          resource.unit ?? resource.label.toLowerCase(),
        ));
  return `${formatMarker(marker)} ${resource.label}: ${resource.count} ${unit} ${resource.action}`;
}

export function appendCommandSummarySection(
  lines: string[],
  title: string,
  resources: CommandSummaryResource[],
  marker: CommandSummaryMarker,
): void {
  if (resources.length === 0) {
    return;
  }
  if (lines.length > 0) {
    lines.push('');
  }
  lines.push(title);
  for (const resource of resources) {
    lines.push(
      `${CHILD_INDENT}${formatCommandSummaryResource(resource, marker)}`,
    );
  }
}
