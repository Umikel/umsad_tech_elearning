// Run with node tests/payment-callback.cjs. No network or payment operations.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../payment-callback.php'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g, '"fixture"');
async function check(mode) {
    const elements = {};
    let requestCount = 0;
    let destination;
    let timeout;
    let cleared = false;
    const context = {
        document: { getElementById(id) { return elements[id] ||= { classList: { add() {}, remove() {} }, addEventListener() {} }; } },
        AbortController,
        window: {
            setTimeout(fn, delay) { if (delay === 45000) timeout = fn; else fn(); return 1; },
            clearTimeout() { cleared = true; },
            location: { assign(url) { destination = url; } }
        },
        fetch: async (url, options) => {
            requestCount++;
            if (mode === 'timeout') return new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(Object.assign(new Error(), { name: 'AbortError' }))));
            return { ok: mode === 'success', json: async () => {
                if (mode === 'invalid') throw new Error('invalid JSON');
                return mode === 'success' ? { success: true, data: { redirect: '/student/my-courses.php' } } : { success: false, message: 'Please retry' };
            } };
        }
    };
    vm.runInNewContext(script, context);
    if (mode === 'timeout') timeout();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(requestCount, 1, 'verification must start');
    assert.equal(cleared, true, 'timeout must be cleared');
    if (mode === 'success') assert.equal(destination, 'fixture/student/my-courses.php');
    else {
        assert.equal(elements.retryPaymentVerification.disabled, false);
        assert.equal(elements['payment-return-title'].textContent, 'Confirmation is still pending.');
        assert.equal(destination, undefined);
    }
}
(async () => {
    for (const mode of ['success', 'failure', 'invalid', 'timeout']) await check(mode);
    console.log('PASS: verification starts; success redirects; API errors, invalid JSON and timeouts allow retry.');
})().catch(error => { console.error(error); process.exitCode = 1; });
