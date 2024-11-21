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
