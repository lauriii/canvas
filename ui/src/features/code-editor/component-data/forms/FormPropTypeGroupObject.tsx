import { useState } from 'react';
import clsx from 'clsx';
import { v4 as uuidv4 } from 'uuid';
import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
  CalendarIcon,
  CheckboxIcon,
  DragHandleDots2Icon,
  ImageIcon,
  Link2Icon,
  ListBulletIcon,
  PlusIcon,
  TextIcon,
  TokensIcon,
  VideoIcon,
} from '@radix-ui/react-icons';
import {
  Box,
  Button,
  Flex,
  Select,
  Switch,
  Text,
  TextArea,
  TextField,
} from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import derivedPropTypes from '@/features/code-editor/component-data/derivedPropTypes';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import { getPropMachineName } from '@/features/code-editor/utils/utils';

import type { DragEndEvent } from '@dnd-kit/core';
import type {
  CodeComponentGroupNestedProp,
  CodeComponentProp,
  CodeComponentPropEnumItem,
} from '@/types/CodeComponent';

import styles from './FormPropTypeGroupObject.module.css';

// The icon shown on a nested prop row, per derived prop type.
// @see https://www.figma.com/design/ZSlXxBDIGLV2riMAxCv9QE (node 899-61353)
const NESTED_PROP_TYPE_ICONS: Record<string, typeof TokensIcon> = {
  text: TextIcon,
  link: Link2Icon,
  image: ImageIcon,
  video: VideoIcon,
  date: CalendarIcon,
  boolean: CheckboxIcon,
  listText: ListBulletIcon,
  listInteger: ListBulletIcon,
};

// The prop types a nested prop of a group can use: any existing prop type
// except another group (the one-level depth limit), formatted text (plain
// strings only inside groups), and content entity references (their
// `dataDependencies` wiring only exists for top-level props).
const NESTED_PROP_TYPES = derivedPropTypes.filter(
  (type) =>
    !['group', 'formattedText', 'contentEntityReference'].includes(type.type),
);

// Nested prop types that support "Allow multiple values" (arrays of scalars).
const NESTED_MULTIPLE_TYPES = [
  'text',
  'link',
  'integer',
  'number',
  'date',
  'listText',
  'listInteger',
];

function newNestedProp(): CodeComponentGroupNestedProp {
  return {
    id: uuidv4(),
    name: '',
    type: 'string',
    example: '',
    derivedType: 'text',
    required: false,
  };
}

/**
 * Authoring UI for "Group object" props: a nested-prop list with a dialog.
 *
 * @see docs/adr/0021-object-props-in-code-components.md
 */
