import ContextualPanel from '@/components/panel/ContextualPanel';
import { defer, createBrowserRouter, RouterProvider } from 'react-router-dom';
import App from '@/app/App';

const { drupalSettings } = window as any;

const getHTML = async (componentId: string) => {
  return new Promise((resolve) => {
    setTimeout(() => {
      resolve(
        `This text was loaded async from AppRoutes.tsx when component(${componentId}) was selected.`,
      );
    }, 700);
  });
};

const AppRoutes = () => {
  const router = createBrowserRouter(
    [
      {
        path: '',
        element: <App />,
        children: [
          {
            path: '/component/:componentId',
            element: <ContextualPanel />,
            loader: async ({ params }) => {
              if (!params.componentId) {
                return;
              }
              return defer({
                html: getHTML(params.componentId),
              });
            },
          },
        ],
      },
    ],
    {
      basename: `${drupalSettings.path.baseUrl}xb`,
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
