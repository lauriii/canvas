import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ArrowDownIcon,
  ArrowUpIcon,
  Cross2Icon,
  DotsHorizontalIcon,
} from '@radix-ui/react-icons';
import {
  AlertDialog,
  Badge,
  Button,
  Callout,
  DropdownMenu,
  Flex,
  IconButton,
  Spinner,
  Text,
  TextField,
} from '@radix-ui/themes';

import { BRAND_KIT_ID } from '@/features/brandKit/constants';
import {
  cssVariableSnippet,
  draftEffectiveValue,
  EDITABLE_TYPES,
  FONT_FAMILY_ERROR,
  hexToColorValue,
  isValidFontFamily,
  tokenValueToCss,
} from '@/features/brandKit/themeTokens';
import {
  useGetBrandKitQuery,
  useSetDesignTokensEnabledMutation,
  useStageThemeTokenMutation,
} from '@/services/brandKit';
import { getColorHex } from '@/utils/brandKitColor';

import type {
  BrandKitColorValue,
  BrandKitThemeToken,
  BrandKitThemeTokenGroup,
} from '@/types/CodeComponent';

const DEBOUNCE_MS = 400;

/**
 * The "Style guide" tab: the theme's own design values, from its tokens.yml.
 *
 * Site builders change them as drafts, which go live when published from
 * Review changes. Structure comes from the theme: Canvas stores none.
 */
const BrandKitStyleGuideSection = () => {
  const { data: brandKit, isLoading } = useGetBrandKitQuery(BRAND_KIT_ID);
  if (isLoading || !brandKit) {
    return <Spinner />;
  }
  const designTokens = brandKit.designTokens;
  if (!designTokens?.available) {
    return null;
  }
  const tokens = brandKit.themeTokens ?? [];
  const groups = brandKit.themeTokenGroups ?? [];
  const enabled = designTokens.enabled;

  return (
    <Flex direction="column" gap="3" data-testid="canvas-style-guide">
      {enabled ? (
        <Flex justify="between" align="center">
          <Text size="1" color="gray">
            Changes are drafts until you publish them.
          </Text>
          <TurnOffMenu />
        </Flex>
      ) : (
        <EnableBanner tokens={tokens} impact={designTokens.enableImpact} />
      )}
      {tokens.length === 0 && (
        <Text size="2" color="gray">
          No design values that Canvas can use are defined yet.
        </Text>
      )}
      <TokenSections tokens={tokens} groups={groups} enabled={enabled} />
    </Flex>
  );
};

const EnableBanner = ({
  tokens,
  impact,
}: {
  tokens: BrandKitThemeToken[];
  impact: { otherModuleValues: number; collidingSiteColors: number } | null;
}) => {
  const [setEnabled, { isLoading }] = useSetDesignTokensEnabledMutation();
  const listed = tokens.filter((t) => t.provider !== null);
  if (listed.length === 0) {
    return null;
  }
  const colors = listed.filter((t) => t.type === 'color').length;
  const fonts = listed.filter((t) => t.type === 'fontFamily').length;
  const kept = tokens.filter((t) => t.overridden).length;
  const theme = listed[0].provider?.label ?? 'Your theme';
  return (
    <Callout.Root size="1" data-testid="canvas-style-guide-banner">
      <Callout.Text>
        {theme} defines {listed.length} design values, including {colors} colors
        and {fonts} font stacks you can change. Use them in Canvas
        (experimental).
      </Callout.Text>
      <AlertDialog.Root>
        <AlertDialog.Trigger>
          <Button size="1" loading={isLoading}>
            Turn on
          </Button>
        </AlertDialog.Trigger>
        <AlertDialog.Content maxWidth="450px">
          <AlertDialog.Title>Use the theme style guide?</AlertDialog.Title>
          <AlertDialog.Description size="2">
            This changes your live site now. Your theme&apos;s style guide will
            appear here, and its colors in color pickers. Changes you make in
            the style guide are drafts until you publish them. You can turn this
            off without losing changes.
          </AlertDialog.Description>
          {!!impact?.otherModuleValues && (
            <Text as="p" size="2" mt="2">
              {impact.otherModuleValues} values set by other modules will also
              take effect.
            </Text>
          )}
          {!!impact?.collidingSiteColors && (
            <Text as="p" size="2" mt="2">
              {impact.collidingSiteColors} site colors share a name with a theme
              color and will now take priority over the theme.
            </Text>
          )}
          {kept > 0 && (
            <Text as="p" size="2" mt="2">
              Your {kept} changes are kept and return when you turn this on.
            </Text>
          )}
          <Text as="p" size="2" mt="2">
            If this site&apos;s configuration is deployed from code, a deploy
            can undo published changes. Ask your developer.
          </Text>
          <Flex gap="3" mt="4" justify="end">
            <AlertDialog.Cancel>
              <Button variant="soft" color="gray">
                Not now
              </Button>
            </AlertDialog.Cancel>
            <AlertDialog.Action>
              <Button onClick={() => setEnabled(true)}>Turn on</Button>
            </AlertDialog.Action>
          </Flex>
        </AlertDialog.Content>
      </AlertDialog.Root>
    </Callout.Root>
  );
};

