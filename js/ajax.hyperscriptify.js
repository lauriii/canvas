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


      Object.keys(Drupal.JSXComponents).forEach(componentName => {
        // If the top-level element in context is a JSX component
        if (context.tagName && context.tagName.toLowerCase() === componentName) {
          if (!context.tagName.toLowerCase().includes('fragment') && !context.hasAttribute('data-drupal-scriptified')) {
            const container =  Drupal.HyperscriptifyAdditional(Drupal.Hyperscriptify(context), context);
            context.hidden = true;
            context.setAttribute('data-drupal-scriptified', true)
            setTimeout(() => {
              Drupal.attachBehaviors(container, {...settings, doNotReinvoke: true});
              setTimeout(() => {
                // The current context has done its job to inform hyperscriptifying.
                // It is emptied instead of removed so `context` isn't null.
                context.innerHTML = '';
              })
            })
          }
        } else {
          // Otherwise, search for the component inside a context.
         [...context.querySelectorAll(`${componentName}:not([data-drupal-scriptified])`)].forEach(component => {
           if (!component.hasAttribute('data-drupal-scriptified')) {
             const container =  Drupal.HyperscriptifyAdditional(Drupal.Hyperscriptify(component),component);
             component.setAttribute('data-drupal-scriptified', true)
             setTimeout(async () => {
               Drupal.attachBehaviors(container, {...settings, doNotReinvoke: true});
               // The element has informed hyperscriptification and is no longer
               // needed in the DOM.
               setTimeout(() => component.remove())
             })
           }
         })
        }
      })
    }
  }
})(Drupal);
