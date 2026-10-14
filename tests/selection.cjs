const { readFileSync } = require('node:fs');
const { runInNewContext } = require('node:vm');
const assert = require('node:assert/strict');
const handlers = {};
let click;
runInNewContext(readFileSync(require('node:path').join(__dirname, '../public/assets/selection.js'), 'utf8'), {
  document: { addEventListener(event, callback) { handlers[event] = callback; if (event === 'click') click = callback; } },
});
const projectA = [{ checked: false }, { checked: false }, { checked: false, disabled: true }];
const projectB = [{ checked: false }];
const section = { querySelectorAll(selector) {
  assert.equal(selector, 'input[data-entry-selection]:not(:disabled)');
  return projectA.filter(input => !input.disabled);
} };
const button = { dataset: { selectProject: 'all' }, closest(selector) { assert.equal(selector, '.hours-group'); return section; } };
const event = { target: { closest(selector) { assert.equal(selector, '[data-select-project]'); return button; } } };
click(event);
assert.equal(projectA[0].checked, true); assert.equal(projectA[1].checked, true);
assert.equal(projectA[2].checked, false); assert.equal(projectB[0].checked, false);
button.dataset.selectProject = 'none'; click(event);
assert.equal(projectA[0].checked, false); assert.equal(projectA[1].checked, false);
click({ target: { closest() { return null; } } });
console.log('Selectie-JS geslaagd: select all/deselect all per project; disabled en andere projecten blijven ongemoeid.');

const target = { value: '' };
let submissions = 0;
const form = { id: 'preview-selection', dataset: {}, elements: { namedItem(name) { assert.equal(name, 'preview_target'); return target; } }, requestSubmit() { handlers.submit({ target: this, preventDefault() { throw new Error('Unexpected duplicate'); } }); submissions++; } };
const radio = { checked: true, form, dataset: { previewProject: '42' } };
const change = () => handlers.change({ target: { closest(selector) { assert.equal(selector, 'input[data-preview-project]'); return radio; } } });
change();assert.equal(submissions, 1);assert.equal(target.value, '42');
change();assert.equal(submissions, 1);
let prevented = false;handlers.submit({ target: form, preventDefault() { prevented = true; } });assert.equal(prevented, true);
handlers.submit({ target: { id: 'prepare-42' }, preventDefault() { throw new Error('Preparation intercepted'); } });
form.dataset = {};radio.checked = false;change();assert.equal(submissions, 1);
radio.checked = true;form.requestSubmit = undefined;change();assert.equal(submissions, 1);
handlers.change({ target: { closest() { return null; } } });
console.log('Preview-JS geslaagd: bestaande form, projecttarget, één submit, fallback en andere formulieren ongemoeid.');
