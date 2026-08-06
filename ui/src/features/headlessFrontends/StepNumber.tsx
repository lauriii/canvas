import { Text } from '@radix-ui/themes';

import styles from './HeadlessFrontends.module.css';

const StepNumber = ({ children }: { children: React.ReactNode }) => (
  <Text size="1" weight="medium" className={styles.stepNumber} aria-hidden>
    {children}
  </Text>
);

export default StepNumber;
