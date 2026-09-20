'use strict';
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {Worker} = require('node:worker_threads');
const {ENGINE_SHA256} = require('../assets/rankmath-analyzer.cjs');
const MAX_BODY = 2*1024*1024;
const digest = value => crypto.createHash('sha256').update(value).digest('hex');
const hmac = (value, secret) => crypto.createHmac('sha256', secret).update(value).digest('hex');
const equal = (a,b) => typeof a === 'string' && /^[a-f0-9]{64}$/.test(a) && crypto.timingSafeEqual(Buffer.from(a), Buffer.from(b));
function runtime() {
    const directory = path.join(__dirname, 'runtime');
    const manifest = JSON.parse(fs.readFileSync(path.join(directory, 'manifest.json'), 'utf8'));
    const scripts = {};
    if (manifest.rankMath !== '1.0.278' || manifest.hashes.analyzer !== ENGINE_SHA256) throw new Error('Unverified analyzer manifest.');
    for (const name of ['analyzer','lodash','hooks','i18n','autop','url','wordcount']) {
        scripts[name] = path.join(directory, name + '.js');
        if (digest(fs.readFileSync(scripts[name])) !== manifest.hashes[name]) throw new Error('Runtime checksum failed: ' + name);
    }
    return {manifest, scripts};
}
function runWorker(payload, scripts) {
    return new Promise((resolve, reject) => {
        const worker = new Worker(path.join(__dirname, 'worker.cjs'), {
            workerData: {...payload, scripts}, resourceLimits: {maxOldGenerationSizeMb: 128, maxYoungGenerationSizeMb: 16, stackSizeMb: 4}
        });
        let result, failure;
        const timer = setTimeout(() => { failure = 'timeout'; worker.terminate(); }, 15000);
        worker.on('message', message => { result = message.result; failure = message.error; worker.terminate(); });
        worker.on('error', () => { failure = 'analysis_failed'; });
        worker.on('exit', () => { clearTimeout(timer); if (!failure && result) resolve(result); else reject(new Error(failure || 'analysis_failed')); });
    });
}
function createServer(secret) {
    if (typeof secret !== 'string' || secret.length < 32 || secret.trim() !== secret) throw new Error('Set GNF5_SCORER_SECRET to a random secret of at least 32 characters.');
    const {manifest, scripts} = runtime();
    const seen = new Map(); let busy = false, reading = 0;
    const server = http.createServer(async (request, response) => {
        function send(status, data, signed = false) {
            if (response.destroyed || response.writableEnded) return;
            const body = JSON.stringify(data);
            const headers = {'Content-Type':'application/json', 'Cache-Control':'no-store', 'X-Content-Type-Options':'nosniff'};
            if (signed) headers['X-GNF5-Signature'] = hmac(body, secret);
            response.writeHead(status, headers); response.end(body);
        }
        if (request.method === 'GET' && request.url === '/health') {
            send(200, {status:'ok', protocol:1, rankMath:manifest.rankMath, wordpress:manifest.wordpress}); return;
        }
        if (request.method !== 'POST' || request.url !== '/v1/analyze') { send(404, {error:'not_found'}); request.resume(); return; }
        if (!/^application\/json(?:;|$)/i.test(request.headers['content-type'] || '') || Number(request.headers['content-length']) > MAX_BODY) {
            send(413, {error:'invalid_body'}); request.resume(); return;
        }
        if (reading >= 4) { send(429, {error:'busy'}); request.resume(); return; }
        let body;
        reading++;
        try {
            body = await new Promise((resolve, reject) => {
                let size = 0; const chunks = [];
                request.setTimeout(10000, () => request.destroy(new Error('timeout')));
                request.on('data', chunk => {
                    size += chunk.length;
                    if (size > MAX_BODY) { send(413, {error:'invalid_body'}); request.destroy(); reject(new Error('invalid_body')); }
                    else chunks.push(chunk);
                });
                request.on('end', () => { request.setTimeout(0); resolve(Buffer.concat(chunks)); });
                request.on('error', reject);
                request.on('aborted', () => reject(new Error('aborted')));
            });
        } catch (_) { send(400, {error:'invalid_body'}); return; }
        finally { reading--; }
        if (!equal(request.headers['x-gnf5-signature'], hmac(body, secret))) { send(401, {error:'unauthorized'}); return; }
        let input;
        try { input = JSON.parse(body.toString('utf8')); } catch (_) { send(400, {error:'invalid_body'}, true); return; }
        const requestId = input && input.requestId;
        const error = (status, code) => send(status, {requestId, error:code}, true);
        if (!input || input.protocol !== 1 || !/^[a-f0-9]{32}$/.test(requestId || '') || typeof input.timestamp !== 'number' || !Number.isInteger(input.timestamp)) { error(400, 'invalid_payload'); return; }
        const now = Math.floor(Date.now()/1000);
        if (Math.abs(now - input.timestamp) > 300) { error(400, 'expired'); return; }
        for (const [id, time] of seen) if (time < now - 600) seen.delete(id);
        if (seen.has(requestId)) { error(409, 'replayed'); return; }
        if (busy || seen.size >= 4096) { error(429, 'busy'); return; }
        const payload = input.payload;
        if (!payload || Object.keys(payload).some(key => !['version','values','config','keywordUsage','scriptHashes','fingerprint'].includes(key))
            || payload.version !== manifest.rankMath || !/^[a-f0-9]{64}$/.test(payload.fingerprint || '')
            || !payload.values || typeof payload.values.content !== 'string' || !payload.config || !payload.config.assessor || !payload.keywordUsage || !payload.scriptHashes) {
            error(400, 'invalid_payload'); return;
        }
        if (Object.keys(manifest.hashes).some(name => payload.scriptHashes[name] !== manifest.hashes[name])) { error(409, 'dependency_mismatch'); return; }
        seen.set(requestId, now); busy = true;
        try {
            const result = await runWorker(payload, scripts);
            if (result.engine !== ENGINE_SHA256 || result.fingerprint !== payload.fingerprint || typeof result.score !== 'number' || !Number.isFinite(result.score) || result.score < 0 || result.score > 100) throw new Error('analysis_failed');
            send(200, {requestId, result}, true);
        } catch (failure) {
            const code = failure.message === 'timeout' ? 'timeout' : 'analysis_failed';
            error(503, code);
            // No articles, secrets, URLs, analysis messages or stack traces in logs.
            console.error('SEO request failed: ' + code);
        } finally { busy = false; }
    });
    server.headersTimeout = 15000;
    server.requestTimeout = 15000;
    server.keepAliveTimeout = 5000;
    server.maxConnections = 32;
    return server;
}
if (require.main === module) {
    try {
        const server = createServer(process.env.GNF5_SCORER_SECRET);
        server.listen(Number(process.env.PORT || 10000), '0.0.0.0', () => console.log('GlobiqNews scoring service ready.'));
        process.on('SIGTERM', () => { server.close(); setTimeout(() => process.exit(0), 18000).unref(); });
    } catch (error) { console.error(error.message); process.exitCode = 1; }
}
module.exports = {createServer, hmac};
