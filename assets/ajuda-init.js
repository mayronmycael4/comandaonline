/* ajuda-init.js — página de Ajuda e Feedback */
document.addEventListener('DOMContentLoaded', () => {
    if (!Storage.requireAuth()) return;

    // Tipo selecionado
    let tipoSelecionado = 'sugestao';
    document.getElementById('tipoGrid').addEventListener('click', (e) => {
        const btn = e.target.closest('.tipo-btn');
        if (!btn) return;
        document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        tipoSelecionado = btn.dataset.tipo;
        document.getElementById('feedbackTipo').value = tipoSelecionado;
    });

    document.getElementById('formFeedback').addEventListener('submit', async (e) => {
        e.preventDefault();
        const session = Storage.getSession();
        const payload = {
            tipo: tipoSelecionado,
            mensagem: document.getElementById('feedbackMensagem').value.trim(),
            funcionario_id: session ? session.funcionarioId : null,
            funcionario_nome: session ? session.nome : 'Anônimo'
        };
        if (!payload.mensagem) { Toast.error('Escreva uma mensagem'); return; }
        try {
            const resp = await fetch('ajuda.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await resp.json();
            if (result.success) {
                Toast.success('Feedback enviado! Obrigado 🙏');
                document.getElementById('feedbackMensagem').value = '';
                await carregarFeedbacks();
            } else {
                Toast.error(result.error || 'Erro ao enviar');
            }
        } catch {
            Toast.error('Erro ao enviar feedback');
        }
    });

    carregarFeedbacks();
});

async function carregarFeedbacks() {
    const session = Storage.getSession();
    const isAdmin = session && session.isAdmin;
    // Admin vê todos; funcionário vê apenas os seus
    const url = isAdmin ? 'ajuda.php' : `ajuda.php?funcionario_id=${session?.funcionarioId || 0}`;
    try {
        const resp = await fetch(url);
        const data = await resp.json();
        renderFeedbacks(Array.isArray(data) ? data : []);
    } catch {
        document.getElementById('feedbackLista').innerHTML = '<p class="feedback-empty">Erro ao carregar feedbacks</p>';
    }
}

function renderFeedbacks(lista) {
    const el = document.getElementById('feedbackLista');
    if (!lista.length) {
        el.innerHTML = '<p class="feedback-empty">Nenhum feedback enviado ainda</p>';
        return;
    }
    el.innerHTML = lista.map(f => {
        const labels = { sugestao: '💡 Sugestão', erro: '🐛 Erro', melhoria: '🚀 Melhoria', outro: '📝 Outro' };
        const label = labels[f.tipo] || f.tipo;
        const dt = f.created_at ? new Date(f.created_at).toLocaleString('pt-BR') : '';
        return `<div class="feedback-item">
            <span class="fi-tipo ${_esc(f.tipo)}">${label}</span>
            <div class="fi-msg">${_esc(f.mensagem)}</div>
            <div class="fi-meta">${_esc(f.funcionario_nome || '')}${dt ? ' · ' + dt : ''}</div>
        </div>`;
    }).join('');
}

function _esc(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
