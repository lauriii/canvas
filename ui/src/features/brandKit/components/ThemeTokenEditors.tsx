import { useEffect, useState } from 'react';
import { ArrowDownIcon, ArrowUpIcon, Cross2Icon } from '@radix-ui/react-icons';
import {
  Flex,
  IconButton,
  RadioGroup,
  Select,
  Text,
  TextField,
} from '@radix-ui/themes';

import {
  convertDimension,
  FONT_FAMILY_ERROR,
  FONT_WEIGHTS,
  hasUploadedWeight,
  hexToColorValue,
  isValidFontFamily,
  isValidFontWeight,
  parseNumberInput,
  tokenValueToCss,
} from '@/features/brandKit/themeTokens';
import { getColorHex } from '@/utils/brandKitColor';

import type {
  BrandKitColorValue,
  BrandKitFont,
  BrandKitThemeToken,
  ThemeTokenDimension,
  ThemeTokenTypography,
} from '@/types/CodeComponent';

/**
 * Editors for each theme token type.
 *
 * Every editor keeps invalid input in its field with an inline error, and
 * calls `onChange` only with a valid value in stored form, so the previews keep
 * the last valid value and nothing invalid is staged.
 */

const GENERIC_FAMILY =
  /^(serif|sans-serif|monospace|cursive|fantasy|system-ui|ui-serif|ui-sans-serif|ui-monospace|ui-rounded|math|emoji|fangsong)$/;

const FieldError = ({ children }: { children: string }) => (
  <Text size="1" color="red" role="alert">
    {children}
  </Text>
);

