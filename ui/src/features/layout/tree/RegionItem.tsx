import { CubeIcon } from '@radix-ui/react-icons';
import { Box, Flex, Text } from '@radix-ui/themes';
import clsx from 'clsx';
import styles from './TreeItem.module.css';
const RegionItem: React.FC<{ region: string }> = ({ region }) => {
  return (
    <div>
      <Flex>
        <Box>
          <div className={clsx(styles.inline)}>
            <CubeIcon
              className={clsx(styles.icon, 'icon', styles.regionItem)}
            />
            <Text size="1">{region}</Text>
          </div>
        </Box>
      </Flex>
    </div>
  );
};

export default RegionItem;