export default function FormPropTypeGroupObject({
  id,
  properties,
  allowMultiple = false,
  isDisabled = false,
}: Pick<CodeComponentProp, 'id'> & {
  properties: CodeComponentGroupNestedProp[];
  allowMultiple?: boolean;
  isDisabled?: boolean;
}) {
  const dispatch = useAppDispatch();
  // The nested prop currently being edited in the dialog, or null.
  const [editedProp, setEditedProp] =
    useState<CodeComponentGroupNestedProp | null>(null);
  const [isNewProp, setIsNewProp] = useState(false);

  const updateProperties = (
    newProperties: CodeComponentGroupNestedProp[],
    extraUpdates: Partial<CodeComponentProp> = {},
  ) => {
    dispatch(
      updateProp({
        id,
        updates: { properties: newProperties, ...extraUpdates },
      }),
    );
  };

  const handleSave = () => {
    if (!editedProp) {
      return;
    }
    const exists = properties.some((nested) => nested.id === editedProp.id);
    updateProperties(
      exists
        ? properties.map((nested) =>
            nested.id === editedProp.id ? editedProp : nested,
          )
        : [...properties, editedProp],
    );
    setEditedProp(null);
  };

  const handleDelete = () => {
    if (!editedProp) {
      return;
    }
    updateProperties(
      properties.filter((nested) => nested.id !== editedProp.id),
    );
    setEditedProp(null);
  };

  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  );

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (over && active.id !== over.id) {
      const oldIndex = properties.findIndex(
        (nested) => nested.id === active.id,
      );
      const newIndex = properties.findIndex((nested) => nested.id === over.id);
      const reordered = [...properties];
      const [moved] = reordered.splice(oldIndex, 1);
      reordered.splice(newIndex, 0, moved);
      updateProperties(reordered);
    }
  };

  return (
    <Flex direction="column" gap="2" flexGrow="1">
      <Divider />
      <Flex align="center" gap="2">
        <Text size="1" color="gray">
          Group Object · {allowMultiple ? 'Multiple' : 'Single'} ·{' '}
          {properties.length} Prop
        </Text>
      </Flex>
      <div
        className={styles.nestedArea}
        data-testid={`group-nested-props-${id}`}
      >
        <div className={styles.nestedHeader}>
          <Text size="1" weight="medium" style={{ flexGrow: 1 }}>
            Nested prop
          </Text>
          <Button
            size="1"
            variant="outline"
            disabled={isDisabled}
            onClick={() => {
              setIsNewProp(true);
              setEditedProp(newNestedProp());
            }}
          >
            <PlusIcon />
            New prop
          </Button>
        </div>
        <DndContext
          sensors={sensors}
          collisionDetection={closestCenter}
          onDragEnd={handleDragEnd}
        >
          <SortableContext
            items={properties.map((nested) => nested.id)}
            strategy={verticalListSortingStrategy}
          >
            <div className={styles.nestedList}>
              {properties.map((nested) => (
                <NestedPropRow
                  key={nested.id}
                  nestedProp={nested}
                  isDisabled={isDisabled}
                  onEdit={() => {
                    setIsNewProp(false);
                    setEditedProp({ ...nested });
                  }}
                />
              ))}
            </div>
          </SortableContext>
        </DndContext>
      </div>
      <Flex align="center" gap="2" mt="1">
        <input
          type="checkbox"
          id={`prop-group-allow-multiple-${id}`}
          checked={allowMultiple}
          disabled={isDisabled}
          onChange={(e) =>
            dispatch(
              updateProp({
                id,
                updates: {
                  allowMultiple: e.target.checked,
                  valueMode: e.target.checked ? 'unlimited' : undefined,
                  limitedCount: undefined,
                },
              }),
            )
          }
        />
        <Label htmlFor={`prop-group-allow-multiple-${id}`}>
          Allow multiple values
        </Label>
      </Flex>
      {editedProp && (
        <NestedPropDialog
          nestedProp={editedProp}
          isNewProp={isNewProp}
          onChange={setEditedProp}
          onSave={handleSave}
          onDelete={handleDelete}
          onCancel={() => setEditedProp(null)}
        />
      )}
    </Flex>
  );
}

/**
 * One nested prop row: drag handle, type icon, "Name · Type"; click to edit.
 */
function NestedPropRow({
  nestedProp,
  isDisabled,
  onEdit,
}: {
  nestedProp: CodeComponentGroupNestedProp;
  isDisabled: boolean;
  onEdit: () => void;
}) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: nestedProp.id, disabled: isDisabled });
  const TypeIcon =
    NESTED_PROP_TYPE_ICONS[nestedProp.derivedType ?? ''] ?? TokensIcon;
  const typeDisplayName =
    derivedPropTypes.find((type) => type.type === nestedProp.derivedType)
      ?.displayName ?? 'Text';

  return (
    <div
      ref={setNodeRef}
      className={clsx(styles.nestedRow, {
        [styles.nestedRowDragging]: isDragging,
      })}
      style={{
        transform: CSS.Transform.toString(transform),
        transition,
      }}
      role="button"
      tabIndex={0}
      aria-label={`Edit ${nestedProp.name || 'untitled prop'}`}
      onClick={() => !isDisabled && onEdit()}
      onKeyDown={(e) => {
        if (!isDisabled && (e.key === 'Enter' || e.key === ' ')) {
          e.preventDefault();
          onEdit();
        }
      }}
    >
      <button
        type="button"
        className={styles.dragHandle}
        aria-label="Move nested prop"
        disabled={isDisabled}
        onClick={(e) => e.stopPropagation()}
        {...attributes}
        {...listeners}
      >
        <DragHandleDots2Icon />
      </button>
      <span className={styles.typeIcon} aria-hidden="true">
        <TypeIcon width={16} height={16} />
      </span>
      <Flex align="center" gap="2" flexGrow="1" minWidth="0">
        <Text size="1" weight="medium" truncate>
          {nestedProp.name || 'Untitled prop'}
        </Text>
        <span className={styles.dotSeparator} aria-hidden="true" />
        <Text size="1" color="gray" truncate>
          {typeDisplayName}
        </Text>
      </Flex>
    </div>
  );
}

