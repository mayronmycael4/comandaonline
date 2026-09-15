const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function check(zone, timestamp) {
    process.env.TZ = zone;
    const serverNow = Date.parse('2026-09-11T16:12:18Z');
    class ClientDate extends Date {
        static now() { return serverNow + 3600000; } // Device clock is an hour ahead.
    }
    const container = {innerHTML: '', querySelectorAll() { return []; }};
    const timestampEl = {};
    let complete;
    const done = new Promise(resolve => { complete = resolve; });
    const context = {
        Date: ClientDate, console,
        localStorage: {getItem(key) { return key === 'cozinha_som' ? 'off' : null; }, setItem() {}},
        fetch: async () => ({ok: true, json: async () => ({server_time_ms: serverNow, timezone: 'America/Belem', pedidos: [{
            comanda_id: 65, numero_mesa: '4', comanda_status: 'aberta',
            comanda_criada_em: timestamp, itens: [{item_id: 32, nome_item: 'QA',
                quantidade: 1, kitchen_status: 'recebido', kitchen_setor: 'cozinha', item_criado_em: timestamp}]
        }]})}),
        setTimeout() { complete(); }, clearTimeout() {},
        opts: {listContainer: container, timestampEl, onError(error) { throw error; }}
    };
    vm.runInNewContext(fs.readFileSync('assets/cozinha-shared.js', 'utf8') + '\nCozinhaModule.startPolling(opts);', context);
    await done;
    assert.match(container.innerHTML, /10 min/, `${zone}: elapsed time must use the server instant`);
    assert.equal(timestampEl.textContent, 'Atualizado 13:12:18', `${zone}: business timezone and server clock`);
}
(async () => {
    for (const zone of ['UTC', 'America/Belem', 'Asia/Tokyo']) {
        await check(zone, '2026-09-11T16:02:18Z');
        await check(zone, '2026-09-11T13:02:18-03:00');
    }
    console.log('PASS KDS elapsed time in three device zones, explicit offsets and clock skew');
})().catch(error => { console.error(error); process.exitCode = 1; });
