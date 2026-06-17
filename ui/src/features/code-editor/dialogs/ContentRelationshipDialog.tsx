import { useEffect, useId, useMemo, useState } from 'react';
import {
  ChevronDownIcon,
  ChevronRightIcon,
  Cross2Icon,
  CubeIcon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Box,
  Callout,
  Checkbox,
  Flex,
  IconButton,
  Select,
  Spinner,
  Text,
} from '@radix-ui/themes';

import Dialog from '@/components/Dialog';
import {
  joinLabelChain,
  rowReferenceKey,
} from '@/features/code-editor/utils/contentEntityReferenceLabelResolver';
import {
  getFieldsHref,
  useListEntityTypesQuery,
  useListFieldsQuery,
} from '@/services/contentEntityReferenceApi';

import type { ReactNode } from 'react';
import type {
  ContentEntityReferenceEntityTypes,
  ContentEntityReferenceField,
  ContentEntityReferenceFieldProperty,
} from '@/services/contentEntityReferenceApi';

const CHEVRON_COL = 24;

export interface ContentEntityReferencePropState {
  entityType: string;
  bundle: string;
  fields: {
    label: string;
    expressions: string[];
    referenceChain: string[];
  }[];
}

interface ContentRelationshipDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  initialState: ContentEntityReferencePropState | null;
  onSave: (result: ContentEntityReferencePropState) => void;
  // When true, the (entityType, bundle) target is locked — the reset (X)
  // button is hidden so the user can only edit which fields are returned.
  // Used for props that are already saved/in use elsewhere.
  lockTarget?: boolean;
}

type ToggleProperties = (
  properties: ContentEntityReferenceFieldProperty[],
  nextChecked: boolean,
  label: string,
  referenceChain: string[],
) => void;

function fieldsByExpression(
  fields: ContentEntityReferencePropState['fields'],
): Map<string, ContentEntityReferencePropState['fields'][number]> {
  return new Map(
    fields.flatMap((field) =>
      field.expressions.map((expression) => [
        expression,
        { ...field, expressions: [expression] },
      ]),
    ),
  );
}

function NestedRows({ children }: { children: ReactNode }) {
  const indentStep = 16;
  const guideCenter = CHEVRON_COL / 2;

  return (
    <Box
      style={{
        paddingLeft: indentStep,
        position: 'relative',
      }}
    >
      <Box
        aria-hidden
        style={{
          position: 'absolute',
          top: 0,
          bottom: 0,
          left: guideCenter,
          width: 1,
          background: 'var(--gray-a4)',
          pointerEvents: 'none',
        }}
      />
      <Flex direction="column">{children}</Flex>
    </Box>
  );
}

function ChevronCell({ children }: { children?: ReactNode }) {
  return (
    <Flex
      align="center"
      justify="center"
      style={{ width: CHEVRON_COL, height: CHEVRON_COL, flexShrink: 0 }}
    >
      {children}
    </Flex>
  );
}

function ExpandButton({
  isExpanded,
  label,
  onClick,
}: {
  isExpanded: boolean;
  label: string;
  onClick: () => void;
}) {
  return (
    <IconButton
      size="1"
      variant="ghost"
      color="blue"
      aria-label={`${isExpanded ? 'Collapse' : 'Expand'} ${label}`}
      onClick={onClick}
    >
      {isExpanded ? <ChevronDownIcon /> : <ChevronRightIcon />}
    </IconButton>
  );
}

function SelectedCountText({
  count,
  singular = 'referenced entity property',
  plural = 'referenced entity properties',
  suffix = 'selected',
}: {
  count: number;
  singular?: string;
  plural?: string;
  suffix?: string;
}) {
  if (count === 0) return null;

  return (
    <Text size="1" color="gray" style={{ fontStyle: 'italic' }}>
      ({count} {count === 1 ? singular : plural} {suffix})
    </Text>
  );
}

