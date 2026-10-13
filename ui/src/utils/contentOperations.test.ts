import { describe, expect, it } from 'vitest';

import { getModuleOperations } from '@/utils/contentOperations';

import type { ContentStub } from '@/types/Content';

const item = (
  links: ContentStub['links'],
  linkLabels?: ContentStub['linkLabels'],
): ContentStub => ({
  title: 'Page',
  path: '/page',
  internalPath: '/page/1',
  id: 1,
  status: true,
  autoSaveLabel: null,
  autoSavePath: '/page',
  links,
  linkLabels,
});

describe('getModuleOperations', () => {
  it('returns no operations without labels', () => {
    expect(
      getModuleOperations(item({ 'edit-form': '/canvas/editor/1' })),
    ).toEqual([]);
  });

  it('returns labeled operations in order, skipping those without a link', () => {
    expect(
      getModuleOperations(
        item(
          {
            'edit-form': '/canvas/editor/1',
            my_module_first: '/first',
            my_module_second: '/second',
          },
          {
            my_module_first: 'First',
            my_module_missing: 'Missing',
            my_module_second: 'Second',
          },
        ),
      ),
    ).toEqual([
      { rel: 'my_module_first', href: '/first', label: 'First' },
      { rel: 'my_module_second', href: '/second', label: 'Second' },
    ]);
  });
});
