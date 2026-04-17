import { useMemo } from 'react';
import {
  InfoCircledIcon,
  QuestionMarkCircledIcon,
} from '@radix-ui/react-icons';
import {
  Box,
  Callout,
  Flex,
  Select,
  Switch,
  TextField,
  Tooltip,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  addProp,
  removeProp,
  reorderProps,
  selectCodeComponentProperty,
  selectSavedPropIds,
  toggleRequired,
  updateProp,
} from '@/features/code-editor/codeEditorSlice';
import derivedPropTypes from '@/features/code-editor/component-data/derivedPropTypes';
import {
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import FormPropTypeArray from '@/features/code-editor/component-data/forms/FormPropTypeArray';
import FormPropTypeBoolean from '@/features/code-editor/component-data/forms/FormPropTypeBoolean';
import FormPropTypeDate from '@/features/code-editor/component-data/forms/FormPropTypeDate';
import FormPropTypeEnum from '@/features/code-editor/component-data/forms/FormPropTypeEnum';
import FormPropTypeFormattedText from '@/features/code-editor/component-data/forms/FormPropTypeFormattedText';
import FormPropTypeImage from '@/features/code-editor/component-data/forms/FormPropTypeImage';
import FormPropTypeLink, {
  DEFAULT_LINK_EXAMPLES,
  linkFormatMap,
} from '@/features/code-editor/component-data/forms/FormPropTypeLink';
import FormPropTypeTextField from '@/features/code-editor/component-data/forms/FormPropTypeTextField';
import FormPropTypeVideo from '@/features/code-editor/component-data/forms/FormPropTypeVideo';
import SortableList from '@/features/code-editor/component-data/SortableList';
import { getPropMachineName } from '@/features/code-editor/utils/utils';
import {
  VALUE_MODE_LIMITED,
  VALUE_MODE_UNLIMITED,
} from '@/types/CodeComponent';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type {
  CodeComponentProp,
  CodeComponentPropImageExample,
  CodeComponentPropVideoExample,
  ValueMode,
} from '@/types/CodeComponent';

import './Props.css';

// Default example values when prop is required.
export const DEFAULT_EXAMPLES: Record<string, string> = {
  text: 'Example text',
  integer: '0',
  number: '0',
  formattedText: '<p>Example text</p>',
  link: 'example',
  date: '2026-01-25',
  'date-time': '2026-01-25T12:00:00.000Z',
  listText: 'option_1',
  listInteger: '1',
};

// Default enum options for list types when prop is required
// Includes both derivedType keys (listText, listInteger) and type keys (string, integer, number)
const TEXT_ENUM_OPTION = { value: 'option_1', label: 'Option 1' };
const NUMBER_ENUM_OPTION = { value: '1', label: '1' };

export const DEFAULT_ENUM_OPTIONS: Record<
  string,
  { value: string; label: string }
> = {
  listText: TEXT_ENUM_OPTION,
  string: TEXT_ENUM_OPTION,
  listInteger: NUMBER_ENUM_OPTION,
  integer: NUMBER_ENUM_OPTION,
  number: NUMBER_ENUM_OPTION,
};

export const REQUIRED_EXAMPLE_ERROR_MESSAGE =
  'A required prop must have an example value.';

export default function Props() {
  const dispatch = useAppDispatch();
  const props = useAppSelector(selectCodeComponentProperty('props'));
  const required = useAppSelector(selectCodeComponentProperty('required'));
  const componentStatus = useAppSelector(selectCodeComponentProperty('status'));
  const initialPropIds = useAppSelector(selectSavedPropIds);

  // Memoized Set of prop IDs that need to be disabled from editing name and type.
  const disabledPropIds = useMemo(() => {
    if (!componentStatus) return new Set<string>();
    return new Set(initialPropIds);
  }, [componentStatus, initialPropIds]);

  /**
   * Helper function to create a new array with the specified count,
   * preserving existing values and filling new slots with default value.
   */
  const createArrayWithCount = (
    currentExample: unknown,
    newCount: number,
    defaultValue: string | number = '',
  ): (string | number)[] => {
    const exampleArray = Array.isArray(currentExample) ? currentExample : [];
    return Array.from(
      { length: newCount },
      (_, i) => exampleArray[i] ?? defaultValue,
    );
  };

  /**
   * Helper function to update both limitedCount and example array for a prop.
   */
  const updateLimitedCount = (
    propId: string,
    currentExample: unknown,
    newCount: number,
    defaultValue: string | number = '',
    derivedType?: string | null,
  ) => {
    // For complex object types (video, image), we need to preserve
    // existing objects or use an empty array.
    let newExample;
    if (derivedType === 'video' || derivedType === 'image') {
      const exampleArray = Array.isArray(currentExample) ? currentExample : [];
      newExample = exampleArray.slice(0, newCount);
    } else {
      newExample = createArrayWithCount(
        currentExample,
        newCount,
        defaultValue,
      ) as string[] | number[];
    }

    dispatch(
      updateProp({
        id: propId,
        updates: {
          limitedCount: newCount,
          example: newExample,
        },
      }),
    );
  };

  const handleAddProp = () => {
    dispatch(addProp());
  };

  const handleRemoveProp = (propId: string) => {
    dispatch(removeProp({ propId }));
  };

  const handleReorder = (oldIndex: number, newIndex: number) => {
    dispatch(reorderProps({ oldIndex, newIndex }));
  };

  const renderPropContent = (prop: CodeComponentProp) => {
    const propName = getPropMachineName(prop.name);
    return (
      <Flex direction="column" flexGrow="1">
        <Flex mb="4" gap="4" align="end" width="100%" wrap="wrap">
          <Box flexShrink="0" flexGrow="1">
            <FormElement>
              <Label htmlFor={`prop-name-${prop.id}`}>Prop name</Label>
              <TextField.Root
                autoComplete="off"
                id={`prop-name-${prop.id}`}
                placeholder="Enter a name"
                value={prop.name}
                size="1"
                onChange={(e) =>
                  dispatch(
                    updateProp({
                      id: prop.id,
                      updates: { name: e.target.value },
                    }),
                  )
                }
                disabled={disabledPropIds.has(prop.id)}
              />
            </FormElement>
          </Box>
          <Box flexShrink="0" minWidth="120px">
            <FormElement>
              <Label htmlFor={`prop-type-${prop.id}`}>Type</Label>
              <Select.Root
                value={prop.derivedType as string}
                size="1"
                onValueChange={(value) => {
                  const selectedPropType = derivedPropTypes.find(
                    (item) => item.type === value,
                  );
                  if (selectedPropType) {
                    const isRequired = required.includes(propName);
                    const isImageOrVideo =
                      value === 'image' || value === 'video';
                    // Default examples for image and video are handled in their own components
                    // regardless of required or not.
                    // @see FormPropTypeImage and FormPropTypeVideo
                    const defaultExample =
                      isRequired && !isImageOrVideo
                        ? DEFAULT_EXAMPLES[value]
                        : '';
                    dispatch(
                      updateProp({
                        id: prop.id,
                        updates: {
                          derivedType: value,
                          $ref: undefined,
                          format: undefined,
                          // Explicitly clear type-specific fields so they are not
                          // carried over from a previously selected prop type.
                          // When adding a new prop type with type-specific fields,
                          // add those fields here explicitly so they are cleared
                          // when switching away from that type.
                          contentMediaType: undefined,
                          'x-formatting-context': undefined,
                          example: defaultExample,
                          allowMultiple: false,
                          items: undefined,
                          valueMode: undefined,
                          limitedCount: undefined,
                          ...selectedPropType.init,
                          // Override the enum value from ...selectedPropType.init if the prop is required
                          // to have it prefilled with a default option.
                          enum:
                            isRequired && DEFAULT_ENUM_OPTIONS[value]
                              ? [DEFAULT_ENUM_OPTIONS[value]]
                              : selectedPropType.init.enum,
                        } as Partial<CodeComponentProp>,
                      }),
                    );
                  }
                }}
                // Disable changing type if component is exposed and prop existed when loaded.
                disabled={disabledPropIds.has(prop.id)}
              >
                <Select.Trigger id={`prop-type-${prop.id}`} />
                <Select.Content>
                  {derivedPropTypes.map((type) => (
                    <Select.Item key={type.type} value={type.type}>
                      {type.displayName}
                    </Select.Item>
                  ))}
                </Select.Content>
              </Select.Root>
            </FormElement>
          </Box>

          <Flex direction="column" gap="2">
            <Label htmlFor={`prop-required-${prop.id}`}>Required</Label>
            <Switch
              id={`prop-required-${prop.id}`}
              checked={required.includes(propName)}
              size="1"
              mb="1"
              onCheckedChange={() =>
                dispatch(
                  toggleRequired({
                    propId: prop.id,
                  }),
                )
              }
            />
          </Flex>
        </Flex>

        {(() => {
          switch (prop.derivedType) {
            case 'text':
            case 'integer':
            case 'number':
              return prop.allowMultiple ? (
                <FormPropTypeArray
                  id={prop.id}
                  example={prop.example as string[] | number[]}
                  itemType={
                    (prop.type === 'array' ? prop.items?.type : prop.type) as
                      | 'string'
                      | 'integer'
                      | 'number'
                  }
                  isDisabled={componentStatus}
                  required={required.includes(propName)}
                  valueMode={prop.valueMode}
                  limitedCount={prop.limitedCount}
                />
              ) : (
                <FormPropTypeTextField
                  id={prop.id}
                  type={prop.type as 'string' | 'number' | 'integer'}
                  example={prop.example as string}
                  required={required.includes(propName)}
                />
              );
            case 'formattedText':
              return (
                <FormPropTypeFormattedText
                  id={prop.id}
                  example={prop.example}
                  required={required.includes(propName)}
                />
              );
            case 'link':
              return (
                <FormPropTypeLink
                  id={prop.id}
                  example={prop.example as string | string[]}
                  format={prop.format as string}
                  isDisabled={disabledPropIds.has(prop.id)}
                  required={required.includes(propName)}
                  allowMultiple={prop.allowMultiple}
                  valueMode={prop.valueMode}
                  limitedCount={prop.limitedCount}
                />
              );
            case 'image':
              return (
                <FormPropTypeImage
                  id={prop.id}
                  example={
                    prop.example as
                      | CodeComponentPropImageExample
                      | CodeComponentPropImageExample[]
                  }
                  required={required.includes(propName)}
                  allowMultiple={prop.allowMultiple}
                  valueMode={prop.valueMode}
                  limitedCount={prop.limitedCount}
                />
              );
            case 'video':
              return (
                <FormPropTypeVideo
                  id={prop.id}
                  example={
                    prop.example as
                      | CodeComponentPropVideoExample
                      | CodeComponentPropVideoExample[]
                  }
                  required={required.includes(propName)}
                  allowMultiple={prop.allowMultiple}
                  valueMode={prop.valueMode}
                  limitedCount={prop.limitedCount}
                />
              );
            case 'boolean':
              return (
                <FormPropTypeBoolean
                  id={prop.id}
                  example={prop.example as string}
                />
              );
            case 'listText':
            case 'listInteger':
              return (
                <FormPropTypeEnum
                  type={
                    (prop.type === 'array' ? prop.items?.type : prop.type) as
                      | 'string'
                      | 'number'
                      | 'integer'
                  }
                  id={prop.id}
                  required={required.includes(propName)}
                  enum={prop.enum || []}
                  example={prop.example as string | string[]}
                  isDisabled={componentStatus}
                  allowMultiple={prop.allowMultiple}
                  valueMode={prop.valueMode}
                  limitedCount={prop.limitedCount}
                />
              );
            case 'date':
              return (
                <FormPropTypeDate
                  id={prop.id}
                  example={prop.example as string | string[]}
                  format={prop.format as string}
                  isDisabled={disabledPropIds.has(prop.id)}
                  required={required.includes(propName)}
                  allowMultiple={prop.allowMultiple}
                  valueMode={prop.valueMode}
                  limitedCount={prop.limitedCount}
                />
              );
          }
        })()}

        {/* Allow multiple values checkbox - shown for types that support it and only in dev mode */}
        {getCanvasSettings()?.devMode &&
          [
            'text',
            'link',
            'integer',
            'number',
            'image',
            'video',
            'date',
            'listText',
            'listInteger',
          ].includes(prop.derivedType ?? '') && (
            <Flex direction="column" gap="2" mt="3">
              <Flex align="center" gap="2" mt="3">
                <input
                  type="checkbox"
                  id={`prop-allow-multiple-${prop.id}`}
                  checked={prop.allowMultiple ?? false}
                  onChange={(e) => {
                    const checked = e.target.checked;
                    const updates: Partial<CodeComponentProp> = {
                      allowMultiple: checked,
                    };

                    if (checked) {
                      // Convert to array type - for date and link types.
                      if (['date', 'link'].includes(prop.derivedType ?? '')) {
                        const isRequired = required.includes(propName);
                        updates.type = 'array';
                        updates.items = {
                          type: 'string',
                          format: prop.format,
                        };
                        // Preserve the current example value for required props
                        // so the required error doesn't flash during the transition.
                        updates.example =
                          isRequired &&
                          prop.example &&
                          typeof prop.example === 'string'
                            ? [prop.example]
                            : [];
                        updates.valueMode = VALUE_MODE_UNLIMITED;
                        updates.limitedCount = 1;
                      } else if (
                        ['string', 'integer', 'number'].includes(prop.type)
                      ) {
                        const isRequired = required.includes(propName);
                        // Convert to array type - for primitive types.
                        updates.type = 'array';
                        updates.items = {
                          type: prop.type as 'string' | 'integer' | 'number',
                        };
                        // Preserve the current example value for required props
                        // so the required error doesn't flash during the transition.
                        updates.example =
                          isRequired &&
                          prop.example !== '' &&
                          prop.example !== undefined
                            ? ([prop.example] as string[] | number[])
                            : [];
                        updates.valueMode = VALUE_MODE_UNLIMITED;
                        updates.limitedCount = 1;
                      } else if (prop.type === 'object') {
                        // Convert to array type - for object types (image/video).
                        updates.type = 'array';
                        updates.items = {
                          type: 'object',
                          $ref: prop.$ref,
                        };
                        // Convert single object to array with that object
                        // Handle both valid objects and empty strings (which is the initial state)
                        if (
                          prop.example &&
                          typeof prop.example === 'object' &&
                          !Array.isArray(prop.example) &&
                          (prop.example as CodeComponentPropImageExample).src
                        ) {
                          updates.example = [prop.example] as
                            | CodeComponentPropImageExample[]
                            | CodeComponentPropVideoExample[];
                        } else {
                          // Start with empty array - FormPropTypeImageArray will handle initialization
                          updates.example = [];
                        }
                        updates.valueMode = 'unlimited';
                        updates.limitedCount = 1;
                      }
                    } else {
                      // Convert back to single value.
                      // Restore the original type from items before clearing.
                      if (prop.items?.type) {
                        updates.type = prop.items.type as
                          | 'string'
                          | 'integer'
                          | 'number'
                          | 'boolean'
                          | 'object';
                      }
                      updates.items = undefined;
                      // If prop is required, try to preserve the first value
                      // from the current array example so that the user's
                      // selection survives the single ↔ multi toggle
                      // round-trip. Fall back to type-specific defaults when
                      // the array is empty.
                      const isRequired = required.includes(propName);
                      let defaultExample = '';
                      if (isRequired) {
                        if (
                          Array.isArray(prop.example) &&
                          prop.example.length > 0 &&
                          String(prop.example[0]) !== ''
                        ) {
                          defaultExample = String(prop.example[0]);
                        } else if (
                          ['listText', 'listInteger'].includes(
                            prop.derivedType ?? '',
                          )
                        ) {
                          // For list types, use the first valid (non-empty) enum
                          // option as the fallback.
                          const firstValid = prop.enum?.find(
                            (item) => item.value !== '' && item.label !== '',
                          );
                          if (firstValid) {
                            defaultExample = String(firstValid.value);
                          }
                        } else if (prop.derivedType === 'link') {
                          const linkType =
                            prop.format && prop.format in linkFormatMap
                              ? linkFormatMap[
                                  prop.format as keyof typeof linkFormatMap
                                ]
                              : 'relative';
                          defaultExample = DEFAULT_LINK_EXAMPLES[linkType];
                        } else if (prop.derivedType === 'date') {
                          defaultExample =
                            DEFAULT_EXAMPLES[prop.format ?? 'date'] ?? '';
                        } else {
                          defaultExample =
                            DEFAULT_EXAMPLES[prop.derivedType ?? ''] ?? '';
                        }
                      }
                      updates.example = defaultExample;
                      updates.valueMode = undefined;
                      updates.limitedCount = undefined;
                    }

                    dispatch(updateProp({ id: prop.id, updates }));
                  }}
                  disabled={componentStatus}
                />
                <Label htmlFor={`prop-allow-multiple-${prop.id}`}>
                  Allow multiple values
                </Label>
                <Tooltip content="Stores a list of values instead of a single value">
                  <QuestionMarkCircledIcon />
                </Tooltip>
              </Flex>
              {/* Limited/Unlimited dropdown - only shown when allowMultiple is true */}
              {prop.allowMultiple && (
                <Flex align="center" gap="2">
                  <Select.Root
                    value={prop.valueMode ?? VALUE_MODE_UNLIMITED}
                    size="1"
                    onValueChange={(value: ValueMode) => {
                      const updates: Partial<CodeComponentProp> = {
                        valueMode: value,
                      };

                      if (value === VALUE_MODE_LIMITED) {
                        // When switching to limited mode, ensure we have exactly limitedCount items.
                        // The server requires maxItems >= 2 for array types, so enforce a minimum of 2.
                        const count = Math.max(2, prop.limitedCount ?? 2);
                        updates.limitedCount = count;

                        // For complex object types (video, image), we need to preserve
                        // existing objects or use an empty array.
                        if (
                          prop.derivedType === 'video' ||
                          prop.derivedType === 'image'
                        ) {
                          const exampleArray = Array.isArray(prop.example)
                            ? prop.example
                            : [];
                          updates.example = exampleArray.slice(0, count);
                        } else {
                          updates.example = createArrayWithCount(
                            prop.example,
                            count,
                            '',
                          ) as string[] | number[];
                        }
                      }

                      dispatch(updateProp({ id: prop.id, updates }));
                    }}
                    disabled={componentStatus}
                  >
                    <Select.Trigger
                      id={`prop-value-mode-${prop.id}`}
                      className="props-select-trigger"
                    />
                    <Select.Content>
                      <Select.Item value={VALUE_MODE_LIMITED}>
                        Limited
                      </Select.Item>
                      <Select.Item value={VALUE_MODE_UNLIMITED}>
                        Unlimited
                      </Select.Item>
                    </Select.Content>
                  </Select.Root>

                  {/* Counter for limited mode */}
                  {prop.valueMode === VALUE_MODE_LIMITED && (
                    <Box className="props-limited-box">
                      <TextField.Root
                        autoComplete="off"
                        id={`prop-limited-count-${prop.id}`}
                        type="number"
                        value={prop.limitedCount ?? 2}
                        size="1"
                        min={2}
                        max={
                          ['listText', 'listInteger'].includes(
                            prop.derivedType ?? '',
                          )
                            ? (prop.enum?.filter(
                                (item) =>
                                  item.value !== '' && item.label !== '',
                              ).length ?? undefined)
                            : undefined
                        }
                        className="props-textfield-root"
                        onChange={(e) => {
                          // For list types, max limit is the number of enum options
                          const maxLimit = ['listText', 'listInteger'].includes(
                            prop.derivedType ?? '',
                          )
                            ? (prop.enum?.filter(
                                (item) =>
                                  item.value !== '' && item.label !== '',
                              ).length ?? Infinity)
                            : Infinity;
                          const newCount = Math.min(
                            maxLimit,
                            Math.max(2, Number(e.target.value)),
                          );
                          // Use empty string as default to match single-value component behavior
                          // (no default value unless explicitly set or required)
                          updateLimitedCount(
                            prop.id,
                            prop.example,
                            newCount,
                            '',
                            prop.derivedType,
                          );
                        }}
                        disabled={componentStatus}
                      >
                        <TextField.Slot side="right">
                          <Flex gap="0">
                            <button
                              type="button"
                              onClick={() => {
                                const currentCount = prop.limitedCount ?? 2;
                                if (currentCount <= 2) return;
                                const newCount = currentCount - 1;
                                // Use empty string as default to match single-value component behavior
                                // (no default value unless explicitly set or required)
                                updateLimitedCount(
                                  prop.id,
                                  prop.example,
                                  newCount,
                                  '',
                                );
                              }}
                              disabled={
                                componentStatus || (prop.limitedCount ?? 2) <= 2
                              }
                              aria-label="Decrease count"
                              style={{
                                border: 'none',
                                background: 'transparent',
                                cursor:
                                  (prop.limitedCount ?? 2) <= 2
                                    ? 'not-allowed'
                                    : 'pointer',
                                padding: '2px 6px',
                                opacity:
                                  (prop.limitedCount ?? 2) <= 2 ? 0.5 : 1,
                              }}
                            >
                              −
                            </button>
                            <span className="props-divider" />
                            <button
                              type="button"
                              onClick={() => {
                                const currentCount = prop.limitedCount ?? 2;
                                // For list types, max limit is the number of enum options
                                const maxLimit = [
                                  'listText',
                                  'listInteger',
                                ].includes(prop.derivedType ?? '')
                                  ? (prop.enum?.filter(
                                      (item) =>
                                        item.value !== '' && item.label !== '',
                                    ).length ?? Infinity)
                                  : Infinity;
                                if (currentCount >= maxLimit) return;
                                const newCount = currentCount + 1;
                                // Use empty string as default to match single-value component behavior
                                // (no default value unless explicitly set or required)
                                updateLimitedCount(
                                  prop.id,
                                  prop.example,
                                  newCount,
                                  '',
                                );
                              }}
                              disabled={(() => {
                                const maxLimit = [
                                  'listText',
                                  'listInteger',
                                ].includes(prop.derivedType ?? '')
                                  ? (prop.enum?.filter(
                                      (item) =>
                                        item.value !== '' && item.label !== '',
                                    ).length ?? Infinity)
                                  : Infinity;
                                return (
                                  componentStatus ||
                                  (prop.limitedCount ?? 2) >= maxLimit
                                );
                              })()}
                              aria-label="Increase count"
                              style={{
                                border: 'none',
                                background: 'transparent',
                                cursor: (() => {
                                  const maxLimit = [
                                    'listText',
                                    'listInteger',
                                  ].includes(prop.derivedType ?? '')
                                    ? (prop.enum?.filter(
                                        (item) =>
                                          item.value !== '' &&
                                          item.label !== '',
                                      ).length ?? Infinity)
                                    : Infinity;
                                  return componentStatus ||
                                    (prop.limitedCount ?? 2) >= maxLimit
                                    ? 'not-allowed'
                                    : 'pointer';
                                })(),
                                padding: '2px 6px',
                                opacity: (() => {
                                  const maxLimit = [
                                    'listText',
                                    'listInteger',
                                  ].includes(prop.derivedType ?? '')
                                    ? (prop.enum?.filter(
                                        (item) =>
                                          item.value !== '' &&
                                          item.label !== '',
                                      ).length ?? Infinity)
                                    : Infinity;
                                  return (prop.limitedCount ?? 2) >= maxLimit
                                    ? 0.5
                                    : 1;
                                })(),
                              }}
                            >
                              +
                            </button>
                          </Flex>
                        </TextField.Slot>
                      </TextField.Root>
                    </Box>
                  )}
                </Flex>
              )}
            </Flex>
          )}
      </Flex>
    );
  };

  return (
    <>
      {/* Show a callout to inform the user the prop name and type is locked if there
       are any prop ids disabled from editing. */}
      {disabledPropIds.size > 0 && (
        <Box flexGrow="1" pt="4" maxWidth="500px" mx="auto">
          <Callout.Root size="1" variant="surface">
            <Callout.Icon>
              <InfoCircledIcon />
            </Callout.Icon>
            <Callout.Text>
              Changing the name and type of an existing prop is not allowed when
              a component is added to <b>Components</b> in the Library. Remove
              prop and create a new one instead.
            </Callout.Text>
          </Callout.Root>
        </Box>
      )}
      <SortableList
        items={props.filter((prop) => prop.derivedType !== null)}
        onAdd={handleAddProp}
        onReorder={handleReorder}
        onRemove={handleRemoveProp}
        renderContent={renderPropContent}
        getItemId={(item) => item.id}
        data-testid="prop"
        moveAriaLabel="Move prop"
        removeAriaLabel="Remove prop"
      />
    </>
  );
}