function RelationshipBadge({
  children,
  entityType,
  entityTypes,
}: {
  children?: ReactNode;
  entityType?: string;
  entityTypes?: ContentEntityReferenceEntityTypes;
}) {
  let label = children;
  if (entityType) {
    const entityTypeDefinition = entityTypes?.[entityType];
    const entityTypeLabel = entityTypeDefinition?.label ?? entityType;
    const capitalized =
      entityTypeLabel.charAt(0).toUpperCase() + entityTypeLabel.slice(1);
    const bundleIds = Object.keys(entityTypeDefinition?.bundles ?? {});
    label =
      bundleIds.length === 1 && bundleIds[0] === entityType
        ? capitalized
        : `${capitalized} type`;
  }

  return (
    <Badge color="gray" variant="soft" size="1">
      {label}
    </Badge>
  );
}

function useSelectedRelationshipFields(
  initialFields: ContentEntityReferencePropState['fields'],
) {
  const [selectedFields, setSelectedFields] = useState<
    Map<string, ContentEntityReferencePropState['fields'][number]>
  >(() => fieldsByExpression(initialFields));

  useEffect(() => {
    const latestInitialFields = fieldsByExpression(initialFields);
    setSelectedFields((prev) => {
      let changed = false;
      const next = new Map(prev);
      for (const [expression, selectedField] of prev) {
        const latestField = latestInitialFields.get(expression);
        if (
          latestField &&
          (latestField.label !== selectedField.label ||
            latestField.referenceChain.join('\n') !==
              selectedField.referenceChain.join('\n'))
        ) {
          next.set(expression, latestField);
          changed = true;
        }
      }
      return changed ? next : prev;
    });
  }, [initialFields]);

  const selected = useMemo(
    () => new Set(selectedFields.keys()),
    [selectedFields],
  );
  const nestedCounts = useMemo(() => {
    const counts = new Map<string, number>();
    for (const { referenceChain } of selectedFields.values()) {
      for (const key of referenceChain) {
        counts.set(key, (counts.get(key) ?? 0) + 1);
      }
    }
    return counts;
  }, [selectedFields]);

  const clearSelectedFields = () => setSelectedFields(new Map());

  const toggleProperties: ToggleProperties = (
    properties,
    nextChecked,
    label,
    referenceChain,
  ) => {
    setSelectedFields((prev) => {
      const next = new Map(prev);
      for (const property of properties) {
        if (nextChecked) {
          next.set(property.expression, {
            label: joinLabelChain([label, property.label]),
            expressions: [property.expression],
            referenceChain,
          });
        } else {
          next.delete(property.expression);
        }
      }
      return next;
    });
  };

  return {
    selectedFields,
    selected,
    nestedCounts,
    clearSelectedFields,
    toggleProperties,
  };
}

interface FieldsTreeProps {
  href: string;
  ancestorLabels: string[];
  ancestorKeys: string[];
  selected: Set<string>;
  nestedCounts: Map<string, number>;
  expanded: Set<string>;
  entityTypes: ContentEntityReferenceEntityTypes | undefined;
  onToggleProperties: ToggleProperties;
  onToggleExpanded: (expression: string) => void;
}

function FieldsTree({
  href,
  ancestorLabels,
  ancestorKeys,
  selected,
  nestedCounts,
  expanded,
  entityTypes,
  onToggleProperties,
  onToggleExpanded,
}: FieldsTreeProps) {
  const { data, isLoading, error } = useListFieldsQuery({ href });

  if (isLoading) {
    return (
      <Flex py="2" align="center" gap="2">
        <Spinner size="1" />
        <Text size="1" color="gray">
          Loading fields…
        </Text>
      </Flex>
    );
  }

  if (error) {
    return (
      <Box py="1">
        <Callout.Root color="red" size="1">
          <Callout.Text>Failed to load fields.</Callout.Text>
        </Callout.Root>
      </Box>
    );
  }

  if (!data || data.length === 0) {
    return (
      <Box py="1">
        <Text size="1" color="gray">
          No fields available.
        </Text>
      </Box>
    );
  }

  return (
    <Flex direction="column">
      {data.map((field) => (
        <FieldRow
          key={field.name}
          field={field}
          ancestorLabels={ancestorLabels}
          ancestorKeys={ancestorKeys}
          selected={selected}
          nestedCounts={nestedCounts}
          expanded={expanded}
          entityTypes={entityTypes}
          onToggleProperties={onToggleProperties}
          onToggleExpanded={onToggleExpanded}
        />
      ))}
    </Flex>
  );
}

