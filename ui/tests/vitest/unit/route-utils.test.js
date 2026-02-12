import {
  removeComponentFromPathname,
  removeRegionFromPathname,
  setComponentInPathname,
  setPreviewEntityIdInPathname,
  setRegionInPathname,
} from '@/utils/route-utils';

describe('removeRegionFromPathname', () => {
  it('should remove region segment from pathname', () => {
    const pathname = '/editor/node/123/region/main';
    const result = removeRegionFromPathname(pathname);
    expect(result).to.equal('/editor/node/123');
  });

  it('should handle pathname without region segment', () => {
    const pathname = '/editor/node/123';
    const result = removeRegionFromPathname(pathname);
    expect(result).to.equal('/editor/node/123');
  });

  it('should remove trailing slash', () => {
    const pathname = '/editor/node/123/region/main/';
    const result = removeRegionFromPathname(pathname);
    expect(result).to.equal('/editor/node/123');
  });
});

describe('removeComponentFromPathname', () => {
  it('should handle pathname without component segment', () => {
    const pathname = '/editor/node/123';
    const result = removeComponentFromPathname(pathname);
    expect(result).to.equal('/editor/node/123');
  });

  it('should remove component segment from pathname', () => {
    const pathname =
      '/template/node/article/full/2/component/97842c37-a3f2-4a04-b304-fdc2dd69a4f9';
    const result = removeComponentFromPathname(pathname);
    expect(result).to.equal('/template/node/article/full/2');
  });

  it('should remove trailing slash', () => {
    const pathname = '/editor/node/123/component/abc-123/';
    const result = removeComponentFromPathname(pathname);
    expect(result).to.equal('/editor/node/123');
  });
});

describe('setRegionInPathname', () => {
  it('should append region when not present', () => {
    const pathname = '/editor/node/123';
    const result = setRegionInPathname(pathname, 'sidebar');
    expect(result).to.equal('/editor/node/123/region/sidebar');
  });

  it('should replace existing region', () => {
    const pathname = '/editor/node/123/region/main';
    const result = setRegionInPathname(pathname, 'sidebar');
    expect(result).to.equal('/editor/node/123/region/sidebar');
  });

  it('should remove region when regionId matches default', () => {
    const pathname = '/editor/node/123/region/main';
    const result = setRegionInPathname(pathname, 'main', 'main');
    expect(result).to.equal('/editor/node/123');
  });

  it('should remove region when regionId is undefined', () => {
    const pathname = '/editor/node/123/region/main';
    const result = setRegionInPathname(pathname);
    expect(result).to.equal('/editor/node/123');
  });

  it('should not add region when regionId is undefined', () => {
    const pathname = '/editor/node/123';
    const result = setRegionInPathname(pathname);
    expect(result).to.equal('/editor/node/123');
  });

  it('should handle default region parameter', () => {
    const pathname = '/editor/node/123';
    const result = setRegionInPathname(pathname, 'content', 'content');
    expect(result).to.equal('/editor/node/123');
  });
});

describe('setComponentInPathname', () => {
  it('should append component when not present', () => {
    const pathname = '/editor/node/123';
    const result = setComponentInPathname(pathname, 'abc-123');
    expect(result).to.equal('/editor/node/123/component/abc-123');
  });

  it('should replace existing component at end', () => {
    const pathname = '/editor/node/123/component/abc-123';
    const result = setComponentInPathname(pathname, 'def-456');
    expect(result).to.equal('/editor/node/123/component/def-456');
  });

  it('should remove component when componentId is undefined', () => {
    const pathname = '/editor/node/123/component/abc-123';
    const result = setComponentInPathname(pathname);
    expect(result).to.equal('/editor/node/123');
  });

  it('should not add component when componentId is undefined', () => {
    const pathname = '/editor/node/123';
    const result = setComponentInPathname(pathname);
    expect(result).to.equal('/editor/node/123');
  });

  it('should handle component with UUID', () => {
    const pathname = '/template/node/article/full/2';
    const result = setComponentInPathname(
      pathname,
      '97842c37-a3f2-4a04-b304-fdc2dd69a4f9',
    );
    expect(result).to.equal(
      '/template/node/article/full/2/component/97842c37-a3f2-4a04-b304-fdc2dd69a4f9',
    );
  });
});

