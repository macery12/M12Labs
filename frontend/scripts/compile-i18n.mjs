import { createHash } from 'node:crypto';
import { mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, extname, join, relative, resolve } from 'node:path';
import { stdout } from 'node:process';
import { fileURLToPath } from 'node:url';
import { compile } from '@inlang/paraglide-js';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const messagesRoot = join(root, 'messages');
const projectRoot = join(root, 'project.inlang');
const outdir = join(root, 'src', 'paraglide');
const generatedRoot = join(root, 'src', 'i18n', 'generated');
const generatedPublicRoot = join(generatedRoot, 'public');
const generatedAppRoot = join(generatedRoot, 'app');
const generatedFullRoot = join(generatedRoot, 'full');
const cacheFile = join(outdir, '.m12labs-compile-cache.json');

async function filesUnder(directory) {
    const entries = await readdir(directory, { withFileTypes: true });
    const nested = await Promise.all(
        entries.map(entry => {
            const path = join(directory, entry.name);
            return entry.isDirectory() ? filesUnder(path) : [path];
        }),
    );
    return nested.flat();
}

const inputFiles = [...(await filesUnder(messagesRoot)), ...(await filesUnder(projectRoot))]
    .filter(path => extname(path) === '.json')
    .sort();
const hash = createHash('sha256');
hash.update('m12labs-i18n-v2\0locale-modules\0@inlang/paraglide-js@2.20.2\0');
for (const path of inputFiles) {
    hash.update(relative(root, path));
    hash.update('\0');
    hash.update(await readFile(path));
    hash.update('\0');
}
const inputHash = hash.digest('hex');

const settings = JSON.parse(await readFile(join(projectRoot, 'settings.json'), 'utf8'));
const locales = /** @type {string[]} */ (settings.locales);
const baseLocale = /** @type {string} */ (settings.baseLocale);
const requiredOutputs = [
    join(outdir, 'runtime.js'),
    join(outdir, 'messages', '_index.js'),
    ...locales.map(locale => join(outdir, 'messages', `${locale}.js`)),
];

let cache;
try {
    cache = JSON.parse(await readFile(cacheFile, 'utf8'));
} catch {
    cache = null;
}

let compiled = false;
if (cache?.inputHash !== inputHash || !(await Promise.all(requiredOutputs.map(path => readFile(path).then(() => true, () => false)))).every(Boolean)) {
    const started = performance.now();
    await compile({
        project: projectRoot,
        outdir,
        strategy: ['localStorage', 'baseLocale'],
        localStorageKey: 'm12labs.locale',
        outputStructure: 'locale-modules',
        emitTsDeclarations: false,
    });
    compiled = true;
    await writeFile(cacheFile, `${JSON.stringify({ inputHash }, null, 2)}\n`);
    stdout.write(`compile-i18n: Paraglide compiled ${locales.length} locale(s) in ${((performance.now() - started) / 1000).toFixed(2)}s\n`);
} else {
    stdout.write('compile-i18n: catalogs unchanged; reused compiled locale modules\n');
}

const catalog = {};
for (const path of (await filesUnder(messagesRoot)).filter(path => path.endsWith(`/${baseLocale}.json`)).sort()) {
    Object.assign(catalog, JSON.parse(await readFile(path, 'utf8')));
}
delete catalog.$schema;

function collectInputs(value, inputs = new Set()) {
    if (typeof value === 'string') {
        for (const match of value.matchAll(/\{([A-Za-z_$][\w$]*)\}/g)) inputs.add(match[1]);
        for (const match of value.matchAll(/\$([A-Za-z_$][\w$]*)\b/g)) inputs.add(match[1]);
        if (/^input\s+[A-Za-z_$][\w$]*$/.test(value)) inputs.add(value.slice('input '.length));
        return inputs;
    }
    if (Array.isArray(value)) {
        for (const item of value) collectInputs(item, inputs);
        return inputs;
    }
    if (value && typeof value === 'object') {
        for (const item of Object.values(value)) collectInputs(item, inputs);
    }
    return inputs;
}

const ids = Object.keys(catalog).sort((a, b) => a.localeCompare(b));