interface FieldRowProps {
  field: ContentEntityReferenceField;
  ancestorLabels: string[];
  ancestorKeys: string[];
  selected: Set<string>;
  nestedCounts: Map<string, number>;
  expanded: Set<string>;
  entityTypes: ContentEntityReferenceEntityTypes | undefined;
  onToggleProperties: ToggleProperties;
  onToggleExpanded: (expression: string) => void;
}

function FieldRow({
  field,
  ancestorLabels,
  ancestorKeys,
  selected,
  nestedCounts,
  expanded,
  entityTypes,
  onToggleProperties,
  onToggleExpanded,
}: FieldRowProps) {
  const targetBundleEntries = Object.entries(field.targetBundles ?? {})
    .map(([bundleId, targetBundle]) => ({
      bundleId,
      targetBundle,
      href: getFieldsHref(targetBundle),
    }))
    .filter(
      (
        entry,
      ): entry is {
        bundleId: string;
        targetBundle: NonNullable<
          ContentEntityReferenceField['targetBundles']
        >[string];
        href: string;
      } => Boolean(entry.href),
    );
  const isReference =
    field.hasChildren &&
    Boolean(field.targetEntityType) &&
    targetBundleEntries.length > 0;
  const hasMultipleProperties = field.properties.length > 1;
  const isExpandable = isReference || hasMultipleProperties;
  const rowExpandKey = isExpandable
    ? rowReferenceKey(ancestorKeys, field.name)
    : null;
  const isExpanded = rowExpandKey ? expanded.has(rowExpandKey) : false;
  const checkboxId = useId();
  const selectedPropertyCount = field.properties.filter((property) =>
    selected.has(property.expression),
  ).length;
  const isChecked =
    selectedPropertyCount === 0
      ? false
      : selectedPropertyCount === field.properties.length
        ? true
        : 'indeterminate';
  const isDisabled = field.properties.length === 0;

  const descendantCount = rowExpandKey
    ? (nestedCounts.get(rowExpandKey) ?? 0)
    : 0;

  const fullLabel = joinLabelChain([...ancestorLabels, field.label]);

  return (
    <Box>
      <Flex align="center" gap="2" py="2" style={{ paddingRight: 4 }}>
        <ChevronCell>
          {isExpandable && (
            <ExpandButton
              isExpanded={isExpanded}
              label={field.label}
              onClick={() => rowExpandKey && onToggleExpanded(rowExpandKey)}
            />
          )}
        </ChevronCell>
        <Text
          as="label"
          htmlFor={checkboxId}
          size="2"
          style={{
            flexGrow: 1,
            userSelect: 'none',
            cursor: isDisabled ? 'default' : 'pointer',
          }}
        >
          {isReference ? (
            <>
              {field.label}
              {descendantCount > 0 && (
                <>
                  {' '}
                  <SelectedCountText count={descendantCount} />
                </>
              )}
            </>
          ) : (
            field.label
          )}
        </Text>
        {isReference && field.targetEntityType && (
          <RelationshipBadge
            entityType={field.targetEntityType}
            entityTypes={entityTypes}
          />
        )}
        <Checkbox
          id={checkboxId}
          size="1"
          aria-label={field.label}
          checked={isChecked}
          disabled={isDisabled}
          onCheckedChange={(checked) =>
            onToggleProperties(
              field.properties,
              checked === true,
              fullLabel,
              ancestorKeys,
            )
          }
        />
      </Flex>

      {isExpanded && (
        <NestedRows>
          {hasMultipleProperties && (
            <FieldPropertiesTree
              properties={field.properties}
              labelPrefix={fullLabel}
              selected={selected}
              referenceChain={ancestorKeys}
              onToggleProperties={onToggleProperties}
            />
          )}

          {isReference &&
            targetBundleEntries.map(({ bundleId, targetBundle, href }) => {
              const bundleExpandKey = rowExpandKey
                ? `${rowExpandKey}:${bundleId}`
                : bundleId;
              const nestedAncestorKeys = rowExpandKey
                ? [...ancestorKeys, rowExpandKey, bundleExpandKey]
                : ancestorKeys;
              const isBundleExpanded = expanded.has(bundleExpandKey);
              const bundleDescendantCount =
                nestedCounts.get(bundleExpandKey) ?? 0;
              return (
                <BundleFieldsSection
                  key={bundleId}
                  label={targetBundle.label}
                  href={href}
                  ancestorLabels={[...ancestorLabels, field.label]}
                  ancestorKeys={nestedAncestorKeys}
                  selected={selected}
                  nestedCounts={nestedCounts}
                  expanded={expanded}
                  entityTypes={entityTypes}
                  isExpanded={isBundleExpanded}
                  selectedCount={bundleDescendantCount}
                  onToggleExpanded={() => onToggleExpanded(bundleExpandKey)}
                  onToggleProperties={onToggleProperties}
                  onToggleNestedExpanded={onToggleExpanded}
                />
              );
            })}
        </NestedRows>
      )}
    </Box>
  );
}

