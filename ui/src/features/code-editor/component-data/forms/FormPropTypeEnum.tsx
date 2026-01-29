import React, { useEffect, useMemo, useState } from 'react';
import { PlusIcon, TrashIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Select, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';

import type {
  CodeComponentProp,
  CodeComponentPropEnumItem,
} from '@/types/CodeComponent';

const NONE_VALUE = '_none_';

const validateValue = (item: CodeComponentPropEnumItem): boolean => {
  return item.value !== '' && item.label !== '';
};

// Helper: find indices of duplicate values in the array of props.
const getDuplicateValueIndices = (
  arr: Array<Pick<CodeComponentPropEnumItem, 'value'>>,
) => {
  const valueCount: Record<string | number, number> = {};
  arr.forEach(({ value }) => {
    valueCount[value] = (valueCount[value] || 0) + 1;
  });
  return arr.map(({ value }) => valueCount[value] > 1 && value !== '');
};

export default function FormPropTypeEnum({
  id,
  enum: enumValues = [],
  example: defaultValue,
  required,
  type,
  isDisabled = false,
}: Pick<CodeComponentProp, 'id' | 'enum'> & {
  example: string;
  required: boolean;
  type: 'string' | 'integer' | 'number';
  isDisabled: boolean;
}) {
  const dispatch = useAppDispatch();
  const [localRequired, setLocalRequired] = useState(required);

  const validEnumValues = useMemo(() => {
    return enumValues.filter((item) => validateValue(item));
  }, [enumValues]);

  useEffect(() => {
    // Whether the prop is required is tracked in a local state, so we can update
    // the default value when it changes.
    setLocalRequired(required);

    // Update the default value when:
    // 1. Required status has changed (required !== localRequired);
    // 2. Prop is becoming required (required === true);
    // 3. We have valid values;
    // 4. No default value is set.
    if (
      required !== localRequired &&
      required &&
      validEnumValues.length > 0 &&
      !defaultValue
    ) {
      dispatch(
        updateProp({
          id,
          updates: { example: String(validEnumValues[0].value) },
        }),
      );
    }
  }, [defaultValue, dispatch, id, localRequired, required, validEnumValues]);

  const handleDefaultValueChange = (value: string | number) => {
    dispatch(
      updateProp({
        id,
        updates: { example: value === NONE_VALUE ? '' : String(value) },
      }),
    );
  };

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <FormElement>
        <Text size="1" weight="medium" as="div">
          Options
        </Text>
        <EnumValuesForm
          propId={id}
          values={enumValues || []}
          type={type}
          isDisabled={isDisabled}
          onChange={(values) => {
            dispatch(
              updateProp({
                id,
                updates: { enum: values },
              }),
            );

            const validNewValues = (values || []).filter((item) =>
              validateValue(item),
            );

            // Update default value if:
            // 1. Current default value doesn't exist in new values, OR
            // 2. Current default value is empty, prop is required, and there are valid values.
            if (
              !validNewValues.some((item) => item.value === defaultValue) ||
              (!defaultValue && validNewValues.length > 0)
            ) {
              if (required && validNewValues.length > 0) {
                handleDefaultValueChange(validNewValues[0].value);
              } else {
                handleDefaultValueChange(NONE_VALUE);
              }
            }
          }}
        />
      </FormElement>
      {validEnumValues.length > 0 && (
        <>
          <Divider />
          <FormElement>
            <Label htmlFor={`prop-enum-default-${id}`}>Default value</Label>
            <Select.Root
              value={defaultValue === '' ? NONE_VALUE : defaultValue}
              onValueChange={handleDefaultValueChange}
              size="1"
              disabled={isDisabled}
            >
              <Select.Trigger id={`prop-enum-default-${id}`} />
              <Select.Content>
                {!required && (
                  <Select.Item value={NONE_VALUE}>- None -</Select.Item>
                )}
                {validEnumValues.map((item, index) => (
                  <Select.Item
                    key={`${item.value}-${index}`}
                    value={String(item.value)}
                  >
                    {item.label}
                  </Select.Item>
                ))}
              </Select.Content>
            </Select.Root>
          </FormElement>
        </>
      )}
    </Flex>
  );
}

