// @vitest-environment jsdom
import '@angular/compiler';

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  BrowserTestingModule,
  platformBrowserTesting,
} from '@angular/platform-browser/testing';
import { fetchEntity } from '@drupal-canvas/headless/server';

import type * as CanvasRenderer from '@drupal-canvas/json-render-angular';
import type * as HeadlessAngular from '../src/index';

const root = `${process.cwd()}/`;
let adapter: typeof HeadlessAngular;
let renderer: typeof CanvasRenderer;

beforeAll(async () => {
  // Reuse CI's build output instead of deleting files used by parallel tests.
  if (!existsSync(`${root}dist/fesm2022/drupal-canvas-headless-angular.mjs`)) {
    execFileSync('npm', ['run', 'build'], { cwd: root, stdio: 'pipe' });
  }
  renderer = await import('@drupal-canvas/json-render-angular');
  adapter = await import(
    /* @vite-ignore */ `${root}dist/fesm2022/drupal-canvas-headless-angular.mjs`
  );
  TestBed.initTestEnvironment(BrowserTestingModule, platformBrowserTesting());
}, 30_000);

afterEach(() => TestBed.resetTestingModule());

describe('built output', () => {
  it('ships partial declarations for adapter services', () => {
    const output = readFileSync(
      resolve(root, 'dist/fesm2022/drupal-canvas-headless-angular.mjs'),
      'utf8',
    );
    expect(output).toContain('ɵɵngDeclareInjectable');
    expect(output).not.toContain('ɵɵdefineInjectable');
  });

  it('exposes the SDK tree wrapper and re-exports the renderer primitives', () => {
    expect(adapter.CanvasComponentTree).toBeTypeOf('function');
    expect(adapter.CanvasSlot).toBe(renderer.CanvasSlot);
    expect(adapter.CanvasElement).toBe(renderer.CanvasElement);
    expect(adapter.CanvasChildren).toBe(renderer.CanvasChildren);
    expect(adapter.CanvasMarkup).toBe(renderer.CanvasMarkup);
    expect(adapter.CanvasMarkerDirective).toBe(renderer.CanvasMarkerDirective);
  });

  it('renders a component from a converted Custom Elements response', async () => {
    const entity = await fetchEntity({
      baseUrl: 'https://drupal.example',
      type: 'node',
      id: '1',
      fetchImpl: vi.fn().mockResolvedValue(
        Response.json({
          managedByCanvas: true,
          content: { element: 'js-hero-card', props: { canvasUuid: 'hero' } },
        }),
      ),
    });
    if (!entity) throw new Error('Expected an entity');

    class HeroCard {}
    Component({ selector: 'test-hero-card', template: '<p>Hero</p>' })(
      HeroCard,
    );
    TestBed.configureTestingModule({ imports: [adapter.CanvasComponentTree] });
    const fixture = TestBed.createComponent(adapter.CanvasComponentTree);
    fixture.componentRef.setInput('tree', entity.content);
    fixture.componentRef.setInput('components', { Hero_Card: HeroCard });
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Hero');
  });
});