const TurnOffMenu = () => {
  const [setEnabled] = useSetDesignTokensEnabledMutation();
  const [open, setOpen] = useState(false);
  return (
    <>
      <DropdownMenu.Root>
        <DropdownMenu.Trigger>
          <IconButton size="1" variant="ghost" aria-label="Style guide options">
            <DotsHorizontalIcon />
          </IconButton>
        </DropdownMenu.Trigger>
        <DropdownMenu.Content size="1">
          <DropdownMenu.Item onSelect={() => setOpen(true)}>
            Turn off the theme style guide
          </DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Root>
      <AlertDialog.Root open={open} onOpenChange={setOpen}>
        <AlertDialog.Content maxWidth="450px">
          <AlertDialog.Title>Turn off the theme style guide?</AlertDialog.Title>
          <AlertDialog.Description size="2">
            This changes your live site right away. Customized design values
            return to their defaults until you turn this back on. Drafts are
            kept, but can&apos;t be published while this is off. Site colors
            return to their previous priority.
          </AlertDialog.Description>
          <Flex gap="3" mt="4" justify="end">
            <AlertDialog.Cancel>
              <Button variant="soft" color="gray">
                Cancel
              </Button>
            </AlertDialog.Cancel>
            <AlertDialog.Action>
              <Button color="red" onClick={() => setEnabled(false)}>
                Turn off
              </Button>
            </AlertDialog.Action>
          </Flex>
        </AlertDialog.Content>
      </AlertDialog.Root>
    </>
  );
};

/**
 * Sections follow the theme: the first group level is a section, deeper
 * groups are subheadings. Tokens directly under the root go in "General".
 */
const TokenSections = ({
  tokens,
  groups,
  enabled,
}: {
  tokens: BrandKitThemeToken[];
  groups: BrandKitThemeTokenGroup[];
  enabled: boolean;
}) => {
  const sections = useMemo(() => {
    const groupName = (provider: string, path: string[]) =>
      groups.find(
        (g) => g.provider === provider && g.path.join('.') === path.join('.'),
      );
    const result = new Map<
      string,
      { title: string; description: string | null; rows: BrandKitThemeToken[] }
    >();
    for (const token of tokens) {
      const provider = token.provider?.name ?? '';
      const key = token.provider
        ? `${provider}:${token.groupPath[0] ?? ''}`
        : 'other';
      if (!result.has(key)) {
        const group = token.groupPath.length
          ? groupName(provider, token.groupPath.slice(0, 1))
          : undefined;
        result.set(key, {
          title: token.provider ? (group?.name ?? 'General') : 'Other values',
          description: group?.description ?? null,
          rows: [],
        });
      }
      result.get(key)!.rows.push(token);
    }
    return [...result.entries()].map(([key, section]) => ({
      key,
      ...section,
      subheading: (token: BrandKitThemeToken) =>
        token.provider && token.groupPath.length > 1
          ? token.groupPath
              .slice(1)
              .map(
                (_, i) =>
                  groupName(
                    token.provider!.name,
                    token.groupPath.slice(0, i + 2),
                  )?.name,
              )
              .join(' / ')
          : null,
    }));
  }, [tokens, groups]);

  return (
    <>
      {sections.map((section) => {
        let previousSubheading: string | null = null;
        return (
          <section
            key={section.key}
            aria-label={section.title}
            data-testid="canvas-style-guide-section"
          >
            <Text as="div" size="2" weight="bold">
              {section.title}
            </Text>
            {section.description && (
              <Text as="div" size="1" color="gray">
                {section.description}
              </Text>
            )}
            {section.rows.map((token) => {
              const subheading = section.subheading(token);
              const showSubheading =
                subheading && subheading !== previousSubheading;
              previousSubheading = subheading;
              return (
                <div key={token.path}>
                  {showSubheading && (
                    <Text as="div" size="1" weight="medium" mt="2">
                      {subheading}
                    </Text>
                  )}
                  <TokenRow token={token} enabled={enabled} />
                </div>
              );
            })}
          </section>
        );
      })}
    </>
  );
};

