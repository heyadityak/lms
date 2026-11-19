import { describe, expect, it, vi } from 'vitest';
import { accessTokenFor, type TokenApi } from './access-token';

const NOW = 1_800_000_000_000;
const h = new Headers();
const at = (ms: number) => new Date(NOW + ms);

function api(overrides: Partial<TokenApi> = {}): TokenApi {
    return {
        listAccounts: vi.fn(async () => [{ id: 'acc1', providerId: 'keycloak' }]),
        getAccessToken: vi.fn(async () => ({
            accessToken: 'old',
            accessTokenExpiresAt: at(120_000),
        })),
        refreshToken: vi.fn(async () => ({
            accessToken: 'new',
            accessTokenExpiresAt: at(300_000),
        })),
        deleteSession: vi.fn(async () => {}),
        ...overrides,
    };
}

describe('accessTokenFor', () => {
    it('returns the stored token when more than 60 s are left', async () => {
        const a = api();
        expect(await accessTokenFor(a, h, () => NOW)).toBe('old');
        expect(a.refreshToken).not.toHaveBeenCalled();
    });

    it('refreshes when less than 60 s are left', async () => {
        const a = api({
            getAccessToken: vi.fn(async () => ({
                accessToken: 'old',
                accessTokenExpiresAt: at(59_000),
            })),
        });
        expect(await accessTokenFor(a, h, () => NOW)).toBe('new');
        expect(a.refreshToken).toHaveBeenCalledWith(h, 'acc1');
    });

    it('returns null without a session or a Keycloak account', async () => {
        expect(
            await accessTokenFor(
                api({ listAccounts: vi.fn(async () => Promise.reject(new Error('401'))) }),
                h,
            ),
        ).toBeNull();
        expect(await accessTokenFor(api({ listAccounts: vi.fn(async () => []) }), h)).toBeNull();
    });

    it('re-reads once after a failed refresh (another replica rotated the token)', async () => {
        const getAccessToken = vi
            .fn()
            .mockResolvedValueOnce({ accessToken: 'old', accessTokenExpiresAt: at(30_000) })
            .mockResolvedValueOnce({ accessToken: 'rotated', accessTokenExpiresAt: at(300_000) });
        const a = api({
            getAccessToken,
            refreshToken: vi.fn(async () => Promise.reject(new Error('400'))),
        });
        expect(await accessTokenFor(a, h, () => NOW)).toBe('rotated');
        expect(a.deleteSession).not.toHaveBeenCalled();
    });

    it('deletes the session when the token cannot be renewed', async () => {
        const a = api({
            getAccessToken: vi.fn(async () => ({
                accessToken: 'old',
                accessTokenExpiresAt: at(1_000),
            })),
            refreshToken: vi.fn(async () => Promise.reject(new Error('400'))),
        });
        expect(await accessTokenFor(a, h, () => NOW)).toBeNull();
        expect(a.deleteSession).toHaveBeenCalledWith(h);
    });
});
