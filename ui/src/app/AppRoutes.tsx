import {
  createBrowserRouter,
  Navigate,
  RouterProvider,
} from 'react-router-dom';
import App from '@/app/App';
import { RouteErrorBoundary } from '@/components/error/ErrorBoundary';
import PagePreview from '@/features/pagePreview/PagePreview';
import Editor from '@/features/editor/Editor';
import DummyPropsEditForm from '@/components/DummyPropsEditForm';
import type React from 'react';
import MosaicContainer from '@/features/code-editor/MosaicContainer';
import CodeComponentDialogs from '@/features/code-editor/dialogs/CodeComponentDialogs';
import PrimaryPanel from '@/components/sidebar/PrimaryPanel';

interface AppRoutesInterface {
  basePath: string;
}
const AppRoutes: React.FC<AppRoutesInterface> = ({ basePath }) => {
  const router = createBrowserRouter(
    [
      {
        path: '',
        element: <App />,
        errorElement: <RouteErrorBoundary />,
        children: [
          {
            path: '', // Base path
            element: <Navigate to="/editor" replace />, // Redirect to /editor
          },
          {
            path: '/editor/',
            element: <Editor />,
            children: [
              {
                path: '/editor/region/:regionId/component/:componentId',
                element: <DummyPropsEditForm />,
              },
              {
                path: '/editor/region/:regionId',
                element: <DummyPropsEditForm />,
              },
              {
                path: '/editor/component/:componentId',
                element: <DummyPropsEditForm />,
              },
            ],
          },
          {
            path: '/preview/:width',
            element: <PagePreview />,
          },
          {
            path: '/preview/',
            element: <Navigate to="/preview/full" replace />,
          },
          {
            // Opens the code editor for an item under 'Code'.
            path: '/code-editor/code/:componentId',
            element: (
              <>
                <PrimaryPanel />
                <MosaicContainer />
                <CodeComponentDialogs />
              </>
            ),
          },
          {
            // Opens the code editor for an item under 'Components'.
            path: '/code-editor/component/:componentId',
            element: (
              <>
                <PrimaryPanel />
                <MosaicContainer />
                <CodeComponentDialogs />
              </>
            ),
          },
        ],
      },
    ],
    {
      basename: `${basePath}`,
      future: {
        v7_fetcherPersist: true,
        v7_normalizeFormMethod: true,
        v7_partialHydration: true,
        v7_relativeSplatPath: true,
        v7_skipActionErrorRevalidation: true,
      },
    },
  );

  return <RouterProvider router={router} />;
};

export default AppRoutes;
