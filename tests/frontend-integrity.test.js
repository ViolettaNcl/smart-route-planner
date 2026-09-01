const fs = require('fs');
const vm = require('vm');

function fail(message) {
    throw new Error(message);
}

const html = fs.readFileSync('public/index.php', 'utf8');
const i18nSource = fs.readFileSync('public/assets/js/i18n.js', 'utf8');
const cssSource = fs.readFileSync('public/assets/css/route.css', 'utf8');
const serviceWorker = fs.readFileSync('public/service-worker.js', 'utf8');
const javascript = fs.readdirSync('public/assets/js')
    .filter((file) => file.endsWith('.js'))
    .map((file) => fs.readFileSync('public/assets/js/' + file, 'utf8'))
    .join('\n');

const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map((match) => match[1]);
const duplicateIds = [...new Set(ids.filter((id, index) => ids.indexOf(id) !== index))];
if (duplicateIds.length) fail('Duplicate DOM IDs: ' + duplicateIds.join(', '));

const context = {};
vm.createContext(context);
vm.runInContext(
    i18nSource.split('const LANG_STORAGE_KEY')[0] + ';globalThis.__I18N = I18N;',
    context
);
const ruKeys = Object.keys(context.__I18N.ru).sort();
const enKeys = Object.keys(context.__I18N.en).sort();
if (JSON.stringify(ruKeys) !== JSON.stringify(enKeys)) fail('RU/EN translation keys differ');

const translationReferences = [...html.matchAll(/data-i18n(?:-(?:placeholder|title|aria-label))?="([^"]+)"/g)]
    .map((match) => match[1]);
const missingTranslations = [...new Set(translationReferences.filter((key) => !ruKeys.includes(key)))];
if (missingTranslations.length) fail('Missing translations: ' + missingTranslations.join(', '));

const ariaReferences = [...html.matchAll(/\b(?:aria-labelledby|aria-controls|for)="([^"]+)"/g)]
    .flatMap((match) => match[1].split(/\s+/));
const missingAriaTargets = [...new Set(ariaReferences.filter((id) => !ids.includes(id)))];
if (missingAriaTargets.length) fail('Missing ARIA targets: ' + missingAriaTargets.join(', '));

const javascriptIds = [
    ...javascript.matchAll(/getElementById\(['"]([^'"]+)['"]\)/g),
    ...javascript.matchAll(/\bbyId\(['"]([^'"]+)['"]\)/g),
    ...javascript.matchAll(/querySelector(?:All)?\(['"]#([a-zA-Z0-9_-]+)/g),
].map((match) => match[1]);
const missingJavascriptTargets = [...new Set(javascriptIds.filter((id) => !ids.includes(id)))];
if (missingJavascriptTargets.length) fail('Missing JavaScript DOM targets: ' + missingJavascriptTargets.join(', '));

const versionMatches = [...html.matchAll(/(?:\.css|\.js)\?v=(\d+)/g)].map((match) => match[1]);
const versions = [...new Set(versionMatches)];
if (versions.length !== 1) fail('HTML asset versions differ: ' + versions.join(', '));
if (!serviceWorker.includes("srp-shell-v" + versions[0])) fail('Service-worker cache version differs from HTML');

const strippedCss = cssSource
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'/g, '');
let braceDepth = 0;
for (const character of strippedCss) {
    if (character === '{') braceDepth += 1;
    if (character === '}') braceDepth -= 1;
    if (braceDepth < 0) fail('CSS contains an unmatched closing brace');
}
if (braceDepth !== 0) fail('CSS contains unmatched opening braces');

if (!ids.includes('map-pick-hint') || !cssSource.includes('.map-pick-hint')) {
    fail('Map picker must use its own compact hint element');
}
if (cssSource.includes('.map-picking::after')) {
    fail('Map picker must not reuse the full-panel ::after overlay');
}

JSON.parse(fs.readFileSync('public/manifest.webmanifest', 'utf8'));

console.log(`frontend integrity: passed (${ids.length} IDs, ${ruKeys.length} translation keys, assets v${versions[0]})`);