function EnumValuesForm({
  propId,
  values = [],
  onChange,
  type,
  isDisabled,
}: {
  propId: string;
  values: Array<CodeComponentPropEnumItem>;
  onChange: (values: Array<CodeComponentPropEnumItem>) => void;
  type: 'string' | 'integer' | 'number';
  isDisabled: boolean;
}) {
  // Keep track of which labels have been touched (manually edited) by the user.
  // If a label has not been touched, it will be auto-updated to match the value.
  const [touched, setTouched] = useState(values.map(({ label }) => !!label));
  const typeRef = React.useRef(type);

  useEffect(() => {
    // If the type changes, the values are reset, so we need to reset the touched state.
    if (typeRef.current !== type && type !== undefined) {
      setTouched([false]);
    }
    typeRef.current = type;
  }, [type]);

  const invalidValueIndices = getDuplicateValueIndices(values);

  const handleAdd = () => {
    onChange([...values, { label: '', value: '' }]);
  };

  const handleRemove = (index: number) => {
    const newValues = [...values];
    newValues.splice(index, 1);
    // Also remove the touched state for this index.
    setTouched((prev) => prev.filter((_, i) => i !== index));
    onChange(newValues);
  };

  const handleValueChange = (index: number, value: string | number) => {
    const newValues = [...values];
    // If label is untouched, update label to match the value entered.
    if (!touched[index]) {
      newValues[index] = { ...newValues[index], value, label: String(value) };
    } else {
      newValues[index] = { ...newValues[index], value };
    }
    onChange(newValues);
  };

  const handleLabelChange = (index: number, label: string) => {
    const newValues = [...values];
    newValues[index] = { ...newValues[index], label };
    setTouched((prev) => Object.assign([...prev], { [index]: true }));
    onChange(newValues);
  };

  return (
    <Flex mt="1" direction="column" gap="2" flexGrow="1" width="100%">
      {values.map((item, index) => (
        <React.Fragment key={index}>
          <Flex gap="2" align="end" flexGrow="1" width="100%">
            <Box flexGrow="1" flexShrink="1">
              <FormElement>
                <Label htmlFor={`canvas-prop-enum-value-${propId}-${index}`}>
                  Value
                </Label>
                <TextField.Root
                  autoComplete="off"
                  data-testid={`canvas-prop-enum-value-${propId}-${index}`}
                  id={`canvas-prop-enum-value-${propId}-${index}`}
                  type={
                    ['integer', 'number'].includes(type) ? 'number' : 'text'
                  }
                  step={type === 'integer' ? 1 : undefined}
                  value={item.value}
                  size="1"
                  onChange={(e) => handleValueChange(index, e.target.value)}
                  placeholder={
                    {
                      string: 'Enter a text value',
                      integer: 'Enter an integer',
                      number: 'Enter a number',
                    }[type]
                  }
                  disabled={isDisabled}
                  // Show as invalid if duplicate
                  color={invalidValueIndices[index] ? 'red' : undefined}
                />
              </FormElement>
            </Box>
            <Box flexGrow="1" flexShrink="1">
              <FormElement>
                <Label htmlFor={`canvas-prop-enum-label-${propId}-${index}`}>
                  Label
                </Label>
                <TextField.Root
                  autoComplete="off"
                  data-testid={`canvas-prop-enum-label-${propId}-${index}`}
                  id={`canvas-prop-enum-label-${propId}-${index}`}
                  type="text"
                  value={item.label}
                  size="1"
                  onChange={(e) => handleLabelChange(index, e.target.value)}
                  placeholder="Enter a label"
                  disabled={isDisabled}
                />
              </FormElement>
            </Box>
            <Button
              aria-label="Remove value"
              data-testid={`canvas-prop-enum-value-delete-${propId}-${index}`}
              size="1"
              color="red"
              variant="soft"
              onClick={() => handleRemove(index)}
              disabled={isDisabled}
            >
              <TrashIcon />
            </Button>
          </Flex>
          {invalidValueIndices[index] && (
            <Text color="red" size="1">
              Value must be unique.
            </Text>
          )}
        </React.Fragment>
      ))}
      <Button size="1" variant="soft" onClick={handleAdd} disabled={isDisabled}>
        <Flex gap="1" align="center">
          <PlusIcon />
          <Text size="1">Add value</Text>
        </Flex>
      </Button>
    </Flex>
  );
}
