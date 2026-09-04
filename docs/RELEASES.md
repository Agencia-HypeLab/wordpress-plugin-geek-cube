# Releases do Geek Cube Studio

O comando padrão é o fluxo completo:

```powershell
php tools\build.php
```

Ele valida o projeto, testa os acessos, incrementa a versão patch, gera o ZIP,
assina o manifesto, cria o commit, faz o push da branch atual, envia os artefatos
por FTP e, somente após confirmar a publicação HTTPS, cria e envia a tag da
versão.

## 1. Credenciais de FTP

O local padrão é `tools/.ftp-credentials.json`. Esse arquivo já está no
`.gitignore`; nunca use `git add -f` nele. Comece copiando o exemplo:

```powershell
Copy-Item tools\.ftp-credentials.example.json tools\.ftp-credentials.json
notepad tools\.ftp-credentials.json
```

Protocolos aceitos: `ftp`, `ftps` e `sftp`. Para FTPS, mantenha
`"verify_ssl": true`. `public_base_url` é a URL HTTPS pública correspondente a
`remote_dir`, não o endereço do servidor FTP. O bundle público de autoridades
certificadoras instalado pelo Composer é usado automaticamente quando o PHP não
possui `curl.cainfo`; `ca_bundle` permite indicar outro arquivo confiável. SFTP
exige o caminho de um arquivo `known_hosts`.

Para guardar o arquivo fora do projeto, defina o caminho absoluto apenas na
sessão que fará a release:

```powershell
$env:GEEK_CUBE_FTP_CONFIG = 'C:\caminho\seguro\geek-cube-ftp.json'
```

Teste sem alterar versão, Git ou servidor:

```powershell
php tools\deploy-ftp.php --test
```

## 2. Identidade de release e nota do Bitwarden

A chave SSH do GitHub autentica o push e não assina atualizações. A identidade
Ed25519 é separada. Somente sua chave pública fica em
`config/release-public-key.php`; todo o restante é secreto e permanece fora do
Git.

Crie a identidade Ed25519 uma única vez:

```powershell
$releaseDir = 'D:\Projetos\hypelab\secrets\wordpress-plugin-geek-cube'
New-Item -ItemType Directory -Force -Path $releaseDir | Out-Null
$releaseKey = Join-Path $releaseDir 'release-key.json'
php tools\release-key.php generate --private-key-file="$releaseKey"
```

Em seguida, gere a nota completa no padrão HypeLab. A ferramenta cria uma chave
X25519 independente, sela a chave Ed25519, testa a recuperação em memória e só
então grava a nota:

```powershell
$releaseNote = Join-Path $releaseDir 'bitwarden-release-note.txt'
php tools\release-vault-note.php generate `
  --signing-key-file="$releaseKey" `
  --output="$releaseNote"
```

Crie no Bitwarden uma **Secure Note** chamada
`wordpress-plugin-geek-cube / release Ed25519 + recovery X25519` e copie para
ela todo o conteúdo de `$releaseNote`. A nota contém três seções:

1. chave privada Ed25519 usada para assinar releases;
2. chave privada X25519 usada para recuperação;
3. backup selado da chave Ed25519, com fingerprints e hash do ciphertext.

As três seções são secretas. Não as envie por chat, e-mail, `.env`, GitHub
Secrets ou Git.

Antes de apagar os arquivos iniciais, copie a nota de volta do Bitwarden para
um novo arquivo temporário e valide essa cópia:

```powershell
$temporaryNote = Join-Path ([System.IO.Path]::GetTempPath()) 'geek-cube-bitwarden-release.txt'
# Salve exatamente o conteúdo recuperado do Bitwarden em $temporaryNote.
php tools\release-vault-note.php verify --input="$temporaryNote"
```

O build recebe diretamente essa exportação completa e repete o teste de
recuperação antes de versionar ou publicar:

```powershell
# Opção temporária, com precedência sobre a configuração local:
$env:GEEK_CUBE_UPDATE_ED25519_PRIVATE_KEY_FILE = $temporaryNote
php tools\build.php
Remove-Item -LiteralPath $temporaryNote
Remove-Item Env:GEEK_CUBE_UPDATE_ED25519_PRIVATE_KEY_FILE
```

Para o fluxo local padrão, copie `tools/.release-credentials.example.json` para
`tools/.release-credentials.json` e informe em `release_note_file` o caminho da
nota mantida em `D:\Projetos\hypelab\secrets`. O arquivo de configuração local
é ignorado pelo Git. Com isso, basta executar `php tools\build.php`, sem definir
uma variável a cada sessão.

Somente depois que a exportação do Bitwarden passar no comando `verify`, apague
as cópias iniciais locais:

```powershell
Remove-Item -LiteralPath $releaseKey
Remove-Item -LiteralPath $releaseNote
```

Se usar a CLI do Bitwarden, `bw get notes` pode criar a exportação temporária.
Mantenha o cofre bloqueado quando não estiver em uso e elimine o arquivo após o
build.

## 3. Preparação e validação

Na primeira vez:

```powershell
composer install
php tools\build.php --validate-only
```

O modo `--validate-only` executa testes e padrão de código, mas não consulta
chaves, não incrementa versão, não cria commit e não publica nada.

Antes de uma release completa, confirme:

```powershell
git status --short
git branch --show-current
git remote -v
php tools\release-vault-note.php verify --input="$env:GEEK_CUBE_UPDATE_ED25519_PRIVATE_KEY_FILE"
php tools\deploy-ftp.php --test
```

## 4. Opções de release

```powershell
php tools\build.php                         # patch, atualização automática desligada
php tools\build.php --bump=minor
php tools\build.php --bump=major
php tools\build.php --auto-update          # permite patch/minor automáticos
php tools\build.php --auto-update-major    # inclui major automático
php tools\build.php --rollout-percent=25
php tools\build.php --channel=preview      # sempre desliga auto-update
```

As decisões de auto-update, canal e rollout fazem parte do conteúdo assinado.
O plugin falha de forma segura se o manifesto, a assinatura, o HTTPS ou o hash
SHA-256 do pacote não puderem ser validados.

Se o commit/push já tiver ocorrido e somente o FTP falhar, corrija o acesso e
repita a mesma versão, sem novo incremento:

```powershell
php tools\build.php --no-bump
```

Não use `--no-bump` para sobrescrever uma versão que já foi publicada com outro
conteúdo.
