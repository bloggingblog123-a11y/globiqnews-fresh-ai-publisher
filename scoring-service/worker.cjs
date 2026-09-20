'use strict';
const {parentPort, workerData} = require('node:worker_threads');
const {analyze} = require('../assets/rankmath-analyzer.cjs');
analyze(workerData).then(result => parentPort.postMessage({result}), () => parentPort.postMessage({error: 'analysis_failed'}));