// Paraglide exports locale functions under identifier-safe names rather than
// their dotted message ids. The browser resolves those names lazily through a
// Proxy, which avoids shipping a second 4,500-entry id map in every locale
// chunk. Validate the naming rule against Paraglide's generated index on every
// build so a future compiler change fails here instead of breaking at runtime.
function compiledMessageName(id) {
    const uppercaseCount = id.match(/[A-Z]/g)?.length ?? 0;
    const normalized = id.replace(/[^A-Za-z0-9_$]/g, '_').toLowerCase();
    return uppercaseCount === 0 ? normalized : `${normalized}${uppercaseCount}`;
}

const indexSource = await readFile(join(outdir, 'messages', '_index.js'), 'utf8');
const generatedNames = new Map(
    [...indexSource.matchAll(/^export \{ ([A-Za-z_$][\w$]*) as ("(?:\\.|[^"])*") \}$/gm)].map(match => [
        JSON.parse(match[2]),
        match[1],
    ]),
);
for (const id of ids) {
    const actual = generatedNames.get(id);
    const expected = compiledMessageName(id);
    if (actual !== expected) {
        throw new Error(`Paraglide export mismatch for ${JSON.stringify(id)}: expected ${expected}, found ${actual ?? 'nothing'}`);
    }
}
if (generatedNames.size !== ids.length) {
    throw new Error(`Paraglide generated ${generatedNames.size} message exports for ${ids.length} source messages.`);
}

