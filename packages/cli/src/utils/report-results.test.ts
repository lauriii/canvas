import chalk from 'chalk';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as p from '@clack/prompts';

import {
  COMMAND_RESULT_REPORT_OPTIONS,
  formatResultReport,
  reportResults,
  splitFailedResultsByFile,
} from './report-results';

vi.mock('@clack/prompts', () => ({
  log: {
    message: vi.fn(),
  },
  note: vi.fn(),
}));

function lastMessage(): string {
  return vi.mocked(p.log.message).mock.calls.at(-1)?.[0] ?? '';
}

describe('reportResults', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('groups plan output in Canvas resource order', () => {
    reportResults(
      [
        {
          itemName: 'article_full',
          itemType: 'Content template',
          success: true,
          details: [{ content: 'create' }],
        },
        {
          itemName: 'home',
          itemType: 'Page',
          success: true,
          details: [{ content: 'update' }],
        },
        {
          itemName: 'button',
          itemType: 'Component',
          success: true,
          details: [{ content: 'create' }],
        },
        {
          itemName: 'Inter 400 normal',
          itemType: 'Font variant',
          success: true,
          details: [{ content: 'create' }],
        },
        {
          itemName: 'header',
          itemType: 'Global region',
          success: true,
          details: [{ content: 'delete' }],
        },
      ],
      'Plan',
      'Item',
      { preview: true },
    );

    expect(lastMessage()).toBe(
      [
        chalk.bold('Plan'),
        'Components: 1 create',
        'Pages: 1 update',
        'Content templates: 1 create',
        'Global regions: 1 delete',
        'brand kit: 1 font variant create',
      ].join('\n'),
    );
  });

  it('groups mixed result output by resource type', () => {
    reportResults(
      [
        {
          itemName: 'button',
          itemType: 'Component',
          success: true,
        },
        {
          itemName: 'broken',
          itemType: 'Page',
          success: false,
          details: [{ content: 'Missing required property: title' }],
        },
      ],
      'Validation results',
      'Item',
    );

    expect(lastMessage()).toBe(
      ['Validation results', '', 'Components', '  Valid: button'].join('\n'),
    );
    expect(p.note).toHaveBeenCalledWith(
      ['✗ broken', '  Missing required property: title'].join('\n'),
      'Failed pages',
    );
  });

  it('orders asset result groups before optional content groups', () => {
    const report = formatResultReport(
      [
        {
          itemName: 'header',
          itemType: 'Global region',
          success: true,
        },
        {
          itemName: 'global.css',
          itemType: 'Asset',
          success: true,
        },
        {
          itemName: 'Home',
          itemType: 'Page',
          success: true,
        },
      ],
      'Pulled content',
      'Item',
      COMMAND_RESULT_REPORT_OPTIONS,
    );

    expect(report.lines).toEqual([
      'Assets',
      'Succeeded: global.css',
      '',
      'Pages',
      'Succeeded: Home',
      '',
      'Global regions',
      'Succeeded: header',
    ]);
  });

  it('can format flat result lines without a section title', () => {
    const report = formatResultReport(
      [
        {
          itemName: 'card',
          success: true,
          details: [{ content: 'Updated' }],
        },
        {
          itemName: 'header',
          success: true,
          details: [{ content: 'Updated' }],
        },
      ],
      'Pushed components',
      'Component',
      { showTitle: false, indent: false },
    );

    expect(report.lines).toEqual(['Updated: card, header']);
    expect(report.failureNotes).toEqual([]);
  });

  it('can format flat result lines without a success heading', () => {
    const report = formatResultReport(
      [
        {
          itemName: 'Global CSS (Tailwind CSS build)',
          itemType: 'Asset',
          success: true,
          details: [{ content: 'Pushed' }],
        },
      ],
      'Pushed assets',
      'Asset',
      { showTitle: false, indent: false, showSuccessHeading: false },
    );

    expect(report.lines).toEqual(['Global CSS (Tailwind CSS build)']);
    expect(report.failureNotes).toEqual([]);
  });

  it('can format failures inline under result output', () => {
    const report = formatResultReport(
      [
        {
          itemName: 'About',
          itemType: 'Page',
          success: true,
          details: [{ content: 'Updated' }],
        },
        {
          itemName: 'home',
          itemType: 'Page',
          success: false,
          details: [{ content: 'Missing required property: title' }],
        },
      ],
      'Pushed pages',
      'Page',
      { showTitle: false, indent: false, failureStyle: 'inline' },
    );

    expect(report.lines).toEqual([
      'Updated: About',
      '',
      'Failed',
      '  ✗ home',
      '    Missing required property: title',
    ]);
    expect(report.failureNotes).toEqual([]);
  });

  it('can format inline failures without a failure heading', () => {
    const report = formatResultReport(
      [
        {
          itemName: 'src/components/paragraph.tsx',
          itemType: 'Component',
          success: false,
          details: [{ content: 'Component must have a default export' }],
        },
      ],
      'Build failed',
      'Component',
      {
        showTitle: false,
        indent: false,
        showFailureHeading: false,
        failureStyle: 'inline',
      },
    );

    expect(report.lines).toEqual([
      '  ✗ src/components/paragraph.tsx',
      '    Component must have a default export',
    ]);
    expect(report.failureNotes).toEqual([]);
  });

  it('dedupes repeated failures for the same item by default', () => {
    const report = formatResultReport(
      [
        {
          itemName: 'components',
          itemType: 'Component',
          success: false,
          details: [
            {
              heading: 'src/components/pricing-table.tsx',
              content:
                'Line 14, Column 17: Parsing error: An identifier or keyword cannot immediately follow a numeric literal.',
            },
          ],
        },
        {
          itemName: 'components',
          itemType: 'Component',
          success: false,
          details: [
            {
              heading: 'src/components/pricing-table.tsx',
              content:
                'Line 14, Column 17: Parsing error: An identifier or keyword cannot immediately follow a numeric literal.',
            },
          ],
        },
      ],
      'Build failed',
      'Component',
      {
        showTitle: false,
        indent: false,
        failureStyle: 'inline',
      },
    );

    expect(report.lines).toEqual([
      'Failed',
      '  ✗ components',
      '    src/components/pricing-table.tsx:',
      '    Line 14, Column 17: Parsing error: An identifier or keyword cannot immediately follow a numeric literal.',
    ]);
    expect(report.failureNotes).toEqual([]);
  });

  it('keeps matching failures for different items visible', () => {
    const report = formatResultReport(
      [
        {
          itemName: 'about',
          itemType: 'Page',
          success: false,
          details: [{ content: 'Missing required property: title' }],
        },
        {
          itemName: 'home',
          itemType: 'Page',
          success: false,
          details: [{ content: 'Missing required property: title' }],
        },
      ],
      'Validation results',
      'Page',
      {
        showTitle: false,
        indent: false,
        failureStyle: 'inline',
      },
    );

    expect(report.lines).toEqual([
      'Failed',
      '  ✗ about',
      '    Missing required property: title',
      '  ✗ home',
      '    Missing required property: title',
    ]);
    expect(report.failureNotes).toEqual([]);
  });

  it('can split failed results with file headings into file-level results', () => {
    expect(
      splitFailedResultsByFile([
        {
          itemName: 'components',
          itemType: 'Component',
          success: false,
          details: [
            {
              heading: 'src/components/paragraph.tsx',
              content: 'Component must have a default export',
            },
            {
              heading: 'src/components/pricing-table.tsx',
              content: 'Component must have a default export',
            },
          ],
        },
      ]),
    ).toEqual([
      {
        itemName: 'src/components/paragraph.tsx',
        itemType: 'Component',
        success: false,
        details: [{ content: 'Component must have a default export' }],
        warnings: undefined,
      },
      {
        itemName: 'src/components/pricing-table.tsx',
        itemType: 'Component',
        success: false,
        details: [{ content: 'Component must have a default export' }],
        warnings: undefined,
      },
    ]);
  });

  it('does not split failed results with non-file headings', () => {
    const result = {
      itemName: 'hero',
      itemType: 'Component',
      success: false,
      details: [
        {
          heading: 'Error while transforming JavaScript',
          content: 'Unexpected token',
        },
      ],
    };

    expect(splitFailedResultsByFile([result])).toEqual([result]);
  });

  it('does not split failed results with dotted schema path headings', () => {
    const result = {
      itemName: 'header',
      itemType: 'Global region',
      success: false,
      details: [
        {
          heading: 'elements.e3f9c386-f998-4d33-ad98-ba95156e3780.type',
          content: 'Invalid input',
        },
      ],
    };

    expect(splitFailedResultsByFile([result])).toEqual([result]);
  });
});
