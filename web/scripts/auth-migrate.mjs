// One-off job: create/upgrade the Better Auth schema in lms_auth (D-45).
// Usage: node scripts/auth-migrate.mjs   (same env as the web container)
import { getMigrations } from 'better-auth/db/migration';
import { authOptions } from '../src/auth/options.mjs';

const options = authOptions();
try {
    const { toBeCreated, toBeAdded, runMigrations } = await getMigrations(options);
    console.log(
        JSON.stringify({
            msg: 'auth-migrate',
            create: toBeCreated.map((t) => t.table),
            alter: toBeAdded.map((t) => t.table),
        }),
    );
    await runMigrations();
    console.log(JSON.stringify({ msg: 'auth-migrate done' }));
} catch (error) {
    console.error(JSON.stringify({ msg: 'auth-migrate failed', error: String(error) }));
    process.exitCode = 1;
} finally {
    await options.database.end();
}
