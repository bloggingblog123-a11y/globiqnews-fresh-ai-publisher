'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const {createServer} = require('../../scoring-service/server.cjs');
const secret = crypto.randomBytes(32).toString('hex');
fs.writeFileSync(process.env.GNF5_TEST_SCORER_SECRET_FILE, secret);
createServer(secret).listen(8098, '127.0.0.1', () => console.log('Isolated test scorer listening on loopback 8098.'));
