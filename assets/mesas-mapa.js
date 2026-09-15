document.addEventListener('DOMContentLoaded', async () => {
    if (!Storage.requireAuth()) return;
    await carregarMapaMesas();
    setInterval(async () => {
        await carregarMapaMesas();
    }, 8000);
});

function getClasseMesa(comanda) {
    if (!comanda) return 'mesa-livre';
    if (comanda.status === 'fechada') return 'mesa-fechando';
    if (comanda.statusOperacional === 'pronta') return 'mesa-pronta';
    if (comanda.statusOperacional === 'em_preparo') return 'mesa-preparo';
    return 'mesa-ocupada';
}

function getRotuloMesa(comanda) {
    if (!comanda) return 'Livre';
    if (comanda.status === 'fechada') return 'Fechando';
    if (comanda.statusOperacional === 'pronta') return 'Pronta para entrega';
    if (comanda.statusOperacional === 'em_preparo') return 'Aguardando cozinha';
    return 'Ocupada';
}

async function carregarMapaMesas() {
    const grid = document.getElementById('mapaMesasGrid');
    if (!grid) return;

    const comandas = await Storage.getComandas();
    const abertas = comandas.filter(c => c.status === 'aberta');

    if (abertas.length === 0) {
        grid.innerHTML = '<p>Nenhuma mesa ocupada no momento.</p>';
        return;
    }

    abertas.sort((a, b) => String(a.numeroMesa).localeCompare(String(b.numeroMesa), 'pt-BR', { numeric: true }));

    grid.innerHTML = abertas.map((c) => {
        const cliente = c.clienteNome || (c.cliente && c.cliente.nome) || 'Sem cliente';
        const status = getRotuloMesa(c);
        const classe = getClasseMesa(c);
        return `
            <article class="mesa-card ${classe}" data-comanda-id="${c.id}">
                <h3>Mesa ${String(c.numeroMesa)}</h3>
                <p>${status}</p>
                <p>Cliente: ${cliente}</p>
            </article>
        `;
    }).join('');

    grid.querySelectorAll('.mesa-card[data-comanda-id]').forEach((el) => {
        el.addEventListener('click', () => {
            const id = el.getAttribute('data-comanda-id');
            window.location.href = `comanda.html?comandaId=${id}`;
        });
    });
}