const TokenRow = ({
  token,
  enabled,
}: {
  token: BrandKitThemeToken;
  enabled: boolean;
}) => {
  const [stageMutation] = useStageThemeTokenMutation();
  // Pending edits are debounced per row: a reset cancels them, and closing
  // the panel saves them.
  const { stage, cancel } = useDebouncedStage((value: unknown) =>
    stageMutation({ token, value }),
  );
  const canEdit =
    enabled && token.editable && EDITABLE_TYPES.includes(token.type);
  const status =
    token.draft !== null ? 'Draft' : token.overridden ? 'Customized' : null;
  const effective = draftEffectiveValue(token);

  return (
    <Flex
      direction="column"
      gap="1"
      py="2"
      data-testid="canvas-style-guide-row"
      data-token-path={token.path}
    >
      <Flex justify="between" align="center" gap="2">
        <Flex direction="column">
          <Text size="2">{token.name}</Text>
          <Text size="1" color="gray" style={{ fontFamily: 'monospace' }}>
            {token.cssVariable}
          </Text>
        </Flex>
        <Flex align="center" gap="2">
          {status && (
            <Badge
              size="1"
              color={status === 'Draft' ? 'amber' : 'blue'}
              data-testid="canvas-style-guide-status"
            >
              {status}
            </Badge>
          )}
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <IconButton
                size="1"
                variant="ghost"
                aria-label={`${token.name} options`}
              >
                <DotsHorizontalIcon />
              </IconButton>
            </DropdownMenu.Trigger>
            <DropdownMenu.Content size="1">
              {enabled &&
                (token.draft?.value != null ||
                  (token.resettable && token.draft === null)) && (
                  <DropdownMenu.Item
                    onSelect={() => {
                      cancel();
                      stageMutation({ token, value: null });
                    }}
                  >
                    Reset to default
                  </DropdownMenu.Item>
                )}
              <DropdownMenu.Item
                onSelect={() =>
                  navigator.clipboard?.writeText(cssVariableSnippet(token))
                }
              >
                Copy CSS variable
              </DropdownMenu.Item>
            </DropdownMenu.Content>
          </DropdownMenu.Root>
        </Flex>
      </Flex>
      {canEdit && token.type === 'color' && (
        <ColorEditor
          token={token}
          value={effective as BrandKitColorValue | null}
          onChange={(hex) => stage(hexToColorValue(hex))}
        />
      )}
      {canEdit && token.type === 'fontFamily' && (
        <FontStackEditor
          token={token}
          value={(effective as string[] | null) ?? []}
          onChange={stage}
        />
      )}
      {!canEdit && <ReadOnlyValue token={token} enabled={enabled} />}
    </Flex>
  );
};

const ReadOnlyValue = ({
  token,
  enabled,
}: {
  token: BrandKitThemeToken;
  enabled: boolean;
}) => {
  const css = tokenValueToCss(token.type, draftEffectiveValue(token));
  const note = !enabled
    ? 'Turn on to change these.'
    : (token.reason ??
      (EDITABLE_TYPES.includes(token.type)
        ? null
        : 'Editing this kind of value is coming in a later release.'));
  return (
    <Flex direction="column" gap="1">
      <Flex align="center" gap="2">
        {token.type === 'color' && css && (
          <span
            aria-hidden
            style={{
              display: 'inline-block',
              width: 16,
              height: 16,
              borderRadius: 4,
              background: css,
              border: '1px solid var(--gray-a6)',
            }}
          />
        )}
        <Text size="1" data-testid="canvas-style-guide-value">
          {css ?? 'No value'}
        </Text>
      </Flex>
      {note && (
        <Text size="1" color="gray">
          {note}
        </Text>
      )}
    </Flex>
  );
};

/**
 * Debounces staging, so typing and dragging do not stage every value.
 *
 * A pending edit is staged when the row unmounts, so closing the panel never
 * loses it, and `cancel()` drops it, so it cannot overwrite a reset.
 */
const useDebouncedStage = <T,>(onChange: (value: T) => void) => {
  const timer = useRef<ReturnType<typeof setTimeout>>();
  const pending = useRef<{ value: T } | null>(null);
  const latest = useRef(onChange);
  latest.current = onChange;
  const flush = useCallback(() => {
    clearTimeout(timer.current);
    const next = pending.current;
    pending.current = null;
    if (next) {
      latest.current(next.value);
    }
  }, []);
  useEffect(() => flush, [flush]);
  return {
    stage: (value: T) => {
      pending.current = { value };
      clearTimeout(timer.current);
      timer.current = setTimeout(flush, DEBOUNCE_MS);
    },
    cancel: () => {
      clearTimeout(timer.current);
      pending.current = null;
    },
  };
};

