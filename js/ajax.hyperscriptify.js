(function (Drupal) {
  /**
   * This is for hyperscriptifying elements added to the DOM via Drupal AJAX.
   *
   * @see /ui/src/local_packages/hyperscriptify/
   *
   * @type {{attach(*, *): void}}
   */
  Drupal.behaviors.jsxAjaxProcess = {
    attach(context, settings) {

      /**
       * Checks if Drupal AJAX operations are in progress.
       *
       * @return {boolean}
       *  True if Drupal AJAX operations are in progress.
       */
      const isAjaxing = () =>
        Drupal.ajax.instances.some((instance) => instance && instance.ajaxing === true)

      /**
       * Attaches behaviors to a context once all
       *
       * @param {Element} theContext
       *   The element to send through behaviors.
       * @param {object} theSettings
       *   Additional settings.
       * @param {number} timeout
       *   Duration of setTimeout before attachBehaviors is called.
       */
      const attachBehaviorsAfterAjaxing = (theContext, theSettings, timeout = 0) => {
        // If no AJAX operations are taking place, behaviors will be attached at
        // the end of the stack.
        if (!isAjaxing()) {
          setTimeout(() => {
            Drupal.attachBehaviors(theContext, {...theSettings, doNotReinvoke: true});
          }, timeout)
        } else {
          // If AJAX operations are occurring, set up an interval that will run
          // until AJAX operations have stopped, after which behaviors are
          // attached and the interval cleared.
          const interval = setInterval(() => {
            if (!isAjaxing()) {
              setTimeout(() => {
                Drupal.attachBehaviors(theContext, {...theSettings, doNotReinvoke: true});
              }, timeout)
              clearInterval(interval)
            }
          });
        }
      }

      // After hyperscriptifying a context, we send it through Drupal
      // behaviors. The doNotReinvoke flag indicates already-scriptified
      // content that does not need to proceed further.
      if (settings.doNotReinvoke) {
        return;
      }
      // If no templates are mapped to components, there's no need to continue.
      if (!Drupal.JSXComponents) {
        return;
      }

      context.querySelectorAll('drupal-html-fragment').forEach((fragment) => {
        setTimeout(() => {
          // Clean out Drupal HTML fragments after they've been scriptified so there
          // aren't matching selectors for elements that have already served
          // their purpose in guiding the scriptification process.
          if (fragment.hasAttribute('data-drupal-scriptified')) {
            fragment.innerHTML = '';
          }
        })
      })

      let attachBehaviorsCalled = false;
      Object.keys(Drupal.JSXComponents).forEach(componentName => {
        // If the top-level element in context is a JSX component
        if (context.tagName && context.tagName.toLowerCase() === componentName) {
          if (!context.tagName.toLowerCase().includes('fragment') && !context.hasAttribute('data-drupal-scriptified')) {
            const container =  Drupal.HyperscriptifyAdditional(Drupal.Hyperscriptify(context), context);
            context.hidden = true;
            context.setAttribute('data-drupal-scriptified', true)
            attachBehaviorsCalled = true;
            attachBehaviorsAfterAjaxing(container, settings);
            setTimeout(() => {
              // The current context has done its job to inform hyperscriptifying.
              // It is emptied instead of removed so `context` isn't null.
              context.innerHTML = '';
            })
          }
        } else {
          // Keep track of any input elements that have been updated by this
          // ajax request.
          const updatedInputElements = [];
          // Otherwise, search for the component inside a context.
         [...context.querySelectorAll(`${componentName}:not([data-drupal-scriptified])`)].forEach(component => {
           if (!component.hasAttribute('data-drupal-scriptified')) {
             const container =  Drupal.HyperscriptifyAdditional(Drupal.Hyperscriptify(component),component);
             component.setAttribute('data-drupal-scriptified', true)
             Drupal.attachBehaviors(container, {...settings, doNotReinvoke: true});

             // The element has informed hyperscriptification and is no longer
             // needed in the DOM.
             setTimeout(() => component.remove())

             // We have attributes here that mean this likely a form field and
             // there are React props, push this element into the list of
             // updated input elements.
             if (component.hasAttribute('attributes')) {
               updatedInputElements.push(component)
             }
             attachBehaviorsCalled = true;
             attachBehaviorsAfterAjaxing(container, settings);
             // The element has informed hyperscriptification and is no longer
             // needed in the DOM.
             setTimeout(() => component.remove())
           }
         })
         if (updatedInputElements.length > 0) {
           // Notify the application that form fields have changed.
           Drupal.HyperscriptifyUpdateStore(updatedInputElements)
         }
        }
      });

      // If Drupal.attachBehaviors has not yet been called, but the context is inside
      // the contextual panel, it will be called here.
      if (!attachBehaviorsCalled && context?.closest && context.closest('[data-testid="xb-contextual-panel"]')) {
        // @todo Change the 30ms arbitrary wait to one that is based on the
        // actual (currently unknown) reason the wait is needed.
        // https://drupal.org/i/3506241
        attachBehaviorsAfterAjaxing(context, settings, 30);
      }
    }
  }
})(Drupal);
