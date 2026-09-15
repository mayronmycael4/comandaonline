async function instalarBanco() {
    const resultado = document.getElementById('resultadoInstalacao');
    const step1 = document.getElementById('step1');
    
    const status = await getSetupStatus();
    if (!status.allow_setup) {
        step1.classList.add('error');
        resultado.innerHTML = '<span style="color: #e53e3e;">✗ Setup bloqueado: sistema ja inicializado.</span>';
        Toast.error('Setup bloqueado: sistema ja inicializado.');
        return;
    }

    resultado.textContent = 'Instalando...';
    
    try {
        const response = await fetch('install.php');
        const data = await response.json();
        
        if (data.success) {
            step1.classList.add('success');
            resultado.innerHTML = '<span style="color: #48bb78;">✓ ' + data.message + '</span>';
            Toast.success('Banco de dados instalado!');
        } else {
            throw new Error(data.error);
        }
    } catch (error) {
        step1.classList.add('error');
        resultado.innerHTML = `<span style="color: #e53e3e;">✗ Erro: ${error.message}</span>`;
        Toast.error('Erro na instalação: ' + error.message);
    }
}

async function testarConexao() {
    const resultado = document.getElementById('resultadoConexao');
    const step2 = document.getElementById('step2');
    
    try {
        resultado.textContent = 'Testando...';
        const response = await fetch('empresa.php');
        const data = await response.json();
        
        if (data.error) {
            throw new Error(data.error);
        }
        
        step2.classList.add('success');
        resultado.innerHTML = '<span style="color: #48bb78;">✓ Conexão estabelecida com sucesso!</span>';
        Toast.success('Conexão com banco de dados OK!');
    } catch (error) {
        step2.classList.add('error');
        resultado.innerHTML = `<span style="color: #e53e3e;">✗ Erro: ${error.message}</span>`;
        Toast.error('Erro de conexão: ' + error.message);
    }
}

async function getSetupStatus() {
    try {
        const response = await fetch('setup_status.php', {
            headers: {
                'Accept': 'application/json'
            }
        });

        const data = await response.json();
        if (!response.ok || !data || data.success === false) {
            return { allow_setup: false };
        }

        return { allow_setup: !!data.allow_setup };
    } catch (error) {
        return { allow_setup: false };
    }
}
