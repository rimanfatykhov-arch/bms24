// Reproducible custom Apache ECharts build for local_kbacompany (issue #192).
//
//   cd tools/echarts && npm ci && npm run build     -> writes the AMD module and the licence files
//   cd tools/echarts && npm ci && npm run check     -> rebuilds in memory and fails if the committed files differ
//
// Output (byte-identical pair, repository convention src == build):
//   local/kbacompany/amd/src/echarts-lazy.js, local/kbacompany/amd/build/echarts-lazy.min.js
//   local/kbacompany/thirdparty/echarts/{LICENSE,NOTICE,LICENSE-d3,LICENSE-zrender,LICENSE-tslib}
// The "-lazy" suffix keeps the library out of Moodle's all-modules core/first.js bundle: it is fetched only by the
// page that requires it (the Information Centre), once per page, never per tile.
import {build} from 'esbuild';
import {readFileSync, writeFileSync, mkdirSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = join(here, '..', '..', 'local', 'kbacompany');
const PINNED = {echarts: '6.1.0', zrender: '6.1.0', tslib: '2.3.0', esbuild: '0.28.2'};
const check = process.argv.includes('--check');

const installed = {};
for (const name of Object.keys(PINNED)) {
    installed[name] = JSON.parse(readFileSync(join(here, 'node_modules', name, 'package.json'), 'utf8')).version;
    if (installed[name] !== PINNED[name]) {
        console.error(`${name} ${installed[name]} installed, ${PINNED[name]} pinned: run "npm ci" in tools/echarts`);
        process.exit(1);
    }
}

const lf = text => text.replace(/\r\n?/g, '\n').replace(/\s*$/, '\n');

const result = await build({
    entryPoints: [join(here, 'entry.mjs')],
    bundle: true,
    format: 'iife',
    globalName: 'bms24echarts',
    platform: 'browser',
    target: 'es2015',
    minify: true,
    legalComments: 'none',
    charset: 'utf8',
    define: {'process.env.NODE_ENV': '"production"', '__DEV__': 'false'},
    write: false,
    logLevel: 'warning',
});
const body = result.outputFiles[0].text;
const header = '/*\n' +
    ' * Apache ECharts ' + PINNED.echarts + ' (custom build: line chart, grid, tooltip, inside data zoom, mark line,\n' +
    ' * mark area, SVG renderer) with ZRender ' + PINNED.zrender + ' and tslib ' + PINNED.tslib + '.\n' +
    ' * Apache ECharts: Copyright 2017-2026 The Apache Software Foundation, Apache License 2.0.\n' +
    ' * ZRender: Copyright (c) 2017, Baidu Inc., BSD 3-Clause. Portions of d3.js, BSD 3-Clause. tslib: 0BSD.\n' +
    ' * Licence texts and NOTICE: local/kbacompany/thirdparty/echarts/. Build: tools/echarts (npm ci && npm run build).\n' +
    ' * Generated file, do not edit.\n' +
    ' */\n';
// Anonymous AMD module; Moodle names it local_kbacompany/echarts-lazy at the first define call, which must be this one.
const amd = lf(header + 'define([], function() {\n' + body + 'return bms24echarts;\n});');
if (amd.indexOf('define(') !== header.length) {
    console.error('the AMD wrapper must be the first define( in the file');
    process.exit(1);
}

const outputs = {
    [join(plugin, 'amd', 'src', 'echarts-lazy.js')]: amd,
    [join(plugin, 'amd', 'build', 'echarts-lazy.min.js')]: amd,
};
const licences = {
    LICENSE: join('echarts', 'LICENSE'),
    NOTICE: join('echarts', 'NOTICE'),
    'LICENSE-d3': join('echarts', 'licenses', 'LICENSE-d3'),
    'LICENSE-zrender': join('zrender', 'LICENSE'),
    'LICENSE-tslib': join('tslib', 'LICENSE.txt'),
};
for (const [name, source] of Object.entries(licences)) {
    outputs[join(plugin, 'thirdparty', 'echarts', name)] = lf(readFileSync(join(here, 'node_modules', source), 'utf8'));
}

let differs = 0;
for (const [path, text] of Object.entries(outputs)) {
    if (check) {
        let current = null;
        try { current = readFileSync(path, 'utf8'); } catch (e) { current = null; }
        if (current !== text) { console.error('differs: ' + path); differs++; }
    } else {
        mkdirSync(dirname(path), {recursive: true});
        writeFileSync(path, text);
    }
}
console.log(JSON.stringify({check, versions: installed, bytes: Buffer.byteLength(amd), differs}));
process.exit(differs ? 1 : 0);
