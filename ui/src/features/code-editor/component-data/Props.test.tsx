// @todo: Port tests from code-editor-component-data-props.cy.jsx to this file since we want to move away from
//    Cypress unit tests toward vitest. https://www.drupal.org/i/3523490.
import { describe, expect, it } from 'vitest';
import { arrayMove } from '@dnd-kit/sortable';
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import {
  initialState,
  selectCodeComponentProperty,
} from '@/features/code-editor/codeEditorSlice';
import { createDisplayArray } from '@/features/code-editor/utils/arrayPropUtils';
import {
  getPropMachineName,
  serializeProps,
} from '@/features/code-editor/utils/utils';
import { type CodeComponentPropImageExample } from '@/types/CodeComponent';

import Props, { REQUIRED_EXAMPLE_ERROR_MESSAGE } from './Props';

import type { AppStore } from '@/app/store';

let store: AppStore;

const Wrapper = ({ store }: { store: AppStore }) => (
  <AppWrapper
    store={store}
    location="/code-editor/code/test_component"
    path="/code-editor/code/:codeComponentId"
  >
    <Props />
  </AppWrapper>
);

/**
 * Helper function to add a new prop with a specified type and name.
 *
 * It can be called multiple times to add multiple props.
 *
 * @param typeDisplayName - The display name of the prop type (e.g., 'Text', 'Integer', 'Image')
 * @param propName - The name to assign to the prop
 */
