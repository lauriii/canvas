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
  const isOpen = openItems.includes(LayoutItemType.AIWIZARD);
  return (
    <Box
      className={styles.aiPanel}
      data-open={!!isOpen}
      data-testid="xb-ai-panel"
    >
      <div data-open={!!isOpen} className={styles.aiPanelContent}>
        {isOpen && <AiWizard />}
      </div>
    </Box>
  );
};

export default AiPanel;
