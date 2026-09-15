document.addEventListener('DOMContentLoaded', async () => {
    if (typeof getSetupStatus === 'function') {
        const status = await getSetupStatus();
        if (!status.allow_setup) {
            Toast.warning('Sistema ja inicializado. Redirecionando para login...');
            setTimeout(() => {
                window.location.href = 'login.html';
            }, 900);
            return;
        }
    }

    const btnInstalar = document.getElementById('btnInstalarBanco');
    if (btnInstalar) {
        btnInstalar.addEventListener('click', () => {
            if (typeof instalarBanco === 'function') {
                instalarBanco();
            }
        });
    }

    const btnConexao = document.getElementById('btnTestarConexao');
    if (btnConexao) {
        btnConexao.addEventListener('click', () => {
            if (typeof testarConexao === 'function') {
                testarConexao();
            }
        });
    }

    const btnIrLogin = document.getElementById('btnIrLogin');
    if (btnIrLogin) {
        btnIrLogin.addEventListener('click', () => {
            window.location.href = 'login.html';
        });
    }
});
