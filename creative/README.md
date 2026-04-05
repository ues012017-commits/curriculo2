# KONEX CREATIVE

Plataforma de geração de currículos profissionais com inteligência artificial.

## Requisitos

- PHP 7.4+
- MySQL / MariaDB
- Extensão cURL habilitada no PHP

## Instalação

1. Clone ou faça o upload dos arquivos para o servidor.
2. Importe o arquivo `database.sql` no seu banco de dados MySQL/MariaDB.
3. Copie `.env.example` para `.env` e preencha **todas** as variáveis com os valores reais do seu ambiente:
   ```bash
   cp .env.example .env
   ```
4. Gere o hash bcrypt para a senha do administrador:
   ```bash
   php -r "echo password_hash('SuaSenhaSegura', PASSWORD_BCRYPT) . PHP_EOL;"
   ```
5. Cole o hash gerado na variável `ADMIN_PASS_HASH` do arquivo `.env`.
6. Altere a senha padrão do admin no banco de dados usando o mesmo hash.
7. Configure as credenciais do Mercado Pago / Asaas no painel admin ou diretamente no `.env`.

## Segurança

- **NUNCA** faça commit do arquivo `.env` no repositório.
- O arquivo `.htaccess` protege o `.env` e `config.php` contra acesso direto via HTTP.
- Todas as credenciais são carregadas via variáveis de ambiente (`.env`).
- Sessões de administrador são armazenadas no banco com expiração automática.
- Endpoints administrativos exigem token de sessão válido.

## Estrutura

| Arquivo        | Descrição                                  |
|---------------|--------------------------------------------|
| `api.php`      | Backend API (todas as ações)               |
| `config.php`   | Carregamento de variáveis de ambiente      |
| `admin.php`    | Painel administrativo                      |
| `index.html`   | App principal do editor de currículos      |
| `landing.html` | Landing page / loja                        |
| `script.js`    | Lógica frontend do editor                 |
| `database.sql` | Esquema do banco de dados                  |
| `.env.example` | Template das variáveis de ambiente         |
| `.htaccess`    | Proteção de arquivos sensíveis             |
