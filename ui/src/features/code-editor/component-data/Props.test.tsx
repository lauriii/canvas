// @todo: Port tests from code-editor-component-data-props.cy.jsx to this file since we want to move away from
//    Cypress unit tests toward vitest. https://www.drupal.org/i/3523490.
import { describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import { selectCodeComponentProperty } from '@/features/code-editor/codeEditorSlice';
import { getPropMachineName } from '@/features/code-editor/utils/utils';
import { type CodeComponentPropImageExample } from '@/types/CodeComponent';

import Props from './Props';

const store = makeStore({});

const Wrapper = () => (
  <AppWrapper
    store={store}
    location="/code-editor/code/test_component"
    path="/code-editor/code/:codeComponentId"
  >
    <Props />
  </AppWrapper>
);

describe('Props', () => {
  it('renders empty', async () => {
    render(<Wrapper />);
    expect(
      screen.queryByRole('textbox', { name: 'Prop name' }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
  });

  it('add new prop form', async () => {
    render(<Wrapper />);

    await userEvent.click(screen.getByRole('button', { name: 'Add' }));
    expect(
      screen.getByRole('textbox', { name: 'Prop name' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('textbox', { name: 'Example value' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Type' })).toBeInTheDocument();
    expect(
      screen.getByRole('switch', { name: 'Required' }),
    ).toBeInTheDocument();

    expect(
      selectCodeComponentProperty('props')(store.getState()).length,
    ).toEqual(1);
  });

  it('remove prop form', async () => {
    render(<Wrapper />);

    await userEvent.click(screen.getByRole('button', { name: /Remove prop/ }));
    expect(
      screen.queryByRole('textbox', { name: 'Prop name' }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('textbox', { name: 'Example value' }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('combobox', { name: 'Type' }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('switch', { name: 'Required' }),
    ).not.toBeInTheDocument();

    expect(
      selectCodeComponentProperty('props')(store.getState()).length,
    ).toEqual(0);
  });

  it('saves prop data', async () => {
    render(<Wrapper />);
    await userEvent.click(screen.getByRole('button', { name: 'Add' }));

    expect(
      screen.getByRole('textbox', { name: 'Prop name' }),
    ).toBeInTheDocument();

    await userEvent.type(
      screen.getByRole('textbox', { name: 'Prop name' }),
      'Alpha',
    );
    expect(
      selectCodeComponentProperty('props')(store.getState())[0].name,
    ).toEqual('Alpha');

    await userEvent.type(
      screen.getByRole('textbox', { name: 'Example value' }),
      'Alpha value',
    );
    expect(
      selectCodeComponentProperty('props')(store.getState())[0].example,
    ).toEqual('Alpha value');
  });

  it('sets required', async () => {
    render(<Wrapper />);
    await waitFor(() => {
      expect(
        screen.getByRole('textbox', { name: 'Prop name' }),
      ).toBeInTheDocument();
    });

    const propName = selectCodeComponentProperty('props')(store.getState())[0]
      .name;

    await userEvent.click(screen.getByRole('switch', { name: 'Required' }));
    expect(screen.getByRole('switch', { name: 'Required' })).toBeChecked();
    expect(
      selectCodeComponentProperty('required')(store.getState())[0],
    ).toEqual(getPropMachineName(propName));
  });

  describe.each([
    ['integer', 'Integer'],
    ['number', 'Number'],
    ['string', 'Formatted text'],
    ['string', 'Link'],
    ['object', 'Video'],
    ['boolean', 'Boolean'],
    ['string', 'List: text'],
    ['integer', 'List: integer'],
  ])('saves type data', (type, name) => {
    it(`${name}`, async () => {
      render(<Wrapper />);
      await waitFor(() => {
        expect(
          screen.getByRole('textbox', { name: 'Prop name' }),
        ).toBeInTheDocument();
      });

      await userEvent.click(
        screen.getByRole('button', { name: /Remove prop/ }),
      );
      expect(
        selectCodeComponentProperty('props')(store.getState()).length,
      ).toEqual(0);
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));

      await userEvent.click(screen.getByRole('combobox', { name: 'Type' }));
      expect(screen.getByRole('option', { name })).toBeInTheDocument();
      await userEvent.click(screen.getByRole('option', { name }));

      expect(
        selectCodeComponentProperty('props')(store.getState())[0].type,
      ).toEqual(type);

      switch (name) {
        case 'Integer':
        case 'Number':
          await userEvent.type(
            screen.getByRole('spinbutton', { name: 'Example value' }),
            '1',
          );
          expect(
            selectCodeComponentProperty('props')(store.getState())[0].example,
          ).toEqual('1');
          break;

        case 'Formatted text':
          await userEvent.type(
            screen.getByRole('textbox', { name: 'Example value' }),
            'Example text',
          );
          expect(
            selectCodeComponentProperty('props')(store.getState())[0].example,
          ).toEqual('Example text');
          break;

        case 'Link':
          await userEvent.click(
            screen.getByRole('combobox', { name: 'Link type' }),
          );
          expect(
            screen.getByRole('option', { name: /Relative path/ }),
          ).toBeInTheDocument();
          expect(
            screen.getByRole('option', { name: /Full URL/ }),
          ).toBeInTheDocument();
          await userEvent.click(
            screen.getByRole('option', { name: /Full URL/ }),
          );

          await userEvent.type(
            screen.getByRole('textbox', { name: 'Example value' }),
            'http://example.com/path/to/something',
          );
          expect(
            selectCodeComponentProperty('props')(store.getState())[0].example,
          ).toEqual('http://example.com/path/to/something');
          break;

        case 'Video':
          await userEvent.click(
            screen.getByRole('combobox', { name: 'Example aspect ratio' }),
          );
          await userEvent.click(
            screen.getByRole('option', { name: '16:9 (Widescreen)' }),
          );
          expect(
            selectCodeComponentProperty('props')(store.getState())[0].example,
          ).toMatchObject({
            src: '/ui/assets/videos/mountain_wide.mp4',
            poster: 'https://placehold.co/1920x1080.png?text=Widescreen',
          });
          await userEvent.click(
            screen.getByRole('combobox', { name: 'Example aspect ratio' }),
          );
          await userEvent.click(
            screen.getByRole('option', { name: '9:16 (Vertical)' }),
          );
          expect(
            selectCodeComponentProperty('props')(store.getState())[0].example,
          ).toMatchObject({
            src: '/ui/assets/videos/bird_vertical.mp4',
            poster: 'https://placehold.co/1080x1920.png?text=Vertical',
          });
          break;

        case 'Boolean':
          await userEvent.click(
            screen.getByRole('switch', { name: 'Example value' }),
          );
          expect(
            screen.getByRole('switch', { name: 'Example value' }),
          ).toBeChecked();
          expect(
            selectCodeComponentProperty('props')(store.getState())[0].example,
          ).toEqual(true);
          break;

        default:
          break;
      }
    });
  });

  describe.each([
    ['string', 'List: text'],
    ['integer', 'List: integer'],
  ])('saves/removes list type data', (type, name) => {
    it(`${name}`, async () => {
      render(<Wrapper />);
      await waitFor(() => {
        expect(
          screen.getByRole('textbox', { name: 'Prop name' }),
        ).toBeInTheDocument();
      });

      await userEvent.click(
        screen.getByRole('button', { name: /Remove prop/ }),
      );
      expect(
        selectCodeComponentProperty('props')(store.getState()).length,
      ).toEqual(0);
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));

      await userEvent.click(screen.getByRole('combobox', { name: 'Type' }));
      expect(screen.getByRole('option', { name })).toBeInTheDocument();
      await userEvent.click(screen.getByRole('option', { name }));

      expect(
        selectCodeComponentProperty('props')(store.getState())[0].type,
      ).toEqual(type);

      switch (name) {
        case 'List: text':
          await userEvent.click(
            screen.getByRole('button', { name: 'Add value' }),
          );
          await userEvent.type(
            screen.getByRole('textbox', { name: 'Label' }),
            'A label',
          );
          await userEvent.type(
            screen.getByRole('textbox', { name: 'Value' }),
            'A value',
          );
          expect(
            selectCodeComponentProperty('props')(store.getState())[0].enum,
          ).toMatchObject([
            {
              label: 'A label',
              value: 'A value',
            },
          ]);
          break;

        case 'List: integer':
          await userEvent.click(
            screen.getByRole('button', { name: 'Add value' }),
          );
          await userEvent.type(
            screen.getByRole('textbox', { name: 'Label' }),
            'A label',
          );
          await userEvent.type(
            screen.getByRole('spinbutton', { name: 'Value' }),
            '1',
          );
          expect(
            selectCodeComponentProperty('props')(store.getState())[0].enum,
          ).toMatchObject([
            {
              label: 'A label',
              value: '1',
            },
          ]);
          break;

        default:
          break;
      }

      await userEvent.click(
        screen.getByRole('button', { name: 'Remove value' }),
      );
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].enum,
      ).toMatchObject([]);
    });
  });

  describe.each([
    ['1:1 (Square)', { width: 600, height: 600 }],
    ['4:3 (Standard)', { width: 800, height: 600 }],
    ['16:9 (Widescreen)', { width: 1280, height: 720 }],
    ['3:2 (Classic Photo)', { width: 900, height: 600 }],
    ['2:1 (Panoramic)', { width: 1000, height: 500 }],
    ['9:16 (Vertical)', { width: 720, height: 1280 }],
    ['21:9 (Ultrawide)', { width: 1400, height: 600 }],
  ])('saves image data', (size, expectedSize) => {
    it(`${size}`, async () => {
      render(<Wrapper />);
      await waitFor(() => {
        expect(
          screen.getByRole('textbox', { name: 'Prop name' }),
        ).toBeInTheDocument();
      });

      await userEvent.click(
        screen.getByRole('button', { name: /Remove prop/ }),
      );
      expect(
        selectCodeComponentProperty('props')(store.getState()).length,
      ).toEqual(0);
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));

      await userEvent.click(screen.getByRole('combobox', { name: 'Type' }));
      await userEvent.click(screen.getByRole('option', { name: 'Image' }));

      await userEvent.click(
        screen.getByRole('combobox', { name: 'Example aspect ratio' }),
      );
      await userEvent.click(screen.getByRole('option', { name: size }));
      let example = selectCodeComponentProperty('props')(store.getState())[0]
        .example as CodeComponentPropImageExample;
      expect(example).toMatchObject(expectedSize);
      expect(example.src).toMatch(
        new RegExp(`${expectedSize.width}x${expectedSize.height}@2x\\.png`),
      );

      await userEvent.click(
        screen.getByRole('combobox', { name: 'Pixel density' }),
      );
      await userEvent.click(screen.getByRole('option', { name: /1x/ }));
      example = selectCodeComponentProperty('props')(store.getState())[0]
        .example as CodeComponentPropImageExample;
      expect(example.src).toMatch(
        new RegExp(`${expectedSize.width}x${expectedSize.height}?`),
      );

      await userEvent.click(
        screen.getByRole('combobox', { name: 'Pixel density' }),
      );
      expect(screen.getByRole('option', { name: /3x/ })).toBeInTheDocument();
      await userEvent.click(screen.getByRole('option', { name: /3x/ }));
      example = selectCodeComponentProperty('props')(store.getState())[0]
        .example as CodeComponentPropImageExample;
      expect(example.src).toMatch(
        new RegExp(`${expectedSize.width}x${expectedSize.height}@3x\\.png`),
      );
    });
  });

  it('changing type clears example data', async () => {
    render(<Wrapper />);
    await waitFor(() => {
      expect(
        screen.getByRole('textbox', { name: 'Prop name' }),
      ).toBeInTheDocument();
    });

    await userEvent.click(screen.getByRole('combobox', { name: 'Type' }));
    await userEvent.click(screen.getByRole('option', { name: 'Text' }));

    expect(
      selectCodeComponentProperty('props')(store.getState())[0].example,
    ).toEqual('');

    await userEvent.type(
      screen.getByRole('textbox', { name: 'Example value' }),
      'Alpha value',
    );
    expect(
      selectCodeComponentProperty('props')(store.getState())[0].example,
    ).toEqual('Alpha value');

    await userEvent.click(screen.getByRole('combobox', { name: 'Type' }));
    await userEvent.click(
      screen.getByRole('option', { name: 'Formatted text' }),
    );
    expect(
      selectCodeComponentProperty('props')(store.getState())[0].example,
    ).toEqual('');
  });

  it('reorders props', async () => {
    render(<Wrapper />);
    await userEvent.click(screen.getByRole('button', { name: 'Add' }));
    expect(screen.getByTestId('prop-0')).toBeInTheDocument();
    expect(screen.getByTestId('prop-1')).toBeInTheDocument();

    const prop1 = screen.getByTestId('prop-0');
    const prop2 = screen.getByTestId('prop-1');

    await userEvent.type(
      within(prop1).getByRole('textbox', { name: 'Prop name' }),
      'Alpha',
    );
    await userEvent.type(
      within(prop2).getByRole('textbox', { name: 'Prop name' }),
      'Beta',
    );

    expect(
      selectCodeComponentProperty('props')(store.getState())[0].name,
    ).toEqual('Alpha');
    expect(
      selectCodeComponentProperty('props')(store.getState())[1].name,
    ).toEqual('Beta');

    await userEvent.click(
      within(prop2).getByRole('button', { name: /Move prop/ }),
    );
    await userEvent.keyboard('[Space]');
    await userEvent.keyboard('[ArrowUp]');

    expect(
      selectCodeComponentProperty('props')(store.getState())[0].name,
    ).toEqual('Beta');
    expect(
      selectCodeComponentProperty('props')(store.getState())[1].name,
    ).toEqual('Alpha');
  });
});