const ColorEditor = ({
  token,
  value,
  onChange,
}: {
  token: BrandKitThemeToken;
  value: BrandKitColorValue | null;
  onChange: (value: string) => void;
}) => {
  const hex = value ? getColorHex(value) : '#000000';
  const [input, setInput] = useState(hex);
  useEffect(() => setInput(hex), [hex]);
  const valid = hexToColorValue(input) !== null;

  const update = (next: string) => {
    setInput(next);
    if (hexToColorValue(next)) {
      onChange(next);
    }
  };

  return (
    <Flex direction="column" gap="1">
      <Flex align="center" gap="2">
        <input
          type="color"
          aria-label={`${token.name} color`}
          value={valid ? hexToColorValue(input)!.hex! : hex}
          onChange={(e) => update(e.target.value)}
          style={{ width: 28, height: 28, padding: 0, border: 'none' }}
        />
        <TextField.Root
          size="1"
          aria-label={`${token.name} hex value`}
          aria-invalid={!valid}
          value={input}
          onChange={(e) => update(e.target.value)}
        />
      </Flex>
      {!valid && (
        <Text size="1" color="red">
          Enter a hex color, such as #0055aa.
        </Text>
      )}
    </Flex>
  );
};

/**
 * An ordered list of font families. Every declared entry is shown, and one
 * Canvas cannot save must be removed or replaced before the draft saves.
 */
const FontStackEditor = ({
  token,
  value,
  onChange,
}: {
  token: BrandKitThemeToken;
  value: string[];
  onChange: (value: string[]) => void;
}) => {
  const [families, setFamilies] = useState(value);
  const serialized = value.join('\n');
  useEffect(() => setFamilies(serialized.split('\n')), [serialized]);
  const [newFamily, setNewFamily] = useState('');

  const update = (next: string[]) => {
    setFamilies(next);
    if (next.length > 0 && next.every(isValidFontFamily)) {
      onChange(next);
    }
  };
  const move = (index: number, by: number) => {
    const next = [...families];
    [next[index], next[index + by]] = [next[index + by], next[index]];
    update(next);
  };
  const hasGeneric = families.some((f) =>
    /^(serif|sans-serif|monospace|cursive|fantasy|system-ui|ui-serif|ui-sans-serif|ui-monospace|ui-rounded|math|emoji|fangsong)$/.test(
      f,
    ),
  );

  return (
    <Flex direction="column" gap="1" aria-label={`${token.name} font stack`}>
      <ol style={{ margin: 0, paddingLeft: 0, listStyle: 'none' }}>
        {families.map((family, index) => (
          <li key={`${family}-${index}`}>
            <Flex align="center" gap="1">
              <Text size="1" style={{ flexGrow: 1, fontFamily: family }}>
                {family}
              </Text>
              <IconButton
                size="1"
                variant="ghost"
                aria-label={`Move ${family} up`}
                disabled={index === 0}
                onClick={() => move(index, -1)}
              >
                <ArrowUpIcon />
              </IconButton>
              <IconButton
                size="1"
                variant="ghost"
                aria-label={`Move ${family} down`}
                disabled={index === families.length - 1}
                onClick={() => move(index, 1)}
              >
                <ArrowDownIcon />
              </IconButton>
              <IconButton
                size="1"
                variant="ghost"
                aria-label={`Remove ${family}`}
                onClick={() => update(families.filter((_, i) => i !== index))}
              >
                <Cross2Icon />
              </IconButton>
            </Flex>
            {!isValidFontFamily(family) && (
              <Text size="1" color="red">
                {FONT_FAMILY_ERROR}
              </Text>
            )}
          </li>
        ))}
      </ol>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          const family = newFamily.trim();
          if (family) {
            update([...families, family]);
            setNewFamily('');
          }
        }}
      >
        <TextField.Root
          size="1"
          placeholder="Add family"
          aria-label={`Add a family to ${token.name}`}
          value={newFamily}
          onChange={(e) => setNewFamily(e.target.value)}
        />
      </form>
      {families.length > 0 && !hasGeneric && (
        <Text size="1" color="gray">
          Add a generic family, such as sans-serif, as a fallback.
        </Text>
      )}
    </Flex>
  );
};

export default BrandKitStyleGuideSection;
