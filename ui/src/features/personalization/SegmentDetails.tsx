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
  Text,
} from '@radix-ui/themes';

import RuleCard from '@/components/personalization/rules/RuleCard';
import EditSegmentDialog from '@/features/personalization/dialogs/EditSegmentDialog';
import {
  CONDITION_DESCRIPTIONS,
  CONDITION_IDS,
  CONDITION_LABELS,
  createDefaultRule,
} from '@/features/personalization/rules';
import {
  useGetSegmentQuery,
  useUpdateSegmentMutation,
} from '@/services/personalization';

import type {
  ConditionId,
  Segment,
  SegmentRule,
  SegmentRules,
} from '@/types/Personalization';

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
  // Unsaved rule edits; null means the saved rules are shown as-is.
  const [draftRules, setDraftRules] = useState<SegmentRules | null>(null);
  // Bumped to remount rule editors so their local input state resets.
  const [resetCount, setResetCount] = useState(0);
  const [isEditingDetails, setIsEditingDetails] = useState(false);

  const rules: SegmentRules = draftRules ?? segment.rules ?? {};
  // Render rules in a stable order regardless of the object key order.
  const ruleList = CONDITION_IDS.flatMap((conditionId) => {
    const rule = rules[conditionId];
    return rule ? [rule as SegmentRule] : [];
  });
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
              {CONDITION_IDS.map((conditionId) => (
                <DropdownMenu.Item
                  key={conditionId}
                  // A segment holds at most one rule of each type.
                  disabled={conditionId in rules}
                  onSelect={() => handleAddRule(conditionId)}
                >
                  <Flex direction="column">
                    <Text size="1" weight="medium">
                      {CONDITION_LABELS[conditionId]}
                    </Text>
                    <Text size="1" color="gray">
                      {CONDITION_DESCRIPTIONS[conditionId]}
                    </Text>
                  </Flex>
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
    return <div>Loading segment...</div>;
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
