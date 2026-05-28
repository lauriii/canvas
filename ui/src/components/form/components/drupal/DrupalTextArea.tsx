import { useEffect, useRef, useState } from 'react';
import { Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import TextArea from '@/components/form/components/TextArea';
import { useFieldContext } from '@/components/form/contexts/FieldContext';
import { withRHF } from '@/components/form/react-hook-form/withRHF';
import {
  selectFormValues,
  setFieldValue,
} from '@/features/form/formStateSlice';
import { a2p } from '@/local_packages/utils.js';
import { getDrupalSettings } from '@/utils/drupal-globals';

import DrupalFormattedTextArea from './DrupalFormattedTextArea';

import type { FormatType } from '@drupal-canvas/types';
import type { FormId } from '@/features/form/formStateSlice';
import type { Attributes } from '@/types/DrupalAttribute';

const drupalSettings = getDrupalSettings();

const DrupalTextArea = ({
  attributes = {},
  wrapperAttributes = {},
}: {
  attributes?: Attributes;
  wrapperAttributes?: Attributes;
}) => {
  const defaultFormatName =
    (attributes?.['data-canvas-text-format'] as string) || '';
  const [format, setFormat] = useState<FormatType>(
    (defaultFormatName &&
      drupalSettings?.editor?.formats?.[defaultFormatName]) || {
      format: defaultFormatName,
    },
  );

  const ref = useRef<HTMLTextAreaElement | null>(null);
  const availableFormats =
    (attributes?.['data-canvas-available-formats'] &&
      JSON.parse(attributes['data-canvas-available-formats'] as string)) ||
    null;

  const selectAttributes =
    (attributes?.['data-canvas-format-select-attributes'] &&
      JSON.parse(`${attributes['data-canvas-format-select-attributes']}`)) ||
    {};

  return (
    <>
      {format?.editor === 'ckeditor5' && format.editorSettings && (
        <DrupalFormattedTextArea
          attributes={attributes}
          format={{
            editorSettings: format.editorSettings,
          }}
          ref={ref}
        />
      )}
      {format?.editor !== 'ckeditor5' && (
        <div {...a2p(wrapperAttributes)}>
          <TextArea
            value={attributes.value?.toString() ?? ''}
            attributes={a2p(attributes, {}, { skipAttributes: ['value'] })}
            ref={ref}
          />
        </div>
      )}
      {availableFormats && format?.format && (
        <WrappedFormatSelect
          attributes={{ ...attributes, ...selectAttributes }}
          selectAttributes={selectAttributes}
          format={format}
          defaultFormatName={defaultFormatName}
          availableFormats={availableFormats}
          setFormat={setFormat}
        />
      )}
    </>
  );
};

interface FormatSelectProps {
  attributes: Attributes;
  selectAttributes: Record<string, any>;
  format: FormatType;
  defaultFormatName: string;
  availableFormats: Record<string, string>;
  setFormat: (format: FormatType) => void;
}

// The select element used to choose the text format.
const FormatSelect = ({
  attributes,
  selectAttributes,
  format,
  defaultFormatName,
  availableFormats,
  setFormat,
}: FormatSelectProps) => {
  const dispatch = useAppDispatch();
  const fieldName = attributes.name as string;
  const formId = attributes['data-form-id'] as FormId;
  const defaultValue = format.format || defaultFormatName;
  const formState = useAppSelector((state) => selectFormValues(state, formId));
  const fieldContext = useFieldContext();

  // On mount, if there's a default value, ensure it's set in the Redux store
  useEffect(() => {
    if (defaultValue && formId && fieldName) {
      // Only dispatch if the current value in the store is different
      const currentValue = formState?.[fieldName];
      if (currentValue !== defaultValue) {
        // Check if we need to initialize the value in the store
        // This ensures validators have the correct value even if the entity
        // was programmatically created without an explicit value
        setTimeout(() => {
          dispatch(
            setFieldValue({
              formId,
              fieldName,
              value: defaultValue,
            }),
          );
        });
      }
    }
  }, [dispatch, defaultValue, formId, fieldName, formState]);

  return (
    <Flex gap="1" align="center" my="2">
      <label htmlFor={(attributes.id as string) || ''}>Text format</label>
      {/* Using a native select instead of Radix requires less plumbing. */}
      <select
        {...a2p(attributes, {}, { skipAttributes: ['value'] })}
        {...a2p(selectAttributes)}
        defaultValue={defaultValue}
        data-testid="text-format-select"
        onChange={(e) => {
          const formatName = e.target.value;
          const newFormat = drupalSettings.editor.formats[formatName] || {
            format: formatName,
          };
          setFormat(newFormat);
          fieldContext?.triggerChange(formatName, e.target);
        }}
      >
        {Object.entries(availableFormats).map(([key, value], index) => (
          <option key={index} value={key}>
            {value as string}
          </option>
        ))}
      </select>
    </Flex>
  );
};

// We need to create a wrapper for FormatSelect that can be processed by withRHF
// withRHF expects a component that can accept any props, but we have specific prop requirements
const FormatSelectWrapper = (props: any) => {
  // Ensure we're using the right props
  return <FormatSelect {...(props as FormatSelectProps)} />;
};

// Now withRHF can process our component correctly
const WrappedFormatSelect = withRHF(FormatSelectWrapper);

export default withRHF(DrupalTextArea);
