import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

afterEach(cleanup);

// antd Modal / picker measure with ResizeObserver, which jsdom lacks.
if (typeof window !== 'undefined' && !window.ResizeObserver) {
    window.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as unknown as typeof ResizeObserver;
}

// antd's responsive helpers (Grid, Sider breakpoint) need matchMedia, which jsdom lacks
// (or reports a 0px viewport). min-width queries match so tests see the desktop shell.
if (typeof window !== 'undefined') {
    window.matchMedia = (query: string) =>
        ({
            matches: query.includes('min-width'),
            media: query,
            onchange: null,
            addListener: () => {},
            removeListener: () => {},
            addEventListener: () => {},
            removeEventListener: () => {},
            dispatchEvent: () => false,
        }) as MediaQueryList;
}
