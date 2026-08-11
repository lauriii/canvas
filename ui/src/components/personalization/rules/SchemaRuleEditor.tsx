import { Flex, Select, Switch, Text, TextField } from '@radix-ui/themes';

import type { ConditionSetting, SegmentRule } from '@/types/Personalization';

interface SchemaRuleEditorProps {
  rule: SegmentRule;
  settings: ConditionSetting[];
  onChange: (rule: SegmentRule) => void;
}

/**
 * Renders a rule from the settings its condition declares on the server.
 *
 * A third-party provider gets an editor here by declaring its settings in the
 * config schema it already has to ship, not by shipping code into this client.
 * Anything the server could not describe as a flat set of controls arrives
 * without settings at all, and RuleCard keeps pointing at the condition's own
 * form — a partial form that silently dropped a setting would be worse than
 * none.
 */
const SchemaRuleEditor = ({
  rule,
  settings,
  onChange,
}: SchemaRuleEditorProps) => (
  <Flex direction="column" gap="3">
    {settings.map((setting) => {
      // A rule of a condition this client has no editor for is deliberately
      // typed as opaque settings, so reading one by name needs the widened
      // view rather than the union.
      const value = (rule as Record<string, unknown>)[setting.name];
      const label = (
        <Text as="div" size="1" weight="bold" mb="1">
          {setting.label}
          {setting.required && ' *'}
        </Text>
      );

      if (setting.widget === 'select') {
        const options = setting.options ?? {};
        const current = typeof value === 'string' ? value : '';
        // An identifier the provider does not know is indistinguishable at
        // runtime from an audience nobody is in, so a value already saved has
        // to stay selectable even once the provider stops reporting it.
        const missing = current !== '' && !(current in options);
        return (
          <label
            key={setting.name}
            data-testid={`rule-setting-${setting.name}`}
          >
            {label}
            <Select.Root
              value={current || undefined}
              disabled={Object.keys(options).length === 0 && !missing}
              onValueChange={(next) =>
                onChange({ ...rule, [setting.name]: next })
              }
            >
              <Select.Trigger
                placeholder={
                  Object.keys(options).length === 0 && !missing
                    ? 'Nothing available to choose'
                    : 'Select an audience'
                }
              />
              <Select.Content>
                {missing && (
                  <Select.Item value={current}>
                    {current} (not currently reported by the provider)
                  </Select.Item>
                )}
                {Object.entries(options).map(([optionValue, optionLabel]) => (
                  <Select.Item key={optionValue} value={optionValue}>
                    {optionLabel}
                  </Select.Item>
                ))}
              </Select.Content>
            </Select.Root>
          </label>
        );
      }

      if (setting.widget === 'checkbox') {
        return (
          <Text
            as="label"
            size="1"
            key={setting.name}
            data-testid={`rule-setting-${setting.name}`}
          >
            <Flex gap="2" align="center">
              <Switch
                size="1"
                checked={value === true}
                onCheckedChange={(next) =>
                  onChange({ ...rule, [setting.name]: next })
                }
              />
              {setting.label}
            </Flex>
          </Text>
        );
      }

      return (
        <label key={setting.name} data-testid={`rule-setting-${setting.name}`}>
          {label}
          <TextField.Root
            size="1"
            type={setting.widget === 'number' ? 'number' : 'text'}
            value={value === undefined || value === null ? '' : String(value)}
            onChange={(event) =>
              onChange({
                ...rule,
                [setting.name]:
                  setting.widget === 'number'
                    ? Number(event.target.value)
                    : event.target.value,
              })
            }
          />
        </label>
      );
    })}
  </Flex>
);

export default SchemaRuleEditor;