interface FieldPropertiesTreeProps {
  properties: ContentEntityReferenceFieldProperty[];
  labelPrefix: string;
  selected: Set<string>;
  referenceChain: string[];
  onToggleProperties: ToggleProperties;
}

function FieldPropertiesTree({
  properties,
  labelPrefix,
  selected,
  referenceChain,
  onToggleProperties,
}: FieldPropertiesTreeProps) {
  return (
    <Flex direction="column">
      {properties.map((property) => (
        <FieldPropertyRow
          key={property.expression}
          property={property}
          labelPrefix={labelPrefix}
          selected={selected}
          referenceChain={referenceChain}
          onToggleProperties={onToggleProperties}
        />
      ))}
    </Flex>
  );
}

interface BundleFieldsSectionProps {
  label: string;
  href: string;
  ancestorLabels: string[];
  ancestorKeys: string[];
  selected: Set<string>;
  nestedCounts: Map<string, number>;
  expanded: Set<string>;
  entityTypes: ContentEntityReferenceEntityTypes | undefined;
  isExpanded: boolean;
  selectedCount: number;
  onToggleExpanded: () => void;
  onToggleProperties: ToggleProperties;
  onToggleNestedExpanded: (expression: string) => void;
}

function BundleFieldsSection({
  label,
  href,
  ancestorLabels,
  ancestorKeys,
  selected,
  nestedCounts,
  expanded,
  entityTypes,
  isExpanded,
  selectedCount,
  onToggleExpanded,
  onToggleProperties,
  onToggleNestedExpanded,
}: BundleFieldsSectionProps) {
  return (
    <Box>
      <Flex align="center" gap="2" py="1" style={{ paddingRight: 4 }}>
        <ChevronCell>
          <ExpandButton
            isExpanded={isExpanded}
            label={label}
            onClick={onToggleExpanded}
          />
        </ChevronCell>
        <RelationshipBadge>{label}</RelationshipBadge>
        {selectedCount > 0 && <SelectedCountText count={selectedCount} />}
      </Flex>
      {isExpanded && (
        <NestedRows>
          <FieldsTree
            href={href}
            ancestorLabels={ancestorLabels}
            ancestorKeys={ancestorKeys}
            selected={selected}
            nestedCounts={nestedCounts}
            expanded={expanded}
            entityTypes={entityTypes}
            onToggleProperties={onToggleProperties}
            onToggleExpanded={onToggleNestedExpanded}
          />
        </NestedRows>
      )}
    </Box>
  );
}

interface FieldPropertyRowProps {
  property: ContentEntityReferenceFieldProperty;
  labelPrefix: string;
  selected: Set<string>;
  referenceChain: string[];
  onToggleProperties: ToggleProperties;
}

