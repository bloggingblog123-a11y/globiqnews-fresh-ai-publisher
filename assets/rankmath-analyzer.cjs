'use strict';

// Execute the installed Rank Math engine. No scoring rules or weights live here.
const fs = require('fs');
const vm = require('vm');
const crypto = require('crypto');
const ENGINE_SHA256 = 'af1e4954b835a846b44d37eb1aa87951475def96bd6f257db1e394afa781e61f';

async function analyze(input) {
    if (Number(process.versions.node.split('.')[0]) < 18) throw new Error('Node.js 18 or newer is required.');
    if (!input || input.version !== '1.0.278' || !input.values || !input.config || !input.scripts) {
        throw new Error('Unsupported Rank Math analysis payload.');
    }
    const engine = fs.readFileSync(input.scripts.analyzer);
    if (crypto.createHash('sha256').update(engine).digest('hex') !== ENGINE_SHA256) {
        throw new Error('Installed Rank Math analyzer has changed; compatibility must be verified.');
    }
    const context = { console: {log() {}, warn() {}, error() {}}, URL, URLSearchParams, setTimeout, clearTimeout };
    context.window = context;
    context.self = context;
    context.wp = {};
    context.rankMath = input.config;
    // keywordNotUsed performs an AJAX lookup in the editor. Supply the same database
    // lookup result collected by PHP; do not grant the analyzer network access.
    context.jQuery = { ajax(request) {
        const keyword = request && request.data && request.data.keyword;
        const lookupKey = Object.keys(input.keywordUsage || {}).find(key => normalize(key).toLowerCase() === keyword);
        if (request.data.action !== 'rank_math_is_keyword_new' ||
            lookupKey === undefined) {
            throw new Error('Unexpected or unresolved Rank Math keyword lookup.');
        }
        return { done(callback) { queueMicrotask(() => callback({isNew: input.keywordUsage[lookupKey]})); return this; } };
    } };
    const sandbox = vm.createContext(context);
    for (const name of ['lodash', 'hooks', 'i18n', 'autop', 'url', 'wordcount']) {
        if (typeof input.scripts[name] !== 'string') throw new Error('Missing WordPress analysis dependency: ' + name);
        vm.runInContext(fs.readFileSync(input.scripts[name], 'utf8'), sandbox, {timeout: 3000, filename: name + '.js'});
        if (name === 'lodash') context.lodash = context._;
    }
    vm.runInContext(engine.toString('utf8'), sandbox, {timeout: 3000, filename: 'rank-math-analyzer.js'});
    const {Paper, Analyzer, ResultManager} = context.rankMathAnalyzer;
    const values = input.values;
    const normalize = text => Object.entries(input.config.assessor.diacritics || {}).reduce(
        (value, [replacement, pattern]) => value.replace(new RegExp(pattern, 'g'), replacement), text);
    const editorContent = values.content.replace(/&(?:amp|quot|#(?:0+)?39);/g, entity =>
        entity === '&amp;' ? '&' : entity === '&quot;' ? '"' : "'");
    const tests = input.config.assessor.researchesTests;
    if (!Array.isArray(tests) || tests.length === 0 || !values.keyword || !values.content) {
        throw new Error('Final article, focus keyword, or analysis tests are missing.');
    }
    const paper = new Paper('', {locale: input.config.localeFull});
    paper.setTitle(values.title);
    paper.setDescription(values.description);
    paper.setText(editorContent);
    paper.setKeyword(normalize(values.keyword));
    paper.setKeywords(values.keywords);
    paper.setPermalink(values.url);
    paper.setUrl(values.url);
    paper.setSchema(values.schemas || {});
    paper.setPostType(values.post_type);
    if (values.thumbnail) paper.setThumbnail(values.thumbnail);
    if (values.thumbnailAlt) paper.setThumbnailAltText(normalize(values.thumbnailAlt));
    paper.setContentAI(values.hasContentAi);
    const analyzer = new Analyzer({i18n: context.wp.i18n, analyses: tests});
    for (const test of tests) {
        if (!Object.prototype.hasOwnProperty.call(analyzer.defaultAnalyses, test)) {
            throw new Error('Unsupported custom Rank Math test: ' + test);
        }
    }
    const results = await analyzer.analyzeSome(tests, paper);
    const manager = new ResultManager();
    manager.update(paper.getKeyword(), results, true);
    const score = manager.getScore(paper.getKeyword());
    if (typeof score !== 'number' || !Number.isFinite(score) || score < 0 || score > 100) {
        throw new Error('Rank Math returned an invalid score.');
    }
    const locale = input.config.localeFull.split('_')[0];
    const details = Object.fromEntries(Object.entries(results).map(([name, result]) => [name, {
        score: result.getScore(), maximum: result.getMaxScore(locale),
        message: typeof result.getText === 'function' ? result.getText() : ''
    }]));
    return {score, fingerprint: input.fingerprint, engine: ENGINE_SHA256, version: input.version, tests: details};
}

module.exports = {analyze, ENGINE_SHA256};
if (require.main === module) {
    let input = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', chunk => {
        input += chunk;
        if (Buffer.byteLength(input) > 2 * 1024 * 1024) { process.stderr.write('Analysis payload exceeds 2 MB.'); process.exit(1); }
    });
    process.stdin.on('end', async () => {
        try { process.stdout.write(JSON.stringify(await analyze(JSON.parse(input)))); }
        catch (error) { process.stderr.write(String(error.message).slice(0, 1000)); process.exitCode = 1; }
    });
}
