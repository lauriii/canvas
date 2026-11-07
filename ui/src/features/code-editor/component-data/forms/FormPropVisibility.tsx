import { useState } from 'react';
import { Cross2Icon, PlusIcon } from '@radix-ui/react-icons';
import {
  Button,
  Code,
  Flex,
  IconButton,
  SegmentedControl,
  Select,
  Text,
  TextField,
} from '@radix-ui/themes';

import { Label } from '@/features/code-editor/component-data/FormElement';
import { getPropMachineName } from '@/features/code-editor/utils/utils';
import {
  conditionToRules,
  OPERATORS,
  OPERATORS_WITHOUT_VALUE,
  operatorsFor,
  rulesToCondition,
} from '@/features/code-editor/utils/visibilityRules';

import type {
  Operator,
  VisibilityRule,
  VisibilityRules,
} from '@/features/code-editor/utils/visibilityRules';
import type { CodeComponentProp } from '@/types/CodeComponent';
import type { VisibilityCondition } from '@/utils/propVisibility';

/**
 * The "Show when" builder: when a prop is shown in the component form.
 *
 * @see \Drupal\canvas\JsonSchemaInterpreter\PropVisibilityCondition
 */
export default function FormPropVisibility({
  prop,
  props,
  onChange,
}: {
  prop: CodeComponentProp;
  props: CodeComponentProp[];
  onChange: (condition: VisibilityCondition | undefined) => void;
}) {
  const condition = prop['x-canvas-visible-if'];
  const [state, setState] = useState(() => conditionToRules(condition));
  const others = props.filter(
    (other) => other.id !== prop.id && other.name && other.derivedType,
  );

  const id = `prop-visible-if-${prop.id}`;
  if (state === null) {
    return (
      <Flex direction="column" gap="2" mt="3">
        <Label>Show only when</Label>
        <Text size="1" color="gray">
          This prop has a condition that can only be edited in its
          configuration: <Code size="1">{JSON.stringify(condition)}</Code>
        </Text>
        <Button
          size="1"
          variant="soft"
          color="red"
          style={{ alignSelf: 'start' }}
          onClick={() => {
            setState({ match: 'all', rules: [] });
            onChange(undefined);
          }}
        >
          Remove condition
        </Button>
      </Flex>
    );
  }

  const update = (next: VisibilityRules) => {
    setState(next);
    onChange(rulesToCondition(next));
  };
  const updateRule = (index: number, changes: Partial<VisibilityRule>) =>
    update({
      ...state,
      rules: state.rules.map((rule, i) =>
        i === index ? { ...rule, ...changes } : rule,
      ),
    });
  const addRule = () =>
    update({
      ...state,
      rules: [...state.rules, { prop: '', operator: 'filled' }],
    });

  if (state.rules.length === 0) {
    return (
      others.length > 0 && (
        <Button
          size="1"
          variant="ghost"
          mt="3"
          style={{ alignSelf: 'start' }}
          onClick={addRule}
        >
          <PlusIcon /> Show only when…
        </Button>
      )
    );
  }

  return (
    <Flex direction="column" gap="2" mt="3" role="group" aria-labelledby={id}>
      <Flex align="center" gap="2">
        <Label>
          <span id={id}>Show only when</span>
        </Label>
        {state.rules.length > 1 && (
          <SegmentedControl.Root
            size="1"
            value={state.match}
            onValueChange={(match) =>
              update({ ...state, match: match as VisibilityRules['match'] })
            }
          >
            <SegmentedControl.Item value="all">all match</SegmentedControl.Item>
            <SegmentedControl.Item value="any">
              any matches
            </SegmentedControl.Item>
          </SegmentedControl.Root>
        )}
      </Flex>
      {state.rules.map((rule, index) => {
        const target = others.find(
          (other) => getPropMachineName(other.name) === rule.prop,
        );
        const operators = operatorsFor(target?.derivedType ?? null);
        return (
          <Flex key={index} gap="2" align="center" wrap="wrap">
            <Select.Root
              size="1"
              value={target ? rule.prop : undefined}
              onValueChange={(value) => {
                const derivedType =
                  others.find(
                    (other) => getPropMachineName(other.name) === value,
                  )?.derivedType ?? null;
                updateRule(index, {
                  prop: value,
                  operator: operatorsFor(derivedType)[0],
                  value: undefined,
                });
              }}
            >
              <Select.Trigger
                aria-label="Prop"
                placeholder={rule.prop ? `Missing: ${rule.prop}` : 'Prop'}
                color={rule.prop && !target ? 'red' : undefined}
              />
              <Select.Content>
                {others.map((other) => (
                  <Select.Item
                    key={other.id}
                    value={getPropMachineName(other.name)}
                  >
                    {other.name}
                  </Select.Item>
                ))}
              </Select.Content>
            </Select.Root>
            <Select.Root
              size="1"
              value={rule.operator}
              disabled={!target}
              onValueChange={(operator) =>
                updateRule(index, { operator: operator as Operator })
              }
            >
              <Select.Trigger aria-label="Operator" />
              <Select.Content>
                {operators.map((operator) => (
                  <Select.Item key={operator} value={operator}>
                    {OPERATORS[operator]}
                  </Select.Item>
                ))}
              </Select.Content>
            </Select.Root>
            {target && !OPERATORS_WITHOUT_VALUE.includes(rule.operator) && (
              <RuleValue
                prop={target}
                value={rule.value}
                onChange={(value) => updateRule(index, { value })}
              />
            )}
            <IconButton
              size="1"
              variant="ghost"
              color="gray"
              aria-label="Remove rule"
              onClick={() =>
                update({
                  ...state,
                  rules: state.rules.filter((_, i) => i !== index),
                })
              }
            >
              <Cross2Icon />
            </IconButton>
          </Flex>
        );
      })}
      <Button
        size="1"
        variant="ghost"
        style={{ alignSelf: 'start' }}
        onClick={addRule}
      >
        <PlusIcon /> Add rule
      </Button>
    </Flex>
  );
}

function RuleValue({
  prop,
  value,
  onChange,
}: {
  prop: CodeComponentProp;
  value: VisibilityRule['value'];
  onChange: (value: VisibilityRule['value']) => void;
}) {
  const isNumber = ['integer', 'number', 'listInteger'].includes(
    prop.derivedType ?? '',
  );
  if (prop.enum?.length) {
    return (
      <Select.Root
        size="1"
        value={value === undefined ? undefined : String(value)}
        onValueChange={(selected) =>
          onChange(isNumber ? Number(selected) : selected)
        }
      >
        <Select.Trigger aria-label="Value" placeholder="Value" />
        <Select.Content>
          {prop.enum
            .filter((item) => item.value !== '')
            .map((item) => (
              <Select.Item key={item.value} value={String(item.value)}>
                {item.label || item.value}
              </Select.Item>
            ))}
        </Select.Content>
      </Select.Root>
    );
  }
  return (
    <TextField.Root
      size="1"
      aria-label="Value"
      placeholder="Value"
      type={isNumber ? 'number' : 'text'}
      value={value ?? ''}
      onChange={(event) =>
        onChange(
          isNumber && event.target.value !== ''
            ? Number(event.target.value)
            : event.target.value,
        )
      }
    />
  );
}