function FieldPropertyRow({
  property,
  labelPrefix,
  selected,
  referenceChain,
  onToggleProperties,
}: FieldPropertyRowProps) {
  const checkboxId = useId();
  const label = joinLabelChain([labelPrefix, property.label]);

  return (
    <Flex align="center" gap="2" py="1" style={{ paddingRight: 4 }}>
      <ChevronCell />
      <Text
        as="label"
        htmlFor={checkboxId}
        size="2"
        style={{
          flexGrow: 1,
          userSelect: 'none',
          cursor: 'pointer',
        }}
      >
        {property.label}
      </Text>
      <Checkbox
        id={checkboxId}
        size="1"
        aria-label={label}
        checked={selected.has(property.expression)}
        onCheckedChange={(checked) =>
          onToggleProperties(
            [property],
            checked === true,
            labelPrefix,
            referenceChain,
          )
        }
      />
    </Flex>
  );
}

function ContentRelationshipTitle() {
  return (
    <Flex align="center" gap="2">
      <Flex
        align="center"
        justify="center"
        style={{
          width: 28,
          height: 28,
          border: '1px solid var(--gray-a5)',
          borderRadius: 'var(--radius-2)',
          color: 'var(--blue-9)',
        }}
      >
        <CubeIcon />
      </Flex>
      <Text size="2" weight="bold">
        Content Relationship
      </Text>
    </Flex>
  );
}

