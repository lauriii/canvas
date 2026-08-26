import { describe, expect, it, vi } from 'vitest';

import { stripNullableKeysForConfigComponentTree } from './component-tree-payload';
import { pushPageVariants } from './prepare-page-variants-push';

import type { PageVariant } from '../types/PageVariant';
import type { PreparedPageVariant } from './prepare-page-variants-push';

describe('stripNullableKeysForConfigComponentTree', () => {
  it('omits null parent_uuid, slot, and label for root-level components', () => {
    const stripped = stripNullableKeysForConfigComponentTree([
      {
        uuid: 'root-uuid',
        component_id: 'js.hero',
        component_version: 'v1',
        inputs: { heading: 'Welcome' },
        parent_uuid: null,
        slot: null,
        label: null,
      },
    ]);

    expect(stripped).toEqual([
      {
        uuid: 'root-uuid',
        component_id: 'js.hero',
        component_version: 'v1',
        inputs: { heading: 'Welcome' },
      },
    ]);
  });

  it('preserves parent_uuid and slot for nested components, drops null label', () => {
    const stripped = stripNullableKeysForConfigComponentTree([
      {
        uuid: 'child',
        component_id: 'js.button',
        component_version: 'v1',
        inputs: {},
        parent_uuid: 'parent',
        slot: 'actions',
        label: null,
      },
    ]);

    expect(stripped).toEqual([
      {
        uuid: 'child',
        component_id: 'js.button',
        component_version: 'v1',
        inputs: {},
        parent_uuid: 'parent',
        slot: 'actions',
      },
    ]);
  });

  it('keeps non-empty label', () => {
    const stripped = stripNullableKeysForConfigComponentTree([
      {
        uuid: 'node',
        component_id: 'js.heading',
        component_version: 'v1',
        inputs: {},
        parent_uuid: null,
        slot: null,
        label: 'Main heading',
      },
    ]);

    expect(stripped[0].label).toBe('Main heading');
  });
});

function makePrepared(
  id: string,
  overrides: Partial<PreparedPageVariant> = {},
): PreparedPageVariant {
  return {
    id,
    label: id,
    description: null,
    status: true,
    default: false,
    components: [],
    filePath: `/tmp/page-templates/${id}.json`,
    ...overrides,
  };
}

function makeVariant(id: string): PageVariant {
  return {
    id,
    label: id,
    status: true,
    component_tree: [],
  };
}

function makeApiService() {
  return {
    createPageVariant: vi.fn(async (variant: { id: string }) =>
      makeVariant(variant.id),
    ),
    updatePageVariant: vi.fn(async (id: string) => makeVariant(id)),
    deletePageVariant: vi.fn(async (): Promise<void> => {}),
    setDefaultPageVariant: vi.fn(async (): Promise<void> => {}),
  };
}

