const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = {Date, Intl};
vm.createContext(context);
vm.runInContext(fs.readFileSync('assets/storage.js', 'utf8') + '\nglobalThis.storage = Storage;', context);
for (const zone of ['UTC', 'America/Belem', 'Asia/Tokyo']) {
    process.env.TZ = zone;
    assert.equal(context.storage.companyDate('2026-09-12T02:59:59Z'), '2026-09-11');
    assert.equal(context.storage.companyDate('2026-09-12T03:00:00Z'), '2026-09-12');
    assert.match(context.storage.companyDateTime('2026-09-11T16:01:56Z'), /13:01:56/);
}
console.log('PASS dashboard business day and Mesa 4 display independent of device timezone');