export default function ContentRelationshipDialog({
  open,
  onOpenChange,
  initialState,
  onSave,
  lockTarget = false,
}: ContentRelationshipDialogProps) {
  const {
    data: entityTypes,
    isLoading: typesLoading,
    error: typesError,
  } = useListEntityTypesQuery();

  const [entityType, setEntityType] = useState<string | null>(
    initialState?.entityType ?? null,
  );
  const [bundle, setBundle] = useState<string | null>(
    initialState?.bundle ?? null,
  );
  const {
    selectedFields,
    selected,
    nestedCounts,
    clearSelectedFields,
    toggleProperties,
  } = useSelectedRelationshipFields(initialState?.fields ?? []);
  const [expanded, setExpanded] = useState<Set<string>>(() => new Set());
  const [bundleExpanded, setBundleExpanded] = useState(true);

  const bundleOptions = useMemo(() => {
    if (!entityType || !entityTypes?.[entityType]) return [];
    return Object.entries(entityTypes[entityType].bundles).map(
      ([id, { label }]) => ({ id, label }),
    );
  }, [entityType, entityTypes]);

  const hasTarget = Boolean(entityType && bundle);
  const rootHref = useMemo(() => {
    if (!entityType || !bundle) return null;
    return getFieldsHref(entityTypes?.[entityType]?.bundles?.[bundle]) ?? null;
  }, [bundle, entityType, entityTypes]);

  const [showFields, setShowFields] = useState(Boolean(initialState));

  const handleEntityTypeChange = (value: string) => {
    setEntityType(value);
    const bundles = Object.keys(entityTypes?.[value]?.bundles ?? {});
    const nextBundle = bundles.length === 1 ? bundles[0] : null;
    setBundle(nextBundle);
    clearSelectedFields();
    setExpanded(new Set());
  };

  const handleBundleChange = (value: string) => {
    setBundle(value);
    clearSelectedFields();
    setExpanded(new Set());
  };

  const handleReset = () => {
    setEntityType(null);
    setBundle(null);
    clearSelectedFields();
    setExpanded(new Set());
    setShowFields(false);
  };

  const handleToggleExpanded = (expression: string) => {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(expression)) {
        for (const key of next) {
          if (
            key === expression ||
            key.startsWith(`${expression}/`) ||
            key.startsWith(`${expression}:`)
          ) {
            next.delete(key);
          }
        }
      } else {
        next.add(expression);
      }
      return next;
    });
  };

  const handleConfirm = () => {
    if (!entityType || !bundle) return;
    if (!showFields) {
      setShowFields(true);
      return;
    }
    onSave({
      entityType,
      bundle,
      fields: Array.from(selectedFields.values()),
    });
    onOpenChange(false);
  };

  const bundleLabel =
    entityType && bundle && entityTypes?.[entityType]?.bundles[bundle]
      ? entityTypes[entityType].bundles[bundle].label
      : (bundle ?? '');
  const pickedCount = selectedFields.size;
  const isConfirmDisabled = !hasTarget || (showFields && pickedCount === 0);

  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      modal
      width="750px"
      title={<ContentRelationshipTitle />}
      headerClose
      footer={{
        cancelText: 'Cancel',
        confirmText: showFields ? 'Save' : 'Continue',
        onCancel: () => onOpenChange(false),
        onConfirm: handleConfirm,
        isConfirmDisabled,
      }}
    >
      <Box
        style={{
          border: '1px solid var(--gray-a5)',
          borderRadius: 'var(--radius-3)',
          padding: 16,
        }}
      >
        <Flex direction="column" gap="3">
          <Flex direction="column" gap="1">
            <Text size="2" weight="bold">
              Allowed type
            </Text>
            <Text size="1" color="gray">
              Select a single type that editors can link to.
            </Text>
            <Text size="1" color="gray">
              For the selected type, choose which fields to include in the API
              response.
            </Text>
          </Flex>

          {typesError && (
            <Callout.Root color="red" size="1">
              <Callout.Text>Failed to load entity types.</Callout.Text>
            </Callout.Root>
          )}

          {!showFields ? (
            <Flex gap="3" wrap="wrap">
              <Flex direction="column" gap="1" flexBasis="200px" flexGrow="1">
                <Text size="1" weight="bold">
                  Entity type
                </Text>
                <Select.Root
                  value={entityType ?? ''}
                  onValueChange={handleEntityTypeChange}
                  size="1"
                  disabled={typesLoading || !entityTypes}
                >
                  <Select.Trigger placeholder="Select entity type" />
                  <Select.Content>
                    {entityTypes &&
                      Object.entries(entityTypes).map(([typeId, { label }]) => (
                        <Select.Item key={typeId} value={typeId}>
                          {label}
                        </Select.Item>
                      ))}
                  </Select.Content>
                </Select.Root>
              </Flex>
              <Flex direction="column" gap="1" flexBasis="200px" flexGrow="1">
                <Text size="1" weight="bold">
                  Bundle
                </Text>
                <Select.Root
                  value={bundle ?? ''}
                  onValueChange={handleBundleChange}
                  size="1"
                  disabled={!entityType || bundleOptions.length === 0}
                >
                  <Select.Trigger placeholder="Select bundle" />
                  <Select.Content>
                    {bundleOptions.map((option) => (
                      <Select.Item key={option.id} value={option.id}>
                        {option.label}
                      </Select.Item>
                    ))}
                  </Select.Content>
                </Select.Root>
              </Flex>
            </Flex>
          ) : (
            <Box
              style={{
                border: '1px solid var(--gray-a5)',
                borderRadius: 'var(--radius-3)',
                padding: '4px 8px 8px',
              }}
            >
              <Flex align="center" gap="2" py="2">
                <ChevronCell>
                  <ExpandButton
                    isExpanded={bundleExpanded}
                    label="fields"
                    onClick={() => setBundleExpanded((v) => !v)}
                  />
                </ChevronCell>
                <Text size="2" weight="bold">
                  {bundleLabel}
                </Text>
                <SelectedCountText
                  count={pickedCount}
                  singular="property"
                  plural="properties"
                  suffix="returned in the API"
                />
                <Box flexGrow="1" />
                {entityType && (
                  <RelationshipBadge
                    entityType={entityType}
                    entityTypes={entityTypes}
                  />
                )}
                {!lockTarget && (
                  <IconButton
                    size="1"
                    variant="ghost"
                    color="gray"
                    aria-label="Reset type and bundle"
                    onClick={handleReset}
                  >
                    <Cross2Icon />
                  </IconButton>
                )}
              </Flex>

              {bundleExpanded && rootHref && (
                <NestedRows>
                  <FieldsTree
                    href={rootHref}
                    ancestorLabels={[]}
                    ancestorKeys={[]}
                    selected={selected}
                    nestedCounts={nestedCounts}
                    expanded={expanded}
                    entityTypes={entityTypes}
                    onToggleProperties={toggleProperties}
                    onToggleExpanded={handleToggleExpanded}
                  />
                </NestedRows>
              )}
            </Box>
          )}
        </Flex>
      </Box>
    </Dialog>
  );
}
