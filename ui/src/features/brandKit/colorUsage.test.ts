import { describe, expect, it } from 'vitest';

import {
  countUniqueComponentUsages,
  countUniqueCurrentAndConfigUsages,
  deduplicateUsagesByComponentUuid,
} from '@/features/brandKit/colorUsage';

import type { ColorComponentUsage } from '@/services/brandKit';

describe('colorUsage', () => {
  it('deduplicates usages by component UUID', () => {
    const usages: ColorComponentUsage[] = [
      {
        component_uuid: 'cmp-1',
        component_id: 'hero',
        label: 'Hero',
        prop_name: 'textColor',
        ancestor_labels: [],
      },
      {
        component_uuid: 'cmp-1',
        component_id: 'hero',
        label: 'Hero',
        prop_name: 'backgroundColor',
        ancestor_labels: [],
      },
      {
        component_uuid: 'cmp-2',
        component_id: 'cta',
        label: 'CTA',
        prop_name: 'textColor',
        ancestor_labels: [],
      },
    ];

    expect(deduplicateUsagesByComponentUuid(usages)).toHaveLength(2);
  });

  it('counts unique component usages per entity', () => {
    const entities = [
      {
        usages: [
          {
            component_uuid: 'cmp-1',
            component_id: 'hero',
            label: 'Hero',
            prop_name: 'textColor',
            ancestor_labels: [],
          },
          {
            component_uuid: 'cmp-1',
            component_id: 'hero',
            label: 'Hero',
            prop_name: 'backgroundColor',
            ancestor_labels: [],
          },
        ],
      },
      {
        usages: [
          {
            component_uuid: 'cmp-2',
            component_id: 'cta',
            label: 'CTA',
            prop_name: 'textColor',
            ancestor_labels: [],
          },
        ],
      },
    ];

    expect(countUniqueComponentUsages(entities)).toBe(2);
  });

  it('counts unique usages across current and config buckets', () => {
    const details = {
      current: [
        {
          title: 'Page',
          type: 'node',
          bundle: 'page',
          id: '1',
          revision_id: '10',
          usages: [
            {
              component_uuid: 'cmp-1',
              component_id: 'hero',
              label: 'Hero',
              prop_name: 'textColor',
              ancestor_labels: [],
            },
            {
              component_uuid: 'cmp-1',
              component_id: 'hero',
              label: 'Hero',
              prop_name: 'backgroundColor',
              ancestor_labels: [],
            },
          ],
        },
      ],
      config: [
        {
          title: 'Template',
          type: 'layout',
          id: 'tpl-1',
          usages: [
            {
              component_uuid: 'cmp-2',
              component_id: 'cta',
              label: 'CTA',
              prop_name: 'textColor',
              ancestor_labels: [],
            },
          ],
        },
      ],
    };

    expect(countUniqueCurrentAndConfigUsages(details)).toBe(2);
    expect(countUniqueCurrentAndConfigUsages()).toBe(0);
  });
});
