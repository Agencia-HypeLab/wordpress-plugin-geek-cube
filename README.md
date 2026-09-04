# Geek Cube Studio

Plugin WordPress para a experiência digital Geek Cube Studio: páginas de jogos,
integração com player web e, futuramente, cartuchos físicos com NFC.

## Estado do projeto

Projeto em fase inicial de descoberta e prova de conceito. A primeira entrega
deverá validar o carregamento de um jogo legalmente redistribuível em um player
web, com experiência mobile-first.

## Desenvolvimento

```powershell
composer install
composer test
composer lint
```

O processo de release completo está documentado em
[`docs/RELEASES.md`](docs/RELEASES.md). O comando padrão valida o projeto,
incrementa a versão, gera e assina o pacote, publica o commit no Git e envia os
artefatos por FTP:

```powershell
php tools\build.php
```

As migrações persistentes executadas depois de uma atualização estão descritas
em [`docs/PATCHES.md`](docs/PATCHES.md).

Credenciais, chaves privadas, builds e ROMs privadas nunca devem ser enviados
ao repositório.
