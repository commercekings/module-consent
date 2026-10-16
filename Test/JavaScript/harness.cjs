const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const modules = path.resolve(__dirname, '../../..');

function browser(configs = {}) {
    const cookies = new Map();
    const writes = [];
    const events = {};
    const node = () => ({
        style: {}, childNodes: [], children: [],
        setAttribute() {}, appendChild() {}, removeChild() {},
        classList: {add() {}, remove() {}, toggle() {}},
        querySelectorAll: () => []
    });
    const head = node();
    const script = {src: 'https://shop.example.test/banner.js', tagName: 'SCRIPT', getAttribute: () => null};
    const document = {
        readyState: 'loading', currentScript: script, head,
        documentElement: Object.assign(node(), {lang: 'en'}),
        getElementById: id => configs[id] ? {textContent: JSON.stringify(configs[id])} : null,
        createElement: node, createTextNode: node,
        getElementsByTagName: () => [script],
        querySelector: selector => selector === 'head' ? head : null,
        querySelectorAll: () => [], addEventListener: (name, fn) => (events[name] ||= []).push(fn)
    };
    Object.defineProperty(document, 'cookie', {
        get: () => [...cookies].map(([key, value]) => `${key}=${value}`).join('; '),
        set: value => {
            writes.push(value);
            const pair = value.split(';')[0];
            const index = pair.indexOf('=');
            const key = pair.slice(0, index);
            if (/max-age=0(?:;|$)/i.test(value)) {
                cookies.delete(key);
            } else {
                cookies.set(key, pair.slice(index + 1));
            }
        }
    });
    const storage = () => {
        const values = new Map();
        return {getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key)};
    };
    const context = vm.createContext({
        document, location: {hostname: 'shop.example.test', protocol: 'https:', hash: ''},
        navigator: {language: 'en', userAgent: 'test'},
        localStorage: storage(), sessionStorage: storage(),
        setTimeout, clearTimeout, console,
        addEventListener: (name, fn) => (events[name] ||= []).push(fn)
    });
    context.window = context.self = context;
    return {context, document, cookies, writes, events};
}

function load(browser, module, dependencies = {}) {
    let exported;
    const shared = {
        'CommerceKings_Consent/js/consent/provider-core': 'module-consent/view/frontend/web/js/consent/provider-core.js',
        'CommerceKings_ConsentVanilla/js/adapter-core': 'module-consent-vanilla/view/frontend/web/js/adapter-core.js',
        'CommerceKings_ConsentVanilla/js/banner-core': 'module-consent-vanilla/view/frontend/web/js/banner-core.js'
    };
    browser.context.define = (names, factory) => {
        exported = factory(...names.map(name => {
            if (!(name in dependencies) && name in shared) return load(browser, shared[name]);
            if (!(name in dependencies)) throw new Error(`Missing dependency ${name}`);
            return dependencies[name];
        }));
    };
    browser.context.define.amd = {};
    vm.runInContext(fs.readFileSync(path.join(modules, module), 'utf8'), browser.context, {filename: module});
    return exported;
}

function provider(initial = false) {
    let allowed = initial;
    const listeners = {};
    return {
        hasConsent: () => allowed,
        onChange: (purpose, fn) => (listeners[purpose] ||= []).push(fn),
        change: (value, purpose = 'analytics') => {
            allowed = value;
            (listeners[purpose] || []).forEach(fn => fn(value));
        },
        notify: (value, purpose = 'analytics') => (listeners[purpose] || []).forEach(fn => fn(value))
    };
}

function pendingRequest(request, extra = {}) {
    const pending = {request, ...extra};
    let done, always;
    pending.complete = (response = request.url.endsWith('grant') ? {granted: true} : {withdrawn: 0}) => {
        if (done) done(response);
        if (always) always();
    };
    pending.fail = () => { if (always) always(); };
    pending.promise = {
        done: fn => { done = fn; return pending.promise; },
        always: fn => { always = fn; return pending.promise; }
    };
    return pending;
}

module.exports = {browser, load, provider, pendingRequest, modules};
