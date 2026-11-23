import { Box } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import { selectAiPanelOpen } from '@/features/ui/primaryPanelSlice';
import { getCanvasSettings } from '@/utils/drupal-globals';

import AiWizard from './AiWizard';
import AiWizardDev from './AiWizardDev';

import styles from './AiPanel.module.css';

interface AiPanelProps {}

const AiPanel: React.FC<AiPanelProps> = () => {
  // @todo Rename AiWizard to AiWizardLegacy and render it on an aiLegacyMode flag set by canvas_ai_legacy, rename AiWizardDev to AiWizard, and set aiDevMode only from canvas_dev_ai, see https://git.drupalcode.org/project/canvas/-/work_items/3592374
  const isOpen = useAppSelector(selectAiPanelOpen);
  return (
    <Box
      className={styles.aiPanel}
      data-open={isOpen}
      data-testid="canvas-ai-panel"
    >
      <div data-open={isOpen} className={styles.aiPanelContent}>
        {isOpen &&
          (getCanvasSettings()?.aiDevMode ? <AiWizardDev /> : <AiWizard />)}
      </div>
    </Box>
  );
};

export default AiPanel;
