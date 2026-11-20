'use strict';

const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const vm = require('node:vm');

const php = process.argv[2] || 'php';
const renderer = path.join(__dirname, 'regression.php');

function setup(scenario, approved = false) {
    const html = execFileSync(php, ['-n', renderer, '--render-preview', scenario], {encoding: 'utf8'});
    const handlers = {};
    const status = {value: scenario.endsWith('-publish') ? 'publish' : 'draft'};
    const checkbox = {checked: false, addEventListener() {}};
    const button = {value: 'Update', closest: () => form};
    const statusBox = {hidden: true};
    const form = {
        addEventListener(name, fn) { (handlers[name] ||= []).push(fn); },
        querySelector(selector) {
            if (selector.includes('aiw_import_status')) return scenario === 'no-publish' ? null : status;
            if (selector.includes('aiw_create_new')) return scenario.startsWith('update-') ? checkbox : null;
            return null;
        },
    };
    const elements = {
        aiw_create_new: checkbox,
        aiw_confirm_button: button,
        aiw_import_status: status,
        aiw_new_article_status: statusBox,
    };
    let confirmations = 0;
    const context = {
        document: {getElementById: id => elements[id], currentScript: {closest: () => form}},
        window: {confirm() { confirmations++; return approved; }},
    };
    vm.createContext(context);
    for (const match of html.matchAll(/<script>([\s\S]*?)<\/script>/g)) {
        vm.runInContext(match[1], context);
    }
    return {
        status, checkbox,
        submit() {
            let prevented = false;
            for (const handler of handlers.submit || []) {
                handler({preventDefault() { prevented = true; }});
            }
            return {prevented, confirmations};
        },
    };
}

for (const scenario of ['new-publish', 'update-publish']) {
    const declined = setup(scenario);
    declined.checkbox.checked = true;
    assert.deepEqual(declined.submit(), {prevented: true, confirmations: 1}, `${scenario}: cancellation must block publication`);
    const approved = setup(scenario, true);
    approved.checkbox.checked = true;
    assert.deepEqual(approved.submit(), {prevented: false, confirmations: 1}, `${scenario}: approval must permit submission`);
    console.log(`PASS: ${scenario} requires exactly one publication confirmation`);
}

const draft = setup('new-draft');
assert.deepEqual(draft.submit(), {prevented: false, confirmations: 0});
draft.status.value = 'publish';
assert.deepEqual(draft.submit(), {prevented: true, confirmations: 1});
console.log('PASS: selecting publish on a draft-default import also requires confirmation');

const update = setup('update-publish');
assert.deepEqual(update.submit(), {prevented: true, confirmations: 1});
console.log('PASS: updating a published article retains its separate confirmation');

const nonPublisher = setup('no-publish');
assert.deepEqual(nonPublisher.submit(), {prevented: false, confirmations: 0});
console.log('PASS: draft-only import does not ask for publication confirmation');