function NestedPropDialog({
  nestedProp,
  isNewProp,
  onChange,
  onSave,
  onDelete,
  onCancel,
}: {
  nestedProp: CodeComponentGroupNestedProp;
  isNewProp: boolean;
  onChange: (nestedProp: CodeComponentGroupNestedProp) => void;
  onSave: () => void;
  onDelete: () => void;
  onCancel: () => void;
}) {
  // Whether the developer manually edited the machine name; until then it is
  // auto-derived from the prop name.
  const [machineNameEdited, setMachineNameEdited] = useState(
    !!nestedProp.machineName &&
      nestedProp.machineName !== getPropMachineName(nestedProp.name),
  );

  const machineName =
    nestedProp.machineName ?? getPropMachineName(nestedProp.name);

  const update = (updates: Partial<CodeComponentGroupNestedProp>) =>
    onChange({ ...nestedProp, ...updates });

  const supportsMultiple = NESTED_MULTIPLE_TYPES.includes(
    nestedProp.derivedType ?? '',
  );
  const isList = ['listText', 'listInteger'].includes(
    nestedProp.derivedType ?? '',
  );

  return (
    <Dialog
      open
      onOpenChange={(open: boolean) => {
        if (!open) {
          onCancel();
        }
      }}
      title={isNewProp ? 'New prop' : 'Edit prop'}
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Save',
        onConfirm: onSave,
        isConfirmDisabled: !nestedProp.name,
      }}
    >
      <Flex direction="column" gap="3">
        <FormElement>
          <Label htmlFor={`nested-prop-type-${nestedProp.id}`}>Prop type</Label>
          <Select.Root
            value={nestedProp.derivedType as string}
            size="1"
            onValueChange={(value) => {
              const selectedPropType = NESTED_PROP_TYPES.find(
                (type) => type.type === value,
              );
              if (selectedPropType) {
                update({
                  derivedType: selectedPropType.type,
                  $ref: undefined,
                  format: undefined,
                  contentMediaType: undefined,
                  'x-formatting-context': undefined,
                  example: '',
                  enum: undefined,
                  allowMultiple: false,
                  items: undefined,
                  valueMode: undefined,
                  limitedCount: undefined,
                  ...selectedPropType.init,
                } as Partial<CodeComponentGroupNestedProp>);
              }
            }}
          >
            <Select.Trigger id={`nested-prop-type-${nestedProp.id}`} />
            <Select.Content>
              {NESTED_PROP_TYPES.map((type) => (
                <Select.Item key={type.type} value={type.type}>
                  {type.displayName}
                </Select.Item>
              ))}
            </Select.Content>
          </Select.Root>
        </FormElement>
        <FormElement>
          <Label htmlFor={`nested-prop-name-${nestedProp.id}`}>Prop name</Label>
          <TextField.Root
            autoComplete="off"
            id={`nested-prop-name-${nestedProp.id}`}
            placeholder="Enter a name"
            value={nestedProp.name}
            size="1"
            onChange={(e) =>
              update({
                name: e.target.value,
                ...(machineNameEdited
                  ? {}
                  : { machineName: getPropMachineName(e.target.value) }),
              })
            }
          />
        </FormElement>
        <FormElement>
          <Label htmlFor={`nested-prop-machine-name-${nestedProp.id}`}>
            Machine name
          </Label>
          <TextField.Root
            autoComplete="off"
            id={`nested-prop-machine-name-${nestedProp.id}`}
            value={machineName}
            size="1"
            onChange={(e) => {
              setMachineNameEdited(true);
              update({ machineName: e.target.value });
            }}
          />
        </FormElement>
        <Flex align="center" gap="2">
          <Label htmlFor={`nested-prop-required-${nestedProp.id}`}>
            Required
          </Label>
          <Switch
            id={`nested-prop-required-${nestedProp.id}`}
            checked={nestedProp.required ?? false}
            size="1"
            onCheckedChange={(checked) => update({ required: checked })}
          />
        </Flex>
        {isList && (
          <NestedEnumOptions
            nestedProp={nestedProp}
            onChange={(enumValues) => update({ enum: enumValues })}
          />
        )}
        <NestedExampleInput nestedProp={nestedProp} update={update} />
        {supportsMultiple && (
          <Flex align="center" gap="2">
            <input
              type="checkbox"
              id={`nested-prop-allow-multiple-${nestedProp.id}`}
              checked={nestedProp.allowMultiple ?? false}
              onChange={(e) => {
                const checked = e.target.checked;
                if (checked) {
                  update({
                    allowMultiple: true,
                    type: 'array',
                    items: {
                      type: nestedProp.type as 'string' | 'integer' | 'number',
                      ...(nestedProp.format && { format: nestedProp.format }),
                    },
                    valueMode: 'unlimited',
                    example:
                      nestedProp.example &&
                      typeof nestedProp.example === 'string'
                        ? [nestedProp.example]
                        : [],
                  });
                } else {
                  update({
                    allowMultiple: false,
                    type: (nestedProp.items?.type ?? 'string') as
                      | 'string'
                      | 'integer'
                      | 'number',
                    items: undefined,
                    valueMode: undefined,
                    limitedCount: undefined,
                    example: Array.isArray(nestedProp.example)
                      ? ((nestedProp.example[0] ?? '') as string)
                      : nestedProp.example,
                  });
                }
              }}
            />
            <Label htmlFor={`nested-prop-allow-multiple-${nestedProp.id}`}>
              Allow multiple values
            </Label>
          </Flex>
        )}
        {!isNewProp && (
          <Box>
            <Button size="1" color="red" variant="soft" onClick={onDelete}>
              Delete
            </Button>
          </Box>
        )}
      </Flex>
    </Dialog>
  );
}

