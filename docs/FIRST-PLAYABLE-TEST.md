# Primeiro teste jogável

Os patches permanentes da versão `0.1.3` criam as tabelas e cadastram, sem
baixar binários automaticamente:

- o jogo homebrew **Falling**, para NES, publicado sob licença MIT;
- o preset do core **FCEUmm**, com chave de runtime `fceumm` e licença GPL-2.0.

Essa separação é intencional: todo arquivo executável entra pelo importador,
recebe SHA-256 e passa por revisão antes de fazer parte de um perfil.

## Preparação dos arquivos

1. Baixe `falling.nes` do repositório oficial:
   <https://github.com/xram64/falling-nes>.
2. Baixe a release estável `4.2.3` do EmulatorJS:
   <https://github.com/EmulatorJS/EmulatorJS/releases/tag/v4.2.3>.
3. Não renomeie nem altere os pacotes depois de importá-los. Uma nova versão
   ou qualquer mudança de bytes deve virar outro artefato.

## Importação

Em **Geek Cube > Artifacts**, importe o player:

- tipo: `player`;
- nome: `EmulatorJS`;
- versão: `4.2.3`;
- plataforma: não aplicável;
- licença: `GPL-3.0`;
- uso comercial revisado: permitido;
- origem: `https://github.com/EmulatorJS/EmulatorJS/releases/tag/v4.2.3`;
- arquivo: o ZIP self-hosted oficial da release.

Depois importe a ROM:

- tipo: `rom`;
- nome: `Falling`;
- versão: `1.1`;
- plataforma: `NES`;
- licença: `MIT`;
- uso comercial revisado: permitido;
- origem: `https://github.com/xram64/falling-nes`;
- arquivo: `falling.nes`.

Confira origem, licença, anotações e SHA-256. Em seguida, mude os dois
artefatos de `pending` para `verified`.

## Perfil e laboratório

1. Em **Geek Cube > Profiles**, crie um perfil usando Falling, o ZIP do
   EmulatorJS, o preset FCEUmm e a ROM Falling. Deixe BIOS vazio.
2. Abra **Test** e execute o jogo no laboratório protegido.
3. Preencha o checklist e salve o resultado. O registro não é editável.
4. Com pelo menos um resultado `passed`, aprove o perfil.
5. Promova o perfil. Isso publica a combinação como a versão de produção do
   jogo.
6. Ative o player público nas configurações. A URL será
   `/jogar/falling-nes/` com os slugs padrão.

## ROM comercial indicada para teste secundário

O arquivo `Super Mundo Mario (BR)` não faz parte do seed e não deve ser
importado sem licença ou autorização de uso e distribuição documentada. O
importador também não aceita `.7z`; ele recebe o arquivo final da ROM somente
depois da revisão de direitos.
