/**
 * @file
 * Customizations to AJAX commands.
 */

/* global csstree */
(function (Drupal, csstree) {
  /**
   *
   * @param {object} styleSheetData
   *   Data about a stylesheet, as passed to the `add_css` Ajax Command.
   * @param scopeSelector
   * @return {Promise<boolean>}
   */
  const scopeCss = async function(styleSheetData, scopeSelector) {
    let css = ''
    try {
      const res = await fetch(styleSheetData.href)
      css = await res.text();
    } catch(err) {
      console.warn(`Could not fetch ${styleSheetData.href}`, err)
    }
    // If the asset was already added this way, there is no need to
    // do it again.
    if (document.querySelector(`[data-dialog-style-from="${styleSheetData.href}"]`)) {
      return;
    }

    const styleElement = document.createElement('style')
    // This attribute keeps track of the CSS file the styles
    // originate from.
    styleElement.setAttribute('data-dialog-style-from', styleSheetData.href)

    // CSSStyleSheet has difficulty parsing shorthand styles that also
    // include CSS variables, so we populate those values in advance
    // when possible. We begin by parsing getting the AST of the CSS.
    const ast = csstree.parse(css)

    /**
     * Updates AST nodes of CSS variables with their values when available.
     *
     * @param {Object} node
     *   An AST node
     */
    const updateVariableNode = (node) => {
      const documentComputedStyles = window.getComputedStyle(document.documentElement);

      // Checks if the node is a call to var().
      if (node.type === 'Function' && node.name === 'var') {
        // If this is a variable with a default value, process the
        // default and replace any var() calls with values when they are
        // available.
        if (node?.children?.head?.next?.data?.type === 'Operator' &&
          node?.children?.head?.next?.data?.value === ',' &&
          node?.children?.head?.next?.next?.data &&
          node?.children?.head?.next?.next?.data.type === 'Raw') {
          // If the current node met the above condition, then the value at this
          // position is the value of the CSS variable fallback.
          const {value} = node.children.head.next.next.data;

          // The CSS variable fallback exists in the AST as a raw string that
          // might contain one or more CSS variables. Get every CSS variable in
          // this string.
          const matches = value.matchAll(/var\((\s)*(--[_a-zA-Z]+[_a-zA-Z0-9-]*)/gm);
          const variables = [...matches].map((aMatch) => aMatch?.[2])

          // Limit the array to only variables that can be resolved to values.
          const variablesWithValues = variables.filter((vr) => documentComputedStyles.getPropertyValue(vr))

          // Replace the call to var() with the value of the first variable that
          // can be resolved.
          if (variablesWithValues.length > 0) {
            node.children.head.next.next.data.value = documentComputedStyles.getPropertyValue(variablesWithValues[0]);
          }
        }

        // Get the CSS variable name and see if it can be resolved to a value.
        const varName = node?.children?.head?.data?.name;
        const cssVarValue = documentComputedStyles.getPropertyValue(varName);
        if (cssVarValue) {
          // Convert the CSS variable value into AST.
          const valueAst = csstree.parse(cssVarValue, {context: 'value'});

          // Replace the var() calling node with the actual value.
          if (valueAst?.children?.head?.data) {
            // Replace individual properties so prototype properties such as
            // position in the AST tree are preserved.
            Object.entries(valueAst.children.head.data).forEach(([key, value]) => {
              node[key] = value;
            })
          }
        }
      }
    }

    // Traverse the AST tree and check every node for variable processing.
    csstree.walk(ast, (node) => {
      updateVariableNode(node)
    })

    // Create a CSS string from the ast with processed variables.
    const newCss = csstree.generate(ast);

    // Create a CSSStyleSheet object that contains the styles
    // provided by the CSS file that was going to be added.
    const stylesheet = new CSSStyleSheet();
    await stylesheet.replace(newCss);

    /**
     * Get the string value of a CSS rule with potentially changed scope.
     *
     * @param {CSSRule} rule
     *   The CSS rule
     * @return {*|string}
     *   The CSS rule as a string.
     */
    const processRule = (rule) =>  {
      // If @scope is not supported it's best to use the default CSS despite it
      // introducing a risk of styles leaking. Without @scope, we run into
      // situations where the selector-fenced styles override styles that are
      // essential to functionality such as visibility state.
      if (typeof CSSScopeRule === 'undefined') {
        return rule.cssText;
      }

      // The topLevelSelectors accounts for selectors that are supposed
      // to appear before the scope selector, such as html and body tags
      // or the .js class.
      const topLevelSelectors = ['html', 'body', 'main'];
      topLevelSelectors.forEach((tagName) => {
        document.querySelector(tagName)?.classList.forEach((aClass) => {
          topLevelSelectors.push(`.${aClass}`);
        });
      })

      // If a rule is scoped to root, return the unaltered string.
      if (rule.cssText.includes(':root') || rule.cssText.startsWith(scopeSelector)) {
        return rule.cssText;
      }

      // If the rule begins with a higher level selector that needs
      // to precede the scope selector, return the rule as a string with
      // the scope selector positioned after the broader selector.
      const beginsWithTopLevel =
        topLevelSelectors.filter((possibleSelector) => rule.cssText.startsWith(possibleSelector))
      if (beginsWithTopLevel.length) {
        const selector = beginsWithTopLevel[0].match(/[^\s]+/);
        return rule.cssText.replace(selector, `${selector} ${scopeSelector}`)
      }

      // Otherwise, return the rule as string scoped within `scopeSelector`.
      return `@scope(${scopeSelector}) { ${rule.cssText} }`;
    }

    // Make the dialog-scoped CSS the contents of the style element.
    styleElement.innerHTML = [...stylesheet.cssRules].reduce((accumulated, rule) =>
        accumulated + processRule(rule)
      , '');
    const priorAdditions = document.querySelectorAll('[data-dialog-style-from]');

    // If this is the first style element added by this method, add it
    // to the beginning of `<head>`.
    if (priorAdditions.length === 0) {
      document.querySelector('head').prepend(styleElement);
    } else {
      // Place any new CSS asset directly after the most recent asset
      // added via this process so load order is maintained, but they
      // still appear before pre-existing CSS so utility classes will
      // get prioritized in situations of otherwise identical specificity.
      const mostRecentAddition = [...priorAdditions].pop();
      mostRecentAddition.insertAdjacentElement('afterend', styleElement);
    }
    return true;
  }

  /**
   * Customizing the add_css AjaxCommand for Experience Builder.
   *
   * @type {{attach(): void}}
   */
  Drupal.behaviors.enhanceAddCssForDialogsUsingAdminTheme = {
    attach() {
      // Copy the original add_css method so it can be called from the overridden
      // version added below.
      const originalAddCss = Drupal.AjaxCommands.prototype.add_css;

      Drupal.AjaxCommands.prototype.scope_css = scopeCss;

      // Overrides the existing add_css to facilitate scoping certain styles
      // within specific selectors.
      Drupal.AjaxCommands.prototype.add_css = function(...args) {
        const [ajax, response] = args;

        // If this is in an AJAX dialog and the dialog trigger specified
        // useAdminTheme, add the CSS assets differently.
        if (ajax?.dialogType === 'ajax' && ajax?.useAdminTheme) {
          // The scope selector is what wraps the styles so they are only
          // applied within the dialog. `.ui-dialog` is the default class.
          // @see \Drupal\Core\Ajax\OpenDialogCommand::$dialogOptions
          const scopeSelector = ajax?.scopeSelector || '.ui-dialog';

          // Although it's typically discouraged to use await within loops, it
          // is done here to ensure every stylesheet in the list is fully
          // added to the DOM before the process begins for the next one in
          // the array. By using Promise.all(), we run into scenarios where
          // the process looks for CSS variables that are not yet available.
          // Having the CSS variables already loaded is necessary due to
          // limitations of CSSStyleSheet() not being able to parse styles
          // that use CSS variables in shorthand in a style that also includes
          // a longhand property of that shorthand. The workaround is
          // populating those values via JavaScript.
          response.data.reduce(async (promise, styleSheetData) => {
            // Wait for the prior call to scopeCss to complete so the loading
            // order is preserved;
            await promise;
            await scopeCss(styleSheetData, scopeSelector)
          }, Promise.resolve());

          // Return a resolved promise to match the return type of the method
          // being overridden.
          return Promise.resolve();
        }

        // If the CSS assets were not designated to be scoped within an admin
        // theme rendered dialog, use default `add_css` from ajax.js.
        return originalAddCss.apply(this, args);
      }
    }
  }
})(Drupal, csstree);