describe('pushPageVariants', () => {
  it('POSTs a new page variant with the id from the filename', async () => {
    const apiService = makeApiService();

    const results = await pushPageVariants(
      [{ index: 0, result: makePrepared('marketing') }],
      new Set(),
      apiService,
    );

    expect(apiService.createPageVariant).toHaveBeenCalledExactlyOnceWith({
      id: 'marketing',
      label: 'marketing',
      description: null,
      status: true,
      component_tree: [],
    });
    expect(apiService.updatePageVariant).not.toHaveBeenCalled();
    expect(apiService.setDefaultPageVariant).not.toHaveBeenCalled();
    expect(results[0]).toMatchObject({
      success: true,
      result: { id: 'marketing', operation: 'Created' },
    });
  });

  it('PATCHes an existing page variant', async () => {
    const apiService = makeApiService();

    const results = await pushPageVariants(
      [
        {
          index: 0,
          result: makePrepared('marketing', {
            label: 'Marketing',
            description: 'Marketing pages.',
            status: false,
          }),
        },
      ],
      new Set(['marketing']),
      apiService,
    );

    expect(apiService.updatePageVariant).toHaveBeenCalledWith('marketing', {
      label: 'Marketing',
      description: 'Marketing pages.',
      status: false,
      component_tree: [],
    });
    expect(apiService.createPageVariant).not.toHaveBeenCalled();
    expect(results[0]).toMatchObject({
      success: true,
      result: { id: 'marketing', operation: 'Updated' },
    });
  });

  it('clears an existing description when the authored file has none', async () => {
    const apiService = makeApiService();

    await pushPageVariants(
      [{ index: 0, result: makePrepared('marketing') }],
      new Set(['marketing']),
      apiService,
    );

    expect(apiService.updatePageVariant).toHaveBeenCalledExactlyOnceWith(
      'marketing',
      {
        label: 'marketing',
        description: null,
        status: true,
        component_tree: [],
      },
    );
  });

  it('DELETEs remote page variants absent locally', async () => {
    const apiService = makeApiService();

    const results = await pushPageVariants([], new Set(), apiService, {
      remoteIdsToDelete: ['docs', 'landing'],
    });

    expect(apiService.createPageVariant).not.toHaveBeenCalled();
    expect(apiService.updatePageVariant).not.toHaveBeenCalled();
    expect(apiService.deletePageVariant).toHaveBeenCalledTimes(2);
    expect(apiService.deletePageVariant).toHaveBeenCalledWith('docs');
    expect(apiService.deletePageVariant).toHaveBeenCalledWith('landing');
    expect(results).toHaveLength(2);
    expect(results[0]).toMatchObject({
      success: true,
      result: { id: 'docs', operation: 'Deleted' },
    });
  });

  it('sets the site default when one file claims it and it differs', async () => {
    const apiService = makeApiService();

    const results = await pushPageVariants(
      [{ index: 0, result: makePrepared('marketing', { default: true }) }],
      new Set(['marketing']),
      apiService,
      { currentDefault: 'docs' },
    );

    expect(apiService.setDefaultPageVariant).toHaveBeenCalledExactlyOnceWith(
      'marketing',
    );
    expect(results.at(-1)).toMatchObject({
      success: true,
      result: { id: 'marketing', operation: 'Set as default' },
    });
  });

  it('changes the default before deleting its previous variant', async () => {
    const apiService = makeApiService();

    const results = await pushPageVariants(
      [{ index: 0, result: makePrepared('marketing', { default: true }) }],
      new Set(),
      apiService,
      {
        currentDefault: 'docs',
        remoteIdsToDelete: ['docs'],
      },
    );

    expect(apiService.setDefaultPageVariant).toHaveBeenCalledExactlyOnceWith(
      'marketing',
    );
    expect(apiService.deletePageVariant).toHaveBeenCalledExactlyOnceWith(
      'docs',
    );
    expect(
      apiService.setDefaultPageVariant.mock.invocationCallOrder[0],
    ).toBeLessThan(apiService.deletePageVariant.mock.invocationCallOrder[0]);
    expect(results.map((result) => result.result?.operation)).toEqual([
      'Created',
      'Set as default',
      'Deleted',
    ]);
  });

  it('keeps the current default when there is no authored replacement', async () => {
    const apiService = makeApiService();

    const results = await pushPageVariants([], new Set(), apiService, {
      currentDefault: 'docs',
      remoteIdsToDelete: ['docs', 'landing'],
    });

    expect(apiService.setDefaultPageVariant).not.toHaveBeenCalled();
    expect(apiService.deletePageVariant).toHaveBeenCalledExactlyOnceWith(
      'landing',
    );
    expect(results.at(-1)).toMatchObject({
      success: true,
      result: { id: 'docs', operation: 'Skipped' },
    });
  });

  it('keeps the current default when switching to its replacement fails', async () => {
    const apiService = makeApiService();
    apiService.setDefaultPageVariant.mockRejectedValueOnce(
      new Error('Could not change the default'),
    );

    const results = await pushPageVariants(
      [{ index: 0, result: makePrepared('marketing', { default: true }) }],
      new Set(),
      apiService,
      {
        currentDefault: 'docs',
        remoteIdsToDelete: ['docs'],
      },
    );

    expect(apiService.deletePageVariant).not.toHaveBeenCalled();
    expect(results).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          success: false,
          result: { id: 'marketing', operation: 'Set as default' },
        }),
        expect.objectContaining({
          success: true,
          result: expect.objectContaining({
            id: 'docs',
            operation: 'Skipped',
          }),
        }),
      ]),
    );
  });

  it('does not select a new default when creating it fails', async () => {
    const apiService = makeApiService();
    apiService.createPageVariant.mockRejectedValueOnce(
      new Error('Could not create the page template'),
    );

    const results = await pushPageVariants(
      [{ index: 0, result: makePrepared('marketing', { default: true }) }],
      new Set(),
      apiService,
      {
        currentDefault: 'docs',
        remoteIdsToDelete: ['docs'],
      },
    );

    expect(apiService.setDefaultPageVariant).not.toHaveBeenCalled();
    expect(apiService.deletePageVariant).not.toHaveBeenCalled();
    expect(results).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ success: false }),
        expect.objectContaining({
          success: true,
          result: expect.objectContaining({
            id: 'docs',
            operation: 'Skipped',
          }),
        }),
      ]),
    );
  });

  it('does not select a new default when updating it fails', async () => {
    const apiService = makeApiService();
    apiService.updatePageVariant.mockRejectedValueOnce(
      new Error('Could not update the page template'),
    );

    await pushPageVariants(
      [{ index: 0, result: makePrepared('marketing', { default: true }) }],
      new Set(['marketing']),
      apiService,
      { currentDefault: 'docs' },
    );

    expect(apiService.setDefaultPageVariant).not.toHaveBeenCalled();
  });

  it('leaves the default untouched when it already matches', async () => {
    const apiService = makeApiService();

    await pushPageVariants(
      [{ index: 0, result: makePrepared('marketing', { default: true }) }],
      new Set(['marketing']),
      apiService,
      { currentDefault: 'marketing' },
    );

    expect(apiService.setDefaultPageVariant).not.toHaveBeenCalled();
  });

  it('rejects multiple authored defaults before writing anything', async () => {
    const apiService = makeApiService();

    await expect(
      pushPageVariants(
        [
          { index: 0, result: makePrepared('marketing', { default: true }) },
          { index: 1, result: makePrepared('docs', { default: true }) },
        ],
        new Set(),
        apiService,
      ),
    ).rejects.toThrow('Only one page template may set "default": true');
    expect(apiService.createPageVariant).not.toHaveBeenCalled();
    expect(apiService.setDefaultPageVariant).not.toHaveBeenCalled();
  });
});
