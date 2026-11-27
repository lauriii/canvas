import { useMemo } from 'react';
import { ExclamationTriangleIcon } from '@radix-ui/react-icons';
import { Callout, Flex, Link } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { recurseNodes } from '@/features/layout/layoutUtils';
import { degradedEntries, needsAttention } from '@/features/styles/styleUtils';
import {
  useAcceptedStyles,
  useStyleDefinitions,
  useStyleLimits,
} from '@/features/styles/useComponentStyles';
import useComponentSelection from '@/hooks/useComponentSelection';
import useGetComponentName from '@/hooks/useGetComponentName';

import type { ComponentNode } from '@/features/layout/layoutModelSlice';
import type { StyleLimit } from '@/types/Styles';

/** Whether stored styles need attention: some render neither as stored nor
 * through a brand kit fallback. */
function hasProblems(
  styles: ComponentNode['styles'],
  definitions: Parameters<typeof degradedEntries>[1],
  accepted: string[],
  hiddenStyles: Record<string, string>,
  limits: Record<string, StyleLimit>,
): boolean {
  return Object.values(
    degradedEntries(styles, definitions, accepted, hiddenStyles, limits),
  ).some(needsAttention);
}

/** Whether an instance stores styles that no longer render. */
export function useHasDegradedStyles(component: ComponentNode): boolean {
  const { active, definitions, hiddenStyles, isLoading } =
    useStyleDefinitions();
  const acceptedFor = useAcceptedStyles();
  const limitsFor = useStyleLimits();
  if (!active || isLoading || !component.styles) {
    return false;
  }
  return hasProblems(
    component.styles,
    definitions,
    acceptedFor(component),
    hiddenStyles,
    limitsFor(component),
  );
}

const InstanceLink = ({ component }: { component: ComponentNode }) => {
  const name = useGetComponentName(component);
  const { setSelectedComponent } = useComponentSelection();
  return (
    <Link
      size="1"
      href="#"
      onClick={(event) => {
        event.preventDefault();
        setSelectedComponent(component.uuid);
      }}
    >
      {name}
    </Link>
  );
};

/**
 * "2 components use styles that are no longer available", with a link to
 * each affected instance.
 */
const StylesPageNotice = () => {
  const layout = useAppSelector(selectLayout);
  const { active, definitions, hiddenStyles, isLoading } =
    useStyleDefinitions();
  const acceptedFor = useAcceptedStyles();
  const limitsFor = useStyleLimits();
  const affected = useMemo(() => {
    const found: ComponentNode[] = [];
    if (!active || isLoading) return found;
    for (const region of layout) {
      recurseNodes(region, (node: ComponentNode) => {
        if (
          node.styles &&
          hasProblems(
            node.styles,
            definitions,
            acceptedFor(node),
            hiddenStyles,
            limitsFor(node),
          )
        ) {
          found.push(node);
        }
      });
    }
    return found;
  }, [
    active,
    isLoading,
    layout,
    definitions,
    acceptedFor,
    hiddenStyles,
    limitsFor,
  ]);

  if (affected.length === 0) {
    return null;
  }
  return (
    <Callout.Root
      size="1"
      color="amber"
      variant="surface"
      mx="4"
      mb="2"
      role="status"
      data-testid="canvas-styles-page-notice"
    >
      <Callout.Icon>
        <ExclamationTriangleIcon />
      </Callout.Icon>
      <Flex direction="column" gap="1">
        <Callout.Text>
          {affected.length}{' '}
          {affected.length === 1 ? 'component uses' : 'components use'} styles
          that are no longer available.
        </Callout.Text>
        <Flex gap="2" wrap="wrap">
          {affected.map((node) => (
            <InstanceLink key={node.uuid} component={node} />
          ))}
        </Flex>
      </Flex>
    </Callout.Root>
  );
};

export default StylesPageNotice;
