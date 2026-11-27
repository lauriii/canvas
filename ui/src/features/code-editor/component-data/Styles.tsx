import { useMemo, useState } from 'react';
import { InfoCircledIcon, MagnifyingGlassIcon } from '@radix-ui/react-icons';
import {
  Badge,
  Button,
  Callout,
  Checkbox,
  Code,
  Flex,
  RadioGroup,
  Switch,
  Text,
  TextField,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCodeComponentProperty,
  setCodeComponentProperty,
} from '@/features/code-editor/codeEditorSlice';
import { groupByCategory, matchesFilter } from '@/features/styles/styleUtils';
import { useStyleDefinitions } from '@/features/styles/useComponentStyles';

import type { StyleDefinition } from '@/types/Styles';

/** Prop names reserved for components that accept styles. */
export const RESERVED_STYLE_PROP_NAMES = ['className', 'style'];
const RENAME_GUIDE =
  'https://project.pages.drupalcode.org/canvas/code-components/styles#rename-a-reserved-prop';
const CLASS_NAME_SNIPPET = "className={cn('…', className)}";

const PROVIDER_BADGES: Record<StyleDefinition['providerType'], string> = {
  site: 'Site style library',
  theme: 'Theme',
  module: 'Module',
};

/** Whether every option sets `class` or `style`, so `className` covers it. */
const onlySetsClassOrStyle = (definition: StyleDefinition) =>
  definition.options.every((option) =>
    ['class', 'style'].includes(option.attribute),
  );

const optionSummary = (definition: StyleDefinition) =>
  definition.options.map((option) => option.label).join(' · ');

/**
 * Component data > Styles: which core Style API styles this code component
 * accepts. Saving creates a new component version; existing instances keep
 * their version until upgraded.
 */
