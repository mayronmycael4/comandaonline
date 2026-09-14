const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const message = 'O modulo "cozinha" nao esta disponivel no plano contratado.';
const list = { children: [], replaceChildren() { this.children = []; }, appendChild(node) { this.children.push(node); } };
const counter = {}, timestamp = {};
let resolve;
const finished = new Promise(r => resolve = r);
const context = {
    localStorage: { getItem() { return null; } },
    document: { createElement() { return {setAttribute(name, value) { this[name] = value; }}; } },
    fetch: async () => ({ok:false,status:403,json:async()=>({message})}),
    setTimeout() { resolve(); }, clearTimeout() {}, Date, console
};
vm.runInNewContext(fs.readFileSync('cozinha-shared.js','utf8')+'\nCozinhaModule.startPolling(opts);', {...context, opts:{listContainer:list,contadorEl:counter,timestampEl:timestamp}});
finished.then(() => {
    assert.equal(list.children[0].textContent, message);
    assert.equal(list.children[0].role, 'alert');
    assert.equal(counter.textContent, 'Consulta indisponivel');
    assert.equal(timestamp.textContent, 'Sem confirmacao do servidor');
    console.log('PASS: HTTP 403 mostra motivo e nao simula fila vazia');
});