// Guests can only reach the landing, authentication, and shared error shells.
// Keep those messages in a small startup catalog; authenticated boots retain
// the complete catalog. Copy the selected compiler output into standalone
// modules: re-exporting from the full locale makes Rollup retain that full
// module as a shared dependency and defeats the split.
const publicMessagePrefixes = ['auth.', 'landing.', 'common.', 'account.recoveryCode.'];
const publicIds = ids.filter(id => publicMessagePrefixes.some(prefix => id.startsWith(prefix)));
// Signed-in players get the `app` tier: everything except the admin area's
// copy, which was over half of the full catalog (~50 KB gzip in en, more in
// ru) downloaded by every player and read by none. AdminLayout loads `full`
// before it renders.
//
// An admin-namespace message used anywhere outside the admin pages -- a shared
// component, a player page borrowing a label -- stays in `app`, found by
// scanning the source for literal ids. Missing it would not be a blank label:
// `m['…']` would be undefined and the page would throw. Ids assembled at
// runtime go through td(), which falls back instead.
const adminOnlyPrefixes = ['admin.', 'landingAdmin.', 'storeAdmin.'];
const srcRoot = join(root, 'src');
const adminPagesRoot = join(srcRoot, 'pages', 'admin') + '/';
const scanExcluded = [adminPagesRoot, join(srcRoot, 'paraglide') + '/', join(srcRoot, 'i18n', 'generated') + '/'];
const referencedOutsideAdmin = new Set();
const scanned = new Set();
const scanQueue = (await filesUnder(srcRoot)).filter(
    path => /\.(ts|tsx)$/.test(path) && !scanExcluded.some(prefix => path.startsWith(prefix)),
);
// An admin-page module imported from outside the admin area (a shared helper,
// a component a player page reuses) renders outside AdminLayout too, so its
// ids count as well -- followed through its own imports.
async function resolveImport(from, specifier) {
    const base = specifier.startsWith('@/') ? join(srcRoot, specifier.slice(2)) : specifier.startsWith('.') ? resolve(dirname(from), specifier) : null;
    if (base === null) return null;
    for (const candidate of [base, `${base}.ts`, `${base}.tsx`, join(base, 'index.ts'), join(base, 'index.tsx')]) {
        if (/\.(ts|tsx)$/.test(candidate) && (await readFile(candidate).then(() => true, () => false))) return candidate;
    }
    return null;
}
while (scanQueue.length > 0) {
    const path = scanQueue.pop();
    if (scanned.has(path)) continue;
    scanned.add(path);

    const source = await readFile(path, 'utf8');
    for (const match of source.matchAll(/m\[\s*(['"])([^'"\n]+)\1\s*\]/g)) referencedOutsideAdmin.add(match[2]);
    for (const match of source.matchAll(/^\s*import\s+(?!type\b)[^;]*?from\s+(['"])([^'"]+)\1/gm)) {
        const target = await resolveImport(path, match[2]);
        if (target !== null && target.startsWith(adminPagesRoot) && !scanned.has(target)) scanQueue.push(target);
    }
}
const appIds = ids.filter(
    id => !adminOnlyPrefixes.some(prefix => id.startsWith(prefix)) || referencedOutsideAdmin.has(id),
);

async function compiledFunctions(locale) {
    const localeSource = await readFile(join(outdir, 'messages', `${locale}.js`), 'utf8');
    const matches = [...localeSource.matchAll(/^export const ([A-Za-z_$][\w$]*) = /gm)];
    return new Map(
        matches.map(match => {
            const remainder = localeSource.slice(match.index);
            const closing = /^};$/m.exec(remainder);
            if (!closing) throw new Error(`Could not find the end of compiled ${locale} export ${match[1]}.`);
            return [match[1], remainder.slice(0, closing.index + closing[0].length)];
        }),
    );
}

const compiledCatalogs = new Map(
    await Promise.all(locales.map(async locale => [locale, await compiledFunctions(locale)])),
);
function generatedCatalogSource(locale, selectedIds) {
    const functions = compiledCatalogs.get(locale);
    const fallbackFunctions = compiledCatalogs.get(baseLocale);
    const selected = selectedIds.map(id => {
        const name = compiledMessageName(id);
        const source = functions?.get(name) ?? fallbackFunctions?.get(name);
        if (!source) throw new Error(`Missing compiled ${locale} message export ${name} for ${JSON.stringify(id)}.`);
        return source;
    });

    return `// Generated by scripts/compile-i18n.mjs. Do not edit.
import * as registry from '@/paraglide/registry.js';

${selected.join('\n\n')}\n`;
}

const typeSource = `// Generated by scripts/compile-i18n.mjs from messages/${baseLocale}.json. Do not edit.
export interface MessageFunctions {
${ids
    .map(id => {
        const inputs = [...collectInputs(catalog[id])].sort();
        const argument = inputs.length
            ? `{ ${inputs.map(input => `${JSON.stringify(input)}: NonNullable<unknown>`).join('; ')} }`
            : 'Record<string, never> | undefined';
        return `    ${JSON.stringify(id)}: (inputs${inputs.length ? '' : '?'}: ${argument}) => string;`;
    })
    .join('\n')}
}

export type MessageId = keyof MessageFunctions;
`;

const loaderSource = `// Generated by scripts/compile-i18n.mjs. Do not edit.
import type { Locale } from '@/paraglide/runtime';

type MessageFunction = (inputs?: Record<string, unknown>) => string;
export type RuntimeCatalog = Record<string, MessageFunction>;

export const catalogLoaders = {
${locales.map(locale => `    ${JSON.stringify(locale)}: () => import(${JSON.stringify(`virtual:m12-i18n-catalog/full/${locale}`)}) as unknown as Promise<RuntimeCatalog>,`).join('\n')}
} satisfies Record<Locale, () => Promise<RuntimeCatalog>>;

export const appCatalogLoaders = {
${locales.map(locale => `    ${JSON.stringify(locale)}: () => import(${JSON.stringify(`virtual:m12-i18n-catalog/app/${locale}`)}) as unknown as Promise<RuntimeCatalog>,`).join('\n')}
} satisfies Record<Locale, () => Promise<RuntimeCatalog>>;

export const publicCatalogLoaders = {
${locales.map(locale => `    ${JSON.stringify(locale)}: () => import(${JSON.stringify(`virtual:m12-i18n-catalog/public/${locale}`)}) as unknown as Promise<RuntimeCatalog>,`).join('\n')}
} satisfies Record<Locale, () => Promise<RuntimeCatalog>>;
`;

await Promise.all([
    mkdir(generatedRoot, { recursive: true }),
    mkdir(generatedPublicRoot, { recursive: true }),
    mkdir(generatedAppRoot, { recursive: true }),
    mkdir(generatedFullRoot, { recursive: true }),
]);
await Promise.all([
    writeFile(join(generatedRoot, 'messageTypes.ts'), typeSource),
    writeFile(join(generatedRoot, 'catalogLoaders.ts'), loaderSource),
    ...locales.map(locale => writeFile(join(generatedPublicRoot, `${locale}.js`), generatedCatalogSource(locale, publicIds))),
    ...locales.map(locale => writeFile(join(generatedAppRoot, `${locale}.js`), generatedCatalogSource(locale, appIds))),
    ...locales.map(locale => writeFile(join(generatedFullRoot, `${locale}.js`), generatedCatalogSource(locale, ids))),
]);

stdout.write(`compile-i18n: generated typed loaders for ${ids.length} message(s), ${appIds.length} app, ${publicIds.length} public${compiled ? '' : ' (cache hit)'}\n`);
