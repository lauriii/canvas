import { Flex } from '@radix-ui/themes';

const ErrorPage: React.FC<{ children?: React.ReactNode }> = ({ children }) => (
  <Flex
    data-testid="error-page"
    align="center"
    justify="center"
    height="100vh"
    style={{ backgroundColor: 'var(--canvas-bg)' }}
  >
    {children}
  </Flex>
);

export default ErrorPage;
