import { useEffect, useState } from 'react';
import { Progress } from '@radix-ui/themes';

import styles from './Preview.module.css';

const PROGRESS_DELAY_MS = 500;

interface PreviewProgressProps {
  loading: boolean;
}

/**
 * Shows the shared preview loading indicator after a short delay.
 *
 * The delay avoids flashing the indicator for preview updates that complete
 * before the user is meaningfully waiting.
 */
const PreviewProgress: React.FC<PreviewProgressProps> = ({ loading }) => {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    if (!loading) {
      setVisible(false);
      return;
    }

    const timer = window.setTimeout(() => {
      setVisible(true);
    }, PROGRESS_DELAY_MS);
    return () => window.clearTimeout(timer);
  }, [loading]);

  if (!visible) {
    return null;
  }

  return (
    <Progress
      aria-label="Loading Preview"
      className={styles.progress}
      duration="1s"
    />
  );
};

export default PreviewProgress;