export const ColorEditor = ({
  label,
  value,
  onChange,
}: {
  label: string;
  value: BrandKitColorValue | null;
  onChange: (value: BrandKitColorValue) => void;
}) => {
  const hex = value ? getColorHex(value) : '#000000';
  const [input, setInput] = useState(hex);
  useEffect(() => setInput(hex), [hex]);
  const parsed = hexToColorValue(input);

  const update = (next: string) => {
    setInput(next);
    const color = hexToColorValue(next);
    if (color) {
      onChange(color);
    }
  };

  return (
    <Flex direction="column" gap="1">
      <Flex align="center" gap="2">
        <input
          type="color"
          aria-label={`${label} color`}
          value={parsed?.hex ?? hex}
          onChange={(e) => update(e.target.value)}
          style={{ width: 28, height: 28, padding: 0, border: 'none' }}
        />
        <TextField.Root
          size="1"
          aria-label={`${label} hex value`}
          aria-invalid={!parsed}
          value={input}
          onChange={(e) => update(e.target.value)}
        />
      </Flex>
      {!parsed && <FieldError>Enter a hex color, such as #0055aa.</FieldError>}
    </Flex>
  );
};

/**
 * An ordered list of font families. Every declared entry is shown, and one
 * Canvas cannot save must be removed or replaced before the draft saves.
 */
export const FontStackEditor = ({
  label,
  value,
  onChange,
  presets = [],
}: {
  label: string;
  value: string[];
  onChange: (value: string[]) => void;
  /** Theme font stacks offered as starting points (typography). */
  presets?: BrandKitThemeToken[];
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

  return (
    <Flex direction="column" gap="1" aria-label={`${label} font stack`}>
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
              <FieldError>{FONT_FAMILY_ERROR}</FieldError>
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
          aria-label={`Add a family to ${label}`}
          value={newFamily}
          onChange={(e) => setNewFamily(e.target.value)}
        />
      </form>
      {presets.length > 0 && (
        <Flex direction="column" gap="1">
          <Select.Root
            size="1"
            value=""
            onValueChange={(path) => {
              const preset = presets.find((p) => p.path === path);
              const stack = preset?.draft?.value ?? preset?.value;
              if (Array.isArray(stack)) {
                update(stack as string[]);
              }
            }}
          >
            <Select.Trigger
              placeholder="Use a theme font stack"
              aria-label={`Use a theme font stack for ${label}`}
            />
            <Select.Content>
              {presets.map((preset) => (
                <Select.Item key={preset.path} value={preset.path}>
                  {preset.name}
                </Select.Item>
              ))}
            </Select.Content>
          </Select.Root>
          <Text size="1" color="gray">
            Copies this font stack. Changing the font later won&apos;t update
            this style.
          </Text>
        </Flex>
      )}
      {families.length > 0 && !families.some((f) => GENERIC_FAMILY.test(f)) && (
        <Text size="1" color="gray">
          Add a generic family, such as sans-serif, as a fallback.
        </Text>
      )}
    </Flex>
  );
};

const CUSTOM_WEIGHT = 'custom';

/**
 * Named weights, plus "Custom" for any whole weight from 1 to 1000.
 *
 * When `family` is an uploaded Brand Kit font, weights without a file are
 * marked, because browsers then imitate them.
 */
export const FontWeightEditor = ({
  label,
  value,
  onChange,
  fonts,
  family,
}: {
  label: string;
  value: number;
  onChange: (value: number) => void;
  fonts?: BrandKitFont[] | null;
  family?: string;
}) => {
  const named = FONT_WEIGHTS.some((w) => w.value === value);
  const [custom, setCustom] = useState(!named);
  const [input, setInput] = useState(String(value));
  useEffect(() => setInput(String(value)), [value]);
  const parsed = parseNumberInput(input);
  const valid = parsed !== null && isValidFontWeight(parsed);
  const missing = (weight: number) =>
    hasUploadedWeight(fonts, family, weight) === false;

  return (
    <Flex direction="column" gap="1">
      <Select.Root
        size="1"
        value={custom ? CUSTOM_WEIGHT : String(value)}
        onValueChange={(next) => {
          if (next === CUSTOM_WEIGHT) {
            setCustom(true);
            return;
          }
          setCustom(false);
          onChange(Number(next));
        }}
      >
        <Select.Trigger aria-label={`${label} weight`} />
        <Select.Content>
          {FONT_WEIGHTS.map((weight) => (
            <Select.Item key={weight.value} value={String(weight.value)}>
              {weight.label} {weight.value}
              {missing(weight.value)
                ? ' (Not uploaded. Browsers will imitate it.)'
                : ''}
            </Select.Item>
          ))}
          <Select.Item value={CUSTOM_WEIGHT}>Custom</Select.Item>
        </Select.Content>
      </Select.Root>
      {custom && (
        <TextField.Root
          size="1"
          inputMode="numeric"
          aria-label={`${label} custom weight`}
          aria-invalid={!valid}
          value={input}
          onChange={(e) => {
            setInput(e.target.value);
            const next = parseNumberInput(e.target.value);
            if (next !== null && isValidFontWeight(next)) {
              onChange(next);
            }
          }}
        />
      )}
      {custom && !valid && (
        <FieldError>Enter a whole number from 1 to 1000.</FieldError>
      )}
      {!custom && missing(value) && (
        <Text size="1" color="gray">
          Not uploaded. Browsers will imitate it.
        </Text>
      )}
    </Flex>
  );
};

export const NumberEditor = ({
  label,
  value,
  onChange,
}: {
  label: string;
  value: number;
  onChange: (value: number) => void;
}) => {
  const [input, setInput] = useState(String(value));
  useEffect(() => setInput(String(value)), [value]);
  const valid = parseNumberInput(input) !== null;
  return (
    <Flex direction="column" gap="1">
      <TextField.Root
        size="1"
        inputMode="decimal"
        aria-label={label}
        aria-invalid={!valid}
        value={input}
        onChange={(e) => {
          setInput(e.target.value);
          const next = parseNumberInput(e.target.value);
          if (next !== null) {
            onChange(next);
          }
        }}
      />
      {!valid && <FieldError>Enter a number.</FieldError>}
    </Flex>
  );
};

/** A number and a px or rem unit. Switching the unit converts the number. */
export const DimensionEditor = ({
  label,
  value,
  onChange,
}: {
  label: string;
  value: ThemeTokenDimension;
  onChange: (value: ThemeTokenDimension) => void;
}) => {
  const [converted, setConverted] = useState(false);
  return (
    <Flex direction="column" gap="1">
      <Flex align="center" gap="2">
        <NumberEditor
          label={`${label} value`}
          value={value.value}
          onChange={(number) => onChange({ value: number, unit: value.unit })}
        />
        <RadioGroup.Root
          size="1"
          value={value.unit}
          aria-label={`${label} unit`}
          onValueChange={(unit) => {
            setConverted(true);
            onChange(
              convertDimension(value, unit as ThemeTokenDimension['unit']),
            );
          }}
        >
          <Flex gap="2">
            <RadioGroup.Item value="px">px</RadioGroup.Item>
            <RadioGroup.Item value="rem">rem</RadioGroup.Item>
          </Flex>
        </RadioGroup.Root>
      </Flex>
      {converted && (
        <Text size="1" color="gray">
          Converted at 16px per rem.
        </Text>
      )}
    </Flex>
  );
};

/**
 * All five typography properties. Letter spacing is kept, read-only: core's
 * `font` shorthand does not output it.
 */
export const TypographyEditor = ({
  token,
  value,
  onChange,
  fontStacks,
  fonts,
}: {
  token: BrandKitThemeToken;
  value: ThemeTokenTypography;
  onChange: (value: ThemeTokenTypography) => void;
  fontStacks: BrandKitThemeToken[];
  fonts?: BrandKitFont[] | null;
}) => {
  const set = <K extends keyof ThemeTokenTypography>(
    key: K,
    next: ThemeTokenTypography[K],
  ) => onChange({ ...value, [key]: next });
  const field = (title: string, editor: React.ReactNode) => (
    <Flex direction="column" gap="1">
      <Text size="1" weight="medium">
        {title}
      </Text>
      {editor}
    </Flex>
  );

  return (
    <Flex direction="column" gap="2" aria-label={`${token.name} typography`}>
      {field(
        'Font family',
        <FontStackEditor
          label={`${token.name} family`}
          value={value.fontFamily}
          onChange={(families) => set('fontFamily', families)}
          presets={fontStacks}
        />,
      )}
      {field(
        'Size',
        <DimensionEditor
          label={`${token.name} size`}
          value={value.fontSize}
          onChange={(size) => set('fontSize', size)}
        />,
      )}
      {field(
        'Weight',
        <FontWeightEditor
          label={token.name}
          value={value.fontWeight}
          onChange={(weight) => set('fontWeight', weight)}
          fonts={fonts}
          family={value.fontFamily[0]}
        />,
      )}
      {field(
        'Line height',
        <NumberEditor
          label={`${token.name} line height`}
          value={value.lineHeight}
          onChange={(lineHeight) => set('lineHeight', lineHeight)}
        />,
      )}
      {field(
        'Letter spacing',
        <Text size="1" color="gray">
          {tokenValueToCss('dimension', value.letterSpacing)} (Not output yet)
        </Text>,
      )}
      <Text size="1" color="gray">
        Use it as <code>font: var({token.cssVariable})</code>. The font
        shorthand resets the other font properties.
      </Text>
    </Flex>
  );
};
