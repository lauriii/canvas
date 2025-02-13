import { Provider } from 'react-redux';
import Preview from '@/features/code-editor/Preview';
import { makeStore } from '@/app/store';

describe('<Preview /> for code editor', () => {
  it('renders simple JS and CSS in the preview iframe', () => {
    // Mock JavaScript and CSS in the Redux store
    const store = makeStore({
      codeEditor: {
        js: `
        export default function MyComponent({ title, initialCount, isVisible, additionalContent }) {
          if (!isVisible) {
            return null;
          }
          return <div id="hello">{ title } { initialCount + 1 } { additionalContent }</div>;
        }
      `,
        css: `
        #hello {
          color: blue;
          font-size: 24px;
        }
      `,
        props: [
          {
            name: 'Title',
            type: 'string',
            example: 'Hello World',
          },
          {
            name: 'Initial count',
            type: 'number',
            example: 1,
          },
          {
            name: 'Is visible',
            type: 'boolean',
            example: true,
          },
        ],
        slots: [
          {
            name: 'Additional content',
            example: '<span>!</span>',
          },
        ],
      },
    });
    cy.mount(
      <Provider store={store}>
        <Preview />
      </Provider>,
    );
    cy.waitForElementInIframe(
      '#hello',
      '[data-xb-iframe="xb-code-editor-preview"]',
    );
    cy.testInIframe(
      '#hello',
      (el) => {
        const computedStyle = window.getComputedStyle(el);
        expect(el.textContent).to.equal('Hello World 2 !');
        expect(computedStyle.fontSize).to.equal('24px');
        expect(computedStyle.color).to.equal('rgb(0, 0, 255)');
      },
      '[data-xb-iframe="xb-code-editor-preview"]',
    );
  });
});
