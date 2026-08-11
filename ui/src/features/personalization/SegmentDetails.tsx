import { useState } from 'react';
import { Link as RouterLink, useParams } from 'react-router-dom';
import {
  ArrowLeftIcon,
  ExclamationTriangleIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Button,
  Callout,
  Card,
  DropdownMenu,
  Flex,
  Heading,
  Link,
  Skeleton,
  Text,
} from '@radix-ui/themes';

import RuleCard from '@/components/personalization/rules/RuleCard';
import EditSegmentDialog from '@/features/personalization/dialogs/EditSegmentDialog';
import { orderedRules } from '@/features/personalization/orderedRules';
import {
  CONDITION_DESCRIPTIONS,
  CONDITION_LABELS,
  createDefaultRule,
  isEditableCondition,
} from '@/features/personalization/rules';
import {
  useGetConditionDefinitionsQuery,
  useGetSegmentQuery,
  useUpdateSegmentMutation,
} from '@/services/personalization';

import type {
  ConditionId,
  Segment,
  SegmentRule,
  SegmentRules,
} from '@/types/Personalization';

/**
 * Where a condition without an in-dashboard editor is configured.
 */
const ruleFormUrl = (segmentId: string): string =>
  `/admin/structure/segment/${segmentId}/rule`;

const BackLink = () => (
  <Link asChild size="1">
    <RouterLink to="/segments">
      <Flex display="inline-flex" align="center" gap="1">
        <ArrowLeftIcon />
        All segments
      </Flex>
    </RouterLink>
  </Link>
);

