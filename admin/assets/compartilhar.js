// Modal simples de compartilhamento de acesso do cliente (copiar/WhatsApp/e-mail).
function abrirCompartilhar(dados) {
    const nome = dados.nome || '';
    const url = dados.url || '';
    const login = dados.login || '';
    const senha = dados.senha || '';

    let texto = `Acesso ao sistema Comanda Online - ${nome}\n`;
    texto += `Link: ${url}\n`;
    texto += `Login: ${login}\n`;
    texto += senha ? `Senha: ${senha}\n` : `Senha: (a definida no cadastro/redefinicao)\n`;

    let modal = document.getElementById('modalCompartilhar');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'modalCompartilhar';
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-box">
                <h3>Compartilhar acesso</h3>
                <textarea id="compartilharTexto" rows="6"></textarea>
                <div class="acoes" style="margin-top:0.75rem;">
                    <button type="button" class="btn" id="btnCopiarAcesso">Copiar</button>
                    <a class="btn btn-sucesso" id="btnWhatsappAcesso" target="_blank" rel="noopener">WhatsApp</a>
                    <a class="btn btn-secundario" id="btnEmailAcesso">E-mail</a>
                    <button type="button" class="btn btn-secundario" id="btnFecharCompartilhar">Fechar</button>
                </div>
            </div>`;
        document.body.appendChild(modal);
        modal.addEventListener('click', (ev) => { if (ev.target === modal) modal.style.display = 'none'; });
        document.getElementById('btnFecharCompartilhar').addEventListener('click', () => { modal.style.display = 'none'; });
        document.getElementById('btnCopiarAcesso').addEventListener('click', () => {
            navigator.clipboard.writeText(document.getElementById('compartilharTexto').value)
                .then(() => { document.getElementById('btnCopiarAcesso').textContent = 'Copiado!'; })
                .catch(() => {});
        });
    }

    document.getElementById('compartilharTexto').value = texto;
    document.getElementById('btnWhatsappAcesso').href = 'https://wa.me/?text=' + encodeURIComponent(texto);
    document.getElementById('btnEmailAcesso').href = 'mailto:'+(dados.email||'')+'?subject=' + encodeURIComponent('Acesso ao sistema - '+nome) + '&body=' + encodeURIComponent(texto);
    document.getElementById('btnCopiarAcesso').textContent = 'Copiar';
    modal.style.display = 'flex';
}

// CSP do painel bloqueia atributos onclick inline; delegamos via data-attributes.
document.addEventListener('click', (ev) => {
    const gatilho = ev.target.closest('.js-compartilhar');
    if (!gatilho) return;
    ev.preventDefault();
    abrirCompartilhar({
        nome: gatilho.dataset.nome || '',
        url: gatilho.dataset.url || '',
        login: gatilho.dataset.login || '',
        senha: gatilho.dataset.senha || '',
        email: gatilho.dataset.email || '',
    });
});

document.addEventListener('DOMContentLoaded', () => {
    const dados = document.getElementById('acessoGeradoData');
    if (dados) {
        try {
            abrirCompartilhar(JSON.parse(dados.textContent));
        } catch (e) {
            // dados invalidos, ignora abertura automatica
        }
    }
});
