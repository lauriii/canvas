import ReactDOM from 'react-dom';
import * as React from 'react';
import { Provider } from 'react-redux';
import { createRoot } from 'react-dom/client';
import ConceptProver from "./components/ConceptProver.jsx";

const { drupalSettings } = window
const container = document.createElement('div');
container.id = 'experience-builder-test-extension';

document.body.append(container)
const root = createRoot(container);

// The XB store is available in Drupal settings, making it possible to add it
// to this App via a <Provider>, giving us access to its data and actions.
const {store} = drupalSettings.xb
root.render(
  <Provider store={store}>
    <div style={{
      zIndex: 5000,
      position: 'fixed',
      backgroundColor: '#c0ffee',
      margin: '2rem',
      border: '3px solid black',
      bottom: '2rem',
      padding: '.75rem',
      maxWidth: '1500px',
    }}>
      <ConceptProver />
    </div>
  </Provider>)