describe('setPreviewEntityIdInPathname', () => {
  describe('valid template routes', () => {
    it('should replace existing entity ID at end', () => {
      const pathname = '/template/node/article/full/2';
      const result = setPreviewEntityIdInPathname(pathname, 5);
      expect(result).to.equal('/template/node/article/full/5');
    });

    it('should replace entity ID and preserve component segment', () => {
      const pathname =
        '/template/node/article/full/2/component/97842c37-a3f2-4a04-b304-fdc2dd69a4f9';
      const result = setPreviewEntityIdInPathname(pathname, 10);
      expect(result).to.equal(
        '/template/node/article/full/10/component/97842c37-a3f2-4a04-b304-fdc2dd69a4f9',
      );
    });

    it('should append entity ID when not present', () => {
      const pathname = '/template/node/page/full';
      const result = setPreviewEntityIdInPathname(pathname, 3);
      expect(result).to.equal('/template/node/page/full/3');
    });

    it('should append entity ID with trailing slash', () => {
      const pathname = '/template/node/page/full/';
      const result = setPreviewEntityIdInPathname(pathname, 7);
      expect(result).to.equal('/template/node/page/full/7');
    });

    it('should remove entity ID when passing undefined', () => {
      const pathname = '/template/node/article/full/2';
      const result = setPreviewEntityIdInPathname(pathname);
      expect(result).to.equal('/template/node/article/full');
    });

    it('should preserve region and component segments', () => {
      const pathname =
        '/template/node/article/full/2/region/main/component/abc-123';
      const result = setPreviewEntityIdInPathname(pathname, 5);
      expect(result).to.equal(
        '/template/node/article/full/5/region/main/component/abc-123',
      );
    });

    it('should preserve any trailing path segments after entity ID', () => {
      const pathname = '/template/node/article/full/2/anything/else/here';
      const result = setPreviewEntityIdInPathname(pathname, 42);
      expect(result).to.equal(
        '/template/node/article/full/42/anything/else/here',
      );
    });

    it('should handle no entity ID and no trailing segments', () => {
      const pathname = '/template/node/page/full';
      const result = setPreviewEntityIdInPathname(pathname);
      expect(result).to.equal('/template/node/page/full');
    });
  });

  describe('invalid routes - should throw errors', () => {
    it('should throw error for entity editor route', () => {
      const pathname = '/editor/node/123';
      expect(() => setPreviewEntityIdInPathname(pathname, 5)).to.throw(
        'setPreviewEntityIdInPathname: Current route "/editor/node/123" is not a template editor route',
      );
    });

    it('should throw error for incomplete template route', () => {
      const pathname = '/template/node';
      expect(() => setPreviewEntityIdInPathname(pathname, 5)).to.throw(
        'is not a template editor route',
      );
    });

    it('should throw error for root path', () => {
      const pathname = '/';
      expect(() => setPreviewEntityIdInPathname(pathname, 5)).to.throw(
        'is not a template editor route',
      );
    });
  });

  describe('edge cases', () => {
    it('should handle entityId of 0', () => {
      const pathname = '/template/node/article/full';
      const result = setPreviewEntityIdInPathname(pathname, 0);
      expect(result).to.equal('/template/node/article/full');
    });

    it('should handle empty string entityId', () => {
      const pathname = '/template/node/article/full/2';
      const result = setPreviewEntityIdInPathname(pathname, '');
      expect(result).to.equal('/template/node/article/full');
    });

    it('should handle bundle and viewMode with special characters', () => {
      const pathname = '/template/node/article_type/full_display/5';
      const result = setPreviewEntityIdInPathname(pathname, 10);
      expect(result).to.equal('/template/node/article_type/full_display/10');
    });
  });
});
