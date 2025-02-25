import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  Box,
  Callout,
  Flex,
  Select,
  Switch,
  TextField,
} from '@radix-ui/themes';
import {
  addProp,
  removeProp,
  reorderProps,
  selectProps,
  selectRequired,
  selectStatus,
  toggleRequired,
  updateProp,
} from '@/features/code-editor/codeEditorSlice';
import FormPropTypeBoolean from '@/features/code-editor/component-data/forms/FormPropTypeBoolean';
import FormPropTypeEnum from '@/features/code-editor/component-data/forms/FormPropTypeEnum';
import FormPropTypeTextField from '@/features/code-editor/component-data/forms/FormPropTypeTextField';
import SortableList from '@/features/code-editor/component-data/SortableList';
import {
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import type { CodeComponentProp } from '@/types/CodeComponent';
import { getPropMachineName } from '@/features/code-editor/utils';
import { InfoCircledIcon } from '@radix-ui/react-icons';

// The followings are actual types in the JavaScriptComponent config entity
// schema for props: string, integer, number, boolean.
// They can all support enums, which is an additional property in the schema.
// Here we create a consolidated view of the types for the UI.
// @see config/schema/experience_builder.schema.yml#experience_builder.js_component.*.mapping.props
const PROP_TYPES = {
  string: 'Text',
  integer: 'Integer',
  number: 'Number',
  boolean: 'Boolean',
  stringEnum: 'List: text',
  integerEnum: 'List: integer',
  numberEnum: 'List: number',
};

function getTypeAndIsEnum(key: keyof typeof PROP_TYPES) {
  const [, type, enum_] = key.match(/^(.*?)(Enum)?$/) || [];
  return { type, isEnum: Boolean(enum_) };
}

export default function Props() {
  const dispatch = useAppDispatch();
  const props = useAppSelector(selectProps);
  const required = useAppSelector(selectRequired);
  const componentStatus = useAppSelector(selectStatus);

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
                disabled={componentStatus}
              />
            </FormElement>
          </Box>
          <Box flexShrink="0" minWidth="120px">
            <FormElement>
              <Label htmlFor={`prop-type-${prop.id}`}>Type</Label>
              <Select.Root
                value={`${prop.type}${prop.enum ? 'Enum' : ''}`}
                size="1"
                onValueChange={(value) => {
                  const { type, isEnum } = getTypeAndIsEnum(
                    value as keyof typeof PROP_TYPES,
                  );
                  dispatch(
                    updateProp({
                      id: prop.id,
                      updates: {
                        type: type as CodeComponentProp['type'],
                        enum: isEnum ? [] : undefined,
                        example: type === 'boolean' ? 'false' : '',
                      },
                    }),
                  );
                }}
                disabled={componentStatus}
              >
                <Select.Trigger id={`prop-type-${prop.id}`} />
                <Select.Content>
                  {Object.entries(PROP_TYPES).map(([key, label]) => (
                    <Select.Item key={key} value={key}>
                      {label}
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
              disabled={componentStatus}
            />
          </Flex>
        </Flex>

        {(() => {
          switch (prop.type) {
            case 'string':
              return prop.enum ? (
                <FormPropTypeEnum
                  type="string"
                  id={prop.id}
                  required={required.includes(propName)}
                  enum={prop.enum || []}
                  example={prop.example}
                  isDisabled={componentStatus}
                />
              ) : (
                <FormPropTypeTextField
                  id={prop.id}
                  example={prop.example}
                  isDisabled={componentStatus}
                />
              );
            case 'integer':
              return prop.enum ? (
                <FormPropTypeEnum
                  type="integer"
                  id={prop.id}
                  required={required.includes(propName)}
                  enum={prop.enum || []}
                  example={prop.example}
                  isDisabled={componentStatus}
                />
              ) : (
                <FormPropTypeTextField
                  id={prop.id}
                  example={prop.example}
                  type="integer"
                  isDisabled={componentStatus}
                />
              );
            case 'number':
              return prop.enum ? (
                <FormPropTypeEnum
                  type="number"
                  id={prop.id}
                  required={required.includes(propName)}
                  enum={prop.enum || []}
                  example={prop.example}
                  isDisabled={componentStatus}
                />
              ) : (
                <FormPropTypeTextField
                  id={prop.id}
                  example={prop.example}
                  type="number"
                  isDisabled={componentStatus}
                />
              );
            case 'boolean':
              return (
                <FormPropTypeBoolean
                  id={prop.id}
                  example={prop.example}
                  isDisabled={componentStatus}
                />
              );
            default:
              return null;
          }
        })()}
      </Flex>
    );
  };

  return (
    <>
      {/* If a component is exposed, show a callout to inform the user that props and slots are locked */}
      {componentStatus && (
        <Box flexGrow="1" pt="4" maxWidth="500px" mx="auto">
          <Callout.Root size="1" variant="surface">
            <Callout.Icon>
              <InfoCircledIcon />
            </Callout.Icon>
            <Callout.Text>
              Props and slots are locked when a component is added to{' '}
              <b>Components</b>.
              <br />
              <br />
              To modify props and slots, remove the component from{' '}
              <b>Components</b>.
            </Callout.Text>
          </Callout.Root>
        </Box>
      )}
      <SortableList
        items={props}
        onAdd={handleAddProp}
        onReorder={handleReorder}
        onRemove={handleRemoveProp}
        renderContent={renderPropContent}
        getItemId={(item) => item.id}
        data-testid="prop"
        moveAriaLabel="Move prop"
        removeAriaLabel="Remove prop"
        isDisabled={componentStatus}
      />
    </>
  );
}
