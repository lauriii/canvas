import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import DrupalInputMultivalueForm from '@/components/form/components/drupal/DrupalInputMultivalueForm';
import { selectUpdatePreview } from '@/features/layout/layoutModelSlice';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import {
  EditorFrameContext,
  initialState as uiInitialState,
} from '@/features/ui/uiSlice';

import type * as PreviewService from '@/services/preview';
import type * as MultivalueFormUtils from './multivalueFormUtils';

// Break a module-init cycle (withRHF -> ComponentFormField ->
// useComponentFormInputInfo -> ComponentInstanceForm -> twig-to-jsx-component-map
// -> DrupalPathWidget -> withRHF) that otherwise crashes the test at import
// time. The map is only used by hyperscriptify, not by the component under test.
vi.mock('@/components/form/twig-to-jsx-component-map', () => ({ default: {} }));

// Stub only triggerDrupalRemoveButton: it returns the Drupal remove button name
// (so the remove handler proceeds) but skips the real DOM click, which fires a
// jsdom MouseEvent that would otherwise error after the test. isRemoveButtonEnabled
// is kept real so it still reads the rendered remove button from the DOM.
const triggerDrupalRemoveButtonMock = vi.hoisted(() =>
  vi.fn(() => 'field_cvt_unlimited_text_1_remove_button'),
);
vi.mock('./multivalueFormUtils', async (importOriginal) => ({
  ...(await importOriginal<typeof MultivalueFormUtils>()),
  triggerDrupalRemoveButton: triggerDrupalRemoveButtonMock,
}));

// Spy on the preview/auto-save mutation so the entity-form path can be asserted
// without a real network request.
type PostPreviewArg = {
  entityId: string;
  entityType: string;
  entity_form_fields: Record<string, unknown>;
  layout?: unknown;
  model?: unknown;
};
const postPreviewMock = vi.hoisted(() =>
  vi.fn((_arg: PostPreviewArg) => Promise.resolve({ html: '', autoSaves: {} })),
);
vi.mock('@/services/preview', async (importOriginal) => ({
  ...(await importOriginal<typeof PreviewService>()),
  useQueuedPostPreviewMutation: () => [postPreviewMock, {}],
}));

// Reproduces https://www.drupal.org/i/3536221: removing an item from a
// multivalue field on the page data (entity) form must update the pageData
// store that auto-save reads from. Before the fix, the remove handler only
// updated formStateSlice (which auto-save never reads), so the removal was not
// captured promptly and a reload restored the "removed" item.
describe('DrupalInputMultivalueForm remove on the page data form', () => {
  const renderField = (
    options: {
      location?: string;
      path?: string;
      editorFrameContext?: EditorFrameContext;
    } = {},
  ) => {
    const {
      location = '/',
      path = '/',
      editorFrameContext = EditorFrameContext.NONE,
    } = options;
    const store = makeStore({
      pageData: {
        present: {
          'field_cvt_unlimited_text[0][value]': 'Marshmallow Coast',
          'field_cvt_unlimited_text[0][_weight]': '0',
          'field_cvt_unlimited_text[1][value]': 'Testing',
          'field_cvt_unlimited_text[1][_weight]': '1',
        },
        past: [],
        future: [],
      },
      ui: { ...uiInitialState, editorFrameContext },
    });

    render(
      <AppWrapper store={store} location={location} path={path}>
        <div data-canvas-multiple-values>
          <table className="field-multiple-table">
            <tbody>
              <tr className="draggable">
                <td>
                  <DrupalInputMultivalueForm
                    attributes={{
                      name: 'field_cvt_unlimited_text[1][value]',
                      value: 'Testing',
                      'data-form-id': 'page_data_form',
                      'data-field-label': 'Canvas Unlimited Text',
                    }}
                  />
                </td>
                <td className="canvas-remove-action">
                  <input
                    type="submit"
                    name="field_cvt_unlimited_text_1_remove_button"
                    data-once="drupal-ajax"
                    defaultValue="Remove"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppWrapper>,
    );

    return store;
  };

  const clickRemove = async (user: ReturnType<typeof userEvent.setup>) => {
    // Open the popover so the (otherwise disabled) Remove button is enabled.
    await user.click(
      screen.getByRole('button', {
        name: 'Edit Canvas Unlimited Text: Testing',
      }),
    );
    await user.click(screen.getByRole('button', { name: 'Remove' }));
  };

  it('removes the item values from the pageData store and suppresses the immediate AJAX click', async () => {
    const user = userEvent.setup();
    const store = renderField();

    // Sanity check: the item is present in the auto-save store before removal.
    expect(
      selectPageData(store.getState())['field_cvt_unlimited_text[1][value]'],
    ).toBe('Testing');

    await clickRemove(user);

    await waitFor(() => {
      const pageData = selectPageData(store.getState());
      expect(pageData).not.toHaveProperty('field_cvt_unlimited_text[1][value]');
      expect(pageData).not.toHaveProperty(
        'field_cvt_unlimited_text[1][_weight]',
      );
    });

    // The unrelated item must be left untouched.
    expect(
      selectPageData(store.getState())['field_cvt_unlimited_text[0][value]'],
    ).toBe('Marshmallow Coast');
    // With no entity context, the standard preview flag is used so the removal
    // is still persisted by auto-save.
    expect(selectUpdatePreview(store.getState())).toBe(true);

    // The first call must suppress the click so the store is updated before the
    // Drupal AJAX rebuild fires (otherwise the auto-save request is blocked by
    // the in-flight AJAX and the reload race remains).
    expect(triggerDrupalRemoveButtonMock).toHaveBeenCalledWith(
      expect.anything(),
      'page_data_form',
      true,
    );
  });

  it('persists the removal immediately via a preview post in the entity context', async () => {
    const user = userEvent.setup();
    renderField({
      location: '/canvas/editor/node/2',
      path: '/canvas/editor/:entityType/:entityId',
      editorFrameContext: EditorFrameContext.ENTITY,
    });

    await clickRemove(user);

    await waitFor(() => {
      expect(postPreviewMock).toHaveBeenCalledTimes(1);
    });

    // The preview post must carry the post-removal page data (removed item gone,
    // untouched item kept) so auto-save records the removal right away.
    const arg: PostPreviewArg = postPreviewMock.mock.calls[0][0];
    expect(arg.entityId).toBe('2');
    expect(arg.entityType).toBe('node');
    expect(arg.entity_form_fields).not.toHaveProperty(
      'field_cvt_unlimited_text[1][value]',
    );
    expect(arg.entity_form_fields).not.toHaveProperty(
      'field_cvt_unlimited_text[1][_weight]',
    );
    expect(arg.entity_form_fields['field_cvt_unlimited_text[0][value]']).toBe(
      'Marshmallow Coast',
    );
  });
});
