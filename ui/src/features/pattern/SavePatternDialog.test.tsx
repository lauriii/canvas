import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import SavePatternDialog from '@/features/pattern/SavePatternDialog';
import { initialState as uiInitialState } from '@/features/ui/uiSlice';

import type { ComponentModels } from '@/features/layout/layoutModelSlice';
import type * as PatternsService from '@/services/patterns';

// The save mutation is mocked so the test can assert whether the dialog attempts
// to POST the pattern, without hitting the network.
const { savePatternMock, resetMock } = vi.hoisted(() => ({
  savePatternMock: vi.fn(),
  resetMock: vi.fn(),
}));

vi.mock('@/services/patterns', async (importOriginal) => {
  const actual = await importOriginal<typeof PatternsService>();
  return {
    ...actual,
    useSavePatternMutation: () => [
      savePatternMock,
      {
        isLoading: false,
        isSuccess: false,
        isError: false,
        error: undefined,
        reset: resetMock,
      },
    ],
  };
});

// Avoid the components RTK-query the real hook makes; the name is cosmetic here.
vi.mock('@/hooks/useGetComponentName', () => ({
  default: () => 'Card',
}));

const SELECTED_UUID = 'comp-1';

const layout = [
  {
    nodeType: 'region',
    id: 'content',
    name: 'Content',
    components: [
      {
        nodeType: 'component',
        uuid: SELECTED_UUID,
        type: 'sdc.canvas_test_sdc.props-no-slots@1',
        slots: [],
      },
    ],
  },
];

const staticSource = {
  sourceType: 'static:field_item:string',
  value: 'Hello',
  expression: 'ℹ︎string␟value',
};

const linkedSource = {
  sourceType: 'entity-field',
  expression: 'ℹ︎␜entity:node:article␝title␞␟value',
};

function renderDialog(model: ComponentModels) {
  const store = makeStore({
    // The layoutModel slice is wrapped by redux-undo, so seed present/past/future.
    layoutModel: {
      present: {
        layout,
        model,
        updatePreview: false,
        isInitialized: true,
        translations: {},
      },
      past: [],
      future: [],
    },
    ui: {
      ...uiInitialState,
      selection: { ...uiInitialState.selection, items: [SELECTED_UUID] },
    },
    dialog: {
      saveAsPattern: true,
      extension: false,
      deletePatternConfirm: { open: false, data: {} },
    },
  } as any);

  render(
    <AppWrapper store={store} location="/" path="/">
      <SavePatternDialog />
    </AppWrapper>,
  );
}

describe('SavePatternDialog', () => {
  beforeEach(() => {
    savePatternMock.mockClear();
    resetMock.mockClear();
  });

  it('blocks saving when the subtree contains a linked (entity-field) prop', async () => {
    const user = userEvent.setup();
    renderDialog({
      [SELECTED_UUID]: {
        resolved: { heading: 'Hello' },
        source: { heading: linkedSource },
      },
    });

    // A clear explanation is shown instead of a generic failure.
    expect(screen.getByText(/linked to entity data/i)).toBeInTheDocument();

    const confirmButton = screen.getByRole('button', {
      name: 'Add to library',
    });
    await user.click(confirmButton);

    // The dialog must not attempt the POST that would 500 server-side.
    expect(savePatternMock).not.toHaveBeenCalled();
  });

  it('detects a linked prop nested deeper in the subtree', async () => {
    const user = userEvent.setup();
    // The linked prop is on a descendant, not the selected root itself.
    const layoutWithChild = [
      {
        nodeType: 'region',
        id: 'content',
        name: 'Content',
        components: [
          {
            nodeType: 'component',
            uuid: SELECTED_UUID,
            type: 'sdc.canvas_test_sdc.props-no-slots@1',
            slots: [
              {
                nodeType: 'slot',
                id: `${SELECTED_UUID}/content`,
                name: 'content',
                components: [
                  {
                    nodeType: 'component',
                    uuid: 'child-1',
                    type: 'sdc.canvas_test_sdc.props-no-slots@1',
                    slots: [],
                  },
                ],
              },
            ],
          },
        ],
      },
    ];
    const store = makeStore({
      layoutModel: {
        present: {
          layout: layoutWithChild,
          model: {
            [SELECTED_UUID]: {
              resolved: { heading: 'Hello' },
              source: { heading: staticSource },
            },
            'child-1': {
              resolved: { heading: 'Linked' },
              source: { heading: linkedSource },
            },
          },
          updatePreview: false,
          isInitialized: true,
          translations: {},
        },
        past: [],
        future: [],
      },
      ui: {
        ...uiInitialState,
        selection: { ...uiInitialState.selection, items: [SELECTED_UUID] },
      },
      dialog: {
        saveAsPattern: true,
        extension: false,
        deletePatternConfirm: { open: false, data: {} },
      },
    } as any);

    render(
      <AppWrapper store={store} location="/" path="/">
        <SavePatternDialog />
      </AppWrapper>,
    );

    await user.click(screen.getByRole('button', { name: 'Add to library' }));
    expect(savePatternMock).not.toHaveBeenCalled();
  });

  it('allows saving a subtree with only static props', async () => {
    const user = userEvent.setup();
    renderDialog({
      [SELECTED_UUID]: {
        resolved: { heading: 'Hello' },
        source: { heading: staticSource },
      },
    });

    expect(
      screen.queryByText(/linked to entity data/i),
    ).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Add to library' }));
    expect(savePatternMock).toHaveBeenCalledTimes(1);
  });
});
