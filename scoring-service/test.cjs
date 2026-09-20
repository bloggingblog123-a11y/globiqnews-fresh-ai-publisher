'use strict';
const {test, before, after} = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const {createServer, hmac} = require('./server.cjs');
const fixtures = require('../tools/tests/rankmath-fixtures.json');
const baseline = require('../tools/tests/analyzer-config.json');
const manifest = require('./runtime/manifest.json');
const secret = crypto.randomBytes(32).toString('hex');
let server, address;
before(async () => {
    server = createServer(secret);
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    address = 'http://127.0.0.1:' + server.address().port;
});
after(async () => { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); });
function input(score = 80) {
    return {protocol:1, requestId:crypto.randomBytes(16).toString('hex'), timestamp:Math.floor(Date.now()/1000), payload:{
        version:'1.0.278', values:fixtures[score].values, config:baseline.config, keywordUsage:baseline.keywordUsage,
        fingerprint:crypto.randomBytes(32).toString('hex'), scriptHashes:manifest.hashes
    }};
}
async function send(value, key = secret) {
    const body = JSON.stringify(value);
    const response = await fetch(address + '/v1/analyze', {method:'POST', headers:{'Content-Type':'application/json', 'X-GNF5-Signature':hmac(body,key)}, body});
    const raw = await response.text();
    if (response.status !== 401) assert.equal(response.headers.get('x-gnf5-signature'),hmac(raw,secret));
    return {status:response.status, body:JSON.parse(raw)};
}
test('health requires no article or secret', async () => {
    const response = await fetch(address + '/health');
    assert.equal(response.status,200); assert.equal((await response.json()).rankMath,'1.0.278');
});
test('unauthorized requests cannot run analysis', async () => {
    assert.equal((await send(input(), 'wrong-secret')).status,401);
});
test('actual analyzer returns 76,79,80,81,84,85 through HTTP', async () => {
    for (const score of [76,79,80,81,84,85]) {
        const value = input(score), response = await send(value);
        assert.equal(response.status,200,JSON.stringify(response.body));
        assert.equal(response.body.result.score,score);
        assert.equal(response.body.result.fingerprint,value.payload.fingerprint);
        assert.equal(response.body.requestId,value.requestId);
    }
});
test('rejects replay, expiry, dependency changes and supplied executable paths', async () => {
    const replay = input(); assert.equal((await send(replay)).status,200); assert.equal((await send(replay)).status,409);
    const expired = input(); expired.timestamp -= 301; assert.equal((await send(expired)).body.error,'expired');
    const changed = input(); changed.payload.scriptHashes = {...manifest.hashes,autop:'invalid'};
    assert.equal((await send(changed)).body.error,'dependency_mismatch');
    const executable = input(); executable.payload.scripts = {analyzer:'/arbitrary/file.js'};
    assert.equal((await send(executable)).status,400);
});
test('only one analysis at a time', async () => {
    const responses = await Promise.all([send(input()),send(input())]);
    assert.deepEqual(responses.map(value => value.status).sort(),[200,429]);
});
test('missing keyword fails with no score', async () => {
    const missing = input(); missing.payload.values = {...missing.payload.values,keyword:''};
    const response = await send(missing);
    assert.equal(response.status,503); assert.equal(response.body.error,'analysis_failed'); assert.equal(response.body.result,undefined);
});
