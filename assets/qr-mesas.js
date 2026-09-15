const qrState = {
    itens: []
};

document.addEventListener('DOMContentLoaded', () => {
    if (!Storage.requireAuth()) return;

    if (!Storage.hasPermission('comandas') && !Storage.hasPermission('funcionarios')) {
        Toast.error('Sem permissao para acessar geracao de QR por mesa.');
        window.location.href = 'perfil.html';
        return;
    }

    bindQrMesasActions();
});

function bindQrMesasActions() {
    const btnGerar = document.getElementById('btnGerarQr');
    const btnPrint = document.getElementById('btnPrintQrs');
    const btnCopiar = document.getElementById('btnCopiarTodos');
    const btnLimpar = document.getElementById('btnLimparQr');

    if (btnGerar) btnGerar.addEventListener('click', gerarQrs);
    if (btnPrint) btnPrint.addEventListener('click', imprimirQrs);
    if (btnCopiar) btnCopiar.addEventListener('click', copiarTodosLinks);
    if (btnLimpar) btnLimpar.addEventListener('click', limparGrid);
}

function parseMesas() {
    const mesasCustom = String(document.getElementById('mesasCustom')?.value || '').trim();
    if (mesasCustom) {
        const parsed = mesasCustom
            .split(',')
            .map((v) => parseInt(v.trim(), 10))
            .filter((n) => Number.isFinite(n) && n > 0);
        return Array.from(new Set(parsed));
    }

    const inicial = parseInt(document.getElementById('mesaInicial')?.value || '1', 10);
    const final = parseInt(document.getElementById('mesaFinal')?.value || '20', 10);

    if (!Number.isFinite(inicial) || !Number.isFinite(final) || inicial <= 0 || final <= 0 || final < inicial) {
        return [];
    }

    const mesas = [];
    for (let i = inicial; i <= final; i += 1) {
        mesas.push(i);
    }
    return mesas;
}

async function gerarQrs() {
    const mesas = parseMesas();
    if (!mesas.length) {
        Toast.warning('Informe um intervalo valido ou lista de mesas.');
        return;
    }

    const grid = document.getElementById('qrGrid');
    if (!grid) return;

    grid.innerHTML = '<p class="hint">Gerando links de QR...</p>';
    qrState.itens = [];

    const novos = [];
    for (const mesa of mesas) {
        try {
            const data = await API.getQrMenuToken(mesa);
            novos.push({
                mesa,
                token: data.token,
                url: data.url
            });
        } catch (error) {
            console.error(error);
            novos.push({
                mesa,
                token: '',
                url: '',
                erro: error.message || 'Falha ao gerar token'
            });
        }
    }

    qrState.itens = novos;
    renderQrGrid();

    const falhas = novos.filter((i) => i.erro).length;
    if (falhas > 0) {
        Toast.warning(`QRs gerados com ${falhas} falha(s).`);
    } else {
        Toast.success(`QRs gerados para ${novos.length} mesa(s).`);
    }
}

function buildQrImageUrl(url) {
    const encoded = encodeURIComponent(url);
    return `https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=8&data=${encoded}`;
}

function renderQrGrid() {
    const grid = document.getElementById('qrGrid');
    if (!grid) return;

    if (!qrState.itens.length) {
        grid.innerHTML = '<p class="hint">Nenhum QR gerado ainda.</p>';
        return;
    }

    grid.innerHTML = qrState.itens.map((item) => {
        if (item.erro) {
            return `
                <article class="qr-card">
                    <h3>Mesa ${item.mesa}</h3>
                    <p class="hint" style="color:#b00020;">${escapeHtml(item.erro)}</p>
                </article>
            `;
        }

        return `
            <article class="qr-card">
                <h3>Mesa ${item.mesa}</h3>
                <img class="qr-image" src="${buildQrImageUrl(item.url)}" alt="QR da mesa ${item.mesa}">
                <div class="qr-link">${escapeHtml(item.url)}</div>
                <div class="qr-card-actions">
                    <button class="btn btn-secondary" type="button" data-copy-url="${escapeAttr(item.url)}">Copiar</button>
                    <a class="btn btn-primary" href="${item.url}" target="_blank" rel="noopener">Abrir</a>
                </div>
            </article>
        `;
    }).join('');

    grid.querySelectorAll('[data-copy-url]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const url = btn.getAttribute('data-copy-url') || '';
            if (!url) return;
            try {
                await navigator.clipboard.writeText(url);
                Toast.success('URL copiada.');
            } catch (_e) {
                Toast.error('Nao foi possivel copiar a URL.');
            }
        });
    });
}

function imprimirQrs() {
    if (!qrState.itens.length) {
        Toast.warning('Gere os QRs antes de imprimir.');
        return;
    }
    window.print();
}

async function copiarTodosLinks() {
    const urls = qrState.itens.filter((i) => i.url).map((i) => `Mesa ${i.mesa}: ${i.url}`);
    if (!urls.length) {
        Toast.warning('Nenhum link gerado para copiar.');
        return;
    }

    try {
        await navigator.clipboard.writeText(urls.join('\n'));
        Toast.success('Lista de URLs copiada.');
    } catch (_e) {
        Toast.error('Nao foi possivel copiar os links.');
    }
}

function limparGrid() {
    qrState.itens = [];
    renderQrGrid();
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value || '');
    return div.innerHTML;
}

function escapeAttr(value) {
    return String(value || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
