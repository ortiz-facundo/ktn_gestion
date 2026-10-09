// Run with: node tests/notification_worker_click.cjs
// Verify notification clicks preserve existing forms without an OS popup.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const origin = 'http://localhost:8070';
const destination = `${origin}/account/notifications/21/open`;

async function click(windows, target = destination) {
    const handlers = new Map();
    const opened = [];
    let closed = false;
    let work;
    const self = {
        location: {origin},
        addEventListener: (name, callback) => handlers.set(name, callback),
        clients: {
            matchAll: async () => windows,
            openWindow: async url => { opened.push(url); return {}; },
        },
    };
    vm.runInNewContext(fs.readFileSync(`${__dirname}/../public/sw.js`, 'utf8'), {self, URL});
    handlers.get('notificationclick')({
        notification: {data: {url: target}, close: () => { closed = true; }},
        waitUntil: promise => { work = promise; },
    });
    await work;
    assert.equal(closed, true);
    return opened;
}

(async () => {
    let navigated;
    let focused = false;
    const existing = {
        url: `${origin}/dashboard`,
        focus: async () => { focused = true; },
        navigate: async url => { navigated = url; return {}; },
    };
    assert.deepEqual(await click([existing]), [destination]);
    assert.equal(focused, false);
    assert.equal(navigated, undefined);
    console.log('PASS new tab opens without focusing or navigating an existing form');

    assert.deepEqual(await click([]), [destination]);
    console.log('PASS no application tab opens destination');
    assert.deepEqual(await click([], 'https://example.com/'), []);
    console.log('PASS external destinations are rejected');
    assert.deepEqual(await click([], '/account/notifications/21/open'), [destination]);
    console.log('PASS relative destination opens on the application origin');
    assert.deepEqual(await click([], null), [`${origin}/account/notifications`]);
    console.log('PASS missing destination opens the inbox in a new tab');
})().catch(error => { console.error(error); process.exitCode = 1; });
