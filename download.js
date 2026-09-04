let arquivoImportar = null;

document.addEventListener('DOMContentLoaded', () => {
    if (!Storage.requireAuth()) return;
    
    const session = Storage.getSession();

    if (!session || !session.isAdmin) {
        const dzBtn = document.getElementById('btnExcluirTodos');
        if (dzBtn) {
            dzBtn.disabled = true;
            dzBtn.style.opacity = '0.5';
            dzBtn.title = 'Apenas administrador pode excluir todos os dados';
        }
    }
    
    document.getElementById('btnDownloadTodos').addEventListener('click', downloadTodosDados);
    
    document.getElementById('btnImportar').addEventListener('click', () => {
        document.getElementById('inputImportar').click();
    });
    
    document.getElementById('inputImportar').addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            arquivoImportar = e.target.files[0];
            document.getElementById('nomeArquivo').textContent = `Arquivo selecionado: ${arquivoImportar.name}`;
            document.getElementById('btnConfirmarImportar').style.display = 'inline-block';
        }
    });
    
    document.getElementById('btnConfirmarImportar').addEventListener('click', importarDados);
    
    document.getElementById('btnExcluirTodos').addEventListener('click', excluirTodosDados);
});

function downloadTodosDados() {
    Storage.exportFullBackup().then(data => {
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const totalTabelas = Number(data && data.table_count ? data.table_count : 0);
        const totalLinhas = Number(data && data.row_count ? data.row_count : 0);
        
        const link = document.createElement('a');
        link.href = url;
        link.download = `comanda_backup_completo_${new Date().toLocaleDateString('pt-BR').replace(/\//g, '_')}.json`;
        link.click();
        
        URL.revokeObjectURL(url);
        Toast.success(`Backup completo gerado! ${totalTabelas} tabela(s), ${totalLinhas} registro(s).`);

        const resumo = document.getElementById('resumoBackupGerado');
        if (resumo) {
            resumo.textContent = `Ultimo backup: ${totalTabelas} tabela(s), ${totalLinhas} registro(s).`;
        }
    }).catch(err => {
        Toast.error('Erro ao gerar backup: ' + err.message);
    });
}

function importarDados() {
    if (!arquivoImportar) {
        Toast.warning('Selecione um arquivo primeiro');
        return;
    }
    
    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const data = JSON.parse(e.target.result);

            const hasTables = data && data.tables && typeof data.tables === 'object';
            if (!hasTables) {
                Toast.error('Arquivo inválido: o backup não contém estrutura completa de tabelas.');
                return;
            }

            if (confirm('Isso substituirá todos os dados existentes. Deseja continuar?')) {
                const login = prompt('Digite seu login de administrador para restaurar:');
                if (!login) return;
                const senha = prompt('Digite sua senha de administrador:');
                if (!senha) return;

                Toast.info('Executando restauração completa...');
                Storage.restoreFullBackup(data, login, senha)
                    .then((result) => {
                        const qtd = Number(result && result.table_count ? result.table_count : 0);
                        Toast.success(`Restauração concluída! ${qtd} tabela(s) restaurada(s).`);

                        document.getElementById('inputImportar').value = '';
                        arquivoImportar = null;
                        document.getElementById('nomeArquivo').textContent = '';
                        document.getElementById('btnConfirmarImportar').style.display = 'none';
                    })
                    .catch((error) => {
                        Toast.error('Erro ao restaurar backup: ' + error.message);
                    });
            }
        } catch (err) {
            Toast.error('Erro ao importar arquivo. Verifique se o formato está correto.');
            console.error(err);
        }
    };
    reader.readAsText(arquivoImportar);
}

function excluirTodosDados() {
    const session = Storage.getSession();
    if (!session || !session.isAdmin) {
        Toast.error('Apenas administrador pode excluir todos os dados');
        return;
    }

    if (!confirm('ATENÇÃO: Isso excluirá TODOS os dados no banco (MySQL) permanentemente. Deseja continuar?')) {
        return;
    }
    if (!confirm('Tem certeza? Esta ação não pode ser desfeita.')) {
        return;
    }

    const confirmText = prompt('Digite EXCLUIR TUDO para confirmar:');
    if (confirmText !== 'EXCLUIR TUDO') {
        Toast.warning('Confirmação inválida. Operação cancelada.');
        return;
    }

    const login = prompt('Digite seu login de administrador:');
    if (!login) return;
    const senha = prompt('Digite sua senha de administrador:');
    if (!senha) return;

    Toast.info('Excluindo dados...');

    Storage.resetDatabase(login, senha, confirmText)
        .then(() => {
            Storage.clearAll();
            Toast.success('Todos os dados foram excluídos. Redirecionando para login...');
            setTimeout(() => {
                window.location.href = 'login.html';
            }, 1200);
        })
        .catch(err => {
            Toast.error('Falha ao excluir dados: ' + err.message);
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
