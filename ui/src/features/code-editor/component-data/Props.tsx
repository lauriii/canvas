import { useMemo } from 'react';
import { InfoCircledIcon } from '@radix-ui/react-icons';
import {
  Box,
  Callout,
  Flex,
  Select,
  Switch,
  TextField,
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
import FormPropTypeBoolean from '@/features/code-editor/component-data/forms/FormPropTypeBoolean';
import FormPropTypeDate from '@/features/code-editor/component-data/forms/FormPropTypeDate';
import FormPropTypeEnum from '@/features/code-editor/component-data/forms/FormPropTypeEnum';
import FormPropTypeFormattedText from '@/features/code-editor/component-data/forms/FormPropTypeFormattedText';
import FormPropTypeImage from '@/features/code-editor/component-data/forms/FormPropTypeImage';
import FormPropTypeLink from '@/features/code-editor/component-data/forms/FormPropTypeLink';
import FormPropTypeTextField from '@/features/code-editor/component-data/forms/FormPropTypeTextField';
import FormPropTypeVideo from '@/features/code-editor/component-data/forms/FormPropTypeVideo';
import SortableList from '@/features/code-editor/component-data/SortableList';
import { getPropMachineName } from '@/features/code-editor/utils/utils';

import type {
  CodeComponentProp,
  CodeComponentPropImageExample,
  CodeComponentPropVideoExample,
} from '@/types/CodeComponent';

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
                    dispatch(
                      updateProp({
                        id: prop.id,
                        updates: {
                          derivedType: value,
                          example: '',
                          enum: undefined,
                          $ref: undefined,
                          format: undefined,
                          ...selectedPropType.init,
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
              return (
                <FormPropTypeTextField
                  id={prop.id}
                  type={prop.type as 'string' | 'number' | 'integer'}
                  example={prop.example as string}
                />
              );
            case 'formattedText':
              return (
                <FormPropTypeFormattedText
                  id={prop.id}
                  example={prop.example}
                />
              );
            case 'link':
              return (
                <FormPropTypeLink
                  id={prop.id}
                  example={prop.example as string}
                  format={prop.format as string}
                  isDisabled={disabledPropIds.has(prop.id)}
                />
              );
            case 'image':
              return (
                <FormPropTypeImage
                  id={prop.id}
                  example={prop.example as CodeComponentPropImageExample}
                  required={required.includes(propName)}
                />
              );
            case 'video':
              return (
                <FormPropTypeVideo
                  id={prop.id}
                  example={prop.example as CodeComponentPropVideoExample}
                  required={required.includes(propName)}
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
                  type={prop.type as 'string' | 'number' | 'integer'}
                  id={prop.id}
                  required={required.includes(propName)}
                  enum={prop.enum || []}
                  example={prop.example as string}
                />
              );
            case 'date':
              return (
                <FormPropTypeDate
                  id={prop.id}
                  example={prop.example as string}
                  format={prop.format as string}
                  isDisabled={disabledPropIds.has(prop.id)}
                />
              );
          }
        })()}
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