const SegmentDetailsContent = ({ segment }: { segment: Segment }) => {
  const [updateSegment, { isLoading: isSaving }] = useUpdateSegmentMutation();
  // The server is authoritative about which condition types exist.
  const { data: conditionDefinitions } = useGetConditionDefinitionsQuery();
  // Unsaved rule edits; null means the saved rules are shown as-is.
  const [draftRules, setDraftRules] = useState<SegmentRules | null>(null);
  // Bumped to remount rule editors so their local input state resets.
  const [resetCount, setResetCount] = useState(0);
  const [isEditingDetails, setIsEditingDetails] = useState(false);

  const rules: SegmentRules = draftRules ?? segment.rules ?? {};
  // Every rule the segment carries, in a stable order — including condition
  // types provided by other modules, which would otherwise be invisible here
  // while still deciding what visitors see.
  const ruleList = orderedRules(rules);
  const availableConditions = Object.values(conditionDefinitions ?? {});
  const isDirty = draftRules !== null;

  const handleRuleChange = (rule: SegmentRule) => {
    setDraftRules({ ...rules, [rule.id]: rule });
  };

  const handleRemoveRule = (conditionId: ConditionId) => {
    const next = { ...rules };
    delete next[conditionId];
    setDraftRules(next);
    setResetCount((count) => count + 1);
  };

  const handleAddRule = (conditionId: ConditionId) => {
    // Only a condition this client has an editor for can be given sensible
    // starting settings; anything else is created through its own form.
    if (!isEditableCondition(conditionId)) {
      window.location.href = ruleFormUrl(segment.id);
      return;
    }
    setDraftRules({ ...rules, [conditionId]: createDefaultRule(conditionId) });
  };

  const handleDiscard = () => {
    setDraftRules(null);
    setResetCount((count) => count + 1);
  };

  const handleSaveRules = async () => {
    const result = await updateSegment({
      id: segment.id,
      changes: { rules },
    });
    if (result && !('error' in result)) {
      setDraftRules(null);
    }
  };

  const handleToggleStatus = () => {
    updateSegment({
      id: segment.id,
      changes: { status: !segment.status },
    });
  };

  return (
    <Flex direction="column" gap="5" maxWidth="960px">
      <Flex direction="column" gap="3">
        <BackLink />
        {!segment.status && (
          <Callout.Root color="amber" size="1">
            <Callout.Icon>
              <ExclamationTriangleIcon />
            </Callout.Icon>
            <Callout.Text>
              This segment is disabled. It never matches visitors until it is
              enabled.
            </Callout.Text>
          </Callout.Root>
        )}
        <Flex justify="between" align="start" gap="4" wrap="wrap">
          <Flex direction="column" gap="1">
            <Flex align="center" gap="2">
              <Heading size="4">{segment.label}</Heading>
              <Badge color={segment.status ? 'green' : 'gray'}>
                {segment.status ? 'Enabled' : 'Disabled'}
              </Badge>
            </Flex>
            {segment.description && (
              <Text size="2" color="gray">
                {segment.description}
              </Text>
            )}
          </Flex>
          <Flex gap="2" align="center">
            <Button size="1" variant="outline" onClick={handleToggleStatus}>
              {segment.status ? 'Disable segment' : 'Enable segment'}
            </Button>
            <Button
              size="1"
              variant="outline"
              onClick={() => setIsEditingDetails(true)}
            >
              Edit details
            </Button>
          </Flex>
        </Flex>
      </Flex>

      <Flex direction="column" gap="3">
        <Flex justify="between" align="center" gap="4" wrap="wrap">
          <Flex direction="column">
            <Text size="2" weight="bold">
              Show to visitors who...
            </Text>
            <Text size="1" color="gray">
              Visitors belong to this segment when they match every rule below.
            </Text>
          </Flex>
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <Button size="1">
                <PlusIcon />
                Add rule
              </Button>
            </DropdownMenu.Trigger>
            <DropdownMenu.Content align="end">
              {availableConditions.map((definition) => (
                <DropdownMenu.Item
                  key={definition.id}
                  // A segment holds at most one rule of each type.
                  disabled={definition.id in rules}
                  // Single-line items keep the menu's native hover and
                  // disabled styling; the description surfaces as a tooltip.
                  title={
                    isEditableCondition(definition.id)
                      ? CONDITION_DESCRIPTIONS[definition.id]
                      : 'Provided by another module — opens its configuration form'
                  }
                  onSelect={() => handleAddRule(definition.id)}
                >
                  {isEditableCondition(definition.id)
                    ? CONDITION_LABELS[definition.id]
                    : definition.label}
                </DropdownMenu.Item>
              ))}
            </DropdownMenu.Content>
          </DropdownMenu.Root>
        </Flex>

        {ruleList.length === 0 ? (
          <Card>
            <Flex p="4" direction="column" align="center" gap="1">
              <Text size="1" weight="medium">
                No rules yet
              </Text>
              <Text size="1" color="gray" align="center">
                Without rules, this segment never matches. Add a rule to choose
                who sees this segment.
              </Text>
            </Flex>
          </Card>
        ) : (
          <Flex direction="column" gap="3" key={`${segment.id}:${resetCount}`}>
            {ruleList.map((rule) => (
              <RuleCard
                key={rule.id}
                rule={rule}
                label={conditionDefinitions?.[rule.id]?.label}
                settings={conditionDefinitions?.[rule.id]?.settings}
                editUrl={ruleFormUrl(segment.id)}
                onChange={handleRuleChange}
                onRemove={() => handleRemoveRule(rule.id)}
              />
            ))}
          </Flex>
        )}

        {isDirty && (
          <Flex gap="2" align="center">
            <Button size="1" onClick={handleSaveRules} loading={isSaving}>
              Save rules
            </Button>
            <Button size="1" variant="outline" onClick={handleDiscard}>
              Discard changes
            </Button>
            <Text size="1" color="gray">
              Unsaved changes
            </Text>
          </Flex>
        )}
      </Flex>

      <EditSegmentDialog
        segment={isEditingDetails ? segment : null}
        onClose={() => setIsEditingDetails(false)}
      />
    </Flex>
  );
};

export default function SegmentDetails() {
  const { segmentId } = useParams<{ segmentId: string }>();
  const {
    data: segment,
    isLoading,
    error,
  } = useGetSegmentQuery(segmentId ?? '', { skip: !segmentId });

  if (segmentId === 'default') {
    return (
      <Flex direction="column" gap="3">
        <BackLink />
        <Text size="2">
          The default segment applies to all visitors and cannot be edited.
        </Text>
      </Flex>
    );
  }

  if (isLoading) {
    // Mirror the loaded layout: back link, heading row, and rule cards.
    return (
      <Flex
        direction="column"
        gap="5"
        maxWidth="960px"
        data-testid="segment-details-loading"
      >
        <Flex direction="column" gap="3">
          <Skeleton height="1rem" width="6rem" />
          <Skeleton height="1.75rem" width="16rem" />
        </Flex>
        <Flex direction="column" gap="3">
          <Skeleton height="1.2rem" width="20rem" />
          <Skeleton height="6rem" width="100%" />
          <Skeleton height="6rem" width="100%" />
        </Flex>
      </Flex>
    );
  }

  if (error || !segment) {
    return (
      <Flex direction="column" gap="3">
        <BackLink />
        <Text size="2">This segment could not be loaded.</Text>
      </Flex>
    );
  }

  return <SegmentDetailsContent segment={segment} />;
}
