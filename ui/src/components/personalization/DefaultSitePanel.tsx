import { Card, Flex, Text } from '@radix-ui/themes';

import styles from './DefaultSitePanel.module.css';

const DefaultSitePanel = () => {
  return (
    <Card className={styles.defaultCard}>
      <Flex p="8" direction="column" gap="1" align="center">
        <Text size="2" weight="bold">
          Default site
        </Text>
        <Text size="1" color="gray" align="center">
          The site as it appears when no personalization rules apply. Shown to
          visitors who match no other segment.
        </Text>
      </Flex>
    </Card>
  );
};

export default DefaultSitePanel;
