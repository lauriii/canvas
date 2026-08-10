import {
  Button,
  Card,
  Flex,
  Link,
  Separator,
  Switch,
  Text,
} from '@radix-ui/themes';

import DayOfWeekRuleEditor from '@/components/personalization/rules/DayOfWeekRuleEditor';
import GeolocationRuleEditor from '@/components/personalization/rules/GeolocationRuleEditor';
import QueryParameterRuleEditor from '@/components/personalization/rules/QueryParameterRuleEditor';
import UtmParametersRuleEditor from '@/components/personalization/rules/UtmParametersRuleEditor';
import {
  CONDITION_LABELS,
  isEditableRule,
  ruleSummary,
} from '@/features/personalization/rules';

import type { EditableSegmentRule, SegmentRule } from '@/types/Personalization';

interface RuleCardProps {
  rule: SegmentRule;
  // The condition's human-readable name as the server reports it. Only the
  // server knows it for a condition this client has no editor for.
  label?: string;
  // Where a condition without an in-dashboard editor is configured.
  editUrl?: string;
  onChange: (rule: SegmentRule) => void;
  onRemove: () => void;
}

const RuleEditor = ({
  rule,
  onChange,
}: {
  rule: EditableSegmentRule;
  onChange: (rule: SegmentRule) => void;
}) => {
  switch (rule.id) {
    case 'query_parameter':
      return <QueryParameterRuleEditor settings={rule} onChange={onChange} />;
    case 'utm_parameters':
      return <UtmParametersRuleEditor settings={rule} onChange={onChange} />;
    case 'geolocation':
      return <GeolocationRuleEditor settings={rule} onChange={onChange} />;
    case 'day_of_week':
      return <DayOfWeekRuleEditor settings={rule} onChange={onChange} />;
  }
};

const RuleCard = ({
  rule,
  label,
  editUrl,
  onChange,
  onRemove,
}: RuleCardProps) => {
  const editable = isEditableRule(rule);
  return (
    <Card>
      <Flex direction="column" gap="3">
        <Flex justify="between" align="center" gap="3">
          <Text size="2" weight="bold">
            {editable
              ? CONDITION_LABELS[rule.id as EditableSegmentRule['id']]
              : (label ?? rule.id)}
          </Text>
          <Button size="1" variant="ghost" color="red" onClick={onRemove}>
            Remove rule
          </Button>
        </Flex>
        <Text size="1" color="gray" data-testid={`rule-summary-${rule.id}`}>
          {ruleSummary(rule)}
        </Text>
        {isEditableRule(rule) ? (
          <RuleEditor rule={rule} onChange={onChange} />
        ) : (
          // Its settings belong to another module's plugin; this client cannot
          // render a form for them without corrupting them on save, so it
          // points at the form that can.
          <Text size="1" color="gray" data-testid={`rule-external-${rule.id}`}>
            This rule type is provided by another module.{' '}
            {editUrl ? (
              <Link href={editUrl} size="1">
                Edit its settings
              </Link>
            ) : (
              'Edit its settings from the segment configuration form.'
            )}
          </Text>
        )}
        <Separator size="4" />
        <Text as="label" size="1">
          <Flex gap="2" align="center">
            <Switch
              size="1"
              checked={rule.negate}
              onCheckedChange={(negate) => onChange({ ...rule, negate })}
            />
            Negate: match everyone except visitors who meet this rule
          </Flex>
        </Text>
      </Flex>
    </Card>
  );
};

export default RuleCard;
