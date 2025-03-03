import { useSelector } from 'react-redux';
import ReactDOM from 'react-dom';
import ConceptProver from './ConceptProver';
import { useState, useEffect } from 'react';

const EXTENSION_ID = 'experience-builder-test-extension';
const { drupalSettings } = window;
drupalSettings.xbExtension.testExtension.component = ConceptProver;

const ExampleExtension = () => {
  const [portalRoot, setPortalRoot] = useState(null);

  // Get the currently active extension from the XB React app's Redux store.
  const activeExtension = useSelector(
    (state) => state.extensions.activeExtension,
  );

  useEffect(() => {
    if (activeExtension?.id) {
      // Wait for a tick here to ensure the div in the extension modal has been rendered so we can portal
      // our extension into it.
      requestAnimationFrame(() => {
        const targetDiv = document.querySelector(
          `#extensionPortalContainer.xb-extension-${activeExtension.id}`,
        );
        if (targetDiv) {
          setPortalRoot(targetDiv);
        }
      });
    }
  }, [activeExtension]);

  // We don't want to render anything if the Extension is not active in the XB app.
  if (activeExtension?.id !== EXTENSION_ID || !portalRoot) {
    return null;
  }

  // This step isn't really necessary in this file, but it demonstrates we can
  // add the entry point component to drupalSettings, which should make it
  // possible to eventually manage most of this in the UI app, with the
  // extension still adding the component to drupalSettings.
  const ExtensionComponent = drupalSettings.xbExtension.testExtension.component;
  return ReactDOM.createPortal(<ExtensionComponent />, portalRoot);
};

export default ExampleExtension;
