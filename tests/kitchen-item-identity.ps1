$ErrorActionPreference='Stop'
$base='http://localhost/Comanda-Online-main/'
function Send-QA($route,$method,$body){$body._audit=@{actor_id=1;actor_nome='QA'};Invoke-RestMethod ($base+$route) -Method $method -ContentType 'application/json' -Body ($body|ConvertTo-Json -Depth 8 -Compress)}
$label='QA-KDS-'+[guid]::NewGuid().ToString('N')
$create=Send-QA 'comandas.php' 'POST' @{numero_mesa=$label;funcionario_id=1;request_id=$label}
$repeated=Send-QA 'comandas.php' 'POST' @{numero_mesa=$label;funcionario_id=1;request_id=$label}
if($repeated.id -ne $create.id){throw 'Criacao repetida duplicou comanda'}
$id=$create.id
$production=Send-QA 'produtos.php' 'POST' @{nome=$label;categoria='QA';preco=10;setor='bar';requer_preparo=$true}
$immediate=Send-QA 'produtos.php' 'POST' @{nome=$label+'-imediato';categoria='QA';preco=3;setor='entrega_imediata';requer_preparo=$false}
$item=@{id=991234567;nome=$label;categoria='QA';quantidade=2;valor=10;observacoes='QA sem cebola + adicional';produto_id=$production.id;adicionais=@(@{nome='QA adicional';valor=1})}
$ready=@{id=991234568;nome=$label+'-imediato';categoria='QA';quantidade=1;valor=3;produto_id=$immediate.id}
$payload=@{id=$id;versao=1;itens=@($item,$ready);audit_actor_id=1}
$save=Send-QA 'comandas.php' 'PUT' $payload
$duplicateRejected=$false
try {Send-QA 'comandas.php' 'PUT' $payload | Out-Null} catch {$duplicateRejected=$_.ErrorDetails.Message -match 'COMANDA_VERSION_CONFLICT'}
if(!$duplicateRejected){throw 'Reenvio de versao antiga nao rejeitado'}
$first=Invoke-RestMethod ($base+'comandas.php?id='+$id)
if($first.itens.Count -ne 2){throw 'Quantidade de itens incorreta'}
$item.id=$first.itens[0].id
$ready.id=$first.itens[1].id
if(!$first.itens[0].enviado_cozinha_em){throw 'Envio sem timestamp'}
if($first.itens[1].enviado_cozinha_em -or $first.itens[1].kitchen_status -ne 'entregue'){throw 'Entrega imediata enviada para producao'}
$queue=Invoke-RestMethod ($base+'cozinha.php?setor=bar')
$group=@($queue.pedidos|Where-Object {$_.comanda_id -eq $id})
if($group.itens.Count -ne 1 -or $group.itens[0].adicionais[0].nome -ne 'QA adicional'){throw 'Roteamento/adicionais falharam'}
Send-QA 'cozinha.php' 'PUT' @{item_id=$item.id;comanda_id=$id;status='em_preparo';audit_actor_id=1} | Out-Null
$payload.versao=$save.versao_nova
$save=Send-QA 'comandas.php' 'PUT' $payload
$second=Invoke-RestMethod ($base+'comandas.php?id='+$id)
if($second.itens[0].id -ne $first.itens[0].id){throw 'ID mudou'}
if($second.itens[0].created_at -ne $first.itens[0].created_at){throw 'Timestamp mudou'}
if($second.itens[0].kitchen_status -ne 'em_preparo'){throw 'Status perdido'}
if($second.itens[0].observacoes -ne $item.observacoes){throw 'Observacoes perdidas'}
$payload.versao=$save.versao_nova
$payload.itens=@()
$payload.motivos_remocao=@{([string]$item.id)='Encerramento do teste QA'}
$payload.motivos_remocao[([string]$ready.id)]='Encerramento do teste QA'
Send-QA 'comandas.php' 'PUT' $payload | Out-Null
$kds=Invoke-RestMethod ($base+'cozinha.php?todos=1')
$cancelled=@($kds.pedidos|Where-Object {$_.comanda_id -eq $id})
if($cancelled.itens[0].item_id -ne $item.id -or $cancelled.itens[0].kitchen_status -ne 'cancelado'){throw 'Cancelamento perdeu identidade'}
Send-QA ('produtos.php?id='+$production.id) 'DELETE' @{} | Out-Null
Send-QA ('produtos.php?id='+$immediate.id) 'DELETE' @{} | Out-Null
[pscustomobject]@{status='PASS';comanda_qa=$id;mesa=$label;checks='ID, timestamp, quantidade, status, observacoes, cancelamento';cleanup='Registro segregado por prefixo QA-KDS, itens cancelados'}|ConvertTo-Json
