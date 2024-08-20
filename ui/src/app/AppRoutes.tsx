import ContextualPanel from '@/components/panel/ContextualPanel';
import { createBrowserRouter, RouterProvider } from 'react-router-dom';
import App from '@/app/App';
import { RouteErrorBoundary } from '@/components/error/ErrorBoundary';

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
            path: '/component/:componentId',
            element: <ContextualPanel />,
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
