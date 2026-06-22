import { ExclamationTriangleIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Text } from '@radix-ui/themes';

import styles from './ConflictBanner.module.css';

interface ConflictBannerProps {
  conflictCount: number;
  onResolveClick: () => void;
  disabled?: boolean;
}

const ConflictBanner = ({
  conflictCount,
  onResolveClick,
  disabled = false,
}: ConflictBannerProps) => {
  if (conflictCount === 0) return null;

  return (
    <Box className={styles.conflictBanner} data-testid="conflict-banner">
      <Flex direction="column" gap="2">
        <Flex align="center" gap="2">
          <ExclamationTriangleIcon className={styles.warningIcon} />
          <Text size="2" weight="bold" className={styles.conflictText}>
            {conflictCount} conflict{conflictCount !== 1 ? 's' : ''} to resolve
          </Text>
        </Flex>
        <Text size="1" className={styles.conflictSubtext}>
          Review and resolve before publishing.
        </Text>
        <Button
          variant="soft"
          className={styles.resolveButton}
          onClick={onResolveClick}
          disabled={disabled}
          data-testid="resolve-conflicts-button"
        >
          Resolve {conflictCount} conflict{conflictCount !== 1 ? 's' : ''}
        </Button>
      </Flex>
    </Box>
  );
};

export default ConflictBanner;
