/* clientes-init.js — gestão da aba Clientes */
let _todosClientes = [];

document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;
    if (!Storage.hasPermission('clientes') && !Storage.getSession()?.isAdmin) {
        window.location.href = 'index.html';
        return;
    }

    await carregarClientes();

    document.getElementById('buscaClientes').addEventListener('input', filtrarTabela);

    document.getElementById('formEditarCliente').addEventListener('submit', async (e) => {
        e.preventDefault();
        await salvarEdicaoCliente();
    });

    document.getElementById('btnFecharModalCliente').addEventListener('click', fecharModal);
    document.getElementById('btnCancelarModalCliente').addEventListener('click', fecharModal);
    document.getElementById('modalEditarCliente').addEventListener('click', (e) => {
        if (e.target === document.getElementById('modalEditarCliente')) fecharModal();
    });
});

async function carregarClientes() {
    try {
        const resp = await fetch('clientes.php');
        const data = await resp.json();
        // Filtrar apenas clientes com algum dado indetificador
        _todosClientes = Array.isArray(data)
            ? data.filter(c => c.cpf || c.contato || c.email)
            : [];
        renderTabela(_todosClientes);
    } catch (err) {
        Toast.error('Erro ao carregar clientes');
        console.error(err);
    }
}

function filtrarTabela() {
    const q = document.getElementById('buscaClientes').value.toLowerCase().trim();
    if (!q) { renderTabela(_todosClientes); return; }
    const filtrado = _todosClientes.filter(c =>
        (c.nome || '').toLowerCase().includes(q) ||
        (c.contato || '').replace(/\D/g, '').includes(q.replace(/\D/g, '')) ||
        (c.cpf || '').replace(/\D/g, '').includes(q.replace(/\D/g, ''))
    );
    renderTabela(filtrado);
}

function renderTabela(clientes) {
    const tbody = document.getElementById('clientesTableBody');
    const totalEl = document.getElementById('totalClientes');
    totalEl.textContent = `${clientes.length} cliente${clientes.length !== 1 ? 's' : ''}`;

    if (!clientes.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="clientes-empty">Nenhum cliente encontrado</td></tr>';
        return;
    }

    tbody.innerHTML = clientes.map(c => {
        const telefone = c.contato ? c.contato : '<span class="badge-sem-dados">—</span>';
        const cpf = c.cpf ? c.cpf : '<span class="badge-sem-dados">—</span>';
        const whatsapp = c.contato
            ? `<a class="whatsapp-link" href="https://wa.me/55${c.contato.replace(/\D/g,'')}" target="_blank" rel="noopener" title="Abrir WhatsApp">📲</a>`
            : '<span class="badge-sem-dados">—</span>';
        return `
            <tr>
                <td>${_esc(c.nome || '')}</td>
                <td>${telefone}</td>
                <td>${cpf}</td>
                <td>${whatsapp}</td>
                <td>
                    <button type="button" class="btn btn-light btn-sm" onclick="abrirModalEditar(${c.id})">✏️ Editar</button>
                </td>
            </tr>
        `;
    }).join('');
}

async function abrirModalEditar(id) {
    try {
        const resp = await fetch(`clientes.php?id=${id}`);
        const c = await resp.json();
        if (!c || !c.id) { Toast.error('Cliente não encontrado'); return; }

        document.getElementById('editClienteId').value = c.id;
        document.getElementById('editClienteNome').value = c.nome || '';
        document.getElementById('editClienteTelefone').value = c.contato || '';
        document.getElementById('editClienteCpf').value = c.cpf || '';
        document.getElementById('editClienteEmail').value = c.email || '';
        document.getElementById('editClienteNascimento').value = c.data_nascimento || '';
        document.getElementById('editClienteObservacoes').value = c.observacoes || '';
        document.getElementById('editClienteConsentimentoMarketing').checked = Number(c.consentimento_marketing || 0) === 1;
        document.getElementById('modalEditarCliente').style.display = 'flex';
    } catch {
        Toast.error('Erro ao carregar dados do cliente');
    }
}

async function salvarEdicaoCliente() {
    const id = parseInt(document.getElementById('editClienteId').value, 10);
    const payload = {
        id,
        nome: document.getElementById('editClienteNome').value.trim(),
        contato: document.getElementById('editClienteTelefone').value.trim() || null,
        cpf: document.getElementById('editClienteCpf').value.trim() || null,
        email: document.getElementById('editClienteEmail').value.trim() || null,
        data_nascimento: document.getElementById('editClienteNascimento').value || null,
        observacoes: document.getElementById('editClienteObservacoes').value.trim() || null
    };
    const consentimentoMarketing = document.getElementById('editClienteConsentimentoMarketing').checked;

    try {
        const resp = await fetch('clientes.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await resp.json();
        if (result.success) {
            try {
                await Storage.salvarConsentimentoLGPD({
                    cliente_id: id,
                    tipo_consentimento: 'marketing',
                    aceito: consentimentoMarketing,
                    origem: 'clientes_tela',
                    observacao: 'Atualizado em cadastro de clientes'
                });
            } catch (e) {
                console.warn('Falha ao salvar consentimento LGPD:', e);
            }
            Toast.success('Cliente atualizado com sucesso!');
            fecharModal();
            await carregarClientes();
        } else {
            Toast.error(result.error || 'Erro ao salvar');
        }
    } catch {
        Toast.error('Erro ao salvar cliente');
    }
}

function fecharModal() {
    document.getElementById('modalEditarCliente').style.display = 'none';
}

function _esc(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
