import {
  buildEntityAddFormUrl,
  getAddNewOptions,
  getTemplatedEntityGroups,
} from '@/features/navigator/templatedContent';

// Builds a content-templates listing entry for one view mode with the given
// exposed slots (server, snake_case shape).
const viewMode = (entityType, bundle, exposed_slots) => ({
  entityType,
  bundle,
  viewMode: 'full',
  viewModeLabel: 'Full',
  label: 'Full',
  status: true,
  id: `${entityType}.${bundle}.full`,
  exposed_slots,
});

const activeSlot = { component_uuid: 'c1', slot_name: 'body', label: 'Body' };
const disabledSlot = {
  component_uuid: 'c2',
  slot_name: 'aside',
  label: 'Aside',
  disabled: true,
};

describe('getTemplatedEntityGroups', () => {
  it('returns an empty array when templates are missing', () => {
    expect(getTemplatedEntityGroups(undefined)).to.deep.equal([]);
  });

  it('excludes Canvas pages and lists templated bundles with an active slot', () => {
    const groups = getTemplatedEntityGroups({
      canvas_page: {
        label: 'Canvas Page',
        bundles: {
          canvas_page: {
            label: 'Canvas Page',
            // Even if a page template had exposed slots, canvas_page is listed
            // separately and must never appear as a templated group.
            viewModes: {
              full: viewMode('canvas_page', 'canvas_page', {
                hero: activeSlot,
              }),
            },
          },
        },
      },
      node: {
        label: 'Content',
        bundles: {
          article: {
            label: 'Article',
            viewModes: {
              full: viewMode('node', 'article', { body: activeSlot }),
            },
          },
        },
      },
    });
    expect(groups).to.have.length(1);
    expect(groups[0].entityType).to.equal('node');
    // Single active bundle -> group title is the bundle label.
    expect(groups[0].title).to.equal('Article');
    expect(groups[0].bundles).to.deep.equal([
      { bundle: 'article', label: 'Article' },
    ]);
  });

  it('excludes bundles whose slots are all disabled or absent', () => {
    const groups = getTemplatedEntityGroups({
      node: {
        label: 'Content',
        bundles: {
          only_disabled: {
            label: 'Only disabled',
            viewModes: {
              full: viewMode('node', 'only_disabled', { aside: disabledSlot }),
            },
          },
          no_slots: {
            label: 'No slots',
            viewModes: { full: viewMode('node', 'no_slots', {}) },
          },
        },
      },
    });
    expect(groups).to.deep.equal([]);
  });

  it('uses the entity type label when several bundles are active', () => {
    const groups = getTemplatedEntityGroups({
      node: {
        label: 'Content',
        bundles: {
          article: {
            label: 'Article',
            viewModes: {
              full: viewMode('node', 'article', { body: activeSlot }),
            },
          },
          landing: {
            label: 'Landing page',
            viewModes: {
              full: viewMode('node', 'landing', { hero: activeSlot }),
            },
          },
        },
      },
    });
    expect(groups).to.have.length(1);
    // Multiple active bundles -> fall back to the entity type label because the
    // content list carries no per-item bundle to sub-group by.
    expect(groups[0].title).to.equal('Content');
    expect(groups[0].bundles).to.deep.equal([
      { bundle: 'article', label: 'Article' },
      { bundle: 'landing', label: 'Landing page' },
    ]);
  });
});

describe('buildEntityAddFormUrl', () => {
  it('builds the Drupal node add-form URL', () => {
    expect(buildEntityAddFormUrl('/', 'node', 'article')).to.equal(
      '/node/add/article',
    );
  });

  it('normalizes a base URL without a trailing slash and a subdirectory', () => {
    expect(buildEntityAddFormUrl('/sub', 'node', 'article')).to.equal(
      '/sub/node/add/article',
    );
    expect(buildEntityAddFormUrl(undefined, 'node', 'page')).to.equal(
      '/node/add/page',
    );
  });

  it('returns null for unsupported entity types', () => {
    expect(buildEntityAddFormUrl('/', 'block_content', 'basic')).to.equal(null);
  });
});

describe('getAddNewOptions', () => {
  const group = {
    entityType: 'node',
    title: 'Content',
    bundles: [
      { bundle: 'article', label: 'Article' },
      { bundle: 'landing', label: 'Landing page' },
    ],
  };

  it('offers only bundles the user may create, with add-form URLs', () => {
    const options = getAddNewOptions(
      group,
      { node: { article: 'Create Article' } },
      '/',
    );
    expect(options).to.deep.equal([
      { bundle: 'article', label: 'Article', url: '/node/add/article' },
    ]);
  });

  it('returns nothing when the user cannot create any bundle', () => {
    expect(getAddNewOptions(group, {}, '/')).to.deep.equal([]);
    expect(getAddNewOptions(group, undefined, '/')).to.deep.equal([]);
  });

  it('drops bundles with no derivable add-form URL', () => {
    const blockGroup = {
      entityType: 'block_content',
      title: 'Blocks',
      bundles: [{ bundle: 'basic', label: 'Basic' }],
    };
    const options = getAddNewOptions(
      blockGroup,
      { block_content: { basic: 'Create Basic' } },
      '/',
    );
    expect(options).to.deep.equal([]);
  });
});