export default function Styles() {
  const dispatch = useAppDispatch();
  const accepted = useAppSelector(selectCodeComponentProperty('styles')) ?? [];
  const props = useAppSelector(selectCodeComponentProperty('props'));
  const sourceCodeJs = useAppSelector(
    selectCodeComponentProperty('sourceCodeJs'),
  );
  const { active, definitions } = useStyleDefinitions();
  const [filter, setFilter] = useState('');
  // Turning styles on starts with "Selected styles" and nothing selected yet.
  const [enabledLocally, setEnabledLocally] = useState(false);

  const all = useMemo(() => Object.values(definitions), [definitions]);
  const acceptsStyles =
    enabledLocally || accepted === '*' || accepted.length > 0;
  const mode = accepted === '*' ? 'all' : 'selected';
  const selected = accepted === '*' ? [] : accepted;

  const setAccepted = (next: '*' | string[]) =>
    dispatch(setCodeComponentProperty(['styles', next]));

  if (!active) {
    return (
      <Text as="p" size="1" color="gray" my="3">
        Component styles are not enabled on this site.
      </Text>
    );
  }

  const reserved = acceptsStyles
    ? props.filter((prop) => RESERVED_STYLE_PROP_NAMES.includes(prop.name))
    : [];
  const ignoresClassName = acceptsStyles && !/\bclassName\b/.test(sourceCodeJs);

  const visible = all.filter((definition) => matchesFilter(definition, filter));
  const needsCode = visible.filter((d) => !onlySetsClassOrStyle(d));
  const groups = groupByCategory(visible.filter(onlySetsClassOrStyle));

  const toggle = (styleId: string, checked: boolean) =>
    setAccepted(
      checked
        ? [...selected, styleId]
        : selected.filter((id) => id !== styleId),
    );

  const row = (definition: StyleDefinition, snippet?: string) => (
    <Flex
      key={definition.id}
      direction="column"
      gap="1"
      data-testid={`canvas-code-editor-style-${definition.id}`}
    >
      <Flex align="center" justify="between" gap="2">
        <Text as="label" size="1">
          <Flex gap="2" align="center">
            <Checkbox
              size="1"
              disabled={mode === 'all' && onlySetsClassOrStyle(definition)}
              checked={
                selected.includes(definition.id) ||
                (mode === 'all' && onlySetsClassOrStyle(definition))
              }
              onCheckedChange={(checked) =>
                toggle(definition.id, checked === true)
              }
            />
            {definition.label}
          </Flex>
        </Text>
        <Badge
          size="1"
          color="gray"
          variant="soft"
          title={PROVIDER_BADGES[definition.providerType]}
        >
          {definition.providerLabel}
        </Badge>
      </Flex>
      <Text size="1" color="gray">
        {snippet ? (
          <>
            Sets {definition.options[0]?.attribute}.{' '}
            <Code size="1">{snippet}</Code>
          </>
        ) : (
          optionSummary(definition)
        )}
      </Text>
    </Flex>
  );

  return (
    <Flex
      direction="column"
      gap="3"
      my="3"
      data-testid="canvas-code-editor-styles"
    >
      <Flex direction="column" gap="1">
        <Text as="label" size="2" weight="medium">
          <Flex gap="2" align="center">
            <Switch
              size="1"
              checked={acceptsStyles}
              aria-label="Accept styles"
              onCheckedChange={(checked) => {
                setEnabledLocally(checked);
                if (!checked) setAccepted([]);
              }}
            />
            Accept styles
          </Flex>
        </Text>
        <Text size="1" color="gray">
          Apply <Code size="1">className</Code> and <Code size="1">style</Code>{' '}
          on your root element.
        </Text>
      </Flex>

      {reserved.map((prop) => (
        <Callout.Root key={prop.id} size="1" color="red" variant="surface">
          <Callout.Text>
            The prop &quot;{prop.name}&quot; is reserved for components that
            accept styles. Rename it.{' '}
            <a href={RENAME_GUIDE} target="_blank" rel="noreferrer">
              How to rename a prop
            </a>
          </Callout.Text>
        </Callout.Root>
      ))}

      {ignoresClassName && (
        <Callout.Root
          size="1"
          color="gray"
          variant="surface"
          data-testid="canvas-code-editor-classname-hint"
        >
          <Callout.Icon>
            <InfoCircledIcon />
          </Callout.Icon>
          <Flex direction="column" gap="1">
            <Callout.Text>
              This component never references <Code size="1">className</Code>,
              so styles may not apply.
            </Callout.Text>
            <Flex gap="2" align="center">
              <Code size="1">{CLASS_NAME_SNIPPET}</Code>
              <Button
                size="1"
                variant="ghost"
                onClick={() =>
                  navigator.clipboard?.writeText(CLASS_NAME_SNIPPET)
                }
              >
                Copy
              </Button>
            </Flex>
          </Flex>
        </Callout.Root>
      )}

      {acceptsStyles && (
        <>
          <RadioGroup.Root
            size="1"
            value={mode}
            onValueChange={(value) =>
              setAccepted(
                value === 'all'
                  ? '*'
                  : // Start from what "All" covered, so nothing is lost.
                    all.filter(onlySetsClassOrStyle).map(({ id }) => id),
              )
            }
          >
            <RadioGroup.Item value="all">
              All styles, including ones added later
            </RadioGroup.Item>
            <RadioGroup.Item value="selected">Selected styles</RadioGroup.Item>
          </RadioGroup.Root>

          <TextField.Root
            size="1"
            value={filter}
            placeholder="Search styles"
            aria-label="Search styles"
            onChange={(event) => setFilter(event.target.value)}
          >
            <TextField.Slot>
              <MagnifyingGlassIcon />
            </TextField.Slot>
          </TextField.Root>

          {groups.map(({ category, styles }) => (
            <Flex key={category} direction="column" gap="2">
              <Flex justify="between" align="center">
                <Text size="1" weight="bold">
                  {category}
                </Text>
                {mode === 'selected' && (
                  <Button
                    size="1"
                    variant="ghost"
                    onClick={() =>
                      setAccepted([
                        ...new Set([...selected, ...styles.map((d) => d.id)]),
                      ])
                    }
                  >
                    Select all
                  </Button>
                )}
              </Flex>
              {styles.map((definition) => row(definition))}
            </Flex>
          ))}

          {needsCode.length > 0 && (
            <Flex direction="column" gap="2">
              <Text size="1" weight="bold">
                Needs code
              </Text>
              {needsCode.map((definition) =>
                row(
                  definition,
                  `${definition.options[0]?.attribute}={useStyleOptions().${definition.id}}`,
                ),
              )}
            </Flex>
          )}
        </>
      )}
    </Flex>
  );
}
