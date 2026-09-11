$ErrorActionPreference = 'Stop'
$base = 'http://localhost/Comanda-Online-main/admin/'
$login = Invoke-WebRequest ($base+'login.php') -SessionVariable qaSession -UseBasicParsing
$token = [regex]::Match($login.Content, 'name="_token"\s+value="([^"]+)"').Groups[1].Value
$loginResult = Invoke-WebRequest ($base+'login.php') -WebSession $qaSession -Method Post -Body @{_token=$token;email='superadmin@comandaonline.com';senha='TesteSenha123!'} -UseBasicParsing
if ($loginResult.Content -notmatch '<h1>Dashboard</h1>') { throw 'Login superadmin falhou' }
$fixtureName = 'QA Comanda '+(Get-Date -Format 'yyyyMMddHHmmss')
$new = Invoke-WebRequest ($base+'empresa_form.php') -WebSession $qaSession -UseBasicParsing
$token = [regex]::Match($new.Content, 'name="_token"\s+value="([^"]+)"').Groups[1].Value
$created = Invoke-WebRequest ($base+'empresa_salvar.php') -WebSession $qaSession -Method Post -Body @{_token=$token;acao='criar';nome=$fixtureName;plano_id='1';admin_nome='Administrador QA';admin_login='adminqa';admin_senha=[guid]::NewGuid().ToString('N');cor_primaria='#4f46e5';cor_secundaria='#111827'} -UseBasicParsing
if ($created.Content -notmatch 'instancia provisionada com sucesso') { throw 'Provisionamento falhou: verificar empresa QA no painel' }
$companyId = [regex]::Match($created.Content,'name="empresa_id" value="(\d+)"').Groups[1].Value
if (!$companyId) { throw 'Empresa QA sem ID' }
$token = [regex]::Match($created.Content, 'name="_token"\s+value="([^"]+)"').Groups[1].Value
$renew = Invoke-WebRequest ($base+'empresa_acoes.php') -WebSession $qaSession -Method Post -Body @{_token=$token;acao='renovar';empresa_id=$companyId;valor_pago='79.90';data_pagamento=(Get-Date -Format 'yyyy-MM-dd');dias_adicionais='30'} -UseBasicParsing
if ($renew.Content -notmatch 'Pagamento registrado e licenca renovada') { throw 'Renovacao falhou' }
$rules = Invoke-WebRequest ($base+'empresa_acoes.php') -WebSession $qaSession -Method Post -Body @{_token=$token;acao='atualizar_licenca';empresa_id=$companyId;dias_carencia='5';dias_aviso_antecedencia='7';aviso_fechavel='1';aviso_recorrente='1'} -UseBasicParsing
if ($rules.Content -notmatch 'Regras de aviso e carencia atualizadas') { throw 'Regras falharam' }
foreach ($action in @('bloquear','desbloquear')) {
    $result = Invoke-WebRequest ($base+'empresa_acoes.php') -WebSession $qaSession -Method Post -Body @{_token=$token;acao=$action;empresa_id=$companyId} -UseBasicParsing
    if ($result.Content -match 'Fatal error|SQLSTATE') { throw "$action falhou" }
}
[pscustomobject]@{status='PASS';empresa=$fixtureName;id=$companyId;checks=@('login','provisionamento novo','renovacao','regras da licenca','bloqueio/desbloqueio');fixture='Preservada para QA; pagamento ficticio apenas no ambiente localhost'} | ConvertTo-Json -Depth 3
