// Le os dados injetados por sso_login.php e finaliza o acesso direto vindo do painel admin.
(function () {
    const bloco = document.getElementById('ssoDados');
    if (!bloco) return;

    let dados;
    try {
        dados = JSON.parse(bloco.textContent);
    } catch (e) {
        return;
    }

    localStorage.setItem('comanda_session', dados.sessao);
    localStorage.setItem('comanda_session_last_activity', String(Date.now()));
    if (dados.adminUrl) {
        localStorage.setItem('comanda_admin_panel_url', dados.adminUrl);
    }
    window.location.replace('index.html');
})();
