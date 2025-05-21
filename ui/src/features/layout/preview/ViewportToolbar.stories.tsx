import type { Meta, StoryObj } from '@storybook/react';
import ViewportToolbar from './ViewportToolbar';
import { Provider } from 'react-redux';
import { configureStore } from '@reduxjs/toolkit';
import { uiSliceReducer } from '@/features/ui/uiSlice';
import { useRef } from 'react';

const store = configureStore({
  reducer: {
    ui: uiSliceReducer,
  },
});

const ViewportToolbarWithRefs = (args: any) => {
  const canvasPaneRef = useRef<HTMLDivElement>(null);
  const scalingContainerRef = useRef<HTMLDivElement>(null);

  return (
    <Provider store={store}>
      <div style={{ width: '1000px' }}>
        <div style={{ padding: '20px' }}>
          <ViewportToolbar
            canvasPaneRef={canvasPaneRef}
            scalingContainerRef={scalingContainerRef}
          />
          <div
            ref={canvasPaneRef}
            style={{ width: '100%', height: '500px', overflow: 'auto' }}
          >
            <div
              ref={scalingContainerRef}
              style={{
                width: '100%',
                height: '300px',
                background: 'peachpuff',
              }}
            ></div>
          </div>
        </div>
      </div>
    </Provider>
  );
};

const meta: Meta<typeof ViewportToolbar> = {
  title: 'Components/ViewportToolbar',
  component: ViewportToolbarWithRefs,
  parameters: {
    layout: 'centered',
  },
};

export default meta;

type Story = StoryObj<typeof ViewportToolbar>;

export const Default: Story = {};
