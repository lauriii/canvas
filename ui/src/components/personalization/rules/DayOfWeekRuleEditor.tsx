import { Checkbox, Flex, Text } from '@radix-ui/themes';

import { capitalize, DAYS_OF_WEEK } from '@/features/personalization/rules';

import type { DayOfWeekCondition } from '@/types/Personalization';

interface DayOfWeekRuleEditorProps {
  settings: DayOfWeekCondition;
  onChange: (settings: DayOfWeekCondition) => void;
}

const DayOfWeekRuleEditor = ({
  settings,
  onChange,
}: DayOfWeekRuleEditorProps) => {
  const toggleDay = (day: DayOfWeekCondition['days'][number]) => {
    // Preserve the week order regardless of the order days were checked in.
    const days = DAYS_OF_WEEK.filter((d) =>
      d === day ? !settings.days.includes(day) : settings.days.includes(d),
    );
    onChange({ ...settings, days });
  };

  return (
    <Flex gap="3" wrap="wrap">
      {DAYS_OF_WEEK.map((day) => (
        <Text key={day} as="label" size="1">
          <Flex gap="1" align="center">
            <Checkbox
              size="1"
              checked={settings.days.includes(day)}
              onCheckedChange={() => toggleDay(day)}
            />
            {capitalize(day)}
          </Flex>
        </Text>
      ))}
    </Flex>
  );
};

export default DayOfWeekRuleEditor;
