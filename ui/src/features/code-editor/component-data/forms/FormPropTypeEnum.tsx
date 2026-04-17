import React, { useEffect, useMemo, useState } from 'react';
import { ChevronDownIcon, PlusIcon, TrashIcon } from '@radix-ui/react-icons';
import {
  Box,
  Button,
  Checkbox,
  Flex,
  Popover,
  Select,
  Text,
  TextField,
} from '@radix-ui/themes';

import './FormPropTypeEnum.css';

import { useAppDispatch } from '@/app/hooks';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import {
  DEFAULT_ENUM_OPTIONS,
  REQUIRED_EXAMPLE_ERROR_MESSAGE,
} from '@/features/code-editor/component-data/Props';
import { useRequiredProp } from '@/features/code-editor/hooks/useRequiredProp';
import {
  dispatchUpdateProp,
  hasNonEmptyArrayValue,
} from '@/features/code-editor/utils/arrayPropUtils';
import {
  VALUE_MODE_LIMITED,
  VALUE_MODE_UNLIMITED,
} from '@/types/CodeComponent';

import type {
  CodeComponentProp,
  CodeComponentPropEnumItem,
  ValueMode,
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
  allowMultiple = false,
  valueMode = VALUE_MODE_UNLIMITED,
  limitedCount = 1,
}: Pick<CodeComponentProp, 'id' | 'enum'> & {
  example: string | string[];
  required: boolean;
  type: 'string' | 'integer' | 'number';
  isDisabled?: boolean;
  allowMultiple?: boolean;
  valueMode?: ValueMode;
  limitedCount?: number;
}) {
  const dispatch = useAppDispatch();

  const validEnumValues = useMemo(() => {
    return enumValues.filter((item) => validateValue(item));
  }, [enumValues]);

  // Normalize for useRequiredProp: ![] is false in JS, so an empty selection
  // must be mapped to an empty string so the hook's empty-check works.
  const hasNonEmptyMultiValue = hasNonEmptyArrayValue(
    allowMultiple ? defaultValue : [],
  );
  const normalizedExampleForHook: string | string[] = allowMultiple
    ? hasNonEmptyMultiValue
      ? 'non-empty'
      : ''
    : (defaultValue as string);

  const { showRequiredError, setShowRequiredError } = useRequiredProp(
    required,
    normalizedExampleForHook,
    () => {
      if (allowMultiple) {
        // Multi-value mode: select the first available option or add a default one.
        if (validEnumValues.length === 0) {
          const defaultOption = DEFAULT_ENUM_OPTIONS[type];
          dispatchUpdateProp(dispatch, id, {
            enum: [defaultOption],
            example: [String(defaultOption.value)],
          });
        } else {
          dispatchUpdateProp(dispatch, id, {
            example: [String(validEnumValues[0].value)],
          });
        }
      } else {
        // Single-value mode (existing logic).
        if (validEnumValues.length === 0) {
          const defaultOption = DEFAULT_ENUM_OPTIONS[type];
          dispatchUpdateProp(dispatch, id, {
            enum: [defaultOption],
            example: String(defaultOption.value),
          });
        } else if (!defaultValue) {
          // If we have valid values but no default, set the first one
          dispatchUpdateProp(dispatch, id, {
            example: String(validEnumValues[0].value),
          });
        }
      }
    },
    [dispatch, id, type, validEnumValues, allowMultiple],
  );

  // Normalize defaultValue to always work with arrays internally when allowMultiple is true
  const selectedValues = useMemo(() => {
    if (!allowMultiple) {
      return [];
    }
    if (Array.isArray(defaultValue)) {
      return defaultValue.map((v) => String(v));
    }
    return defaultValue ? [String(defaultValue)] : [];
  }, [allowMultiple, defaultValue]);

  // Filter selectedValues to only include values that exist in validEnumValues
  const validSelectedValues = useMemo(() => {
    const validValueStrings = validEnumValues.map((item) => String(item.value));
    return selectedValues.filter((v) => validValueStrings.includes(v));
  }, [selectedValues, validEnumValues]);

  // Show error when required but no valid example or no options.
  // Also handles the multi-value case where defaultValue is an empty array
  // (![] is false in JS, so we use hasNonEmptyMultiValue instead).
  useEffect(() => {
    const hasNoValue = allowMultiple ? !hasNonEmptyMultiValue : !defaultValue;
    if (required && (validEnumValues.length === 0 || hasNoValue)) {
      setShowRequiredError(true);
    } else {
      setShowRequiredError(false);
    }
  }, [
    required,
    defaultValue,
    validEnumValues.length,
    setShowRequiredError,
    allowMultiple,
    hasNonEmptyMultiValue,
  ]);

  const handleDefaultValueChange = (value: string | number) => {
    dispatchUpdateProp(dispatch, id, {
      example: value === NONE_VALUE ? '' : String(value),
    });
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
            const validNewValues = (values || []).filter((item) =>
              validateValue(item),
            );

            const updates: Partial<CodeComponentProp> = { enum: values };

            // If in limited mode and valid options count decreased, update limitedCount.
            // Only reduce when valid options actually decreased (not when an empty row is
            // being added), to avoid resetting a user-configured cardinality back to 1.
            if (
              allowMultiple &&
              valueMode === VALUE_MODE_LIMITED &&
              validNewValues.length < validEnumValues.length &&
              validNewValues.length < limitedCount
            ) {
              updates.limitedCount = Math.max(1, validNewValues.length);
            }

            // For allowMultiple, filter out selected values that no longer exist
            if (allowMultiple && Array.isArray(defaultValue)) {
              const validValueStrings = validNewValues.map((item) =>
                String(item.value),
              );
              const filteredSelected = defaultValue.filter((v) =>
                validValueStrings.includes(String(v)),
              );
              // Only update if the filtered list is different
              if (filteredSelected.length !== defaultValue.length) {
                updates.example = filteredSelected;
              }
            } else {
              // For single value: Update default value if:
              // 1. Current default value doesn't exist in new values, OR
              // 2. Current default value is empty, prop is required, and there are valid values.
              if (
                !validNewValues.some((item) => item.value === defaultValue) ||
                (!defaultValue && validNewValues.length > 0)
              ) {
                if (required && validNewValues.length > 0) {
                  updates.example = String(validNewValues[0].value);
                } else {
                  updates.example = '';
                }
              }
            }

            dispatchUpdateProp(dispatch, id, updates);
          }}
        />
      </FormElement>
      {validEnumValues.length > 0 && (
        <>
          <Divider />
          <FormElement>
            <Label htmlFor={`prop-enum-default-${id}`}>
              Default value
              {allowMultiple &&
                valueMode === VALUE_MODE_LIMITED &&
                ` (max ${limitedCount})`}
            </Label>
            {allowMultiple ? (
              <Popover.Root>
                <Popover.Trigger>
                  <Button
                    variant="outline"
                    size="1"
                    color="gray"
                    className="fpe-btn-popover-trigger"
                  >
                    <Text size="1" truncate>
                      {validSelectedValues.length > 0
                        ? `${validSelectedValues.length} selected`
                        : '- None -'}
                    </Text>
                    <ChevronDownIcon />
                  </Button>
                </Popover.Trigger>
                <Popover.Content
                  className="fpe-popover-content"
                  style={{ width: 'var(--radix-popover-trigger-width)' }}
                  side="bottom"
                  align="start"
                >
                  <Flex direction="column" gap="2">
                    {validEnumValues.map((item, index) => {
                      const isSelected = validSelectedValues.includes(
                        String(item.value),
                      );
                      const isAtLimit =
                        valueMode === VALUE_MODE_LIMITED &&
                        validSelectedValues.length >= limitedCount &&
                        !isSelected;
                      return (
                        <Text
                          as="label"
                          key={`${item.value}-${index}`}
                          size="2"
                        >
                          <Flex gap="2" align="center">
                            <Checkbox
                              size="1"
                              checked={isSelected}
                              disabled={isAtLimit || isDisabled}
                              onCheckedChange={(checked) => {
                                let newSelected: string[];
                                if (checked) {
                                  newSelected = [
                                    ...validSelectedValues,
                                    String(item.value),
                                  ];
                                } else {
                                  newSelected = validSelectedValues.filter(
                                    (v) => v !== String(item.value),
                                  );
                                }

                                // Convert back to proper type for backend
                                const typedExample =
                                  type === 'integer' || type === 'number'
                                    ? newSelected.map((v) => Number(v))
                                    : newSelected;

                                dispatchUpdateProp(dispatch, id, {
                                  example: typedExample,
                                });
                              }}
                            />
                            {item.label}
                          </Flex>
                        </Text>
                      );
                    })}
                    {valueMode === VALUE_MODE_LIMITED && (
                      <>
                        <Box className="fpe-divider" />
                        <Text size="1" color="gray">
                          Selected: {validSelectedValues.length} /{' '}
                          {limitedCount}
                        </Text>
                      </>
                    )}
                  </Flex>
                </Popover.Content>
              </Popover.Root>
            ) : (
              <Select.Root
                value={
                  defaultValue === '' ? NONE_VALUE : (defaultValue as string)
                }
                onValueChange={(value) => {
                  handleDefaultValueChange(value);
                  // Show/hide error based on whether value is empty while required
                  setShowRequiredError(required && value === NONE_VALUE);
                }}
                size="1"
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
            )}
          </FormElement>
        </>
      )}
      {showRequiredError && (
        <Text color="red" size="1">
          {REQUIRED_EXAMPLE_ERROR_MESSAGE}
        </Text>
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
