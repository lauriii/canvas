import { useId } from 'react';
import { Flex, Select, Text, TextField } from '@radix-ui/themes';

import type {
  QueryParameterCondition,
  QueryParameterMatching,
} from '@/types/Personalization';

interface QueryParameterRuleEditorProps {
  settings: QueryParameterCondition;
  onChange: (settings: QueryParameterCondition) => void;
}

const QueryParameterRuleEditor = ({
  settings,
  onChange,
}: QueryParameterRuleEditorProps) => {
  const parameterId = useId();
  const valueId = useId();

  return (
    <Flex gap="3" wrap="wrap" align="end">
      <Flex direction="column" gap="1">
        <Text as="label" size="1" weight="medium" htmlFor={parameterId}>
          Parameter name
        </Text>
        <TextField.Root
          id={parameterId}
          size="1"
          value={settings.parameter}
          placeholder="For example, coupon"
          onChange={(e) => onChange({ ...settings, parameter: e.target.value })}
        />
      </Flex>
      <Flex direction="column" gap="1">
        <Text size="1" weight="medium">
          Matching
        </Text>
        <Select.Root
          size="1"
          value={settings.matching}
          onValueChange={(matching) =>
            onChange({
              ...settings,
              matching: matching as QueryParameterMatching,
            })
          }
        >
          <Select.Trigger aria-label="Matching" />
          <Select.Content>
            <Select.Item value="exact">Equals value</Select.Item>
            <Select.Item value="starts_with">Starts with value</Select.Item>
            <Select.Item value="present">Is present</Select.Item>
          </Select.Content>
        </Select.Root>
      </Flex>
      {settings.matching !== 'present' && (
        <Flex direction="column" gap="1">
          <Text as="label" size="1" weight="medium" htmlFor={valueId}>
            Value
          </Text>
          <TextField.Root
            id={valueId}
            size="1"
            value={settings.value}
            onChange={(e) => onChange({ ...settings, value: e.target.value })}
          />
        </Flex>
      )}
    </Flex>
  );
};

export default QueryParameterRuleEditor;
