# Estratégia de Deploy em Produção — Hospedagem Compartilhada (tipo Infinityfree)

## Por que o provisionamento atual não funciona em hospedagem compartilhada

O `TenantProvisioningService` hoje depende de:
1. **`shell_exec`/`Symfony\Process`** chamando `mysqldump.exe`/`mysql.exe` diretamente no servidor.
2. **Escrita livre no filesystem** fora da pasta da aplicação (cria pastas em `clientes/{slug}/`).
3. **Múltiplos bancos de dados MySQL criados dinamicamente** (`CREATE DATABASE`).

Hospedagens gratuitas/compartilhadas como Infinityfree **bloqueiam todos os três pontos**:
- `exec`, `shell_exec`, `proc_open`, `passthru` ficam desabilitados no `php.ini` (sem acesso a shell).
- Não há SSH; o usuário só tem FTP e um painel (cPanel-like) para criar bancos manualmente.
- O número de bancos MySQL por conta é limitado (geralmente 1 em planos free, poucos em planos pagos) e você não pode rodar `CREATE DATABASE` via PHP — só pelo painel.

Ou seja: **o modelo atual (1 banco + 1 pasta por cliente, criado sob demanda) não é compatível com Infinityfree**, mesmo adaptando o código.

## Opções viáveis

### Opção A (recomendada) — VPS barato com root/SSH
Serviços como Hetzner, Contabo ou DigitalOcean oferecem VPS a partir de ~US$4-6/mês com acesso root completo. Isso permite:
- Manter o `TenantProvisioningService` quase como está (trocar apenas caminhos/binários para Linux: `mysqldump`, `mysql` no PATH, sem os workarounds de socket do Windows).
- `CREATE DATABASE` ilimitado via usuário MySQL com privilégio.
- Múltiplas pastas de clientes servidas por Apache/Nginx com vhosts ou subpastas, exatamente como hoje.
- Custo baixo e escala conforme o número de clientes cresce (upgrade de plano do próprio VPS).

**Esta é a única opção que aproveita 100% do código já construído sem reescrever a arquitetura de provisionamento.**

### Opção B — Adaptar para multi-tenant "banco único, schema compartilhado"
Reescrever o app legado para usar uma única base compartilhada com uma coluna `empresa_id` em todas as tabelas (multi-tenant real, não um banco por cliente). Isso funcionaria em hospedagem compartilhada com um único banco MySQL, mas:
- Exige alterar **todas** as queries do sistema legado (dezenas de arquivos PHP) para filtrar por `empresa_id`.
- Elimina o isolamento total entre clientes (risco de vazamento de dados entre empresas se algum filtro for esquecido).
- É essencialmente reescrever o sistema — o oposto do que foi pedido ("não quero recriar o sistema, quero gerenciar o que já existe").
- **Não recomendado** dado o objetivo original do projeto.

### Opção C — Provisionamento manual/semiautomático em hospedagem compartilhada
Se por algum motivo for necessário usar hospedagem compartilhada:
1. O superadmin cria o banco manualmente pelo painel de hospedagem (ou via um painel que ofereça API, como cPanel API, se disponível no plano contratado).
2. O Laravel gera os *scripts* SQL (estrutura + dados iniciais) e o pacote de arquivos (.zip) para upload manual via FTP.
3. Um endpoint de import roda a criação de tabelas via `PDO` (sem depender de `mysqldump`/`mysql` CLI), já que PHP puro com PDO funciona em qualquer hospedagem.

Isso é possível mas envolve trabalho manual por cliente (sem automação completa) — só faz sentido como solução temporária/paliativa.

## Recomendação final
Migrar a produção para um **VPS com acesso root** (Opção A) assim que o negócio decidir ir ao ar. É a opção que:
- Preserva 100% do trabalho de provisionamento automático já implementado e testado.
- Mantém o isolamento total de dados entre clientes (segurança e simplicidade).
- Tem custo mensal baixo e previsível, compatível com o modelo de SaaS por assinatura.

Ajustes necessários ao migrar de Windows/XAMPP local para Linux/VPS:
- Trocar caminhos de `mysqldump.exe`/`mysql.exe` para `mysqldump`/`mysql` (via `config/tenants.php`).
- Remover os workarounds de `SystemRoot`/`WINDIR`/`--protocol=tcp` específicos do bug do Windows (não são necessários no Linux).
- Ajustar `clients_base_path` e `base_url` em `config/tenants.php` para o domínio real de produção.
- Configurar HTTPS (Let's Encrypt) para o domínio do painel superadmin e para os subdomínios/subpastas dos clientes.
