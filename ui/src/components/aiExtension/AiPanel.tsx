import { Box } from '@radix-ui/themes';
import AiWizard from './AiWizard';
import styles from './AiPanel.module.css';

import {
  selectOpenLayoutItems,
  LayoutItemType,
} from '@/features/ui/primaryPanelSlice';
import { useAppSelector } from '@/app/hooks';

interface AiPanelProps {}

const AiPanel: React.FC<AiPanelProps> = () => {
  const openItems = useAppSelector(selectOpenLayoutItems);
  if (!openItems.includes(LayoutItemType.AIWIZARD)) return null;

  return (
    <Box className={styles.aiPanel} data-testid="xb-ai-panel">
      <AiWizard />
    </Box>
  );
};

export default AiPanel;
