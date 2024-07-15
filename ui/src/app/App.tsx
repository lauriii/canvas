import styles from './App.module.css';
import Layout from '@/features/layout/Layout';
import { Button, Flex, Card, Select, Text } from '@radix-ui/themes';
import clsx from 'clsx';
import ContextualPanel from '@/components/panel/ContextualPanel';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCanvasViewPort,
  selectContextualPanelOpen,
  setCanvasViewPort,
  scaleValues,
} from '@/features/ui/uiSlice';
import Canvas from '@/features/canvas/Canvas';
import { ZoomInIcon } from '@radix-ui/react-icons';
import PrimaryMenubar from '@/components/sidebar/primary/PrimaryMenubar';
import Topbar from '@/components/topbar/Topbar';

const App = () => {
  const dispatch = useAppDispatch();
  const contextualPanelOpen = useAppSelector(selectContextualPanelOpen);
  const canvasViewPort = useAppSelector(selectCanvasViewPort);

  return (
    <div
      className={clsx(styles.app, {
        [styles.rightSideBarOpen]: contextualPanelOpen,
      })}
    >
      <Canvas />
      <Layout />
      <Topbar />
      <PrimaryMenubar />
      <ContextualPanel />
      <div className={styles.canvasControls}>
        <Card size="1">
          <Flex align="center" gap="3">
            <Text size="1">x: {Math.round(canvasViewPort.x)}px, </Text>
            <Text size="1">y: {Math.round(canvasViewPort.y)}px</Text>
            <Select.Root
              defaultValue="100%"
              value={
                scaleValues.find((sv) => sv.scale === canvasViewPort.scale)
                  ?.percent
              }
              onValueChange={(value) =>
                dispatch(
                  setCanvasViewPort({
                    scale: scaleValues.find((sv) => value === sv.percent)
                      ?.scale,
                  }),
                )
              }
            >
              <Select.Trigger variant="ghost">
                <Flex as="span" align="center" gap="2">
                  <ZoomInIcon />
                  {
                    scaleValues.find((sv) => sv.scale === canvasViewPort.scale)
                      ?.percent
                  }
                </Flex>
              </Select.Trigger>
              <Select.Content>
                {scaleValues.map((sv) => (
                  <Select.Item key={sv.scale} value={sv.percent}>
                    {sv.percent}
                  </Select.Item>
                ))}
              </Select.Content>
            </Select.Root>
            <Button
              onClick={() =>
                dispatch(setCanvasViewPort({ x: 4000, y: 4500, scale: 1 }))
              }
            >
              Debug: scroll to middle
            </Button>
          </Flex>
        </Card>
      </div>
      <div id="menuBarContainer" className="menuBarContainer"></div>
    </div>
  );
};

export default App;
