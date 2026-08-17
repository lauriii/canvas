import { useId, useMemo, useState } from 'react';
import {
  ChevronDownIcon,
  ChevronUpIcon,
  Pencil1Icon,
  PlusIcon,
} from '@radix-ui/react-icons';
import { Box, Button, Callout, Flex, IconButton, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { setEntityFieldExpressions } from '@/features/code-editor/codeEditorSlice';
import { Divider } from '@/features/code-editor/component-data/FormElement';
import ContentRelationshipDialog from '@/features/code-editor/dialogs/ContentRelationshipDialog';
import { useResolvedExpressionFields } from '@/features/code-editor/utils/contentEntityReferenceLabelResolver';
import { useListEntityTypesQuery } from '@/services/contentEntityReferenceApi';

import type { ContentEntityReferencePropState } from '@/features/code-editor/dialogs/ContentRelationshipDialog';

interface FormPropTypeContentEntityReferenceProps {
  id: string;
  expressions: string[];
  targetEntityType: string | null;
  targetBundle: string | null;
  isDisabled: boolean;
}

export default function FormPropTypeContentEntityReference({
  id,
  expressions,
  targetEntityType,
  targetBundle,
  isDisabled,
}: FormPropTypeContentEntityReferenceProps) {
  const dispatch = useAppDispatch();
  const { data: entityTypes, error } = useListEntityTypesQuery();

  const target = useMemo(() => {
    if (!targetEntityType) return null;
    return {
      entityType: targetEntityType,
      bundle: targetBundle ?? targetEntityType,
    };
  }, [targetEntityType, targetBundle]);

  // Resolve labels for initial expressions from the typed-data browser API.
  const resolvedExpressionFields = useResolvedExpressionFields(
    expressions,
    target,
    entityTypes,
  );

  const [sessionState, setSessionState] =
    useState<ContentEntityReferencePropState | null>(null);
  const resolvedState = useMemo(
    () =>
      sessionState ??
      (target ? { ...target, fields: resolvedExpressionFields } : null),
    [resolvedExpressionFields, sessionState, target],
  );

  const [dialogOpen, setDialogOpen] = useState(false);

  const entityTypeLabel = resolvedState
    ? (entityTypes?.[resolvedState.entityType]?.label ??
      resolvedState.entityType)
    : '';
  const bundleLabel =
    resolvedState &&
    entityTypes?.[resolvedState.entityType]?.bundles[resolvedState.bundle]
      ? entityTypes[resolvedState.entityType].bundles[resolvedState.bundle]
          .label
      : (resolvedState?.bundle ?? '');

  const handleSave = (result: ContentEntityReferencePropState) => {
    setSessionState(result);
    dispatch(
      setEntityFieldExpressions({
        propId: id,
        expressions: result.fields.flatMap((f) => f.expressions),
      }),
    );
  };

  return (
    <Flex direction="column" gap="3" flexGrow="1">
      <Divider />
      {error && (
        <Callout.Root color="red" size="1">
          <Callout.Text>
            {Drupal.t('Failed to load entity types.')}
          </Callout.Text>
        </Callout.Root>
      )}

      {!resolvedState ? (
        <NoTargetSelected onPick={() => setDialogOpen(true)} />
      ) : (
        <Flex direction="column" gap="3">
          <TargetSummary
            entityTypeLabel={entityTypeLabel}
            bundleLabel={bundleLabel}
          />
          <FieldList
            fields={resolvedState.fields}
            onEdit={() => setDialogOpen(true)}
          />
        </Flex>
      )}

      {dialogOpen && (
        <ContentRelationshipDialog
          open={dialogOpen}
          onOpenChange={setDialogOpen}
          initialState={resolvedState}
          onSave={handleSave}
          lockTarget={isDisabled}
        />
      )}
    </Flex>
  );
}

function NoTargetSelected({ onPick }: { onPick: () => void }) {
  return (
    <Flex direction="column" align="center" gap="2" py="4">
      <Text size="2" weight="bold">
        {Drupal.t('No type selected')}
      </Text>
      <Flex direction="column" align="center">
        <Text size="1" color="gray" align="center">
          {Drupal.t('Select the type editors can link to.')}
        </Text>
        <Text size="1" color="gray" align="center">
          {Drupal.t(
            'Then, choose which fields to return in the component props.',
          )}
        </Text>
      </Flex>
      <Button size="1" variant="outline" onClick={onPick} mt="2">
        <PlusIcon />
        {Drupal.t('Add type')}
      </Button>
    </Flex>
  );
}

function TargetSummary({
  entityTypeLabel,
  bundleLabel,
}: {
  entityTypeLabel: string;
  bundleLabel: string;
}) {
  return (
    <Box
      px="3"
      py="2"
      style={{
        background: 'var(--gray-2)',
        borderRadius: 'var(--radius-2)',
      }}
    >
      <Flex align="center" gap="2">
        <Text size="1">
          {Drupal.t('Entity Type:')}{' '}
          <Text weight="medium">{entityTypeLabel}</Text>
        </Text>
        <Text size="1" color="gray">
          |
        </Text>
        <Text size="1">
          {Drupal.t('Bundle:')} <Text weight="medium">{bundleLabel}</Text>
        </Text>
      </Flex>
    </Box>
  );
}

function FieldList({
  fields,
  onEdit,
}: {
  fields: ContentEntityReferencePropState['fields'];
  onEdit: () => void;
}) {
  const [expanded, setExpanded] = useState(false);
  const summaryId = useId();
  const toggleExpanded = () => setExpanded((v) => !v);
  return (
    <Flex direction="column">
      <Flex align="center" justify="between" gap="2" py="1">
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={summaryId}
          onClick={toggleExpanded}
          style={{
            appearance: 'none',
            background: 'none',
            border: 0,
            color: 'inherit',
            cursor: 'pointer',
            padding: 0,
            textAlign: 'left',
          }}
        >
          <Text size="1" weight="medium">
            {Drupal.t('Entity fields (!number)', { '!number': fields.length })}
          </Text>
        </button>
        <Flex align="center" gap="2">
          <Button
            size="1"
            variant="ghost"
            color="blue"
            onClick={onEdit}
            style={{ color: 'var(--blue-9)', fontWeight: 500 }}
          >
            <Pencil1Icon />
            {Drupal.t('Edit', {}, { context: 'Canvas code component' })}
          </Button>
          <IconButton
            size="1"
            variant="ghost"
            color="gray"
            aria-label={
              expanded ? Drupal.t('Collapse fields') : Drupal.t('Expand fields')
            }
            aria-controls={summaryId}
            onClick={toggleExpanded}
          >
            {expanded ? <ChevronUpIcon /> : <ChevronDownIcon />}
          </IconButton>
        </Flex>
      </Flex>
      {expanded && (
        <Flex id={summaryId} direction="column">
          {fields.map((field) => (
            <Flex
              key={field.expressions[0]}
              align="center"
              justify="between"
              gap="2"
              py="2"
              style={{
                borderBottom: '1px solid var(--gray-a4)',
                minWidth: 0,
              }}
            >
              <Text
                size="1"
                style={{
                  minWidth: 0,
                  overflowWrap: 'anywhere',
                  whiteSpace: 'normal',
                  wordBreak: 'break-word',
                }}
              >
                {field.label}
              </Text>
            </Flex>
          ))}
        </Flex>
      )}
    </Flex>
  );
}