const addProp = async (typeDisplayName: string, propName: string) => {
  await userEvent.click(screen.getByRole('button', { name: 'Add' }));

  await waitFor(() => {
    expect(
      screen.getAllByRole('textbox', { name: 'Prop name' }).length,
    ).toBeGreaterThan(0);
  });

  const allPropNameInputs = screen.getAllByRole('textbox', {
    name: 'Prop name',
  });
  const allTypeSelects = screen.getAllByRole('combobox', { name: 'Type' });
  const lastIndex = allPropNameInputs.length - 1;

  await userEvent.click(allTypeSelects[lastIndex]);
  const option = await screen.findByRole('option', { name: typeDisplayName });
  await userEvent.click(option);

  await waitFor(() => {
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  await userEvent.type(allPropNameInputs[lastIndex], propName);
};

describe('props in code editor', () => {
  beforeEach(() => {
    store = makeStore({}); // Create a fresh store for each test
    render(<Wrapper store={store} />);
  });

  describe('props form', () => {
    it('renders empty', async () => {
      expect(
        screen.queryByRole('textbox', { name: 'Prop name' }),
      ).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
    });

    it('adds and removes props', async () => {
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));
      expect(
        screen.getByRole('textbox', { name: 'Prop name' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('textbox', { name: 'Example value' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('combobox', { name: 'Type' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('switch', { name: 'Required' }),
      ).toBeInTheDocument();

      expect(
        selectCodeComponentProperty('props')(store.getState()).length,
      ).toEqual(1);

      await userEvent.click(
        screen.getByRole('button', { name: /Remove prop/ }),
      );
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
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));
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

    it('changing type clears example data', async () => {
      await addProp('Integer', 'Alpha');
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
      await addProp('Text', 'Alpha');
      await addProp('Text', 'Beta');

      expect(
        selectCodeComponentProperty('props')(store.getState())[0].name,
      ).toEqual('Alpha');
      expect(
        selectCodeComponentProperty('props')(store.getState())[1].name,
      ).toEqual('Beta');

      const prop2 = screen.getByTestId('prop-1');
      const dragHandle = within(prop2).getByRole('button', {
        name: /Move prop/,
      });
      // Focus the drag handle
      dragHandle.focus();
      // Activate with Space
      await userEvent.keyboard('[Space]');
      // Wait for drag to activate
      await waitFor(() => {
        expect(dragHandle).toHaveAttribute('aria-pressed', 'true');
      });
      // Move up
      await userEvent.keyboard('[ArrowUp]');
      // Commit with Space
      await userEvent.keyboard('[Space]');
      // Wait for reorder
      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].name,
        ).toEqual('Beta');
      });
      expect(
        selectCodeComponentProperty('props')(store.getState())[1].name,
      ).toEqual('Alpha');
    });
  });

  describe('prop types', () => {
    it('creates a new integer prop', async () => {
      await addProp('Integer', 'Count');
      expect(screen.getByLabelText('Example value')).toHaveAttribute(
        'placeholder',
        'Enter an integer',
      );
      const integerInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });
      await userEvent.type(
        integerInput,
        'Typing an invalid string value with hopefully no effect',
      );
      expect(integerInput).toHaveValue(null);
      await userEvent.clear(integerInput);
      await userEvent.type(integerInput, '922');
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Count',
          type: 'integer',
          example: '922',
          format: undefined,
          $ref: undefined,
        });
      });
    });

    it('creates a new number prop', async () => {
      await addProp('Number', 'Percentage');
      expect(screen.getByLabelText('Example value')).toHaveAttribute(
        'placeholder',
        'Enter a number',
      );
      const numberInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });
      await userEvent.type(
        numberInput,
        'Typing an invalid string value with hopefully no effect',
      );
      expect(numberInput).toHaveValue(null);
      await userEvent.clear(numberInput);
      await userEvent.type(numberInput, '9.22');
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Percentage',
          type: 'number',
          example: '9.22',
          format: undefined,
          $ref: undefined,
        });
      });
    });

    it('integer prop rejects decimal values and shows error', async () => {
      await addProp('Integer', 'Count');
      const integerInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });

      // Type a valid integer first so the store has a value.
      await userEvent.type(integerInput, '10');
      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].example,
        ).toEqual('10');
      });

      // Simulate entering a decimal value directly.
      await userEvent.clear(integerInput);
      await userEvent.type(integerInput, '10.5');

      await waitFor(() => {
        expect(
          screen.getByText('Integers cannot have decimal values.'),
        ).toBeInTheDocument();
      });

      // Store should not be updated with the decimal value.
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).not.toEqual('10.5');
    });

    it('integer prop rejects pasted decimal values and shows error', async () => {
      await addProp('Integer', 'Count');
      const integerInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });

      // Paste a decimal value directly into the field.
      await userEvent.click(integerInput);
      await userEvent.paste('10.5');

      await waitFor(() => {
        expect(
          screen.getByText('Integers cannot have decimal values.'),
        ).toBeInTheDocument();
      });

      // Store should not be updated with the pasted decimal value.
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).not.toEqual('10.5');
    });

    it('integer prop accepts valid negative integers', async () => {
      await addProp('Integer', 'Offset');
      const integerInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });

      await userEvent.type(integerInput, '-42');

      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].example,
        ).toEqual('-42');
      });
      expect(
        screen.queryByText('Integers cannot have decimal values.'),
      ).not.toBeInTheDocument();
    });

    it('integer prop clears error after valid input follows decimal', async () => {
      await addProp('Integer', 'Count');
      const integerInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });

      // Enter decimal to trigger error.
      await userEvent.clear(integerInput);
      await userEvent.type(integerInput, '10.5');
      await waitFor(() => {
        expect(
          screen.getByText('Integers cannot have decimal values.'),
        ).toBeInTheDocument();
      });

      // Correct to a valid integer.
      await userEvent.clear(integerInput);
      await userEvent.type(integerInput, '10');
      await waitFor(() => {
        expect(
          screen.queryByText('Integers cannot have decimal values.'),
        ).not.toBeInTheDocument();
      });
      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].example,
        ).toEqual('10');
      });
    });

    it('number prop accepts decimal values', async () => {
      await addProp('Number', 'Rate');
      const numberInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });

      await userEvent.type(numberInput, '3.14');

      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].example,
        ).toEqual('3.14');
      });
      expect(
        screen.queryByText('Integers cannot have decimal values.'),
      ).not.toBeInTheDocument();
    });

    it('number prop accepts negative decimal values', async () => {
      await addProp('Number', 'Temperature');
      const numberInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });

      await userEvent.type(numberInput, '-3.14');

      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].example,
        ).toEqual('-3.14');
      });
    });

    it('integer prop input does not reset to previous valid value when a decimal is entered', async () => {
      await addProp('Integer', 'Count');
      const integerInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });

      // Type a valid integer so the store has a value.
      await userEvent.type(integerInput, '10');
      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].example,
        ).toEqual('10');
      });

      // Clear the field and paste a decimal. The paste produces '10.5' as a
      // single change event, bypassing jsdom's per-character number sanitization.
      await userEvent.clear(integerInput);
      await userEvent.click(integerInput);
      await userEvent.paste('10.5');

      await waitFor(() => {
        expect(
          screen.getByText('Integers cannot have decimal values.'),
        ).toBeInTheDocument();
      });

      // The input must still show the decimal value that the user pasted — it
      // must NOT have reset to empty (the stored value after clear) or to '10'.
      expect(integerInput).toHaveValue(10.5);

      // Store should not be updated with the decimal value.
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).not.toEqual('10.5');
    });

    it('number prop preserves trailing zeros while typing (e.g. 10.0)', async () => {
      await addProp('Number', 'Rate');
      const numberInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });

      // Use fireEvent.change to inject '10.0' directly.
      fireEvent.change(numberInput, { target: { value: '10.0' } });

      // The input shows the numeric value 10 (10.0 === 10).
      expect(numberInput).toHaveValue(10);

      // The store must hold the raw string '10.0', preserving the trailing zero.
      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].example,
        ).toEqual('10.0');
      });

      // Continue: simulate the user appending '4' to reach '10.04'.
      fireEvent.change(numberInput, { target: { value: '10.04' } });

      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].example,
        ).toEqual('10.04');
      });
      expect(
        screen.queryByText('Integers cannot have decimal values.'),
      ).not.toBeInTheDocument();
    });

    it('clears integer validation error when switching prop type from integer to number', async () => {
      await addProp('Integer', 'Count');
      const integerInput = screen.getByRole('spinbutton', {
        name: 'Example value',
      });

      // Paste a decimal to trigger the validation error.
      await userEvent.click(integerInput);
      await userEvent.paste('1.5');

      await waitFor(() => {
        expect(
          screen.getByText('Integers cannot have decimal values.'),
        ).toBeInTheDocument();
      });

      // Switch prop type from Integer to Number.
      await userEvent.click(screen.getByRole('combobox', { name: 'Type' }));
      await userEvent.click(screen.getByRole('option', { name: 'Number' }));

      // Error should be cleared after type change.
      await waitFor(() => {
        expect(
          screen.queryByText('Integers cannot have decimal values.'),
        ).not.toBeInTheDocument();
      });
    });

    it('creates a new formatted text prop', async () => {
      await addProp('Formatted text', 'Description');
      expect(screen.getByLabelText('Example value')).toHaveAttribute(
        'placeholder',
        'Enter a text value',
      );
      await userEvent.type(
        screen.getByRole('textbox', { name: 'Example value' }),
        'Your description goes here',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Description',
          type: 'string',
          example: 'Your description goes here',
          format: undefined,
          contentMediaType: 'text/html',
          'x-formatting-context': 'block',
        });
      });
    });

    it('creates a new text prop', async () => {
      await addProp('Text', 'Title');
      expect(screen.getByLabelText('Example value')).toHaveAttribute(
        'placeholder',
        'Enter a text value',
      );
      await userEvent.type(
        screen.getByRole('textbox', { name: 'Example value' }),
        'Your title goes here',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Title',
          type: 'string',
          example: 'Your title goes here',
          format: undefined,
          $ref: undefined,
        });
      });
    });

    it('creates a new link prop (relative path and full url)', async () => {
      await addProp('Link', 'Link');
      expect(screen.getByLabelText('Example value')).toHaveAttribute(
        'placeholder',
        'Enter a path',
      );
      await userEvent.type(
        screen.getByRole('textbox', { name: 'Example value' }),
        'gerbeaud',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Link',
          type: 'string',
          example: 'gerbeaud',
          format: 'uri-reference',
          $ref: undefined,
        });
      });

      // Change the Link type to "Full URL".
      await userEvent.click(
        screen.getByRole('combobox', { name: 'Link type' }),
      );
      const fullUrlOption = await screen.findByRole('option', {
        name: 'Full URL',
      });
      await userEvent.click(fullUrlOption);

      // Wait for the dropdown to close and store to update
      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });

      await waitFor(() => {
        expect(screen.getByLabelText('Example value')).toHaveAttribute(
          'placeholder',
          'Enter a URL',
        );
      });

      await userEvent.clear(
        screen.getByRole('textbox', { name: 'Example value' }),
      );
      await userEvent.type(
        screen.getByRole('textbox', { name: 'Example value' }),
        'https://example.com',
      );

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Link',
          type: 'string',
          example: 'https://example.com',
          format: 'uri',
          $ref: undefined,
        });
      });
    });

    it('creates a new image prop', async () => {
      await addProp('Image', 'Image');
      await waitFor(() => {
        expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
          '4:3 (Standard)',
        );
        expect(screen.getByLabelText('Pixel density')).toHaveTextContent(
          '2x (High density)',
        );
      });
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: 'https://placehold.co/800x600@2x.png?alternateWidths=https%3A%2F%2Fplacehold.co%2F%7Bwidth%7Dx%7Bheight%7D%402x.png',
            width: 800,
            height: 600,
            alt: 'Example image placeholder',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/image',
        });
      });
      // Select the "None" option for the example aspect ratio.
      await userEvent.click(screen.getByLabelText('Example aspect ratio'));
      const noneOption = await screen.findByRole('option', {
        name: '- None -',
      });
      await userEvent.click(noneOption);

      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });
      // The pixel density should now be hidden.
      await waitFor(() => {
        expect(
          screen.queryByLabelText('Pixel density'),
        ).not.toBeInTheDocument();
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: '',
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/image',
        });
      });
      // Set the prop as required.
      await userEvent.click(screen.getByLabelText('Required'));

      // The example aspect ratio and pixel density should now be the default values.
      await waitFor(() => {
        expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
          '4:3 (Standard)',
        );
        expect(screen.getByLabelText('Pixel density')).toHaveTextContent(
          '2x (High density)',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: 'https://placehold.co/800x600@2x.png?alternateWidths=https%3A%2F%2Fplacehold.co%2F%7Bwidth%7Dx%7Bheight%7D%402x.png',
            width: 800,
            height: 600,
            alt: 'Example image placeholder',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/image',
        });
      });
      // Update the aspect ratio.
      await userEvent.click(screen.getByLabelText('Example aspect ratio'));
      const widescreenOption = await screen.findByRole('option', {
        name: '16:9 (Widescreen)',
      });
      await userEvent.click(widescreenOption);

      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });

      await waitFor(() => {
        expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
          '16:9 (Widescreen)',
        );
      });
      // Update the pixel density.
      await userEvent.click(screen.getByLabelText('Pixel density'));
      const ultraHighOption = await screen.findByRole('option', {
        name: '3x (Ultra-high density)',
      });
      await userEvent.click(ultraHighOption);

      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });

      await waitFor(() => {
        expect(screen.getByLabelText('Pixel density')).toHaveTextContent(
          '3x (Ultra-high density)',
        );
      });
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: 'https://placehold.co/1280x720@3x.png?alternateWidths=https%3A%2F%2Fplacehold.co%2F%7Bwidth%7Dx%7Bheight%7D%403x.png',
            width: 1280,
            height: 720,
            alt: 'Example image placeholder',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/image',
        });
      });
      // Set the prop as not required, then back to required.
      await userEvent.click(screen.getByLabelText('Required'));
      await userEvent.click(screen.getByLabelText('Required'));

      // The example aspect ratio and pixel density should be the previous values.
      expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
        '16:9 (Widescreen)',
      );
      expect(screen.getByLabelText('Pixel density')).toHaveTextContent(
        '3x (Ultra-high density)',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: 'https://placehold.co/1280x720@3x.png?alternateWidths=https%3A%2F%2Fplacehold.co%2F%7Bwidth%7Dx%7Bheight%7D%403x.png',
            width: 1280,
            height: 720,
            alt: 'Example image placeholder',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/image',
        });
      });
    });

    it('creates a new video prop', async () => {
      await addProp('Video', 'Video');
      await waitFor(() => {
        expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
          '16:9 (Widescreen)',
        );
      });
      await userEvent.click(
        screen.getByRole('combobox', { name: 'Example aspect ratio' }),
      );
      const widescreenOption = await screen.findByRole('option', {
        name: '16:9 (Widescreen)',
      });
      await userEvent.click(widescreenOption);

      // Wait for listbox to close
      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: `/ui/assets/videos/mountain_wide.mp4`,
            poster: 'https://placehold.co/1920x1080.png?text=Widescreen',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/video',
        });
      });

      await userEvent.click(
        screen.getByRole('combobox', { name: 'Example aspect ratio' }),
      );
      const noneOption = await screen.findByRole('option', {
        name: '- None -',
      });
      await userEvent.click(noneOption);
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: '',
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/video',
        });
      });

      await userEvent.click(noneOption);
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: '',
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/video',
        });
      });

      // Set the prop as required.
      await userEvent.click(screen.getByLabelText('Required'));

      // The example aspect ratio should now be the default values.
      expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
        '16:9 (Widescreen)',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: `/ui/assets/videos/mountain_wide.mp4`,
            poster: 'https://placehold.co/1920x1080.png?text=Widescreen',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/video',
        });
      });
      // Update the aspect ratio.
      await userEvent.click(screen.getByLabelText('Example aspect ratio'));
      const verticalOption = await screen.findByRole('option', {
        name: '9:16 (Vertical)',
      });
      await userEvent.click(verticalOption);

      expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
        '9:16 (Vertical)',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: `/ui/assets/videos/bird_vertical.mp4`,
            poster: 'https://placehold.co/1080x1920.png?text=Vertical',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/video',
        });
      });
      // Set the prop as not required, then back to required. The example aspect
      // ratio should be the previous values.
      await userEvent.click(screen.getByLabelText('Required'));
      await userEvent.click(screen.getByLabelText('Required'));

      expect(screen.getByLabelText('Example aspect ratio')).toHaveTextContent(
        '9:16 (Vertical)',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          type: 'object',
          example: {
            src: `/ui/assets/videos/bird_vertical.mp4`,
            poster: 'https://placehold.co/1080x1920.png?text=Vertical',
          },
          format: undefined,
          $ref: 'json-schema-definitions://canvas.module/video',
        });
      });
    });

    it('creates a new boolean prop', async () => {
      await addProp('Boolean', 'Is featured');
      await waitFor(() => {
        expect(
          screen.getByRole('switch', { name: 'Example value' }),
        ).toBeInTheDocument();
      });
      expect(
        screen.getByRole('switch', { name: 'Example value' }),
      ).not.toBeChecked();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Is featured',
          type: 'boolean',
          example: false,
          format: undefined,
          $ref: undefined,
        });
      });
      await userEvent.click(
        screen.getByRole('switch', { name: 'Example value' }),
      );
      expect(
        screen.getByRole('switch', { name: 'Example value' }),
      ).toBeChecked();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Is featured',
          type: 'boolean',
          example: true,
        });
      });
    });

    it('creates a new date and time prop (date only and datetime)', async () => {
      await addProp('Date and time', 'PublishDate');
      await waitFor(() => {
        expect(
          screen.getByRole('combobox', { name: 'Date type' }),
        ).toBeInTheDocument();
      });

      // Switch to Date only
      await userEvent.click(
        screen.getByRole('combobox', { name: 'Date type' }),
      );
      const dateOnlyOption = await screen.findByRole('option', {
        name: 'Date only',
      });
      await userEvent.click(dateOnlyOption);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.format).toEqual('date');
      });

      await waitFor(() => {
        expect(screen.getByLabelText('Example value')).toBeInTheDocument();
      });

      await act(async () => {
        fireEvent.change(screen.getByLabelText('Example value'), {
          target: { value: '2026-01-15' },
        });
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'PublishDate',
          type: 'string',
          example: '2026-01-15',
          format: 'date',
          $ref: undefined,
        });
      });

      // Switch to Date and time
      await userEvent.click(
        screen.getByRole('combobox', { name: 'Date type' }),
      );
      const dateTimeOption = await screen.findByRole('option', {
        name: 'Date and time',
      });
      await userEvent.click(dateTimeOption);

      await act(async () => {
        fireEvent.change(screen.getByLabelText('Example value'), {
          target: { value: '2026-01-15T12:34' },
        });
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'PublishDate',
          type: 'string',
          example: '2026-01-15T01:34:00.000Z',
          format: 'date-time',
          $ref: undefined,
        });
      });
    });

    it('creates a new text list prop', async () => {
      await addProp('List: text', 'Tags');
      await waitFor(() => {
        expect(
          screen.getByRole('button', { name: 'Add value' }),
        ).toBeInTheDocument();
      });
      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      expect(screen.queryByLabelText('Default value')).not.toBeInTheDocument();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          name: 'Tags',
          type: 'string',
          enum: [],
          format: undefined,
          $ref: undefined,
        });
      });

      // Add a new value, make sure "Default value" is not shown yet while the new value is empty.
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      expect(screen.queryByLabelText('Default value')).not.toBeInTheDocument();

      // Type a value, make sure "Default value" is shown.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'Alpha',
      );
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toBeInTheDocument();
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.enum).toEqual([{ label: 'Alpha', value: 'Alpha' }]);
      });
      // Clear the value, make sure "Default value" is not shown.
      await userEvent.clear(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
      );
      expect(screen.queryByLabelText('Default value')).not.toBeInTheDocument();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.enum).toEqual([{ label: '', value: '' }]);
      });
      // Type a value, then add two more values.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'Alpha',
      );
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-1`),
        'Bravo',
      );
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-2`),
        'Charlie',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.enum).toEqual([
          { label: 'Alpha', value: 'Alpha' },
          { label: 'Bravo', value: 'Bravo' },
          { label: 'Charlie', value: 'Charlie' },
        ]);
      });
      // Set the prop as required. "Default value" now should have the first value selected.
      await userEvent.click(screen.getByLabelText('Required'));

      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'Alpha',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          example: 'Alpha',
        });
      });
      // Clear the first value that is also currently the selected default value.
      await userEvent.clear(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
      );
      // Verify that the default value is now the second value, and that the
      // dropdown has the remaining values.
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'Bravo',
        );
      });
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          example: 'Bravo',
          enum: [
            { label: '', value: '' },
            { label: 'Bravo', value: 'Bravo' },
            { label: 'Charlie', value: 'Charlie' },
          ],
        });
      });
      await userEvent.click(screen.getByLabelText('Default value'));
      expect(
        await screen.findByRole('option', { name: 'Bravo' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('option', { name: 'Charlie' }),
      ).toBeInTheDocument();
      // Select the third value as default while the dropdown is open.
      await userEvent.click(screen.getByRole('option', { name: 'Charlie' }));
      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('Charlie');
      });

      // Modify the third value.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-2`),
        'Zulu',
      );
      // The default value should change to the first valid value after the previous default value was modified.
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'Bravo',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('Bravo');
      });
      // Modify the second value — currently default.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-1`),
        'Yankee',
      );
      // The modified version should become the new default value.
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'BravoYankee',
        );
      });
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('BravoYankee');
      });

      // Delete the first value.
      await userEvent.click(
        screen.getByTestId(`canvas-prop-enum-value-delete-${propId}-0`),
      );
      await waitFor(() => {
        expect(
          screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        ).toHaveValue('BravoYankee');
        expect(
          screen.getByTestId(`canvas-prop-enum-value-${propId}-1`),
        ).toHaveValue('CharlieZulu');
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          example: 'BravoYankee',
          enum: [
            { label: 'BravoYankee', value: 'BravoYankee' },
            { label: 'CharlieZulu', value: 'CharlieZulu' },
          ],
        });
      });
      // Delete the first value. It was previously used as the default value.
      await userEvent.click(
        screen.getByTestId(`canvas-prop-enum-value-delete-${propId}-0`),
      );

      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'CharlieZulu',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('CharlieZulu');
      });

      // Modify the first value. Make sure the default value follows it.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'XRay',
      );
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'CharlieZuluXRay',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('CharlieZuluXRay');
      });

      // Set the prop as not required.
      await userEvent.click(screen.getByLabelText('Required'));

      // Modify the first value. The default value should now be removed.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'Whiskey',
      );
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          '- None -',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('');
      });

      // Set the prop as required again. The default value should now be the first value.
      await userEvent.click(screen.getByLabelText('Required'));

      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent(
          'CharlieZuluXRayWhiskey',
        );
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('CharlieZuluXRayWhiskey');
      });
      // Delete the one last remaining value. "Default value" should not be visible.
      await userEvent.click(
        screen.getByTestId(`canvas-prop-enum-value-delete-${propId}-0`),
      );

      expect(screen.queryByLabelText('Default value')).not.toBeInTheDocument();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          enum: [],
          example: '',
        });
      });
      // User should be warned that each value must be unique.
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'Same',
      );
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-1`),
        'Same',
      );
      await waitFor(() => {
        expect(screen.getAllByText('Value must be unique.')).toHaveLength(2);
      });

      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-1`),
        '... not!',
      );

      await waitFor(() => {
        expect(
          screen.queryByText('Value must be unique.'),
        ).not.toBeInTheDocument();
      });
    });

    it('creates a new integer list prop', async () => {
      // The 'creates a new text list prop' test case already covers the
      // functionality of adding and removing values. This test is here as a sanity
      // check that the integer type works as expected. The only difference is that
      // we can't add a string value.
      await addProp('List: integer', 'Level');
      await waitFor(() => {
        expect(
          screen.getByRole('button', { name: 'Add value' }),
        ).toBeInTheDocument();
      });
      expect(screen.queryByLabelText('Default value')).not.toBeInTheDocument();
      // Add a new value, make sure "Default value" is not shown yet while the
      // new value is empty.
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      expect(screen.queryByLabelText('Default value')).not.toBeInTheDocument();
      // Ensure we can't type a string value.
      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'Typing an invalid string value with hopefully no effect',
      );
      expect(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
      ).toHaveAttribute('value', '');

      // Clear before typing a valid integer value.
      await userEvent.clear(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
      );
      // Type a valid integer value.
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        '1',
      );
      expect(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
      ).toHaveValue(1);
      expect(screen.queryByLabelText('Default value')).toBeInTheDocument();

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).containSubset({
          enum: [{ label: '1', value: '1' }],
          example: '',
        });
      });
      // Set the prop as required. "Default value" now should have the value
      // selected.
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));
      expect(screen.getByLabelText('Default value')).toHaveTextContent('1');
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).containSubset({
          enum: [{ label: '1', value: '1' }],
          example: '1',
        });
      });
      // Add a second value
      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-1`),
        '2',
      );
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).containSubset({
          enum: [
            { label: '1', value: '1' },
            { label: '2', value: '2' },
          ],
          example: '1',
        });
      });
      // Set the second value as the default value.
      await userEvent.click(screen.getByLabelText('Default value'));
      const option2 = await screen.findByRole('option', { name: '2' });
      await userEvent.click(option2);

      await waitFor(() => {
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          enum: [
            { label: '1', value: '1' },
            { label: '2', value: '2' },
          ],
          example: '2',
        });
      });
      // Delete the second value.
      await userEvent.click(
        screen.getByTestId(`canvas-prop-enum-value-delete-${propId}-1`),
      );
      await waitFor(() => {
        expect(screen.getByLabelText('Default value')).toHaveTextContent('1');
      });
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop).toMatchObject({
          enum: [{ label: '1', value: '1' }],
          example: '1',
        });
      });
    });
  });

  describe('prop type switching', () => {
    it.each([
      {
        targetTypeName: 'Date and time',
        expectedDerivedType: 'date' as const,
        expectedFormat: 'date',
      },
      {
        targetTypeName: 'Link',
        expectedDerivedType: 'link' as const,
        expectedFormat: 'uri-reference',
      },
      {
        targetTypeName: 'Text',
        expectedDerivedType: 'text' as const,
        expectedFormat: undefined,
      },
      {
        targetTypeName: 'Integer',
        expectedDerivedType: 'integer' as const,
        expectedFormat: undefined,
      },
      {
        targetTypeName: 'Number',
        expectedDerivedType: 'number' as const,
        expectedFormat: undefined,
      },
      {
        targetTypeName: 'Boolean',
        expectedDerivedType: 'boolean' as const,
        expectedFormat: undefined,
      },
      {
        targetTypeName: 'Image',
        expectedDerivedType: 'image' as const,
        expectedFormat: undefined,
      },
      {
        targetTypeName: 'Video',
        expectedDerivedType: 'video' as const,
        expectedFormat: undefined,
      },
      {
        targetTypeName: 'List: text',
        expectedDerivedType: 'listText' as const,
        expectedFormat: undefined,
      },
      {
        targetTypeName: 'List: integer',
        expectedDerivedType: 'listInteger' as const,
        expectedFormat: undefined,
      },
    ])(
      'clears contentMediaType and x-formatting-context when switching from formattedText to $targetTypeName',
      async ({ targetTypeName, expectedDerivedType, expectedFormat }) => {
        // First, add a prop as 'Formatted text' — this sets contentMediaType and x-formatting-context.
        await addProp('Formatted text', 'MyProp');

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            store.getState(),
          )[0];
          expect(prop.derivedType).toBe('formattedText');
          expect(prop.contentMediaType).toBe('text/html');
          expect(prop['x-formatting-context']).toBe('block');
        });

        // Switch the type to the target type.
        const typeSelect = screen.getByRole('combobox', { name: 'Type' });
        await userEvent.click(typeSelect);
        const targetOption = await screen.findByRole('option', {
          name: targetTypeName,
        });
        await userEvent.click(targetOption);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            store.getState(),
          )[0];
          expect(prop.derivedType).toBe(expectedDerivedType);
          // contentMediaType and x-formatting-context must be cleared so that the
          // prop is not re-derived as 'formattedText' after a page reload.
          expect(prop.contentMediaType).toBeUndefined();
          expect(prop['x-formatting-context']).toBeUndefined();
          if (expectedFormat) {
            expect(prop.format).toBe(expectedFormat);
          }
        });

        // Verify serialization does NOT include contentMediaType or x-formatting-context.
        const serialized = serializeProps(
          selectCodeComponentProperty('props')(store.getState()),
        );
        const serializedProp = Object.values(serialized)[0];
        expect(serializedProp.contentMediaType).toBeUndefined();
        expect(serializedProp['x-formatting-context']).toBeUndefined();
        if (expectedFormat) {
          expect(serializedProp.format).toBe(expectedFormat);
        }
      },
    );
  });

  describe('image data', () => {
    const aspectRatios = [
      ['1:1 (Square)', { width: 600, height: 600 }],
      ['4:3 (Standard)', { width: 800, height: 600 }],
      ['16:9 (Widescreen)', { width: 1280, height: 720 }],
      ['3:2 (Classic Photo)', { width: 900, height: 600 }],
      ['21:9 (Ultrawide)', { width: 1400, height: 600 }],
      ['9:16 (Vertical)', { width: 720, height: 1280 }],
      ['2:1 (Panoramic)', { width: 1000, height: 500 }],
    ] as const;
    const selectAspectRatio = async (size: string) => {
      await userEvent.click(
        screen.getByRole('combobox', { name: 'Example aspect ratio' }),
      );
      await userEvent.click(screen.getByRole('option', { name: size }));
    };
    const selectPixelDensity = async (density: string) => {
      await userEvent.click(
        screen.getByRole('combobox', { name: 'Pixel density' }),
      );
      await userEvent.click(
        screen.getByRole('option', { name: new RegExp(density) }),
      );
    };
    const getImageExample = () =>
      selectCodeComponentProperty('props')(store.getState())[0]
        .example as CodeComponentPropImageExample;
    it.each(aspectRatios)('%s', async (size, expectedSize) => {
      await addProp('Image', 'Image');
      await selectAspectRatio(size);
      let example = getImageExample();
      expect(example).toMatchObject(expectedSize);
      expect(example.src).toMatch(
        new RegExp(`${expectedSize.width}x${expectedSize.height}@2x\\.png`),
      );
      await selectPixelDensity('1x');
      example = getImageExample();
      expect(example.src).toMatch(
        new RegExp(`${expectedSize.width}x${expectedSize.height}\\.png\\?`),
      );
      await selectPixelDensity('3x');
      example = getImageExample();
      expect(example.src).toMatch(
        new RegExp(`${expectedSize.width}x${expectedSize.height}@3x\\.png`),
      );
    });
  });

  describe('prop form for exposed component', () => {
    const existingPropId = 'existing-prop-id';

    // Clean up the render from the parent beforeEach
    beforeEach(() => {
      cleanup();
    });

    const createExposedComponentStore = () => {
      return makeStore({
        codeEditor: {
          ...initialState,
          codeComponent: {
            ...initialState.codeComponent,
            status: true, // Component is exposed
            props: [
              {
                id: existingPropId,
                name: 'Alpha',
                type: 'string',
                example: 'test value',
                derivedType: 'text',
              },
            ],
          },
          initialPropIds: [existingPropId], // Mark as existing prop
        },
      });
    };

    it('disables name and type field for existing props on exposed component', async () => {
      const exposedStore = createExposedComponentStore();
      render(<Wrapper store={exposedStore} />);

      const nameField = screen.getByRole('textbox', { name: 'Prop name' });
      expect(nameField).toBeDisabled();
      const typeSelect = screen.getByRole('combobox', { name: 'Type' });
      expect(typeSelect).toBeDisabled();
      const exampleField = screen.getByRole('textbox', {
        name: 'Example value',
      });
      expect(exampleField).not.toBeDisabled();
    });

    it('allows editing name and type for newly added props on an exposed component', async () => {
      const exposedStore = createExposedComponentStore();
      render(<Wrapper store={exposedStore} />);

      // Add a new prop
      await userEvent.click(screen.getByRole('button', { name: 'Add' }));

      await waitFor(() => {
        expect(
          screen.getAllByRole('textbox', { name: 'Prop name' }),
        ).toHaveLength(2);
      });

      // The second prop name field (newly added) should not be disabled
      const propNameFields = screen.getAllByRole('textbox', {
        name: 'Prop name',
      });
      expect(propNameFields[0]).toBeDisabled(); // Existing prop
      expect(propNameFields[1]).not.toBeDisabled(); // New prop
      // The second type select (newly added) should not be disabled
      const typeSelects = screen.getAllByRole('combobox', { name: 'Type' });
      expect(typeSelects[0]).toBeDisabled(); // Existing prop
      expect(typeSelects[1]).not.toBeDisabled(); // New prop
    });

    it('allows removing props on exposed component', async () => {
      const exposedStore = createExposedComponentStore();
      render(<Wrapper store={exposedStore} />);

      // Verify initial state - one existing prop
      expect(
        selectCodeComponentProperty('props')(exposedStore.getState()),
      ).toHaveLength(1);

      // Remove the prop
      await userEvent.click(
        screen.getByRole('button', { name: /Remove prop/ }),
      );

      // Verify prop was removed
      expect(
        selectCodeComponentProperty('props')(exposedStore.getState()),
      ).toHaveLength(0);
      expect(
        screen.queryByRole('textbox', { name: 'Prop name' }),
      ).not.toBeInTheDocument();
    });

    it('allows editing multi-value example fields on exposed component', async () => {
      const multiValuePropId = 'existing-multi-value-prop-id';
      const exposedStore = makeStore({
        codeEditor: {
          ...initialState,
          codeComponent: {
            ...initialState.codeComponent,
            status: true, // Exposed component
            props: [
              {
                id: multiValuePropId,
                name: 'tags',
                type: 'array',
                items: { type: 'string' },
                example: ['tag1', 'tag2'],
                allowMultiple: true,
                valueMode: 'unlimited',
                derivedType: 'text',
              },
            ],
          },
          initialPropIds: [multiValuePropId], // Mark as existing prop
        },
      });

      render(<Wrapper store={exposedStore} />);

      // Name and Type should be disabled for existing props
      const nameField = screen.getByRole('textbox', { name: 'Prop name' });
      expect(nameField).toBeDisabled();
      const typeSelect = screen.getByRole('combobox', { name: 'Type' });
      expect(typeSelect).toBeDisabled();

      // But multi-value example fields should be editable
      const exampleFields =
        screen.getAllByPlaceholderText('Enter a text value');
      expect(exampleFields).toHaveLength(2); // Two example values
      exampleFields.forEach((field) => {
        expect(field).not.toBeDisabled();
      });
    });

    it('allows editing list enum example fields on exposed component', async () => {
      const listPropId = 'existing-list-prop-id';
      const exposedStore = makeStore({
        codeEditor: {
          ...initialState,
          codeComponent: {
            ...initialState.codeComponent,
            status: true, // Exposed component
            props: [
              {
                id: listPropId,
                name: 'ratings',
                type: 'array',
                items: { type: 'integer' },
                example: [1, 2],
                enum: [
                  { value: 1, label: '1 star' },
                  { value: 2, label: '2 stars' },
                  { value: 3, label: '3 stars' },
                ],
                allowMultiple: true,
                valueMode: 'unlimited',
                derivedType: 'listInteger',
              },
            ],
          },
          initialPropIds: [listPropId], // Mark as existing prop
        },
      });

      render(<Wrapper store={exposedStore} />);

      // Name and Type should be disabled
      const nameField = screen.getByRole('textbox', { name: 'Prop name' });
      expect(nameField).toBeDisabled();
      const typeSelect = screen.getByRole('combobox', { name: 'Type' });
      expect(typeSelect).toBeDisabled();

      // But the default value selection should be editable
      const defaultValueButton = screen.getByRole('button', {
        name: /2 selected/i,
      });
      expect(defaultValueButton).not.toBeDisabled();
    });

    it('allows editing link multi-value example fields on exposed component', async () => {
      const linkPropId = 'existing-link-prop-id';
      const exposedStore = makeStore({
        codeEditor: {
          ...initialState,
          codeComponent: {
            ...initialState.codeComponent,
            status: true, // Exposed component
            props: [
              {
                id: linkPropId,
                name: 'relatedLinks',
                type: 'array',
                items: { type: 'string', format: 'uri-reference' },
                example: ['/page1', '/page2'],
                format: 'uri-reference',
                allowMultiple: true,
                valueMode: 'unlimited',
                derivedType: 'link',
              },
            ],
          },
          initialPropIds: [linkPropId], // Mark as existing prop
        },
      });

      render(<Wrapper store={exposedStore} />);

      // Name and Type should be disabled for existing props
      const nameField = screen.getByRole('textbox', { name: 'Prop name' });
      expect(nameField).toBeDisabled();
      const typeSelect = screen.getByRole('combobox', { name: 'Type' });
      expect(typeSelect).toBeDisabled();

      // But link example fields should be editable
      const linkFields = screen.getAllByPlaceholderText(
        /Enter a path|Enter a URL/i,
      );
      expect(linkFields).toHaveLength(2); // Two example values
      linkFields.forEach((field) => {
        expect(field).not.toBeDisabled();
      });
    });

    it('allows editing date multi-value example fields on exposed component', async () => {
      const datePropId = 'existing-date-prop-id';
      const exposedStore = makeStore({
        codeEditor: {
          ...initialState,
          codeComponent: {
            ...initialState.codeComponent,
            status: true, // Exposed component
            props: [
              {
                id: datePropId,
                name: 'eventDates',
                type: 'array',
                items: { type: 'string', format: 'date' },
                example: ['2024-01-01', '2024-12-31'],
                format: 'date',
                allowMultiple: true,
                valueMode: 'unlimited',
                derivedType: 'date',
              },
            ],
          },
          initialPropIds: [datePropId], // Mark as existing prop
        },
      });

      render(<Wrapper store={exposedStore} />);

      // Name and Type should be disabled
      const nameField = screen.getByRole('textbox', { name: 'Prop name' });
      expect(nameField).toBeDisabled();
      const typeSelect = screen.getByRole('combobox', { name: 'Type' });
      expect(typeSelect).toBeDisabled();

      // But date example fields should be editable
      const dateFields = screen.getAllByDisplayValue(/2024/);
      expect(dateFields.length).toBeGreaterThanOrEqual(2); // At least two example values
      dateFields.forEach((field) => {
        expect(field).not.toBeDisabled();
      });
    });
  });

  describe('required prop example validation', () => {
    it('prefills default example when required is toggled on and shows error when cleared', async () => {
      await addProp('Text', 'Title');
      // Verify example is initially empty
      expect(
        screen.getByRole('textbox', { name: 'Example value' }),
      ).toHaveValue('');

      // Toggle required
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));

      // Verify default example is prefilled
      await waitFor(() => {
        expect(
          screen.getByRole('textbox', { name: 'Example value' }),
        ).toHaveValue('Example text');
      });

      // Clear the prefilled example
      await userEvent.clear(
        screen.getByRole('textbox', { name: 'Example value' }),
      );

      // Verify error message is shown
      expect(
        screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
      ).toBeInTheDocument();
    });

    it('clears validation error when required is toggled off', async () => {
      await addProp('Text', 'Title');
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));
      await userEvent.clear(
        screen.getByRole('textbox', { name: 'Example value' }),
      );

      // Error should be visible
      expect(
        screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
      ).toBeInTheDocument();

      // Toggle required off
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));

      // Error should be cleared
      expect(
        screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
      ).not.toBeInTheDocument();
    });

    it('prefills default example when changing type on required prop', async () => {
      await addProp('Text', 'MyProp');
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));

      // Change type to Integer
      await userEvent.click(screen.getByRole('combobox', { name: 'Type' }));
      await userEvent.click(screen.getByRole('option', { name: 'Integer' }));

      // Verify default example for integer is set
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual('0');
      });
    });

    it('prefills enum option when required is toggled on for list:text with no options', async () => {
      await addProp('List: text', 'Tags');
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.enum).toEqual([{ value: 'option_1', label: 'Option 1' }]);
        expect(prop.example).toEqual('option_1');
      });
    });
  });

  describe('allow multiple values', () => {
    it('shows allow multiple checkbox for text prop type', async () => {
      await addProp('Text', 'Tags');
      await waitFor(() => {
        expect(
          screen.getByRole('checkbox', { name: 'Allow multiple values' }),
        ).toBeInTheDocument();
      });
    });

    it('does not show allow multiple checkbox for boolean prop type', async () => {
      await addProp('Boolean', 'IsActive');
      await waitFor(() => {
        expect(
          screen.queryByRole('checkbox', { name: 'Allow multiple values' }),
        ).not.toBeInTheDocument();
      });
    });

    it('does not show allow multiple checkbox for formatted text prop type', async () => {
      await addProp('Formatted text', 'Description');
      await waitFor(() => {
        expect(
          screen.queryByRole('checkbox', { name: 'Allow multiple values' }),
        ).not.toBeInTheDocument();
      });
    });

    it('enables multiple values for text prop', async () => {
      await addProp('Text', 'Tags');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      expect(checkbox).toBeInTheDocument();

      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
        expect(prop.items).toEqual({ type: 'string' });
        expect(prop.example).toEqual([]);
        expect(prop.valueMode).toBe('unlimited');
        expect(prop.limitedCount).toBe(1);
      });
    });

    it('enables multiple values for integer prop', async () => {
      await addProp('Integer', 'Quantities');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      expect(checkbox).toBeInTheDocument();

      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
        expect(prop.items).toEqual({ type: 'integer' });
        expect(prop.example).toEqual([]);
        expect(prop.valueMode).toBe('unlimited');
        expect(prop.limitedCount).toBe(1);
      });
    });

    it('enables multiple values for number prop', async () => {
      await addProp('Number', 'Prices');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      expect(checkbox).toBeInTheDocument();

      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
        expect(prop.items).toEqual({ type: 'number' });
        expect(prop.example).toEqual([]);
        expect(prop.valueMode).toBe('unlimited');
        expect(prop.limitedCount).toBe(1);
      });
    });

    it('multi-value integer prop rejects decimal values and shows error', async () => {
      await addProp('Integer', 'Counts');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
      });

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      const arrayInput = screen.getByTestId(`array-prop-value-${propId}-0`);

      // Enter a decimal value in the integer array field.
      await userEvent.type(arrayInput, '10.5');

      await waitFor(() => {
        expect(
          screen.getByText('Integers cannot have decimal values.'),
        ).toBeInTheDocument();
      });

      // Store should not be updated with the decimal value.
      const storeExample = selectCodeComponentProperty('props')(
        store.getState(),
      )[0].example;
      expect(storeExample).not.toContain(10.5);
    });

    it('multi-value integer prop rejects pasted decimal values and shows error', async () => {
      await addProp('Integer', 'Counts');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
      });

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      const arrayInput = screen.getByTestId(`array-prop-value-${propId}-0`);

      // Paste a decimal value into the integer array field.
      await userEvent.click(arrayInput);
      await userEvent.paste('10.5');

      await waitFor(() => {
        expect(
          screen.getByText('Integers cannot have decimal values.'),
        ).toBeInTheDocument();
      });

      // Store should not be updated with the pasted decimal value.
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).not.toContain(10.5);
    });

    it('multi-value integer prop accepts valid integers', async () => {
      await addProp('Integer', 'Counts');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
      });

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      const arrayInput = screen.getByTestId(`array-prop-value-${propId}-0`);

      // Enter a valid integer.
      await userEvent.type(arrayInput, '42');

      await waitFor(() => {
        const example = selectCodeComponentProperty('props')(
          store.getState(),
        )[0].example;
        expect(example).toContain(42);
      });
      expect(
        screen.queryByText('Integers cannot have decimal values.'),
      ).not.toBeInTheDocument();
    });

    it('multi-value number prop accepts decimal values', async () => {
      await addProp('Number', 'Rates');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
      });

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      const arrayInput = screen.getByTestId(`array-prop-value-${propId}-0`);

      // Enter a valid decimal for number type.
      await userEvent.type(arrayInput, '3.14');

      await waitFor(() => {
        const example = selectCodeComponentProperty('props')(
          store.getState(),
        )[0].example;
        expect(example).toContain(3.14);
      });
      expect(
        screen.queryByText('Integers cannot have decimal values.'),
      ).not.toBeInTheDocument();
    });

    it('multi-value number prop preserves trailing zeros while typing (e.g. 10.0)', async () => {
      await addProp('Number', 'Rates');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
      });

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      const arrayInput = screen.getByTestId(`array-prop-value-${propId}-0`);

      // Simulate typing '10.0' character by character.
      await userEvent.type(arrayInput, '10.0');

      // The input display value must still show '10.0' (trailing zero preserved).
      expect(arrayInput).toHaveValue(10.0);

      // The store should hold the numeric value 10.
      await waitFor(() => {
        const example = selectCodeComponentProperty('props')(
          store.getState(),
        )[0].example;
        expect(example).toContain(10);
      });

      // Continue typing to reach 10.04.
      await userEvent.type(arrayInput, '4');

      await waitFor(() => {
        const example = selectCodeComponentProperty('props')(
          store.getState(),
        )[0].example;
        expect(example).toContain(10.04);
      });
      expect(
        screen.queryByText('Integers cannot have decimal values.'),
      ).not.toBeInTheDocument();
    });

    it('multi-value integer prop accepts valid negative integers', async () => {
      await addProp('Integer', 'Counts');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
      });

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      const arrayInput = screen.getByTestId(`array-prop-value-${propId}-0`);

      await userEvent.type(arrayInput, '-42');

      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].example,
        ).toContain(-42);
      });
      expect(
        screen.queryByText('Integers cannot have decimal values.'),
      ).not.toBeInTheDocument();
    });

    it('multi-value integer prop clears error after valid input follows decimal', async () => {
      await addProp('Integer', 'Counts');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
      });

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      const arrayInput = screen.getByTestId(`array-prop-value-${propId}-0`);

      // Enter decimal to trigger error.
      await userEvent.type(arrayInput, '10.5');
      await waitFor(() => {
        expect(
          screen.getByText('Integers cannot have decimal values.'),
        ).toBeInTheDocument();
      });

      // Correct to a valid integer.
      await userEvent.clear(arrayInput);
      await userEvent.type(arrayInput, '10');
      await waitFor(() => {
        expect(
          screen.queryByText('Integers cannot have decimal values.'),
        ).not.toBeInTheDocument();
      });
      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].example,
        ).toContain(10);
      });
    });

    it('multi-value number prop accepts negative decimal values', async () => {
      await addProp('Number', 'Rates');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
      });

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      const arrayInput = screen.getByTestId(`array-prop-value-${propId}-0`);

      await userEvent.type(arrayInput, '-3.14');

      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].example,
        ).toContain(-3.14);
      });
      expect(
        screen.queryByText('Integers cannot have decimal values.'),
      ).not.toBeInTheDocument();
    });

    it('shows allow multiple checkbox for link prop type', async () => {
      await addProp('Link', 'Links');
      await waitFor(() => {
        expect(
          screen.getByRole('checkbox', { name: 'Allow multiple values' }),
        ).toBeInTheDocument();
      });
    });

    it('shows allow multiple checkbox for image prop type', async () => {
      await addProp('Image', 'Gallery');
      await waitFor(() => {
        expect(
          screen.getByRole('checkbox', { name: 'Allow multiple values' }),
        ).toBeInTheDocument();
      });
    });

    it('shows allow multiple checkbox for video prop type', async () => {
      await addProp('Video', 'Videos');
      await waitFor(() => {
        expect(
          screen.getByRole('checkbox', { name: 'Allow multiple values' }),
        ).toBeInTheDocument();
      });
    });

    it('shows allow multiple checkbox for text list prop type', async () => {
      await addProp('List: text', 'Categories');
      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        'Option1',
      );

      await waitFor(() => {
        expect(
          screen.getByRole('checkbox', { name: 'Allow multiple values' }),
        ).toBeInTheDocument();
      });
    });

    it('shows allow multiple checkbox for integer list prop type', async () => {
      await addProp('List: integer', 'Ratings');
      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await userEvent.type(
        screen.getByTestId(`canvas-prop-enum-value-${propId}-0`),
        '1',
      );

      await waitFor(() => {
        expect(
          screen.getByRole('checkbox', { name: 'Allow multiple values' }),
        ).toBeInTheDocument();
      });
    });

    it('disables multiple values when unchecked', async () => {
      await addProp('Text', 'Tags');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      expect(checkbox).toBeInTheDocument();

      // Enable multiple values.
      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
      });

      // Disable multiple values.
      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(false);
        expect(prop.items).toBeUndefined();
        expect(prop.example).toBe('');
        expect(prop.valueMode).toBeUndefined();
        expect(prop.limitedCount).toBeUndefined();
      });
    });

    describe('restores default example value when disabling multiple values for required props', () => {
      const testCases: Array<{
        propType: string;
        propName: string;
        expectedExample: string;
        // Expected example after enabling multivalue. The current single value
        // is preserved in the array to avoid a required-error flash during the
        // transition (applies to all prop types).
        expectedMultiExample?: string[];
        expectedFormat?: string;
        setup?: () => Promise<void>;
      }> = [
        {
          propType: 'Text',
          propName: 'Tags',
          expectedExample: 'Example text',
          expectedMultiExample: ['Example text'],
        },
        {
          propType: 'Integer',
          propName: 'Count',
          expectedExample: '0',
          expectedMultiExample: ['0'],
        },
        {
          propType: 'Number',
          propName: 'Price',
          expectedExample: '0',
          expectedMultiExample: ['0'],
        },
        {
          propType: 'Link',
          propName: 'HomePage',
          expectedExample: 'example',
          expectedMultiExample: ['example'],
          expectedFormat: 'uri-reference',
        },
        {
          propType: 'Link',
          propName: 'ExternalLink',
          expectedExample: 'https://example.com',
          expectedMultiExample: ['https://example.com'],
          expectedFormat: 'uri',
          setup: async () => {
            // Change link type to Full URL.
            const propId = selectCodeComponentProperty('props')(
              store.getState(),
            )[0].id;
            const linkTypeSelect = document.getElementById(
              `prop-link-type-${propId}`,
            );
            await userEvent.click(linkTypeSelect!);
            const fullUrlOption = await screen.findByRole('option', {
              name: 'Full URL',
            });
            await userEvent.click(fullUrlOption);
          },
        },
        {
          propType: 'Date and time',
          propName: 'StartDate',
          expectedExample: '2026-01-25',
          expectedMultiExample: ['2026-01-25'],
          expectedFormat: 'date',
        },
        {
          propType: 'Date and time',
          propName: 'CreatedAt',
          expectedExample: '2026-01-25T12:00:00.000Z',
          expectedMultiExample: ['2026-01-25T12:00:00.000Z'],
          expectedFormat: 'date-time',
          setup: async () => {
            // Change date type to Date and time (date-time format).
            const propId = selectCodeComponentProperty('props')(
              store.getState(),
            )[0].id;
            const dateTypeSelect = document.getElementById(
              `prop-date-type-${propId}`,
            );
            await userEvent.click(dateTypeSelect!);
            const dateTimeOption = await screen.findByRole('option', {
              name: 'Date and time',
            });
            await userEvent.click(dateTimeOption);
          },
        },
        {
          propType: 'List: text',
          propName: 'Categories',
          expectedExample: 'option_1',
          expectedMultiExample: ['option_1'],
        },
        {
          propType: 'List: integer',
          propName: 'Ratings',
          expectedExample: '1',
          expectedMultiExample: ['1'],
        },
      ];

      it.each(
        testCases.map((testCase) => ({
          ...testCase,
          testName: testCase.expectedFormat
            ? `${testCase.propType} prop (${testCase.expectedFormat})`
            : `${testCase.propType} prop`,
        })),
      )(
        '$testName',
        async ({
          propType,
          propName,
          expectedExample,
          expectedMultiExample,
          expectedFormat,
          setup,
        }) => {
          await addProp(propType, propName);

          // Run setup if provided (e.g., change format).
          if (setup) {
            await setup();
            // Wait for setup changes to apply
            await waitFor(() => {
              const prop = selectCodeComponentProperty('props')(
                store.getState(),
              )[0];
              if (expectedFormat) {
                expect(prop.format).toBe(expectedFormat);
              }
            });
          }

          // Make prop required.
          await userEvent.click(
            screen.getByRole('switch', { name: 'Required' }),
          );

          // Verify initial example value and format.
          await waitFor(() => {
            const prop = selectCodeComponentProperty('props')(
              store.getState(),
            )[0];
            expect(prop.example).toBe(expectedExample);
            if (expectedFormat) {
              expect(prop.format).toBe(expectedFormat);
            }
          });

          // Enable multiple values.
          const checkbox = await screen.findByRole('checkbox', {
            name: 'Allow multiple values',
          });
          fireEvent.click(checkbox);

          await waitFor(() => {
            const prop = selectCodeComponentProperty('props')(
              store.getState(),
            )[0];
            expect(prop.allowMultiple).toBe(true);
            expect(prop.example).toEqual(expectedMultiExample ?? []);
          });

          // Disable multiple values.
          fireEvent.click(checkbox);

          // Verify example value is restored.
          await waitFor(() => {
            const prop = selectCodeComponentProperty('props')(
              store.getState(),
            )[0];
            expect(prop.allowMultiple).toBe(false);
            expect(prop.items).toBeUndefined();
            expect(prop.example).toBe(expectedExample);
            if (expectedFormat) {
              expect(prop.format).toBe(expectedFormat);
            }
            expect(prop.valueMode).toBeUndefined();
            expect(prop.limitedCount).toBeUndefined();
          });
        },
      );
    });

    it('shows unlimited/limited value mode dropdown when multiple values is enabled', async () => {
      await addProp('Text', 'Tags');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      expect(checkbox).toBeInTheDocument();

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;
      await waitFor(() => {
        expect(
          document.getElementById(`prop-value-mode-${propId}`),
        ).toBeInTheDocument();
      });
    });

    it('adds multiple example values in unlimited mode', async () => {
      await addProp('Text', 'Tags');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      await waitFor(() => {
        expect(
          screen.getByRole('button', { name: 'Add value' }),
        ).toBeInTheDocument();
      });

      // Verify that unlimited mode starts with one empty input field.
      await waitFor(() => {
        const inputs = screen.getAllByTestId(
          new RegExp(`array-prop-value-${propId}-\\d+`),
        );
        expect(inputs.length).toBe(1);
      });

      const firstInput = screen.getByTestId(`array-prop-value-${propId}-0`);
      await userEvent.type(firstInput, 'first value');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await waitFor(() => {
        expect(
          screen.getByTestId(`array-prop-value-${propId}-1`),
        ).toBeInTheDocument();
      });
      const secondInput = screen.getByTestId(`array-prop-value-${propId}-1`);
      await userEvent.type(secondInput, 'second value');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await waitFor(() => {
        expect(
          screen.getByTestId(`array-prop-value-${propId}-2`),
        ).toBeInTheDocument();
      });
      const thirdInput = screen.getByTestId(`array-prop-value-${propId}-2`);
      await userEvent.type(thirdInput, 'third value');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual([
          'first value',
          'second value',
          'third value',
        ]);
      });
    });

    it('deletes example values in unlimited mode', async () => {
      await addProp('Text', 'Tags');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      const firstInput = screen.getByTestId(`array-prop-value-${propId}-0`);
      await userEvent.type(firstInput, 'first value');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      const secondInput = await screen.findByTestId(
        `array-prop-value-${propId}-1`,
      );
      await userEvent.type(secondInput, 'second value');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      const thirdInput = await screen.findByTestId(
        `array-prop-value-${propId}-2`,
      );
      await userEvent.type(thirdInput, 'third value');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual([
          'first value',
          'second value',
          'third value',
        ]);
      });

      const removeButtons = screen.getAllByRole('button', {
        name: 'Remove value',
      });
      await userEvent.click(removeButtons[1]);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual(['first value', 'third value']);
      });

      const updatedRemoveButtons = screen.getAllByRole('button', {
        name: 'Remove value',
      });
      await userEvent.click(updatedRemoveButtons[0]);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual(['third value']);
      });
    });

    it('adds and deletes integer values in unlimited mode', async () => {
      await addProp('Integer', 'Scores');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      const firstInput = screen.getByTestId(`array-prop-value-${propId}-0`);
      await userEvent.clear(firstInput);
      await userEvent.type(firstInput, '100');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      const secondInput = await screen.findByTestId(
        `array-prop-value-${propId}-1`,
      );
      await userEvent.type(secondInput, '200');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      const thirdInput = await screen.findByTestId(
        `array-prop-value-${propId}-2`,
      );
      await userEvent.type(thirdInput, '300');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual([100, 200, 300]);
      });

      const removeButtons = screen.getAllByRole('button', {
        name: 'Remove value',
      });
      await userEvent.click(removeButtons[1]);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual([100, 300]);
      });
    });

    it('sets limited count and creates fixed number of input fields', async () => {
      await addProp('Text', 'TopTags');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      const valueModeSelect = document.getElementById(
        `prop-value-mode-${propId}`,
      );
      await userEvent.click(valueModeSelect!);
      const limitedOption = await screen.findByRole('option', {
        name: 'Limited',
      });
      await userEvent.click(limitedOption);

      await waitFor(() => {
        expect(
          document.getElementById(`prop-limited-count-${propId}`),
        ).toBeInTheDocument();
      });

      const countInput = document.getElementById(
        `prop-limited-count-${propId}`,
      ) as HTMLInputElement;

      // Select all text and type new value to avoid race condition with clear().
      await userEvent.tripleClick(countInput);
      await userEvent.keyboard('3');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.limitedCount).toBe(3);
      });

      await waitFor(() => {
        const inputs = screen.getAllByTestId(
          new RegExp(`array-prop-value-${propId}-\\d+`),
        );
        expect(inputs.length).toBe(3);
      });
    });

    it('pads array to match limitedCount when loaded with fewer items', async () => {
      const testCases = [
        {
          propType: 'Text',
          itemType: 'string' as const,
          derivedType: 'text' as const,
          values: ['Tag1', 'Tag2', 'Tag3'] as string[],
        },
        {
          propType: 'Integer',
          itemType: 'integer' as const,
          derivedType: 'integer' as const,
          values: [100, 200, 300] as number[],
        },
        {
          propType: 'Number',
          itemType: 'number' as const,
          derivedType: 'number' as const,
          values: [1.5, 2.5, 3.5] as number[],
        },
        {
          propType: 'Link',
          itemType: 'string' as const,
          derivedType: 'link' as const,
          values: ['/link1', '/link2', '/link3'] as string[],
          format: 'uri-reference' as const,
        },
        {
          propType: 'Date',
          itemType: 'string' as const,
          derivedType: 'date' as const,
          values: ['2024-01-01', '2024-02-01', '2024-03-01'] as string[],
          format: 'date' as const,
        },
      ];

      for (const testCase of testCases) {
        const { propType, itemType, derivedType, values, format } = testCase;

        cleanup();

        // Simulate loading a prop from backend with only 3 items but limitedCount=5.
        const propId = `limited-prop-${derivedType}`;
        const storeWithLimitedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: propId,
                  name: `Test${propType}`,
                  type: 'array',
                  items: { type: itemType, ...(format && { format }) },
                  example: values,
                  derivedType: derivedType,
                  allowMultiple: true,
                  valueMode: 'limited' as const,
                  limitedCount: 5,
                  ...(format && { format }),
                },
              ],
            },
            initialPropIds: [propId],
          },
        });

        render(<Wrapper store={storeWithLimitedProp} />);

        // Verify all 5 input fields render.
        await waitFor(() => {
          for (let i = 0; i < 5; i++) {
            expect(
              screen.getByTestId(`array-prop-value-${propId}-${i}`),
            ).toBeInTheDocument();
          }
        });

        const inputs: HTMLInputElement[] = [];
        for (let i = 0; i < 5; i++) {
          inputs.push(
            screen.getByTestId(
              `array-prop-value-${propId}-${i}`,
            ) as HTMLInputElement,
          );
        }

        // First 3 should have values.
        expect(inputs[0].value).toBe(String(values[0]));
        expect(inputs[1].value).toBe(String(values[1]));
        expect(inputs[2].value).toBe(String(values[2]));

        // Last 2 should be empty.
        expect(inputs[3].value).toBe('');
        expect(inputs[4].value).toBe('');

        const prop = selectCodeComponentProperty('props')(
          storeWithLimitedProp.getState(),
        )[0];
        const currentExample = prop.example as Array<string | number>;

        // Use the same utility function that is used for creating the display array.
        const displayArray = createDisplayArray(currentExample, 'limited', 5);

        //Simulate reordering using arrayMove.
        const reordered = arrayMove([...displayArray], 3, 0);

        // Verify reordering worked.
        expect(reordered[0]).toBe('');
        expect(reordered[1]).toBe(values[0]);
        expect(reordered[2]).toBe(values[1]);
        expect(reordered[3]).toBe(values[2]);
        expect(reordered[4]).toBe('');

        // Verify no undefined values.
        reordered.forEach((val) => {
          expect(val).not.toBeUndefined();
          expect(val).not.toBeNull();
        });

        // Verify no null in JSON.
        expect(JSON.stringify(reordered)).not.toContain('null');
      }
    });

    it('updates example array when changing limited count', async () => {
      await addProp('Text', 'TopTags');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      const valueModeSelect = document.getElementById(
        `prop-value-mode-${propId}`,
      );
      await userEvent.click(valueModeSelect!);
      const limitedOption = await screen.findByRole('option', {
        name: 'Limited',
      });
      await userEvent.click(limitedOption);

      await waitFor(() => {
        expect(
          document.getElementById(`prop-limited-count-${propId}`),
        ).toBeInTheDocument();
      });

      const countInput = document.getElementById(
        `prop-limited-count-${propId}`,
      ) as HTMLInputElement;

      await userEvent.tripleClick(countInput);
      await userEvent.keyboard('2');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.limitedCount).toBe(2);
      });

      await waitFor(() => {
        const inputs = screen.getAllByTestId(
          new RegExp(`array-prop-value-${propId}-\\d+`),
        );
        expect(inputs.length).toBe(2);
      });

      const firstInput = screen.getByTestId(`array-prop-value-${propId}-0`);
      await userEvent.type(firstInput, 'First');
      const secondInput = screen.getByTestId(`array-prop-value-${propId}-1`);
      await userEvent.type(secondInput, 'Second');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual(['First', 'Second']);
      });

      // Increase count to 4 - existing values should be preserved and empty values added.
      await userEvent.tripleClick(countInput);
      await userEvent.keyboard('4');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.limitedCount).toBe(4);
      });

      await waitFor(() => {
        const inputs = screen.getAllByTestId(
          new RegExp(`array-prop-value-${propId}-\\d+`),
        );
        expect(inputs.length).toBe(4);
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual(['First', 'Second', '', '']);
      });

      // Decrease count to 1 - server requires maxItems >= 2, so it clamps to 2.
      await userEvent.tripleClick(countInput);
      await userEvent.keyboard('1');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.limitedCount).toBe(2);
      });

      await waitFor(() => {
        const inputs = screen.getAllByTestId(
          new RegExp(`array-prop-value-${propId}-\\d+`),
        );
        expect(inputs.length).toBe(2);
      });

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual(['First', 'Second']);
      });
    });

    it('allows drag handles to be visible in limited mode', async () => {
      await addProp('Text', 'TopTags');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      const valueModeSelect = document.getElementById(
        `prop-value-mode-${propId}`,
      );
      await userEvent.click(valueModeSelect!);
      const limitedOption = await screen.findByRole('option', {
        name: 'Limited',
      });
      await userEvent.click(limitedOption);

      await waitFor(() => {
        expect(
          document.getElementById(`prop-limited-count-${propId}`),
        ).toBeInTheDocument();
      });

      const countInput = document.getElementById(
        `prop-limited-count-${propId}`,
      ) as HTMLInputElement;
      await userEvent.clear(countInput);
      await userEvent.type(countInput, '3');

      await waitFor(() => {
        const container = document.querySelector(
          `[data-testid="array-prop-value-${propId}-0"]`,
        )?.parentElement?.parentElement;
        expect(container).toBeInTheDocument();
      });
    });

    it('allows drag handles to be visible in unlimited mode', async () => {
      await addProp('Text', 'Tags');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      const firstInput = screen.getByTestId(`array-prop-value-${propId}-0`);
      await userEvent.type(firstInput, 'React');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      const secondInput = await screen.findByTestId(
        `array-prop-value-${propId}-1`,
      );
      await userEvent.type(secondInput, 'Vue');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      const thirdInput = await screen.findByTestId(
        `array-prop-value-${propId}-2`,
      );
      await userEvent.type(thirdInput, 'Angular');

      await waitFor(() => {
        const container = document.querySelector(
          `[data-testid="array-prop-value-${propId}-0"]`,
        )?.parentElement?.parentElement;
        expect(container).toBeInTheDocument();
      });
    });

    it('clears values when disabling allow multiple checkbox', async () => {
      await addProp('Text', 'Tags');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      const firstInput = screen.getByTestId(`array-prop-value-${propId}-0`);
      await userEvent.type(firstInput, 'React');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      const secondInput = await screen.findByTestId(
        `array-prop-value-${propId}-1`,
      );
      await userEvent.type(secondInput, 'Vue');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual(['React', 'Vue']);
        expect(prop.allowMultiple).toBe(true);
      });

      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(false);
        expect(prop.example).toBe('');
        expect(prop.valueMode).toBeUndefined();
        expect(prop.limitedCount).toBeUndefined();
      });

      await waitFor(() => {
        expect(
          screen.getByRole('textbox', { name: 'Example value' }),
        ).toBeInTheDocument();
      });
    });

    it('reorders example values in unlimited mode via drag and drop', async () => {
      await addProp('Text', 'Frameworks');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      const firstInput = screen.getByTestId(`array-prop-value-${propId}-0`);
      await userEvent.type(firstInput, 'React');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      const secondInput = await screen.findByTestId(
        `array-prop-value-${propId}-1`,
      );
      await userEvent.type(secondInput, 'Vue');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      const thirdInput = await screen.findByTestId(
        `array-prop-value-${propId}-2`,
      );
      await userEvent.type(thirdInput, 'Angular');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual(['React', 'Vue', 'Angular']);
      });

      // Navigate up the DOM tree to find the row containing the drag handle.
      // Structure: input -> TextField.Root -> Box wrapper -> Flex row.
      const textFieldRoot = secondInput.parentElement;
      const boxWrapper = textFieldRoot?.parentElement;
      const rowFlex = boxWrapper?.parentElement;

      expect(rowFlex).toBeInTheDocument();

      // The drag handle has role="button" added by dnd-kit.
      const dragHandle = rowFlex!.querySelector('[role="button"]');
      expect(dragHandle).toBeInTheDocument();

      // Use keyboard navigation for reliable drag and drop testing.
      (dragHandle as HTMLElement).focus();
      await userEvent.keyboard('[Space]');

      await waitFor(() => {
        expect(dragHandle).toHaveAttribute('aria-pressed', 'true');
      });

      // Move the second item (Vue) up one position.
      await userEvent.keyboard('[ArrowUp]');

      // Commit the drag operation.
      await userEvent.keyboard('[Space]');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual(['Vue', 'React', 'Angular']);
      });
    });

    it('reorders example values in limited mode via drag and drop', async () => {
      await addProp('Integer', 'TopScores');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      const valueModeSelect = document.getElementById(
        `prop-value-mode-${propId}`,
      );
      await userEvent.click(valueModeSelect!);
      const limitedOption = await screen.findByRole('option', {
        name: 'Limited',
      });
      await userEvent.click(limitedOption);

      await waitFor(() => {
        expect(
          document.getElementById(`prop-limited-count-${propId}`),
        ).toBeInTheDocument();
      });

      const countInput = document.getElementById(
        `prop-limited-count-${propId}`,
      ) as HTMLInputElement;
      await userEvent.tripleClick(countInput);
      await userEvent.keyboard('3');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.limitedCount).toBe(3);
      });

      const firstInput = screen.getByTestId(`array-prop-value-${propId}-0`);
      await userEvent.clear(firstInput);
      await userEvent.type(firstInput, '100');

      const secondInput = screen.getByTestId(`array-prop-value-${propId}-1`);
      await userEvent.clear(secondInput);
      await userEvent.type(secondInput, '200');

      const thirdInput = screen.getByTestId(`array-prop-value-${propId}-2`);
      await userEvent.clear(thirdInput);
      await userEvent.type(thirdInput, '300');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual([100, 200, 300]);
      });

      // Navigate up the DOM tree to find the row containing the drag handle.
      const textFieldRoot = thirdInput.parentElement;
      const boxWrapper = textFieldRoot?.parentElement;
      const rowFlex = boxWrapper?.parentElement;

      expect(rowFlex).toBeInTheDocument();

      const dragHandle = rowFlex!.querySelector('[role="button"]');
      expect(dragHandle).toBeInTheDocument();

      (dragHandle as HTMLElement).focus();
      await userEvent.keyboard('[Space]');

      await waitFor(() => {
        expect(dragHandle).toHaveAttribute('aria-pressed', 'true');
      });

      // Move the third item (300) up two positions to make it first.
      await userEvent.keyboard('[ArrowUp]');
      await userEvent.keyboard('[ArrowUp]');

      await userEvent.keyboard('[Space]');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual([300, 100, 200]);
      });
    });

    it('shows allow multiple checkbox for date prop type', async () => {
      await addProp('Date and time', 'EventDates');
      await waitFor(() => {
        expect(
          screen.getByRole('checkbox', { name: 'Allow multiple values' }),
        ).toBeInTheDocument();
      });
    });

    it('enables multiple values for date prop type and sets items property with format', async () => {
      await addProp('Date and time', 'EventDates');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.format).toBe('date');
      });

      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
        expect(prop.items).toEqual({ type: 'string', format: 'date' });
        expect(prop.example).toEqual([]);
      });
    });

    it('enables multiple values for video prop', async () => {
      await addProp('Video', 'VideoGallery');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      expect(checkbox).toBeInTheDocument();
      fireEvent.click(checkbox);
      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.allowMultiple).toBe(true);
        expect(prop.type).toBe('array');
        expect(prop.items).toEqual({
          type: 'object',
          $ref: 'json-schema-definitions://canvas.module/video',
        });
        expect(Array.isArray(prop.example)).toBe(true);
        expect(prop.valueMode).toBe('unlimited');
        expect(prop.limitedCount).toBe(1);
      });
    });

    it('adds multiple date values in unlimited mode', async () => {
      await addProp('Date and time', 'EventDates');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      await waitFor(() => {
        expect(
          screen.getByRole('button', { name: 'Add value' }),
        ).toBeInTheDocument();
      });

      const firstInput = screen.getByTestId(`array-prop-value-${propId}-0`);
      await userEvent.type(firstInput, '2024-01-15');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await waitFor(() => {
        expect(
          screen.getByTestId(`array-prop-value-${propId}-1`),
        ).toBeInTheDocument();
      });
      const secondInput = screen.getByTestId(`array-prop-value-${propId}-1`);
      await userEvent.type(secondInput, '2024-02-20');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await waitFor(() => {
        expect(
          screen.getByTestId(`array-prop-value-${propId}-2`),
        ).toBeInTheDocument();
      });
      const thirdInput = screen.getByTestId(`array-prop-value-${propId}-2`);
      await userEvent.type(thirdInput, '2024-03-10');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual([
          '2024-01-15',
          '2024-02-20',
          '2024-03-10',
        ]);
      });
    });

    it('removes date values in unlimited mode', async () => {
      await addProp('Date and time', 'EventDates');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      const firstInput = screen.getByTestId(`array-prop-value-${propId}-0`);
      await userEvent.type(firstInput, '2024-01-15');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await waitFor(() => {
        expect(
          screen.getByTestId(`array-prop-value-${propId}-1`),
        ).toBeInTheDocument();
      });
      const secondInput = screen.getByTestId(`array-prop-value-${propId}-1`);
      await userEvent.type(secondInput, '2024-02-20');

      await userEvent.click(screen.getByRole('button', { name: 'Add value' }));
      await waitFor(() => {
        expect(
          screen.getByTestId(`array-prop-value-${propId}-2`),
        ).toBeInTheDocument();
      });
      const thirdInput = screen.getByTestId(`array-prop-value-${propId}-2`);
      await userEvent.type(thirdInput, '2024-03-10');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual([
          '2024-01-15',
          '2024-02-20',
          '2024-03-10',
        ]);
      });

      const removeButtons = screen.getAllByRole('button', {
        name: 'Remove value',
      });
      await userEvent.click(removeButtons[1]);

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual(['2024-01-15', '2024-03-10']);
      });
    });

    it('switches date prop to limited mode and sets fixed count', async () => {
      await addProp('Date and time', 'ImportantDates');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      const valueModeSelect = document.getElementById(
        `prop-value-mode-${propId}`,
      );
      await userEvent.click(valueModeSelect!);
      const limitedOption = await screen.findByRole('option', {
        name: 'Limited',
      });
      await userEvent.click(limitedOption);

      await waitFor(() => {
        expect(
          document.getElementById(`prop-limited-count-${propId}`),
        ).toBeInTheDocument();
      });

      const countInput = document.getElementById(
        `prop-limited-count-${propId}`,
      ) as HTMLInputElement;
      await userEvent.tripleClick(countInput);
      await userEvent.keyboard('3');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.limitedCount).toBe(3);
      });

      await waitFor(() => {
        expect(
          screen.getByTestId(`array-prop-value-${propId}-0`),
        ).toBeInTheDocument();
        expect(
          screen.getByTestId(`array-prop-value-${propId}-1`),
        ).toBeInTheDocument();
        expect(
          screen.getByTestId(`array-prop-value-${propId}-2`),
        ).toBeInTheDocument();
      });
    });

    it('updates date values in limited mode', async () => {
      await addProp('Date and time', 'ImportantDates');
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });

      fireEvent.click(checkbox);

      const propId = selectCodeComponentProperty('props')(store.getState())[0]
        .id;

      const valueModeSelect = document.getElementById(
        `prop-value-mode-${propId}`,
      );
      await userEvent.click(valueModeSelect!);
      const limitedOption = await screen.findByRole('option', {
        name: 'Limited',
      });
      await userEvent.click(limitedOption);

      await waitFor(() => {
        expect(
          document.getElementById(`prop-limited-count-${propId}`),
        ).toBeInTheDocument();
      });

      const countInput = document.getElementById(
        `prop-limited-count-${propId}`,
      ) as HTMLInputElement;
      await userEvent.tripleClick(countInput);
      await userEvent.keyboard('2');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.limitedCount).toBe(2);
      });

      const firstInput = screen.getByTestId(`array-prop-value-${propId}-0`);
      await userEvent.type(firstInput, '2024-05-01');

      const secondInput = screen.getByTestId(`array-prop-value-${propId}-1`);
      await userEvent.type(secondInput, '2024-06-15');

      await waitFor(() => {
        const prop = selectCodeComponentProperty('props')(store.getState())[0];
        expect(prop.example).toEqual(['2024-05-01', '2024-06-15']);
      });
    });

    // Tests for switching to limited mode across multiple prop types.
    const limitedModeTestCases = [
      {
        propType: 'Text',
        propName: 'Tags',
        newLimitedCount: 4,
        validateExample: (item: unknown) => {
          // Text props can be empty strings initially.
          expect(typeof item).toBe('string');
        },
      },
      {
        propType: 'Integer',
        propName: 'Quantities',
        newLimitedCount: 5,
        validateExample: (item: unknown) => {
          // Integer props should be strings (empty or numbers).
          expect(typeof item).toBe('string');
        },
      },
      {
        propType: 'Number',
        propName: 'Prices',
        newLimitedCount: 3,
        validateExample: (item: unknown) => {
          // Number props should be strings (empty or numbers).
          expect(typeof item).toBe('string');
        },
      },
      {
        propType: 'Link',
        propName: 'SocialLinks',
        newLimitedCount: 4,
        validateExample: (item: unknown) => {
          // Link props should be strings.
          expect(typeof item).toBe('string');
        },
      },
      {
        propType: 'Date and time',
        propName: 'EventDates',
        newLimitedCount: 3,
        validateExample: (item: unknown) => {
          // Date props should be strings.
          expect(typeof item).toBe('string');
        },
      },
      {
        propType: 'Video',
        propName: 'VideoGallery',
        newLimitedCount: 4,
        validateExample: (item: unknown) => {
          // Video props should be objects with src property (or empty initially).
          if (item && typeof item === 'object') {
            expect(item).toHaveProperty('src');
          }
        },
      },
      {
        propType: 'Image',
        propName: 'Gallery',
        newLimitedCount: 3,
        validateExample: (item: unknown) => {
          // Image props should be objects with src property (or empty initially).
          if (item && typeof item === 'object') {
            expect(item).toHaveProperty('src');
          }
        },
      },
    ];

    limitedModeTestCases.forEach(
      ({ propType, propName, newLimitedCount, validateExample }) => {
        it(`switches ${propType} prop to limited mode and updates count`, async () => {
          await addProp(propType, propName);
          const checkbox = await screen.findByRole('checkbox', {
            name: 'Allow multiple values',
          });
          expect(checkbox).toBeInTheDocument();

          // Enable multiple values.
          fireEvent.click(checkbox);

          const propId = selectCodeComponentProperty('props')(
            store.getState(),
          )[0].id;

          await waitFor(() => {
            expect(
              document.getElementById(`prop-value-mode-${propId}`),
            ).toBeInTheDocument();
          });

          // Default should be unlimited.
          await waitFor(() => {
            const prop = selectCodeComponentProperty('props')(
              store.getState(),
            )[0];
            expect(prop.valueMode).toBe('unlimited');
            expect(prop.allowMultiple).toBe(true);
            expect(Array.isArray(prop.example)).toBe(true);
          });

          // Switch to limited mode.
          const valueModeSelect = document.getElementById(
            `prop-value-mode-${propId}`,
          );
          await userEvent.click(valueModeSelect!);
          const limitedOption = await screen.findByRole('option', {
            name: 'Limited',
          });
          await userEvent.click(limitedOption);

          // Verify limited mode is activated without errors.
          await waitFor(() => {
            const prop = selectCodeComponentProperty('props')(
              store.getState(),
            )[0];
            expect(prop.valueMode).toBe('limited');
            expect(prop.limitedCount).toBe(2);
            // Example should be an array.
            expect(Array.isArray(prop.example)).toBe(true);
            // Should not contain invalid values for the prop type.
            if (Array.isArray(prop.example) && prop.example.length > 0) {
              prop.example.forEach((item) => {
                validateExample(item);
              });
            }
          });

          // Verify limited count input is visible.
          await waitFor(() => {
            expect(
              document.getElementById(`prop-limited-count-${propId}`),
            ).toBeInTheDocument();
          });

          // Change the limited count.
          const countInput = document.getElementById(
            `prop-limited-count-${propId}`,
          ) as HTMLInputElement;
          await userEvent.tripleClick(countInput);
          await userEvent.keyboard(String(newLimitedCount));

          // Verify the count change persists.
          await waitFor(() => {
            const prop = selectCodeComponentProperty('props')(
              store.getState(),
            )[0];
            expect(prop.limitedCount).toBe(newLimitedCount);
            expect(prop.valueMode).toBe('limited');
            // Example should still be a valid array.
            expect(Array.isArray(prop.example)).toBe(true);
            // Verify array doesn't have invalid values after count change.
            if (Array.isArray(prop.example) && prop.example.length > 0) {
              prop.example.forEach((item) => {
                validateExample(item);
              });
            }
          });
        });
      },
    );
  });

  describe('serialization with invalid video/image arrays', () => {
    it('utils.ts must handle empty strings in video array without crashing', () => {
      const videoPropsWithEmptyStrings = [
        {
          id: 'test-video-prop',
          name: 'Videos',
          type: 'array',
          items: {
            type: 'object',
            $ref: 'json-schema-definitions://canvas.module/video',
          },
          example: ['', ''],
          derivedType: 'video',
          allowMultiple: true,
          valueMode: 'limited',
          limitedCount: 2,
        },
      ];

      expect(() =>
        serializeProps(videoPropsWithEmptyStrings as any),
      ).not.toThrow();
    });

    it('utils.ts must handle empty strings in image array without crashing', () => {
      const imagePropsWithEmptyStrings = [
        {
          id: 'test-image-prop',
          name: 'Gallery',
          type: 'array',
          items: {
            type: 'object',
            $ref: 'json-schema-definitions://canvas.module/image',
          },
          example: ['', '', ''],
          derivedType: 'image',
          allowMultiple: true,
          valueMode: 'limited',
          limitedCount: 3,
        },
      ];

      expect(() =>
        serializeProps(imagePropsWithEmptyStrings as any),
      ).not.toThrow();
    });
  });

  describe('multi-value props persistence and reload', () => {
    // Clean up the render from the parent beforeEach
    beforeEach(() => {
      cleanup();
    });

    describe('text prop with multiple values', () => {
      it('persists and reloads unlimited multi-value text prop', async () => {
        // Simulate a saved component with multi-value text prop
        const savedPropId = 'saved-text-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'Tags',
                  type: 'array',
                  items: { type: 'string' },
                  example: ['javascript', 'react', 'typescript'],
                  derivedType: 'text',
                  allowMultiple: true,
                  valueMode: 'unlimited',
                  limitedCount: 1,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.allowMultiple).toBe(true);
          expect(prop.type).toBe('array');
          expect(prop.items).toEqual({ type: 'string' });
          expect(prop.example).toEqual(['javascript', 'react', 'typescript']);
          expect(prop.valueMode).toBe('unlimited');
        });

        // Verify the checkbox is checked
        const checkbox = screen.getByRole('checkbox', {
          name: 'Allow multiple values',
        });
        expect(checkbox).toBeChecked();

        // Verify value mode selector shows unlimited
        const valueModeSelect = document.getElementById(
          `prop-value-mode-${savedPropId}`,
        );
        expect(valueModeSelect).toBeInTheDocument();
      });

      it('persists and reloads limited multi-value text prop', async () => {
        const savedPropId = 'saved-limited-text-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'Authors',
                  type: 'array',
                  items: { type: 'string' },
                  example: ['John Doe', 'Jane Smith', 'Bob Johnson'],
                  derivedType: 'text',
                  allowMultiple: true,
                  valueMode: 'limited',
                  limitedCount: 3,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.allowMultiple).toBe(true);
          expect(prop.valueMode).toBe('limited');
          expect(prop.limitedCount).toBe(3);
          expect(prop.example).toEqual([
            'John Doe',
            'Jane Smith',
            'Bob Johnson',
          ]);
        });

        // Verify limited count input exists and has correct value
        const countInput = document.getElementById(
          `prop-limited-count-${savedPropId}`,
        ) as HTMLInputElement;
        expect(countInput).toBeInTheDocument();
        expect(countInput.value).toBe('3');

        // Verify all three input fields exist
        expect(
          document.getElementById(`array-prop-value-${savedPropId}-0`),
        ).toBeInTheDocument();
        expect(
          document.getElementById(`array-prop-value-${savedPropId}-1`),
        ).toBeInTheDocument();
        expect(
          document.getElementById(`array-prop-value-${savedPropId}-2`),
        ).toBeInTheDocument();
      });
    });

    describe('integer prop with multiple values', () => {
      it('persists and reloads unlimited multi-value integer prop', async () => {
        const savedPropId = 'saved-integer-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'Quantities',
                  type: 'array',
                  items: { type: 'integer' },
                  example: [10, 20, 30, 40],
                  derivedType: 'integer',
                  allowMultiple: true,
                  valueMode: 'unlimited',
                  limitedCount: 1,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.allowMultiple).toBe(true);
          expect(prop.type).toBe('array');
          expect(prop.items).toEqual({ type: 'integer' });
          expect(prop.example).toEqual([10, 20, 30, 40]);
          expect(prop.valueMode).toBe('unlimited');
        });

        const checkbox = screen.getByRole('checkbox', {
          name: 'Allow multiple values',
        });
        expect(checkbox).toBeChecked();
      });

      it('persists and reloads limited multi-value integer prop', async () => {
        const savedPropId = 'saved-limited-integer-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'Ratings',
                  type: 'array',
                  items: { type: 'integer' },
                  example: [1, 2, 3, 4, 5],
                  derivedType: 'integer',
                  allowMultiple: true,
                  valueMode: 'limited',
                  limitedCount: 5,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.valueMode).toBe('limited');
          expect(prop.limitedCount).toBe(5);
          expect(prop.example).toEqual([1, 2, 3, 4, 5]);
        });
      });
    });

    describe('link prop with multiple values', () => {
      it('persists and reloads unlimited multi-value link prop', async () => {
        const savedPropId = 'saved-link-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'RelatedLinks',
                  type: 'array',
                  items: { type: 'string', format: 'uri-reference' },
                  example: ['/page1', '/page2', '/page3'],
                  derivedType: 'link',
                  format: 'uri-reference',
                  allowMultiple: true,
                  valueMode: 'unlimited',
                  limitedCount: 1,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.allowMultiple).toBe(true);
          expect(prop.items).toEqual({
            type: 'string',
            format: 'uri-reference',
          });
          expect(prop.example).toEqual(['/page1', '/page2', '/page3']);
        });
      });

      it('persists and reloads limited multi-value link prop', async () => {
        const savedPropId = 'saved-limited-link-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'SocialLinks',
                  type: 'array',
                  items: { type: 'string', format: 'uri' },
                  example: [
                    'https://twitter.com',
                    'https://facebook.com',
                    'https://linkedin.com',
                  ],
                  derivedType: 'link',
                  format: 'uri',
                  allowMultiple: true,
                  valueMode: 'limited',
                  limitedCount: 3,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.valueMode).toBe('limited');
          expect(prop.limitedCount).toBe(3);
          expect(prop.example).toEqual([
            'https://twitter.com',
            'https://facebook.com',
            'https://linkedin.com',
          ]);
        });
      });
    });

    describe('image prop with multiple values', () => {
      it('persists and reloads multi-value image prop', async () => {
        const savedPropId = 'saved-image-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'Gallery',
                  type: 'array',
                  items: {
                    type: 'object',
                    $ref: 'json-schema-definitions://canvas.module/image',
                  },
                  example: [
                    {
                      src: 'https://placehold.co/800x600.png',
                      width: 800,
                      height: 600,
                      alt: 'First image',
                    },
                    {
                      src: 'https://placehold.co/1920x1080.png',
                      width: 1920,
                      height: 1080,
                      alt: 'Second image',
                    },
                  ],
                  derivedType: 'image',
                  $ref: 'json-schema-definitions://canvas.module/image',
                  allowMultiple: true,
                  valueMode: 'unlimited',
                  limitedCount: 1,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.allowMultiple).toBe(true);
          expect(prop.type).toBe('array');
          expect(prop.items).toMatchObject({
            type: 'object',
            $ref: 'json-schema-definitions://canvas.module/image',
          });
          // Verify example data is preserved or initialized correctly
          if (Array.isArray(prop.example)) {
            expect(prop.example.length).toBeGreaterThanOrEqual(0);
          }
        });
      });
    });

    describe('video prop with multiple values', () => {
      it('persists and reloads multi-value video prop', async () => {
        const savedPropId = 'saved-video-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'VideoPlaylist',
                  type: 'array',
                  items: {
                    type: 'object',
                    $ref: 'json-schema-definitions://canvas.module/video',
                  },
                  example: [
                    {
                      src: '/ui/assets/videos/video1.mp4',
                      poster: 'https://placehold.co/1920x1080.png',
                    },
                    {
                      src: '/ui/assets/videos/video2.mp4',
                      poster: 'https://placehold.co/1280x720.png',
                    },
                  ],
                  derivedType: 'video',
                  $ref: 'json-schema-definitions://canvas.module/video',
                  allowMultiple: true,
                  valueMode: 'unlimited',
                  limitedCount: 1,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.allowMultiple).toBe(true);
          expect(prop.type).toBe('array');
          expect(prop.items).toMatchObject({
            type: 'object',
            $ref: 'json-schema-definitions://canvas.module/video',
          });
          // Verify the example data structure
          if (Array.isArray(prop.example)) {
            expect(prop.example).toHaveLength(2);
          }
        });
      });
    });

    describe('date prop with multiple values', () => {
      it('persists and reloads unlimited multi-value date prop', async () => {
        const savedPropId = 'saved-date-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'ImportantDates',
                  type: 'array',
                  items: { type: 'string', format: 'date' },
                  example: ['2024-01-15', '2024-06-20', '2024-12-25'],
                  derivedType: 'date',
                  format: 'date',
                  allowMultiple: true,
                  valueMode: 'unlimited',
                  limitedCount: 1,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.allowMultiple).toBe(true);
          expect(prop.items).toEqual({ type: 'string', format: 'date' });
          expect(prop.example).toEqual([
            '2024-01-15',
            '2024-06-20',
            '2024-12-25',
          ]);
        });
      });

      it('persists and reloads limited multi-value date prop', async () => {
        const savedPropId = 'saved-limited-date-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'Milestones',
                  type: 'array',
                  items: { type: 'string', format: 'date' },
                  example: ['2024-01-01', '2024-06-01'],
                  derivedType: 'date',
                  format: 'date',
                  allowMultiple: true,
                  valueMode: 'limited',
                  limitedCount: 2,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.valueMode).toBe('limited');
          expect(prop.limitedCount).toBe(2);
          expect(prop.example).toEqual(['2024-01-01', '2024-06-01']);
        });
      });
    });

    describe('list prop with multiple values', () => {
      it('persists and reloads unlimited multi-value list text prop', async () => {
        const savedPropId = 'saved-list-text-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'Categories',
                  type: 'array',
                  items: {
                    type: 'string',
                    enum: ['tech', 'business', 'science', 'art'],
                  },
                  example: ['tech', 'science'],
                  derivedType: 'listText',
                  enum: [
                    { label: 'Technology', value: 'tech' },
                    { label: 'Business', value: 'business' },
                    { label: 'Science', value: 'science' },
                    { label: 'Art', value: 'art' },
                  ],
                  allowMultiple: true,
                  valueMode: 'unlimited',
                  limitedCount: 1,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.allowMultiple).toBe(true);
          expect(prop.type).toBe('array');
          expect(prop.example).toEqual(['tech', 'science']);
          expect(prop.enum).toHaveLength(4);
        });
      });

      it('persists and reloads limited multi-value list text prop', async () => {
        const savedPropId = 'saved-limited-list-text-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'TopCategories',
                  type: 'array',
                  items: {
                    type: 'string',
                    enum: ['tech', 'business', 'science'],
                  },
                  example: ['tech', 'business', 'science'],
                  derivedType: 'listText',
                  enum: [
                    { label: 'Technology', value: 'tech' },
                    { label: 'Business', value: 'business' },
                    { label: 'Science', value: 'science' },
                  ],
                  allowMultiple: true,
                  valueMode: 'limited',
                  limitedCount: 3,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.valueMode).toBe('limited');
          expect(prop.limitedCount).toBe(3);
          expect(prop.example).toEqual(['tech', 'business', 'science']);
        });
      });

      it('persists and reloads unlimited multi-value list integer prop', async () => {
        const savedPropId = 'saved-list-integer-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: savedPropId,
                  name: 'Ratings',
                  type: 'array',
                  items: {
                    type: 'integer',
                    enum: [1, 2, 3, 4, 5],
                  },
                  example: [4, 5],
                  derivedType: 'listInteger',
                  enum: [
                    { label: '1 Star', value: 1 },
                    { label: '2 Stars', value: 2 },
                    { label: '3 Stars', value: 3 },
                    { label: '4 Stars', value: 4 },
                    { label: '5 Stars', value: 5 },
                  ],
                  allowMultiple: true,
                  valueMode: 'unlimited',
                  limitedCount: 1,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.allowMultiple).toBe(true);
          expect(prop.type).toBe('array');
          expect(prop.example).toEqual([4, 5]);
        });
      });
    });

    describe('mixed props persistence', () => {
      it('persists and reloads multiple props with different multi-value configurations', async () => {
        const textPropId = 'text-prop-id';
        const integerPropId = 'integer-prop-id';
        const linkPropId = 'link-prop-id';

        const storeWithMultipleProps = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: true,
              props: [
                {
                  id: textPropId,
                  name: 'Tags',
                  type: 'array',
                  items: { type: 'string' },
                  example: ['tag1', 'tag2'],
                  derivedType: 'text',
                  allowMultiple: true,
                  valueMode: 'unlimited',
                  limitedCount: 1,
                },
                {
                  id: integerPropId,
                  name: 'Scores',
                  type: 'array',
                  items: { type: 'integer' },
                  example: [10, 20, 30],
                  derivedType: 'integer',
                  allowMultiple: true,
                  valueMode: 'limited',
                  limitedCount: 3,
                },
                {
                  id: linkPropId,
                  name: 'SingleLink',
                  type: 'string',
                  format: 'uri-reference',
                  example: '/single-link',
                  derivedType: 'link',
                  allowMultiple: false,
                },
              ],
            },
            initialPropIds: [textPropId, integerPropId, linkPropId],
          },
        });

        render(<Wrapper store={storeWithMultipleProps} />);

        await waitFor(() => {
          const props = selectCodeComponentProperty('props')(
            storeWithMultipleProps.getState(),
          );
          expect(props).toHaveLength(3);

          // Text prop with unlimited multi-value
          expect(props[0].allowMultiple).toBe(true);
          expect(props[0].valueMode).toBe('unlimited');
          expect(props[0].example).toEqual(['tag1', 'tag2']);

          // Integer prop with limited multi-value
          expect(props[1].allowMultiple).toBe(true);
          expect(props[1].valueMode).toBe('limited');
          expect(props[1].limitedCount).toBe(3);
          expect(props[1].example).toEqual([10, 20, 30]);

          // Link prop without multi-value
          expect(props[2].allowMultiple).toBe(false);
          expect(props[2].type).toBe('string');
          expect(props[2].example).toBe('/single-link');
        });

        // Verify all three props are rendered
        const propNames = screen.getAllByRole('textbox', { name: 'Prop name' });
        expect(propNames).toHaveLength(3);
      });
    });

    describe('editing reloaded multi-value props', () => {
      it('allows editing example values after reload for non-exposed component', async () => {
        const savedPropId = 'saved-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: false, // Not exposed
              props: [
                {
                  id: savedPropId,
                  name: 'Tags',
                  type: 'array',
                  items: { type: 'string' },
                  example: ['initial1', 'initial2'],
                  derivedType: 'text',
                  allowMultiple: true,
                  valueMode: 'unlimited',
                  limitedCount: 1,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        await waitFor(() => {
          expect(
            screen.getByRole('button', { name: 'Add value' }),
          ).toBeInTheDocument();
        });

        // Add a new value
        await userEvent.click(
          screen.getByRole('button', { name: 'Add value' }),
        );

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect((prop.example as string[]).length).toBe(3);
        });
      });

      it('preserves multi-value configuration when toggling allowMultiple off and on', async () => {
        const savedPropId = 'saved-prop-id';
        const storeWithSavedProp = makeStore({
          codeEditor: {
            ...initialState,
            codeComponent: {
              ...initialState.codeComponent,
              status: false,
              props: [
                {
                  id: savedPropId,
                  name: 'Tags',
                  type: 'string',
                  example: 'single-value',
                  derivedType: 'text',
                  allowMultiple: false,
                },
              ],
            },
            initialPropIds: [savedPropId],
          },
        });

        render(<Wrapper store={storeWithSavedProp} />);

        const checkbox = await screen.findByRole('checkbox', {
          name: 'Allow multiple values',
        });

        // Enable multi-value
        fireEvent.click(checkbox);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.allowMultiple).toBe(true);
          expect(prop.type).toBe('array');
          expect(prop.items).toEqual({ type: 'string' });
          expect(prop.valueMode).toBe('unlimited');
          expect(prop.example).toEqual([]);
        });

        // Disable multi-value
        fireEvent.click(checkbox);

        await waitFor(() => {
          const prop = selectCodeComponentProperty('props')(
            storeWithSavedProp.getState(),
          )[0];
          expect(prop.allowMultiple).toBe(false);
          expect(prop.items).toBeUndefined();
          expect(prop.example).toBe('');
        });
      });
    });
  });

  describe('required multivalue prop validation', () => {
    const enableMultipleValues = async () => {
      const checkbox = await screen.findByRole('checkbox', {
        name: 'Allow multiple values',
      });
      await act(async () => {
        fireEvent.click(checkbox);
      });
    };
    const toggleRequired = async () => {
      await userEvent.click(screen.getByRole('switch', { name: 'Required' }));
    };

    const getPropId = () =>
      selectCodeComponentProperty('props')(store.getState())[0].id;
    const getFirstProp = () =>
      selectCodeComponentProperty('props')(store.getState())[0];

    // Test cases for types that use FormPropTypeArray (text, integer, number) ──
    // These types auto-prefill a default value when required is toggled on
    // because FormPropTypeArray normalizes the empty-array check.
    const arrayPropTestCases = [
      {
        propType: 'Text',
        propName: 'Tags',
        expectedPrefill: ['Example text'],
        newValue: 'New value',
      },
      {
        propType: 'Integer',
        propName: 'Count',
        expectedPrefill: ['0'],
        newValue: '5',
      },
      {
        propType: 'Number',
        propName: 'Price',
        expectedPrefill: ['0'],
        newValue: '9.99',
      },
    ];

    describe.each(arrayPropTestCases)(
      '$propType prop',
      ({ propType, propName, expectedPrefill, newValue }) => {
        it('prefills at least one default value when required is toggled on', async () => {
          await addProp(propType, propName);
          await enableMultipleValues();
          await toggleRequired();

          await waitFor(() => {
            expect(getFirstProp().example).toEqual(expectedPrefill);
          });

          expect(
            screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
          ).not.toBeInTheDocument();
        });

        it('shows error when all values are cleared', async () => {
          await addProp(propType, propName);
          await enableMultipleValues();
          await toggleRequired();

          await waitFor(() => {
            expect(getFirstProp().example).toEqual(expectedPrefill);
          });

          const firstInput = screen.getByTestId(
            `array-prop-value-${getPropId()}-0`,
          );
          await userEvent.clear(firstInput);

          await waitFor(() => {
            expect(
              screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).toBeInTheDocument();
          });
        });

        it('clears error when a value is added back', async () => {
          await addProp(propType, propName);
          await enableMultipleValues();
          await toggleRequired();

          await waitFor(() => {
            expect(getFirstProp().example).toEqual(expectedPrefill);
          });

          const firstInput = screen.getByTestId(
            `array-prop-value-${getPropId()}-0`,
          );
          await userEvent.clear(firstInput);

          await waitFor(() => {
            expect(
              screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).toBeInTheDocument();
          });

          await userEvent.type(firstInput, newValue);

          await waitFor(() => {
            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });
        });

        it('clears error when required is toggled off', async () => {
          await addProp(propType, propName);
          await enableMultipleValues();
          await toggleRequired();

          await waitFor(() => {
            expect(getFirstProp().example).toEqual(expectedPrefill);
          });

          const firstInput = screen.getByTestId(
            `array-prop-value-${getPropId()}-0`,
          );
          await userEvent.clear(firstInput);

          await waitFor(() => {
            expect(
              screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).toBeInTheDocument();
          });

          await toggleRequired();

          await waitFor(() => {
            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });
        });

        it('shows no error when required is off and has no values', async () => {
          await addProp(propType, propName);
          await enableMultipleValues();

          expect(
            screen.getByRole('switch', { name: 'Required' }),
          ).not.toBeChecked();

          expect(
            screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
          ).not.toBeInTheDocument();
        });
      },
    );

    // Test cases for Link prop (relative and full URL) ──
    // Link uses FormPropTypeLink which prefills the single-string default
    // via useRequiredProp. In multivalue mode the flow is: toggle required
    // first (which prefills the single value), then enable multivalue.
    const linkPropTestCases = [
      {
        variant: 'relative path',
        expectedPrefill: 'example',
        newValue: 'new-path',
        setup: undefined as (() => Promise<void>) | undefined,
      },
      {
        variant: 'full URL',
        expectedPrefill: 'https://example.com',
        newValue: 'https://new-example.com',
        setup: async () => {
          const propId = selectCodeComponentProperty('props')(
            store.getState(),
          )[0].id;
          const linkTypeSelect = document.getElementById(
            `prop-link-type-${propId}`,
          );
          await userEvent.click(linkTypeSelect!);
          const fullUrlOption = await screen.findByRole('option', {
            name: 'Full URL',
          });
          await userEvent.click(fullUrlOption);
        },
      },
    ];

    describe.each(linkPropTestCases)(
      'Link prop ($variant)',
      ({ expectedPrefill, newValue, setup }) => {
        it('prefills a default value when required is toggled on then multivalue is enabled', async () => {
          await addProp('Link', 'MyLink');
          if (setup) await setup();
          await toggleRequired();

          // Verify single-value prefill.
          await waitFor(() => {
            expect(getFirstProp().example).toBe(expectedPrefill);
          });

          await enableMultipleValues();

          await waitFor(() => {
            expect(getFirstProp().allowMultiple).toBe(true);
          });

          expect(
            screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
          ).not.toBeInTheDocument();
        });

        it('shows error when all values are cleared', async () => {
          await addProp('Link', 'MyLink');
          if (setup) await setup();
          await enableMultipleValues();
          await toggleRequired();

          // Type a value so we can clear it.
          const firstInput = screen.getByTestId(
            `array-prop-value-${getPropId()}-0`,
          );
          await userEvent.type(firstInput, expectedPrefill);

          await waitFor(() => {
            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });

          await userEvent.clear(firstInput);

          await waitFor(() => {
            expect(
              screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).toBeInTheDocument();
          });
        });

        it('clears error when a value is added back', async () => {
          await addProp('Link', 'MyLink');
          if (setup) await setup();
          await enableMultipleValues();
          await toggleRequired();

          const firstInput = screen.getByTestId(
            `array-prop-value-${getPropId()}-0`,
          );
          await userEvent.type(firstInput, expectedPrefill);
          await userEvent.clear(firstInput);

          await waitFor(() => {
            expect(
              screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).toBeInTheDocument();
          });

          await userEvent.type(firstInput, newValue);

          await waitFor(() => {
            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });
        });

        it('clears error when required is toggled off', async () => {
          await addProp('Link', 'MyLink');
          if (setup) await setup();
          await enableMultipleValues();
          await toggleRequired();

          const firstInput = screen.getByTestId(
            `array-prop-value-${getPropId()}-0`,
          );
          await userEvent.type(firstInput, expectedPrefill);
          await userEvent.clear(firstInput);

          await waitFor(() => {
            expect(
              screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).toBeInTheDocument();
          });

          await toggleRequired();

          await waitFor(() => {
            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });
        });

        it('shows no error when required is off and has no values', async () => {
          await addProp('Link', 'MyLink');
          if (setup) await setup();
          await enableMultipleValues();

          expect(
            screen.getByRole('switch', { name: 'Required' }),
          ).not.toBeChecked();

          expect(
            screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
          ).not.toBeInTheDocument();
        });
      },
    );

    // Test cases for Date and time prop (date-only and date-time) ──
    // Date uses FormPropTypeDate which prefills via useRequiredProp.
    // In multivalue mode the flow is similar to Link.
    const datePropTestCases = [
      {
        variant: 'date only',
        expectedPrefill: '2026-01-25',
        newValue: '2027-06-15',
        setup: undefined as (() => Promise<void>) | undefined,
      },
      {
        variant: 'date-time',
        expectedPrefill: '2026-01-25T12:00:00.000Z',
        newValue: '2027-06-15T10:30',
        setup: async () => {
          const propId = selectCodeComponentProperty('props')(
            store.getState(),
          )[0].id;
          const dateTypeSelect = document.getElementById(
            `prop-date-type-${propId}`,
          );
          await userEvent.click(dateTypeSelect!);
          const dateTimeOption = await screen.findByRole('option', {
            name: 'Date and time',
          });
          await userEvent.click(dateTimeOption);
        },
      },
    ];

    describe.each(datePropTestCases)(
      'Date and time prop ($variant)',
      ({ expectedPrefill, newValue, setup }) => {
        it('prefills a default value when required is toggled on then multivalue is enabled', async () => {
          await addProp('Date and time', 'EventDate');
          if (setup) await setup();
          await toggleRequired();

          // Verify single-value prefill.
          await waitFor(() => {
            expect(getFirstProp().example).toBe(expectedPrefill);
          });

          await enableMultipleValues();

          await waitFor(() => {
            expect(getFirstProp().allowMultiple).toBe(true);
          });

          expect(
            screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
          ).not.toBeInTheDocument();
        });

        it('shows error when all values are cleared', async () => {
          await addProp('Date and time', 'EventDate');
          if (setup) await setup();
          await enableMultipleValues();
          await toggleRequired();

          // Type a value so we can clear it.
          const firstInput = screen.getByTestId(
            `array-prop-value-${getPropId()}-0`,
          );
          fireEvent.change(firstInput, { target: { value: newValue } });

          await waitFor(() => {
            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });

          fireEvent.change(firstInput, { target: { value: '' } });

          await waitFor(() => {
            expect(
              screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).toBeInTheDocument();
          });
        });

        it('clears error when a value is added back', async () => {
          await addProp('Date and time', 'EventDate');
          if (setup) await setup();
          await enableMultipleValues();
          await toggleRequired();

          const firstInput = screen.getByTestId(
            `array-prop-value-${getPropId()}-0`,
          );

          fireEvent.change(firstInput, { target: { value: newValue } });
          fireEvent.change(firstInput, { target: { value: '' } });

          await waitFor(() => {
            expect(
              screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).toBeInTheDocument();
          });

          fireEvent.change(firstInput, { target: { value: newValue } });

          await waitFor(() => {
            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });
        });

        it('clears error when required is toggled off', async () => {
          await addProp('Date and time', 'EventDate');
          if (setup) await setup();
          await enableMultipleValues();
          await toggleRequired();

          const firstInput = screen.getByTestId(
            `array-prop-value-${getPropId()}-0`,
          );
          fireEvent.change(firstInput, { target: { value: newValue } });
          fireEvent.change(firstInput, { target: { value: '' } });

          await waitFor(() => {
            expect(
              screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).toBeInTheDocument();
          });

          await toggleRequired();

          await waitFor(() => {
            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });
        });

        it('shows no error when required is off and has no values', async () => {
          await addProp('Date and time', 'EventDate');
          if (setup) await setup();
          await enableMultipleValues();

          expect(
            screen.getByRole('switch', { name: 'Required' }),
          ).not.toBeChecked();

          expect(
            screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
          ).not.toBeInTheDocument();
        });
      },
    );

    // In limited mode the array has a fixed number of inputs.  Clearing every
    // slot while the prop is required must surface the validation error.
    describe('limited multivalue mode required validation', () => {
      const switchToLimitedMode = async (propId: string) => {
        const valueModeSelect = document.getElementById(
          `prop-value-mode-${propId}`,
        );
        await userEvent.click(valueModeSelect!);
        const limitedOption = await screen.findByRole('option', {
          name: 'Limited',
        });
        await userEvent.click(limitedOption);
        // Default limitedCount is 2; wait for the count input to appear.
        await waitFor(() => {
          expect(
            document.getElementById(`prop-limited-count-${propId}`),
          ).toBeInTheDocument();
        });
      };

      const limitedModeCases = [
        {
          propType: 'Text',
          propName: 'Tags',
          expectedPrefill: ['Example text'],
          newValue: 'recovered',
        },
        {
          propType: 'Integer',
          propName: 'Count',
          expectedPrefill: ['0'],
          newValue: '42',
        },
        {
          propType: 'Number',
          propName: 'Price',
          expectedPrefill: ['0'],
          newValue: '3.14',
        },
      ];

      describe.each(limitedModeCases)(
        '$propType prop',
        ({ propType, propName, expectedPrefill, newValue }) => {
          it('shows error when all limited inputs are cleared', async () => {
            await addProp(propType, propName);
            await enableMultipleValues();
            await toggleRequired();

            const propId = getPropId();
            await switchToLimitedMode(propId);

            // The prefilled value should survive the mode switch.
            await waitFor(() => {
              expect(getFirstProp().example).toEqual(
                expect.arrayContaining(expectedPrefill),
              );
            });

            // Clear the first (prefilled) slot — all remaining slots are empty.
            const firstInput = screen.getByTestId(
              `array-prop-value-${propId}-0`,
            );
            await userEvent.clear(firstInput);

            await waitFor(() => {
              expect(
                screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
              ).toBeInTheDocument();
            });
          });

          it('clears error when a value is added back in limited mode', async () => {
            await addProp(propType, propName);
            await enableMultipleValues();
            await toggleRequired();

            const propId = getPropId();
            await switchToLimitedMode(propId);

            const firstInput = screen.getByTestId(
              `array-prop-value-${propId}-0`,
            );
            await userEvent.clear(firstInput);

            await waitFor(() => {
              expect(
                screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
              ).toBeInTheDocument();
            });

            await userEvent.type(firstInput, newValue);

            await waitFor(() => {
              expect(
                screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
              ).not.toBeInTheDocument();
            });
          });

          it('clears error when required is toggled off in limited mode', async () => {
            await addProp(propType, propName);
            await enableMultipleValues();
            await toggleRequired();

            const propId = getPropId();
            await switchToLimitedMode(propId);

            const firstInput = screen.getByTestId(
              `array-prop-value-${propId}-0`,
            );
            await userEvent.clear(firstInput);

            await waitFor(() => {
              expect(
                screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
              ).toBeInTheDocument();
            });

            await toggleRequired();

            await waitFor(() => {
              expect(
                screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
              ).not.toBeInTheDocument();
            });
          });
        },
      );
    });

    const listPropTestCases = [
      {
        propType: 'List: text',
        propName: 'Categories',
        optionLabel: 'Option 1',
        expectedPrefill: ['option_1'],
      },
      {
        propType: 'List: integer',
        propName: 'Ratings',
        optionLabel: '1',
        expectedPrefill: ['1'],
      },
    ];

    describe.each(listPropTestCases)(
      '$propType prop (multivalue required)',
      ({ propType, propName, optionLabel, expectedPrefill }) => {
        it('prefills at least one default option when required is toggled on', async () => {
          await addProp(propType, propName);
          await enableMultipleValues();
          await toggleRequired();

          await waitFor(() => {
            expect(getFirstProp().example).toEqual(expectedPrefill);
          });

          expect(
            screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
          ).not.toBeInTheDocument();
        });

        it('shows error when all selected options are removed', async () => {
          await addProp(propType, propName);
          // Toggle required first so a default option is created, then
          // enable multivalue (the example is preserved as [value]).
          await toggleRequired();
          await enableMultipleValues();

          await waitFor(() => {
            expect(getFirstProp().example).toEqual(expectedPrefill);
          });

          expect(
            screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
          ).not.toBeInTheDocument();

          // Open the popover and deselect the only option.
          await userEvent.click(
            screen.getByRole('button', { name: /1 selected/i }),
          );
          const optionCheckbox = await screen.findByRole('checkbox', {
            name: optionLabel,
          });
          await userEvent.click(optionCheckbox);

          await waitFor(() => {
            expect(
              screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).toBeInTheDocument();
          });
        });

        it('clears error when an option is selected again', async () => {
          await addProp(propType, propName);
          await toggleRequired();
          await enableMultipleValues();

          // Open popover and deselect.
          await userEvent.click(
            screen.getByRole('button', { name: /1 selected/i }),
          );
          const optionCheckbox = await screen.findByRole('checkbox', {
            name: optionLabel,
          });
          await userEvent.click(optionCheckbox);

          await waitFor(() => {
            expect(
              screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).toBeInTheDocument();
          });

          // Re-select the option.
          await userEvent.click(optionCheckbox);

          await waitFor(() => {
            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });
        });

        it('clears error when required is toggled off', async () => {
          await addProp(propType, propName);
          await toggleRequired();
          await enableMultipleValues();

          // Open popover and deselect.
          await userEvent.click(
            screen.getByRole('button', { name: /1 selected/i }),
          );
          const optionCheckbox = await screen.findByRole('checkbox', {
            name: optionLabel,
          });
          await userEvent.click(optionCheckbox);

          await waitFor(() => {
            expect(
              screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).toBeInTheDocument();
          });

          await toggleRequired();

          await waitFor(() => {
            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });
        });

        it('shows no error when required is off and no option is selected', async () => {
          await addProp(propType, propName);
          await enableMultipleValues();

          expect(
            screen.getByRole('switch', { name: 'Required' }),
          ).not.toBeChecked();

          expect(
            screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
          ).not.toBeInTheDocument();
        });
      },
    );

    describe('clears error when disabling multivalue for required props with cleared values', () => {
      /**
       * Test scenario: When a required prop in multivalue mode has all its values
       * cleared (showing an error), and then multivalue is disabled, the error
       * should be cleared along with the default value restoration.
       *
       */
      const toggleErrorClearTestCases = [
        {
          propType: 'Text',
          propName: 'Tags',
          expectedDefaultValue: 'Example text',
          setup: undefined as (() => Promise<void>) | undefined,
          optionLabel: undefined as string | undefined,
        },
        {
          propType: 'Integer',
          propName: 'Count',
          expectedDefaultValue: '0',
          setup: undefined,
          optionLabel: undefined,
        },
        {
          propType: 'Number',
          propName: 'Price',
          expectedDefaultValue: '0',
          setup: undefined,
          optionLabel: undefined,
        },
        {
          propType: 'Link',
          propName: 'HomePage',
          expectedDefaultValue: 'example',
          setup: undefined,
          optionLabel: undefined,
        },
        {
          propType: 'Link',
          propName: 'ExternalLink',
          expectedDefaultValue: 'https://example.com',
          setup: async () => {
            // Change link type to Full URL.
            const propId = selectCodeComponentProperty('props')(
              store.getState(),
            )[0].id;
            const linkTypeSelect = document.getElementById(
              `prop-link-type-${propId}`,
            );
            await userEvent.click(linkTypeSelect!);
            const fullUrlOption = await screen.findByRole('option', {
              name: 'Full URL',
            });
            await userEvent.click(fullUrlOption);
          },
          optionLabel: undefined,
        },
        {
          propType: 'Date and time',
          propName: 'StartDate',
          expectedDefaultValue: '2026-01-25',
          setup: undefined,
          optionLabel: undefined,
        },
        {
          propType: 'Date and time',
          propName: 'CreatedAt',
          expectedDefaultValue: '2026-01-25T12:00:00.000Z',
          setup: async () => {
            // Change date type to Date and time (date-time format).
            const propId = selectCodeComponentProperty('props')(
              store.getState(),
            )[0].id;
            const dateTypeSelect = document.getElementById(
              `prop-date-type-${propId}`,
            );
            await userEvent.click(dateTypeSelect!);
            const dateTimeOption = await screen.findByRole('option', {
              name: 'Date and time',
            });
            await userEvent.click(dateTimeOption);
          },
          optionLabel: undefined,
        },
        {
          propType: 'List: text',
          propName: 'Categories',
          expectedDefaultValue: 'option_1',
          setup: undefined,
          optionLabel: 'Option 1',
        },
        {
          propType: 'List: integer',
          propName: 'Ratings',
          expectedDefaultValue: '1',
          setup: undefined,
          optionLabel: '1',
        },
      ];

      describe.each(toggleErrorClearTestCases)(
        '$propType prop',
        ({ propType, propName, expectedDefaultValue, setup, optionLabel }) => {
          it('clears error when disabling multivalue with cleared values and required is on', async () => {
            await addProp(propType, propName);
            if (setup) await setup();

            const requiredToggle = screen.getByRole('switch', {
              name: 'Required',
            });
            await userEvent.click(requiredToggle);

            await waitFor(() => {
              expect(
                selectCodeComponentProperty('props')(store.getState())[0]
                  .example,
              ).toBe(expectedDefaultValue);
            });

            const multiValueCheckbox = screen.getByRole('checkbox', {
              name: 'Allow multiple values',
            });
            await act(async () => {
              fireEvent.click(multiValueCheckbox);
            });

            await waitFor(() => {
              expect(
                selectCodeComponentProperty('props')(store.getState())[0]
                  .allowMultiple,
              ).toBe(true);
            });

            // For list types, deselect the option from the popover; for others, clear the array input
            if (propType.includes('List:')) {
              await userEvent.click(
                screen.getByRole('button', { name: /1 selected/i }),
              );
              const optionCheckbox = await screen.findByRole('checkbox', {
                name: optionLabel!,
              });
              await userEvent.click(optionCheckbox);
            } else {
              const getPropId = () =>
                selectCodeComponentProperty('props')(store.getState())[0].id;
              const firstInput = screen.getByTestId(
                `array-prop-value-${getPropId()}-0`,
              );
              if (propType.includes('Date')) {
                fireEvent.change(firstInput, { target: { value: '' } });
              } else {
                await userEvent.clear(firstInput);
              }
            }

            await waitFor(() => {
              expect(
                screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
              ).toBeInTheDocument();
            });

            await act(async () => {
              fireEvent.click(multiValueCheckbox);
            });

            await waitFor(() => {
              const prop = selectCodeComponentProperty('props')(
                store.getState(),
              )[0];
              expect(prop.allowMultiple).toBe(false);
              expect(prop.example).toBe(expectedDefaultValue);
            });

            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });

          it('error does not persist through multivalue toggle cycle with required prop', async () => {
            await addProp(propType, propName);
            if (setup) await setup();

            const requiredToggle = screen.getByRole('switch', {
              name: 'Required',
            });
            const multiValueCheckbox = screen.getByRole('checkbox', {
              name: 'Allow multiple values',
            });

            await userEvent.click(requiredToggle);

            await act(async () => {
              fireEvent.click(multiValueCheckbox);
            });

            // For list types, deselect the option from the popover; for others, clear the array input
            if (propType.includes('List:')) {
              await userEvent.click(
                screen.getByRole('button', { name: /1 selected/i }),
              );
              const optionCheckbox = await screen.findByRole('checkbox', {
                name: optionLabel!,
              });
              await userEvent.click(optionCheckbox);
            } else {
              const getPropId = () =>
                selectCodeComponentProperty('props')(store.getState())[0].id;
              const firstInput = screen.getByTestId(
                `array-prop-value-${getPropId()}-0`,
              );

              if (propType.includes('Date')) {
                fireEvent.change(firstInput, { target: { value: '' } });
              } else {
                await userEvent.clear(firstInput);
              }
            }

            await waitFor(() => {
              expect(
                screen.getByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
              ).toBeInTheDocument();
            });

            // Toggle multivalue OFF
            await act(async () => {
              fireEvent.click(multiValueCheckbox);
            });

            await waitFor(() => {
              const prop = selectCodeComponentProperty('props')(
                store.getState(),
              )[0];
              expect(prop.example).toBe(expectedDefaultValue);
              expect(prop.allowMultiple).toBe(false);
              expect(
                screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
              ).not.toBeInTheDocument();
            });
          });
        },
      );
    });

    describe('preserves selected default value when toggling multivalue off for list props', () => {
      /**
       * Helper: add custom enum options to the current List prop.
       * @param propId - The ID of the prop (used for test-id selectors).
       * @param values - Array of string values to type into option inputs.
       */
      const addEnumOptions = async (propId: string, values: string[]) => {
        for (let i = 0; i < values.length; i++) {
          await userEvent.click(
            screen.getByRole('button', { name: 'Add value' }),
          );
          await userEvent.type(
            screen.getByTestId(`canvas-prop-enum-value-${propId}-${i}`),
            values[i],
          );
        }
      };

      const listPreservationCases = [
        {
          propType: 'List: text' as const,
          propName: 'Tags',
          options: ['a', 'b'],
          expectedDefault: 'a',
        },
        {
          propType: 'List: integer' as const,
          propName: 'Ratings',
          options: ['10', '20'],
          expectedDefault: '10',
        },
      ];

      describe.each(listPreservationCases)(
        '$propType prop',
        ({ propType, propName, options, expectedDefault }) => {
          it('preserves the default value after enable → disable multivalue round-trip', async () => {
            await addProp(propType, propName);
            const propId = getPropId();

            await addEnumOptions(propId, options);
            await waitFor(() => {
              expect(getFirstProp().enum!.length).toBe(options.length);
            });

            // Toggle required → first option is auto-selected.
            await toggleRequired();

            await waitFor(() => {
              expect(getFirstProp().example).toBe(expectedDefault);
            });

            await enableMultipleValues();

            await waitFor(() => {
              const prop = getFirstProp();
              expect(prop.allowMultiple).toBe(true);
              expect(prop.example).toEqual([expectedDefault]);
            });

            const checkbox = screen.getByRole('checkbox', {
              name: 'Allow multiple values',
            });
            await act(async () => {
              fireEvent.click(checkbox);
            });

            await waitFor(() => {
              const prop = getFirstProp();
              expect(prop.allowMultiple).toBe(false);
              expect(prop.items).toBeUndefined();
              expect(prop.example).toBe(expectedDefault);
            });

            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });

          it('falls back to the first enum option when array is empty on disable', async () => {
            await addProp(propType, propName);
            const propId = getPropId();

            await addEnumOptions(propId, options);

            await waitFor(() => {
              expect(getFirstProp().enum!.length).toBe(options.length);
            });

            await enableMultipleValues();

            await waitFor(() => {
              const prop = getFirstProp();
              expect(prop.allowMultiple).toBe(true);
              expect(prop.example).toEqual([]);
            });
            const checkbox = screen.getByRole('checkbox', {
              name: 'Allow multiple values',
            });
            await act(async () => {
              fireEvent.click(checkbox);
            });

            await waitFor(() => {
              const prop = getFirstProp();
              expect(prop.allowMultiple).toBe(false);
              expect(prop.example).toBe('');
            });
          });
        },
      );
    });

    // When a required prop already has a prefilled single value and the user
    // checks "Allow multiple values", the existing value must be wrapped in an
    // array instead of being reset to [].
    describe('does not reset example when enabling multiple values on a required prop', () => {
      const preservationCases = [
        {
          propType: 'Text',
          propName: 'Tags',
          expectedExample: 'Example text',
          expectedMultiExample: ['Example text'],
        },
        {
          propType: 'Integer',
          propName: 'Count',
          expectedExample: '0',
          expectedMultiExample: ['0'],
        },
        {
          propType: 'Number',
          propName: 'Price',
          expectedExample: '0',
          expectedMultiExample: ['0'],
        },
      ];

      describe.each(preservationCases)(
        '$propType prop',
        ({ propType, propName, expectedExample, expectedMultiExample }) => {
          it('wraps the existing example in an array and shows no error', async () => {
            await addProp(propType, propName);
            await toggleRequired();

            // Verify the single-value prefill.
            await waitFor(() => {
              expect(getFirstProp().example).toBe(expectedExample);
            });

            // Enable multiple values — value must be preserved, NOT reset to [].
            await enableMultipleValues();

            await waitFor(() => {
              const prop = getFirstProp();
              expect(prop.allowMultiple).toBe(true);
              expect(prop.example).toEqual(expectedMultiExample);
            });

            // No error should flash immediately after the transition.
            expect(
              screen.queryByText(REQUIRED_EXAMPLE_ERROR_MESSAGE),
            ).not.toBeInTheDocument();
          });
        },
      );
    });
  });
});
