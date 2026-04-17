import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { Box, Flex, Select, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { jsonSchemaValidate } from '@/components/form/formUtil';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import { PropValuesSortableList } from '@/features/code-editor/component-data/forms/PropValuesSortableList';
import { REQUIRED_EXAMPLE_ERROR_MESSAGE } from '@/features/code-editor/component-data/Props';
import {
  useRequiredProp,
  useSyncRequiredArrayError,
} from '@/features/code-editor/hooks/useRequiredProp';
import {
  createArrayDragEndHandler,
  createDisplayArray,
  dispatchUpdateProp,
  handleArrayAdd,
  handleArrayRemove,
  handleArrayValueChange,
  hasNonEmptyArrayValue,
} from '@/features/code-editor/utils/arrayPropUtils';
import {
  VALUE_MODE_LIMITED,
  VALUE_MODE_UNLIMITED,
} from '@/types/CodeComponent';

import type { CodeComponentProp, ValueMode } from '@/types/CodeComponent';

import styles from '@/features/code-editor/component-data/FormElement.module.css';

const BASE_URL = window.location.origin;

export const linkFormatMap = {
  'uri-reference': 'relative',
  uri: 'full',
} as const;

export const DEFAULT_LINK_EXAMPLES = {
  relative: 'example',
  full: 'https://example.com',
};

export default function FormPropTypeLink({
  id,
  example,
  format,
  isDisabled = false,
  required,
  allowMultiple = false,
  valueMode = VALUE_MODE_UNLIMITED,
  limitedCount = 1,
}: Pick<CodeComponentProp, 'id'> & {
  example: string | string[];
  format: string;
  isDisabled?: boolean;
  required: boolean;
  allowMultiple?: boolean;
  valueMode?: ValueMode;
  limitedCount?: number;
}) {
  const dispatch = useAppDispatch();
  const [linkType, setLinkType] = useState<'relative' | 'full'>(
    format && format in linkFormatMap
      ? linkFormatMap[format as keyof typeof linkFormatMap]
      : 'relative',
  );
  function validateLinkValue(value: string, linkType: 'relative' | 'full') {
    if (value === '') return true;
    const [isValidValue] = jsonSchemaValidate(value, {
      type: 'string',
      format: linkType === 'full' ? 'uri' : 'uri-reference',
    });
    return isValidValue;
  }

  const [isExampleValueValid, setIsExampleValueValid] = useState(true);

  // For multi-value mode, normalize the example so `useRequiredProp` can
  // detect empty arrays (since `![]` is false in JS).
  const hasNonEmptyValue = hasNonEmptyArrayValue(allowMultiple ? example : []);

  const { showRequiredError, setShowRequiredError } = useRequiredProp(
    required,
    allowMultiple ? (hasNonEmptyValue ? 'non-empty' : '') : example,
    () => {
      if (allowMultiple) {
        dispatchUpdateProp(dispatch, id, {
          example: [DEFAULT_LINK_EXAMPLES[linkType]],
          format: linkType === 'full' ? 'uri' : 'uri-reference',
        });
      } else {
        dispatchUpdateProp(dispatch, id, {
          example: DEFAULT_LINK_EXAMPLES[linkType],
          format: linkType === 'full' ? 'uri' : 'uri-reference',
        });
      }
    },
    [dispatch, id, linkType, allowMultiple],
  );

  // Keep error state in sync with whether the array has non-empty values.
  useSyncRequiredArrayError(
    required,
    hasNonEmptyValue,
    setShowRequiredError,
    allowMultiple,
  );

  const displayArray = useMemo(
    () =>
      createDisplayArray(
        example,
        valueMode,
        limitedCount,
        allowMultiple,
      ) as string[],
    [example, valueMode, limitedCount, allowMultiple],
  );

  // Add validity state for multiple values
  const [multiValueValidityStates, setMultiValueValidityStates] = useState<
    boolean[]
  >(() => displayArray.map((v) => validateLinkValue(v, linkType)));

  useEffect(() => {
    if (
      valueMode === VALUE_MODE_LIMITED &&
      limitedCount > multiValueValidityStates.length
    ) {
      setMultiValueValidityStates((prev) => {
        const next = [...prev];
        while (next.length < limitedCount) {
          next.push(true);
        }
        return next;
      });
    }
  }, [limitedCount, valueMode, multiValueValidityStates.length]);

  const handleDragEnd = createArrayDragEndHandler(displayArray, dispatch, id);

  const handleAdd = () => {
    handleArrayAdd(displayArray, dispatch, id, '');
    setMultiValueValidityStates((prev) => [...prev, true]); // Add validity for new input
  };

  const handleRemove = (index: number) => {
    handleArrayRemove(displayArray, dispatch, id, index);
    setMultiValueValidityStates((prev) => {
      const next = [...prev];
      next.splice(index, 1);
      return next;
    });
  };

  const handleMultiValueChange = (index: number, value: string) => {
    handleArrayValueChange(displayArray, dispatch, id, index, value, {
      format: linkType === 'full' ? 'uri' : 'uri-reference',
    });
    // Reset validity on change
    setMultiValueValidityStates((prev) => {
      const next = [...prev];
      next[index] = true;
      return next;
    });
  };

  const handleMultiValueBlur = (index: number, value: string) => {
    setMultiValueValidityStates((prev) => {
      const next = [...prev];
      next[index] = validateLinkValue(value, linkType);
      return next;
    });
  };

  const renderInputField = (index: number) => (
    <Flex align="center" gap="1" flexGrow="1">
      {linkType === 'relative' && (
        <Flex flexShrink="0" align="center">
          <Text size="1" color="gray">
            {BASE_URL}/
          </Text>
        </Flex>
      )}
      <Box flexGrow="1">
        <TextField.Root
          autoComplete="off"
          data-testid={`array-prop-value-${id}-${index}`}
          id={`array-prop-value-${id}-${index}`}
          type="text"
          placeholder={linkType === 'relative' ? 'Enter a path' : 'Enter a URL'}
          value={String(displayArray[index] ?? '')}
          size="1"
          onChange={(e) => handleMultiValueChange(index, e.target.value)}
          onBlur={(e) => handleMultiValueBlur(index, e.target.value)}
          className={clsx({
            [styles.error]: !multiValueValidityStates[index],
          })}
          {...(!multiValueValidityStates[index]
            ? { 'data-invalid-prop-value': true }
            : {})}
        />
      </Box>
    </Flex>
  );

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <FormElement>
        <Label htmlFor={`prop-link-type-${id}`}>Link type</Label>
        <Select.Root
          value={linkType}
          onValueChange={(value: 'relative' | 'full') => {
            setIsExampleValueValid(true);
            setLinkType(value);

            // Get the default value for the new link type
            const newDefaultValue = DEFAULT_LINK_EXAMPLES[value];
            const newFormat = value === 'full' ? 'uri' : 'uri-reference';

            // In multi-value mode, reset the array to a single default value
            if (allowMultiple) {
              dispatchUpdateProp(dispatch, id, {
                example: [newDefaultValue],
                format: newFormat,
                valueMode: VALUE_MODE_UNLIMITED,
                limitedCount: undefined,
              });
              // Reset validity states to a single valid entry
              setMultiValueValidityStates([true]);
            } else {
              // Single value mode - just update format and example
              dispatchUpdateProp(dispatch, id, {
                example: newDefaultValue,
                format: newFormat,
              });
            }
          }}
          size="1"
          disabled={isDisabled}
        >
          <Select.Trigger id={`prop-link-type-${id}`} />
          <Select.Content>
            <Select.Item value="relative">Relative path</Select.Item>
            <Select.Item value="full">Full URL</Select.Item>
          </Select.Content>
        </Select.Root>
      </FormElement>
      <Divider />
      <FormElement>
        <Label htmlFor={`prop-example-${id}`}>Example value</Label>
        {/* Single value mode */}
        {!allowMultiple && (
          <Flex align="center" gap="1" width="100%">
            {linkType === 'relative' && (
              <Flex flexShrink="0" align="center">
                <Text size="1" color="gray">
                  {BASE_URL}/
                </Text>
              </Flex>
            )}
            <Box flexGrow="1">
              <TextField.Root
                autoComplete="off"
                id={`prop-example-${id}`}
                type="text"
                placeholder={
                  linkType === 'relative' ? 'Enter a path' : 'Enter a URL'
                }
                value={typeof example === 'string' ? example : ''}
                size="1"
                onChange={(e) => {
                  const input = e.target;
                  setIsExampleValueValid(true); // Reset validation state on change
                  // Show/hide error based on whether field is empty while required
                  setShowRequiredError(required && !input.value);
                  dispatchUpdateProp(dispatch, id, {
                    example: input.value,
                    format: linkType === 'full' ? 'uri' : 'uri-reference',
                  });
                }}
                onBlur={(e) => {
                  setIsExampleValueValid(
                    validateLinkValue(e.target.value, linkType),
                  );
                }}
                className={clsx({
                  [styles.error]: !isExampleValueValid || showRequiredError,
                })}
                {...(!isExampleValueValid || showRequiredError
                  ? { 'data-invalid-prop-value': true }
                  : {})}
              />
            </Box>
          </Flex>
        )}

        {/* Multiple values mode */}
        {allowMultiple && (
          <PropValuesSortableList
            items={displayArray.map((_, index) => index)}
            renderItem={renderInputField}
            onDragEnd={handleDragEnd}
            onRemove={
              valueMode === VALUE_MODE_UNLIMITED ? handleRemove : undefined
            }
            onAdd={valueMode === VALUE_MODE_UNLIMITED ? handleAdd : undefined}
            isDisabled={isDisabled}
            mode={valueMode}
            errorMessage={
              showRequiredError && (
                <Text color="red" size="1">
                  {REQUIRED_EXAMPLE_ERROR_MESSAGE}
                </Text>
              )
            }
          />
        )}
        {!allowMultiple && showRequiredError && (
          <Text color="red" size="1">
            {REQUIRED_EXAMPLE_ERROR_MESSAGE}
          </Text>
        )}
      </FormElement>
    </Flex>
  );
}
