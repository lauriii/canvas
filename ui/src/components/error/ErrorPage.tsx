import { Flex } from '@radix-ui/themes';

const ErrorPage: React.FC<{ children?: React.ReactNode }> = ({ children }) => (
  <Flex
    data-testid="xb-error-page"
    align="center"
    justify="center"
    height="100vh"
    width="100%"
    style={{ backgroundColor: 'var(--canvas-bg)' }}
  >
    {children}
  </Flex>
);

export default ErrorPage;
