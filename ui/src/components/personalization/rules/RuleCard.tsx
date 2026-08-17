import { Button, Card, Flex, Separator, Switch, Text } from '@radix-ui/themes';

import DayOfWeekRuleEditor from '@/components/personalization/rules/DayOfWeekRuleEditor';
import GeolocationRuleEditor from '@/components/personalization/rules/GeolocationRuleEditor';
import QueryParameterRuleEditor from '@/components/personalization/rules/QueryParameterRuleEditor';
import UtmParametersRuleEditor from '@/components/personalization/rules/UtmParametersRuleEditor';
import {
  CONDITION_LABELS,
  ruleSummary,
} from '@/features/personalization/rules';

import type { SegmentRule } from '@/types/Personalization';

interface RuleCardProps {
  rule: SegmentRule;
  onChange: (rule: SegmentRule) => void;
  onRemove: () => void;
}

const RuleEditor = ({
  rule,
  onChange,
}: {
  rule: SegmentRule;
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

const RuleCard = ({ rule, onChange, onRemove }: RuleCardProps) => (
  <Card>
    <Flex direction="column" gap="3">
      <Flex justify="between" align="center" gap="3">
        <Text size="2" weight="bold">
          {CONDITION_LABELS[rule.id]}
        </Text>
        <Button size="1" variant="ghost" color="red" onClick={onRemove}>
          Remove rule
        </Button>
      </Flex>
      <Text size="1" color="gray" data-testid={`rule-summary-${rule.id}`}>
        {ruleSummary(rule)}
      </Text>
      <RuleEditor rule={rule} onChange={onChange} />
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

export default RuleCard;
