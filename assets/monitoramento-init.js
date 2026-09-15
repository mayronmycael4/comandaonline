(function () {
    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function jsonText(v) {
        if (v == null) return '';
        if (typeof v === 'string') return esc(v);
        try { return esc(JSON.stringify(v)); } catch (_e) { return ''; }
    }

    function renderRows(rows, kind) {
        if (!rows.length) {
            return `<tr><td colspan="${kind === 'action' ? 5 : 5}" class="monitor-empty">Sem registros.</td></tr>`;
        }

        if (kind === 'action') {
            return rows.map(row => `
                <tr>
                    <td>${esc(row.created_at || '')}</td>
                    <td>${esc(row.actor_nome || row.actor_login || row.actor_id || '')}</td>
                    <td>${esc(row.acao || '')}</td>
                    <td>${esc(row.entidade || '')}${row.entidade_id ? ` #${esc(row.entidade_id)}` : ''}</td>
                    <td><pre class="json-pre">${jsonText(row.detalhes)}</pre></td>
                </tr>
            `).join('');
        }

        if (kind === 'api') {
            return rows.map(row => `
                <tr>
                    <td>${esc(row.created_at || '')}</td>
                    <td>${esc(row.rota || '')}</td>
                    <td>${esc(row.metodo || '')}</td>
                    <td>${esc(row.status_code || '')}</td>
                    <td>${esc(row.duracao_ms || '')} ms</td>
                </tr>
            `).join('');
        }

        return rows.map(row => `
            <tr>
                <td>${esc(row.created_at || '')}</td>
                <td>${esc(row.rota || '')}</td>
                <td>${esc(row.status_code || '')}</td>
                <td>${esc(row.error_code || '')}</td>
                <td>${esc(row.mensagem || '')}</td>
            </tr>
        `).join('');
    }

    async function carregar() {
        if (typeof Storage === 'undefined' || !Storage.requireAuth()) return;
        const limit = Math.max(1, Math.min(200, Number(document.getElementById('fLimit').value || 25)));
        const actorId = Number(document.getElementById('fActor').value || 0);
        const params = { limit };
        if (actorId > 0) {
            params.actor_id = actorId;
            params.actor_nome = 'Filtro';
            params.actor_login = 'Filtro';
            params.role = 'admin';
        }

        const data = await Storage.getMonitoramento(params);
        const actionRows = Array.isArray(data.action_log) ? data.action_log : [];
        const apiRows = Array.isArray(data.api_request_log) ? data.api_request_log : [];
        const errorRows = Array.isArray(data.error_events) ? data.error_events : [];

        document.getElementById('statAction').textContent = String((data.totais && data.totais.total_action_log) || actionRows.length || 0);
        document.getElementById('statApi').textContent = String((data.totais && data.totais.total_api_request_log) || apiRows.length || 0);
        document.getElementById('statErrors').textContent = String((data.totais && data.totais.total_error_events) || errorRows.length || 0);
        document.getElementById('actionBody').innerHTML = renderRows(actionRows, 'action');
        document.getElementById('apiBody').innerHTML = renderRows(apiRows, 'api');
        document.getElementById('errorBody').innerHTML = renderRows(errorRows, 'error');
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('btnAtualizarMonitor').addEventListener('click', () => carregar().catch(console.error));
        carregar().catch((e) => {
            console.error(e);
            alert('Falha ao carregar monitoramento: ' + (e && e.message ? e.message : e));
        });
    });
})();
