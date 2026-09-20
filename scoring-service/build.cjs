'use strict';
// Fetch only pinned official distributions at build time. Requests never supply code.
const fs = require('node:fs');
const path = require('node:path');
const https = require('node:https');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const {ENGINE_SHA256} = require('../assets/rankmath-analyzer.cjs');
const sources = {
    wordpress: {url: 'https://wordpress.org/wordpress-7.1.zip', sha256: 'd1ae02b5ae18428031ffc3943659fa87ab361d827f4aa804adf9276e4dc75df6'},
    rankmath: {url: 'https://downloads.wordpress.org/plugin/seo-by-rank-math.1.0.278.zip', sha256: '06b7b30b0d7f350fae929519c9c5265488c7b1f24ebe5650988de90975a1e73f'}
};
const digest = data => crypto.createHash('sha256').update(data).digest('hex');
function download(url, redirects = 0) {
    if (!['wordpress.org','downloads.wordpress.org'].includes(new URL(url).hostname) || !url.startsWith('https:')) throw new Error('Unexpected download host.');
    return new Promise((resolve, reject) => {
        const request = https.get(url, {headers: {'User-Agent': 'GlobiqNews-Scorer-Build/5.31'}}, response => {
            if (response.statusCode >= 300 && response.statusCode < 400 && redirects < 4 && response.headers.location) {
                response.resume();
                try { download(new URL(response.headers.location, url).href, redirects + 1).then(resolve, reject); } catch (error) { reject(error); }
                return;
            }
            if (response.statusCode !== 200) { response.resume(); reject(new Error('Official download returned HTTP ' + response.statusCode)); return; }
            const chunks = []; let total = 0;
            response.on('data', chunk => { total += chunk.length; if (total > 64*1024*1024) request.destroy(new Error('Download too large.')); else chunks.push(chunk); });
            response.on('end', () => resolve(Buffer.concat(chunks)));
            response.on('error', reject);
        });
        request.setTimeout(60000, () => request.destroy(new Error('Download timed out.')));
        request.on('error', reject);
    });
}
// Extract specific members from a hash-verified ZIP, never arbitrary archive paths.
function zipMember(zip, wanted) {
    let end = zip.length - 22;
    while (end >= Math.max(0, zip.length - 65557) && zip.readUInt32LE(end) !== 0x06054b50) end--;
    if (end < 0 || zip.readUInt32LE(end) !== 0x06054b50) throw new Error('Invalid ZIP directory.');
    let cursor = zip.readUInt32LE(end + 16);
    const count = zip.readUInt16LE(end + 10);
    for (let i = 0; i < count; i++) {
        if (zip.readUInt32LE(cursor) !== 0x02014b50) throw new Error('Invalid ZIP entry.');
        const nameLength = zip.readUInt16LE(cursor + 28);
        const name = zip.subarray(cursor + 46, cursor + 46 + nameLength).toString('utf8');
        if (name === wanted) {
            const method = zip.readUInt16LE(cursor + 10), size = zip.readUInt32LE(cursor + 20), plainSize = zip.readUInt32LE(cursor + 24);
            const local = zip.readUInt32LE(cursor + 42);
            if (plainSize > 4*1024*1024 || zip.readUInt32LE(local) !== 0x04034b50) throw new Error('Invalid ZIP member.');
            const start = local + 30 + zip.readUInt16LE(local + 26) + zip.readUInt16LE(local + 28);
            const packed = zip.subarray(start, start + size);
            const content = method === 0 ? packed : method === 8 ? zlib.inflateRawSync(packed, {maxOutputLength: 4*1024*1024}) : null;
            if (!content || content.length !== plainSize) throw new Error('Unsupported ZIP member.');
            return content;
        }
        cursor += 46 + nameLength + zip.readUInt16LE(cursor + 30) + zip.readUInt16LE(cursor + 32);
    }
    throw new Error('Missing official file: ' + wanted);
}
async function build() {
    const archives = {};
    for (const [name, source] of Object.entries(sources)) {
        const flag = process.argv.indexOf('--' + name + '-zip');
        const data = flag === -1 ? await download(source.url) : fs.readFileSync(process.argv[flag + 1]);
        if (digest(data) !== source.sha256) throw new Error('Official ' + name + ' archive has changed; review its checksum before building.');
        archives[name] = data;
    }
    const directory = path.join(__dirname, 'runtime');
    fs.mkdirSync(directory, {recursive: true});
    const members = {analyzer: ['rankmath','seo-by-rank-math/assets/admin/js/analyzer.js'], lodash: ['wordpress','wordpress/wp-includes/js/dist/vendor/lodash.min.js']};
    for (const name of ['hooks','i18n','autop','url','wordcount']) members[name] = ['wordpress', 'wordpress/wp-includes/js/dist/' + name + '.min.js'];
    const hashes = {};
    for (const [name, [source, member]] of Object.entries(members)) {
        const content = zipMember(archives[source], member);
        hashes[name] = digest(content);
        fs.writeFileSync(path.join(directory, name + '.js'), content);
    }
    if (hashes.analyzer !== ENGINE_SHA256) throw new Error('Unverified Rank Math engine.');
    fs.writeFileSync(path.join(directory, 'wordpress-license.txt'), zipMember(archives.wordpress, 'wordpress/license.txt'));
    fs.writeFileSync(path.join(directory, 'rankmath-readme.txt'), zipMember(archives.rankmath, 'seo-by-rank-math/readme.txt'));
    fs.writeFileSync(path.join(directory, 'manifest.json'), JSON.stringify({rankMath: '1.0.278', wordpress: '7.1', hashes}, null, 2) + '\n');
    console.log('Verified Rank Math 1.0.278 and WordPress 7.1 analysis files ready.');
}
if (require.main === module) build().catch(error => { console.error(error.message); process.exitCode = 1; });
module.exports = {build, zipMember};
