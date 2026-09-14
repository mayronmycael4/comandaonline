(function () {
    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtJson(v) {
        if (!v) return '';
        if (typeof v === 'string') return esc(v);
        try { return esc(JSON.stringify(v)); } catch (_e) { return ''; }
    }

    async function carregar() {
        if (typeof Storage === 'undefined' || !Storage.requireAuth()) return;

        const inicio = document.getElementById('fInicio').value;
        const fim = document.getElementById('fFim').value;
        const acao = document.getElementById('fAcao').value.trim();
        const entidade = document.getElementById('fEntidade').value.trim();
        const q = document.getElementById('fBusca').value.trim();

        const params = { limit: 200 };
        if (inicio) params.inicio = inicio;
        if (fim) params.fim = fim;
        if (acao) params.acao = acao;
        if (entidade) params.entidade = entidade;
        if (q) params.q = q;

        const result = await Storage.getAuditoria(params);
        const rows = Array.isArray(result.registros) ? result.registros : [];
        const formatter = new Intl.DateTimeFormat('pt-BR', {
            timeZone: result.timezone || 'America/Belem', dateStyle: 'short', timeStyle: 'medium'
        });

        document.getElementById('auditMeta').textContent = `Total: ${result.total || rows.length}`;

        document.getElementById('auditBody').innerHTML = rows.map((r) => {
            return `
                <tr>
                    <td>${esc(r.created_at ? formatter.format(new Date(r.created_at)) : '')}</td>
                    <td>${esc(r.actor_nome || r.actor_login || r.actor_id || '')}</td>
                    <td>${esc(r.acao || '')}</td>
                    <td>${esc(r.entidade || '')}</td>
                    <td>${esc(r.entidade_id || '')}</td>
                    <td><small>${fmtJson(r.detalhes)}</small></td>
                </tr>
            `;
        }).join('');
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('btnPesquisar').addEventListener('click', carregar);
        carregar().catch((e) => {
            console.error(e);
            alert('Falha ao carregar auditoria: ' + (e && e.message ? e.message : e));
        });
    });
})();
