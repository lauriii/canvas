import {
  Badge,
  Box,
  Button,
  Flex,
  Heading,
  Spinner,
  Text,
} from '@radix-ui/themes';

import { getDisplayNameForNode } from '@/features/layout/layoutUtils';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type { ComponentNode } from '@/features/layout/layoutModelSlice';
import type { PageVersionChange } from '@/features/versionComparison/pageVersionDiff';
import type { ComponentsList } from '@/types/Component';

import styles from './VersionComparisonPage.module.css';

const CHANGE_BADGES = {
  added: { label: 'Added', color: 'green' },
  removed: { label: 'Removed', color: 'red' },
  moved: { label: 'Moved', color: 'blue' },
  props: { label: 'Changed', color: 'amber' },
  field: { label: 'Changed', color: 'amber' },
} as const;

const EmptyDiff = ({ children }: { children: React.ReactNode }) => (
  <Flex align="center" justify="center" p="6">
    <Text color="gray">{children}</Text>
  </Flex>
);

const ValueChange = ({
  name,
  before,
  after,
}: {
  name: string;
  before: string;
  after: string;
}) => (
  <div className={styles.diffValueChange}>
    <Text size="2" weight="medium">
      {name}
    </Text>
    <del className={styles.diffRemoved}>{before || '(empty)'}</del>
    <ins className={styles.diffAdded}>{after || '(empty)'}</ins>
  </div>
);

const ChangeRow = ({
  change,
  componentName,
  onShowChange,
}: {
  change: PageVersionChange;
  componentName: (node: ComponentNode) => string;
  onShowChange?: (change: PageVersionChange) => void;
}) => {
  const badge = CHANGE_BADGES[change.kind];
  if (change.kind === 'field') {
    return (
      <li className={styles.diffChange}>
        <ValueChange
          name={change.label}
          before={change.before}
          after={change.after}
        />
      </li>
    );
  }

  const detail =
    change.kind === 'moved'
      ? `${change.from} → ${change.to}`
      : change.kind === 'props'
        ? null
        : change.parent;

  return (
    <li className={styles.diffChange}>
      <Flex align="center" gap="2">
        <Badge color={badge.color} size="1">
          {badge.label}
        </Badge>
        <Text size="2" weight="medium">
          {componentName(change.node)}
        </Text>
        {detail && (
          <Text size="1" color="gray">
            {detail}
          </Text>
        )}
        {onShowChange && (
          <Button
            variant="ghost"
            size="1"
            ml="auto"
            onClick={() => onShowChange(change)}
          >
            Show in preview
          </Button>
        )}
      </Flex>
      {change.kind === 'props' &&
        change.props.map((prop) => <ValueChange key={prop.name} {...prop} />)}
      {(change.kind === 'added' || change.kind === 'removed') &&
        change.props.map(({ name, value }) => (
          <div key={name} className={styles.diffValue}>
            <Text size="2" weight="medium">
              {name}
            </Text>
            {change.kind === 'added' ? (
              <ins className={styles.diffAdded}>{value}</ins>
            ) : (
              <del className={styles.diffRemoved}>{value}</del>
            )}
          </div>
        ))}
    </li>
  );
};

export const PageVersionChanges = ({
  changes,
  onShowChange,
}: {
  // Undefined while either version is loading.
  changes?: PageVersionChange[];
  onShowChange?: (change: PageVersionChange) => void;
}) => {
  const { data: components } = useGetComponentsQuery();

  if (!changes) {
    return (
      <EmptyDiff>
        <Spinner />
      </EmptyDiff>
    );
  }
  if (!changes.length) {
    return <EmptyDiff>No differences found.</EmptyDiff>;
  }

  const fieldChanges = changes.filter((change) => change.kind === 'field');
  const componentChanges = changes.filter((change) => change.kind !== 'field');
  const componentName = (node: ComponentNode) =>
    getDisplayNameForNode(node, null, components as ComponentsList);

  return (
    <Box className={styles.diffPanel} data-testid="page-version-changes">
      {[
        { title: 'Page data', items: fieldChanges },
        { title: 'Components', items: componentChanges },
      ].map(
        ({ title, items }) =>
          items.length > 0 && (
            <section key={title} className={styles.diffSection}>
              <Heading as="h4" size="2" mb="2">
                {title}
              </Heading>
              <ul className={styles.diffList}>
                {items.map((change, index) => (
                  <ChangeRow
                    key={index}
                    change={change}
                    componentName={componentName}
                    onShowChange={onShowChange}
                  />
                ))}
              </ul>
            </section>
          ),
      )}
    </Box>
  );
};
