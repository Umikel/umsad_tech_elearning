// Exercise the course page's actual script without network or payment operations.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../course-detail.php'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g, '"fixture"');
function scenario(action, hasGuide = true, plan = 'online') {
    const elements = {};
    function element(id) {
        return elements[id] ||= { dataset: { version: 'current' }, listeners: {}, disabled: false,
            addEventListener(event, fn) { this.listeners[event] = fn; },
            focus() {}, showModal() { this.open = true; }, close() { this.open = false; this.listeners.close?.(); } };
    }
    const button = element('enroll');
    button.dataset = { enrollmentCourse: '1', enrollmentAction: action, learningPlan: plan };
    const context = { document: {
        getElementById(id) { return id === 'courseRegistrationGuide' && !hasGuide ? null : element(id); },
        querySelector() { return null; }, querySelectorAll() { return [button]; }
    }, window: {}, URL };
    vm.createContext(context);
    vm.runInContext(script, context);
    vm.runInContext('var requests = []; enrollCourse = id => requests.push({id, action: "paid", ...courseGuideAcknowledgment}); freeEnroll = id => requests.push({id, action: "free", ...courseGuideAcknowledgment});', context);
    const click = () => button.listeners.click.call(button);
    if (!hasGuide) { click(); assert.equal(context.requests.length, 1); return; }
    element('previewCourseGuide').listeners.click();
    assert.equal(element('courseGuideForm').hidden, true);
    assert.equal(context.requests.length, 0);
    element('courseGuideClose').listeners.click();
    click();
    assert.equal(element('courseGuideForm').hidden, false);
    assert.equal(element('courseGuideContinue').disabled, true);
    element('courseGuideForm').listeners.submit({ preventDefault() {} });
    assert.equal(context.requests.length, 0);
    element('courseGuideCancel').listeners.click();
    assert.equal(context.requests.length, 0);
    click();
    element('courseGuideAccepted').checked = true;
    element('courseGuideAccepted').listeners.change();
    assert.equal(element('courseGuideContinue').disabled, false);
    element('courseGuideForm').listeners.submit({ preventDefault() {} });
    assert.equal(context.requests.length, 1);
    assert.equal(context.requests[0].action, action);
    assert.equal(context.requests[0].guide_accepted, true);
    assert.equal(context.requests[0].guide_version, 'current');
    assert.equal(context.requests[0].learning_plan, plan);
    element('courseGuideForm').listeners.submit({ preventDefault() {} });
    assert.equal(context.requests.length, 1);
}
scenario('paid', true, 'sunday_physical'); scenario('paid'); scenario('free'); scenario('free', false);
console.log('PASS: preview, cancel, required checkbox, free/paid consent payloads, duplicate submit and unrelated courses.');
