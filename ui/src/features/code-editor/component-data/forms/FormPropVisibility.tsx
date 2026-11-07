import { useEffect, useRef, useState } from 'react';
import { Cross2Icon, PlusIcon } from '@radix-ui/react-icons';
import {
  Box,
  Button,
  Code,
  Flex,
  Grid,
  IconButton,
  Select,
  Text,
  TextField,
} from '@radix-ui/themes';

import {
  Divider,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import { getPropMachineName } from '@/features/code-editor/utils/utils';
import {
  conditionToRules,
  OPERATORS,
  OPERATORS_WITHOUT_VALUE,
  operatorsFor,
  referencedProps,
  rulesToCondition,
} from '@/features/code-editor/utils/visibilityRules';

import type {
  Operator,
  VisibilityRule,
  VisibilityRules,
} from '@/features/code-editor/utils/visibilityRules';
import type { CodeComponentProp } from '@/types/CodeComponent';
import type { VisibilityCondition } from '@/utils/propVisibility';

// Prop, operator, value, remove.
const RULE_COLUMNS = 'minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) auto';

/**
 * Lists the props whose visibility depends on the given prop, directly or
 * through other props. A rule on one of those would create a cycle.
 */
const dependentsOf = (
  machineName: string,
  props: CodeComponentProp[],
): Set<string> => {
  const dependents = new Set<string>();
  let added = true;
  while (added) {
    added = false;
    props.forEach((prop) => {
      const name = getPropMachineName(prop.name);
      const condition = prop['x-canvas-visible-if'];
      if (
        !dependents.has(name) &&
        condition &&
        referencedProps(condition).some(
          (referenced) =>
            referenced === machineName || dependents.has(referenced),
        )
      ) {
        dependents.add(name);
        added = true;
      }
    });
  }
  return dependents;
};

/**
 * The "Show only when…" builder: when a prop is shown in the component form.
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
  const addRuleRef = useRef<HTMLButtonElement>(null);
  // Follows changes made elsewhere, such as undo or renaming a prop, but keeps
  // rules that are still incomplete while the condition stays the same.
  const emitted = useRef(JSON.stringify(condition));
  useEffect(() => {
    const json = JSON.stringify(condition);
    if (json !== emitted.current) {
      emitted.current = json;
      setState(conditionToRules(condition));
    }
  }, [condition]);

  const dependents = dependentsOf(getPropMachineName(prop.name), props);
  const others = props.filter(
    (other) =>
      other.id !== prop.id &&
      other.name &&
      other.derivedType &&
      !dependents.has(getPropMachineName(other.name)),
  );

  const id = `prop-visible-if-${prop.id}`;
  if (state === null) {
    return (
      <Section>
        <Label>Show only when</Label>
        <Text size="1" color="gray">
          This condition cannot be edited here:{' '}
          <Code size="1">{JSON.stringify(condition)}</Code>. Edit{' '}
          <Code size="1">x-canvas-visible-if</Code> in the component's{' '}
          <Code size="1">component.yml</Code>, or remove it.
        </Text>
        <Button
          size="1"
          variant="soft"
          color="red"
          style={{ alignSelf: 'start' }}
          onClick={() => {
            setState({ match: 'all', rules: [] });
            emitted.current = JSON.stringify(undefined);
            onChange(undefined);
          }}
        >
          Remove condition
        </Button>
      </Section>
    );
  }

  const update = (next: VisibilityRules) => {
    setState(next);
    const nextCondition = rulesToCondition(next);
    emitted.current = JSON.stringify(nextCondition);
    onChange(nextCondition);
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
        <Section>
          <Button
            size="1"
            variant="ghost"
            style={{ alignSelf: 'start' }}
            onClick={addRule}
          >
            <PlusIcon /> Show only when…
          </Button>
        </Section>
      )
    );
  }

  return (
    <Section role="group" aria-labelledby={id}>
      {state.rules.length > 1 ? (
        <Flex align="center" gap="2" id={id}>
          <Text size="1" weight="medium">
            Show when
          </Text>
          <Select.Root
            size="1"
            value={state.match}
            onValueChange={(match) =>
              update({ ...state, match: match as VisibilityRules['match'] })
            }
          >
            <Select.Trigger aria-label="Match" />
            <Select.Content>
              <Select.Item value="all">all</Select.Item>
              <Select.Item value="any">any</Select.Item>
            </Select.Content>
          </Select.Root>
          <Text size="1" weight="medium">
            of these match
          </Text>
        </Flex>
      ) : (
        <Label>
          <span id={id}>Show only when</span>
        </Label>
      )}
      {state.rules.map((rule, index) => {
        const target = props.find(
          (other) =>
            other.id !== prop.id &&
            other.name &&
            getPropMachineName(other.name) === rule.prop,
        );
        const operators = operatorsFor(
          target?.derivedType ?? null,
          target?.allowMultiple,
        );
        return (
          <Grid key={index} columns={RULE_COLUMNS} gap="2" align="center">
            <Select.Root
              size="1"
              value={target ? rule.prop : undefined}
              onValueChange={(value) => {
                const selected = others.find(
                  (other) => getPropMachineName(other.name) === value,
                );
                updateRule(index, {
                  prop: value,
                  operator: operatorsFor(
                    selected?.derivedType ?? null,
                    selected?.allowMultiple,
                  )[0],
                  value: undefined,
                });
              }}
            >
              <Select.Trigger
                aria-label="Prop"
                placeholder={
                  rule.prop ? `Missing prop: ${rule.prop}` : 'Choose a prop'
                }
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
            {target ? (
              <Select.Root
                size="1"
                value={operators.includes(rule.operator) ? rule.operator : ''}
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
            ) : (
              <Box />
            )}
            {target && !OPERATORS_WITHOUT_VALUE.includes(rule.operator) ? (
              <RuleValue
                prop={target}
                value={rule.value}
                onChange={(value) => updateRule(index, { value })}
              />
            ) : (
              <Box />
            )}
            <IconButton
              size="1"
              variant="ghost"
              color="gray"
              aria-label="Remove rule"
              onClick={() => {
                update({
                  ...state,
                  rules: state.rules.filter((_, i) => i !== index),
                });
                setTimeout(() => addRuleRef.current?.focus());
              }}
            >
              <Cross2Icon />
            </IconButton>
          </Grid>
        );
      })}
      <Button
        ref={addRuleRef}
        size="1"
        variant="ghost"
        style={{ alignSelf: 'start' }}
        onClick={addRule}
      >
        <PlusIcon /> Add rule
      </Button>
    </Section>
  );
}

function Section({
  children,
  ...attributes
}: {
  children: React.ReactNode;
  role?: string;
  'aria-labelledby'?: string;
}) {
  return (
    <Box mt="4" mb="4">
      <Divider />
      <Flex direction="column" gap="2" mt="3" {...attributes}>
        {children}
      </Flex>
    </Box>
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
  const isNumber =
    ['integer', 'number', 'listInteger'].includes(prop.derivedType ?? '') ||
    (prop.allowMultiple &&
      ['integer', 'number'].includes(prop.items?.type ?? ''));
  if (prop.enum?.length) {
    return (
      <Select.Root
        size="1"
        value={value === undefined ? undefined : String(value)}
        onValueChange={(selected) =>
          onChange(isNumber ? Number(selected) : selected)
        }
      >
        <Select.Trigger aria-label="Value" placeholder="Choose a value" />
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
