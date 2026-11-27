import { useCallback, useMemo, useRef, useState } from 'react';
import {
  ExclamationTriangleIcon,
  InfoCircledIcon,
} from '@radix-ui/react-icons';
import { Button, Callout, Flex, Select, Text } from '@radix-ui/themes';

import StyleControl from '@/features/styles/StyleControl';
import { useStyleDecorations } from '@/features/styles/styleDecorations';
import { useStylePartFocus } from '@/features/styles/stylePartFocus';
import { useStyleElements } from '@/features/styles/stylePreview';
import {
  groupByCategory,
  isCustomValue,
  PART_SEPARATOR,
  partOf,
  styleIdOf,
} from '@/features/styles/styleUtils';
import {
  useComponentStyles,
  useSetStyles,
  useStyleTranslationLock,
} from '@/features/styles/useComponentStyles';

import type { StyleSelection } from '@/types/Styles';

import styles from './StyleTab.module.css';

interface StyleTabProps {
  uuid: string;
}

/**
 * The Style tab: one control per accepted style, grouped by category, with
 * notices and recovery for styles that are no longer available.
 */
const StyleTab = ({ uuid }: StyleTabProps) => {
  const {
    component,
    definitions,
    hiddenStyles,
    limits,
    canUseCustom,
    accepted,
    parts,
    selection,
    degraded,
    canEdit,
  } = useComponentStyles(uuid);
  const { rows } = useStyleDecorations(uuid);
  const setStyles = useSetStyles();
  const elementsFor = useStyleElements();
  const [focusedPart, setFocusedPart] = useState<string | null>(null);
  const outlined = useRef<Array<[HTMLElement, string]>>([]);

  // Hovering a part's group outlines that part on the page.
  const outline = (part: string | null) => {
    outlined.current.forEach(([element, previous]) =>
      element.style.setProperty('outline', previous),
    );
    outlined.current = [];
    if (part === null) return;
    for (const element of elementsFor(uuid, `${part}${PART_SEPARATOR}`)) {
      outlined.current.push([
        element,
        element.style.getPropertyValue('outline'),
      ]);
      element.style.setProperty('outline', '2px dashed rgb(0 144 255 / 0.8)');
    }
  };

  // A part chosen from the preview: scroll to its group and flash it.
  useStylePartFocus(
    uuid,
    useCallback((part: string) => {
      setFocusedPart(part);
      requestAnimationFrame(() =>
        document
          .querySelector(
            `[data-testid="canvas-style-part-${CSS.escape(part)}"]`,
          )
          ?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }),
      );
      setTimeout(() => setFocusedPart(null), 1200);
    }, []),
  );
  const lockedToLanguage = useStyleTranslationLock();
  const readOnly = !canEdit || lockedToLanguage !== null;

  // The component's own styles; parts are listed after them.
  const offered = useMemo(
    () =>
      accepted
        .filter((key) => partOf(key) === undefined)
        .map((id) => definitions[id])
        .filter(Boolean),
    [accepted, definitions],
  );
  const partGroups = useMemo(
    () =>
      Object.entries(parts)
        .map(([part, { label, styles: partStyles }]) => ({
          part,
          label,
          definitions: partStyles
            .map((styleId) => definitions[styleId])
            .filter(
              (definition) =>
                definition !== undefined &&
                degraded[`${part}${PART_SEPARATOR}${definition.id}`] ===
                  undefined,
            ),
        }))
        .filter(
          ({ definitions: partDefinitions }) => partDefinitions.length > 0,
        ),
    [parts, definitions, degraded],
  );
  const groups = useMemo(
    () =>
      groupByCategory(
        offered.filter((definition) => degraded[definition.id] === undefined),
      ),
    [offered, degraded],
  );

  if (!component) {
    return null;
  }

  const update = (styleId: string, optionId: string | undefined) => {
    const next: StyleSelection = { ...selection };
    if (optionId === undefined) {
      delete next[styleId];
    } else {
      next[styleId] = optionId;
    }
    // Rapid changes to one style merge into one undo step, but clearing is
    // always its own step: merged with the change before it, Undo would
    // seem to do nothing.
    setStyles(
      [{ uuid, styles: next }],
      optionId === undefined ? undefined : `${uuid}:${styleId}`,
    );
  };

  const degradedIds = Object.keys(degraded);

  if (
    offered.length === 0 &&
    partGroups.length === 0 &&
    degradedIds.length === 0
  ) {
    return (
      <Text
        as="p"
        size="1"
        color="gray"
        my="4"
        data-testid="canvas-style-tab-empty"
      >
        This component has no style options.
      </Text>
    );
  }

  return (
    <Flex direction="column" gap="3" my="3" data-testid="canvas-style-tab">
      {lockedToLanguage && (
        <Callout.Root size="1" color="gray" variant="surface">
          <Callout.Icon>
            <InfoCircledIcon />
          </Callout.Icon>
          <Callout.Text>
            Style is shared with all languages. Change it in {lockedToLanguage}.
          </Callout.Text>
        </Callout.Root>
      )}
      {!canEdit && !lockedToLanguage && (
        <Text size="1" color="gray">
          You don&apos;t have permission to change styles.
        </Text>
      )}

      {degradedIds.map((styleId) => {
        const reason = degraded[styleId];
        const bareId = styleIdOf(styleId);
        const part = partOf(styleId);
        const definition = definitions[bareId];
        const styleLabel = definition?.label ?? hiddenStyles[bareId] ?? bareId;
        const label =
          part === undefined
            ? styleLabel
            : `${parts[part]?.label ?? part} › ${styleLabel}`;
        const optionId = selection[styleId];
        const optionLabel =
          definition?.options.find((o) => o.id === optionId)?.label ??
          definition?.notOffered[optionId] ??
          (isCustomValue(optionId) ? optionId.slice(1) : optionId);
        // When a component moves a style to one of its parts, the value can
        // follow: offer the part that now accepts it.
        const movableTo =
          reason === 'not_accepted'
            ? Object.entries(parts).find(
                ([candidate, { styles: partStyles }]) =>
                  candidate !== part &&
                  partStyles.includes(bareId) &&
                  selection[`${candidate}${PART_SEPARATOR}${bareId}`] ===
                    undefined,
              )
            : undefined;

        // Rendering through the brand kit's fallback is intended: explain it,
        // and offer to store the option that is showing.
        if (reason === 'fallback' && definition) {
          const fallbackId = definition.fallbacks[optionId];
          const fallbackLabel =
            definition.options.find((o) => o.id === fallbackId)?.label ??
            fallbackId;
          return (
            <Callout.Root
              key={styleId}
              size="1"
              color="gray"
              variant="surface"
              data-testid={`canvas-style-fallback-${styleId}`}
            >
              <Callout.Icon>
                <InfoCircledIcon />
              </Callout.Icon>
              <Flex direction="column" gap="2">
                <Callout.Text>
                  {label}: {optionLabel} isn&apos;t offered on this site.
                  Showing {fallbackLabel}.
                </Callout.Text>
                {!readOnly && (
                  <Flex gap="2" align="center">
                    <Button
                      size="1"
                      variant="soft"
                      onClick={() => update(styleId, fallbackId)}
                    >
                      Keep {fallbackLabel}
                    </Button>
                    <Button
                      size="1"
                      variant="soft"
                      color="gray"
                      onClick={() => update(styleId, undefined)}
                    >
                      Remove
                    </Button>
                  </Flex>
                )}
              </Flex>
            </Callout.Root>
          );
        }

        const message = {
          not_accepted: 'No longer supported by this component.',
          not_offered: 'Not offered on this site.',
          unavailable: 'No longer available in your theme.',
          not_allowed_here: 'Not available on this component.',
          invalid: 'This custom value is outside what the style allows.',
          fallback: '',
        }[reason];
        const canReplace =
          definition &&
          (reason === 'unavailable' ||
            reason === 'not_offered' ||
            reason === 'not_allowed_here' ||
            reason === 'invalid');
        return (
          <Callout.Root
            key={styleId}
            size="1"
            color="amber"
            variant="surface"
            data-testid={`canvas-style-degraded-${styleId}`}
          >
            <Callout.Icon>
              <ExclamationTriangleIcon />
            </Callout.Icon>
            <Flex direction="column" gap="2">
              <Callout.Text>
                {label}: {optionLabel}. {message}
              </Callout.Text>
              {!readOnly && (
                <Flex gap="2" align="center">
                  {movableTo && (
                    <Button
                      size="1"
                      variant="soft"
                      onClick={() => {
                        const next: StyleSelection = { ...selection };
                        delete next[styleId];
                        next[`${movableTo[0]}${PART_SEPARATOR}${bareId}`] =
                          optionId;
                        setStyles([{ uuid, styles: next }]);
                      }}
                    >
                      Move to {movableTo[1].label}
                    </Button>
                  )}
                  {canReplace && (
                    <Select.Root
                      size="1"
                      onValueChange={(next) => update(styleId, next)}
                    >
                      <Select.Trigger
                        placeholder="Replace"
                        aria-label={`Replace ${label}`}
                      />
                      <Select.Content>
                        {definition.options.map((candidate) => (
                          <Select.Item key={candidate.id} value={candidate.id}>
                            {candidate.label}
                          </Select.Item>
                        ))}
                      </Select.Content>
                    </Select.Root>
                  )}
                  <Button
                    size="1"
                    variant="soft"
                    color="gray"
                    onClick={() => update(styleId, undefined)}
                  >
                    Remove
                  </Button>
                </Flex>
              )}
            </Flex>
          </Callout.Root>
        );
      })}

      <div>
        {/* As in Framer and Figma, every section is open: a component
            accepts only the styles it opted into, so the list stays short. */}
        {groups.map(({ category, styles: categoryStyles }) => (
          <section
            key={category}
            className={styles.section}
            aria-label={category}
            data-testid={`canvas-style-category-${category}`}
          >
            <Text
              as="div"
              size="1"
              weight="medium"
              className={styles.sectionTitle}
            >
              {category}
            </Text>
            {categoryStyles.map((definition) => (
              <StyleControl
                key={definition.id}
                uuid={uuid}
                definition={definition}
                value={selection[definition.id]}
                readOnly={readOnly}
                decoration={rows[definition.id]}
                limit={limits[definition.id]}
                canUseCustom={canUseCustom}
                onChange={(optionId) => update(definition.id, optionId)}
              />
            ))}
          </section>
        ))}
        {/* Each named part is its own group, under the part's label: the
            elements inside the component, after the component itself. */}
        {partGroups.map(({ part, label, definitions: partDefinitions }) => (
          <section
            key={part}
            className={`${styles.section}${focusedPart === part ? ` ${styles.sectionFocused}` : ''}`}
            aria-label={label}
            data-testid={`canvas-style-part-${part}`}
            onPointerEnter={() => outline(part)}
            onPointerLeave={() => outline(null)}
          >
            <Text
              as="div"
              size="1"
              weight="medium"
              className={styles.sectionTitle}
            >
              <span className={styles.partIcon} aria-hidden="true" />
              {label}
            </Text>
            {partDefinitions.map((definition) => {
              const key = `${part}${PART_SEPARATOR}${definition.id}`;
              return (
                <StyleControl
                  key={key}
                  uuid={uuid}
                  selectionKey={key}
                  partLabel={label}
                  definition={definition}
                  value={selection[key]}
                  readOnly={readOnly}
                  decoration={rows[key]}
                  limit={limits[key]}
                  canUseCustom={canUseCustom}
                  onChange={(optionId) => update(key, optionId)}
                />
              );
            })}
          </section>
        ))}
      </div>
    </Flex>
  );
};

export default StyleTab;