function NestedEnumOptions({
  nestedProp,
  onChange,
}: {
  nestedProp: CodeComponentGroupNestedProp;
  onChange: (enumValues: CodeComponentPropEnumItem[]) => void;
}) {
  const enumValues = nestedProp.enum ?? [];
  return (
    <FormElement>
      <Label>Options</Label>
      <Flex direction="column" gap="1">
        {enumValues.map((item, index) => (
          <Flex key={index} gap="1" align="center">
            <TextField.Root
              autoComplete="off"
              placeholder="Value"
              value={String(item.value)}
              size="1"
              aria-label={`Option ${index + 1} value`}
              onChange={(e) => {
                const next = [...enumValues];
                next[index] = { ...next[index], value: e.target.value };
                onChange(next);
              }}
            />
            <TextField.Root
              autoComplete="off"
              placeholder="Label"
              value={item.label}
              size="1"
              aria-label={`Option ${index + 1} label`}
              onChange={(e) => {
                const next = [...enumValues];
                next[index] = { ...next[index], label: e.target.value };
                onChange(next);
              }}
            />
            <Button
              size="1"
              variant="ghost"
              color="red"
              aria-label={`Remove option ${index + 1}`}
              onClick={() => onChange(enumValues.filter((_, i) => i !== index))}
            >
              ✕
            </Button>
          </Flex>
        ))}
        <Box>
          <Button
            size="1"
            variant="soft"
            onClick={() => onChange([...enumValues, { value: '', label: '' }])}
          >
            Add option
          </Button>
        </Box>
      </Flex>
    </FormElement>
  );
}

function NestedExampleInput({
  nestedProp,
  update,
}: {
  nestedProp: CodeComponentGroupNestedProp;
  update: (updates: Partial<CodeComponentGroupNestedProp>) => void;
}) {
  const derivedType = nestedProp.derivedType ?? 'text';

  if (['image', 'video'].includes(derivedType)) {
    return (
      <Text size="1" color="gray">
        A placeholder example value is generated automatically.
      </Text>
    );
  }

  if (derivedType === 'boolean') {
    return (
      <Flex align="center" gap="2">
        <Label htmlFor={`nested-prop-example-${nestedProp.id}`}>
          Example value
        </Label>
        <Switch
          id={`nested-prop-example-${nestedProp.id}`}
          checked={String(nestedProp.example) === 'true'}
          size="1"
          onCheckedChange={(checked) => update({ example: checked })}
        />
      </Flex>
    );
  }

  if (nestedProp.allowMultiple) {
    const values = Array.isArray(nestedProp.example) ? nestedProp.example : [];
    return (
      <FormElement>
        <Label htmlFor={`nested-prop-example-${nestedProp.id}`}>
          Example values (one per line)
        </Label>
        <TextArea
          id={`nested-prop-example-${nestedProp.id}`}
          value={values.join('\n')}
          size="1"
          onChange={(e) =>
            update({
              example: e.target.value.split('\n') as string[],
            })
          }
        />
      </FormElement>
    );
  }

  return (
    <FormElement>
      <Label htmlFor={`nested-prop-example-${nestedProp.id}`}>
        Example value
      </Label>
      <TextField.Root
        autoComplete="off"
        id={`nested-prop-example-${nestedProp.id}`}
        type={
          ['integer', 'number'].includes(nestedProp.type) ? 'number' : 'text'
        }
        step={nestedProp.type === 'integer' ? 1 : 'any'}
        placeholder="Enter an example value"
        value={String(nestedProp.example ?? '')}
        size="1"
        onChange={(e) => update({ example: e.target.value })}
      />
    </FormElement>
  );
}
