# Patches de atualização

O patcher separa a troca dos arquivos do plugin das migrações de dados. Ele
mantém estado por patch, usa lock, processa lotes pequenos pelo WP-Cron e tenta
novamente com espera progressiva quando há uma falha transitória.

O registro permanente começou com estes patches da versão 0.1.3:

- `001-create-content-schema`: cria as tabelas do catálogo e laboratório;
- `002-seed-initial-nes-catalog`: cadastra Falling e o preset FCEUmm sem baixar
  arquivos remotos.

Quando uma versão precisar migrar opções, tabelas ou metadados, adicione uma
nova entrada permanente:

```php
add_filter(
	'geek_cube_studio_update_patch_registry',
	static function ( $patches ) {
		$patches['003-normalize-game-slugs'] = array(
			'introduced_in' => '0.2.0',
			'description'   => 'Normaliza slugs de jogos existentes.',
			'callback'      => 'geek_cube_studio_patch_normalize_game_slugs',
		);

		return $patches;
	}
);
```

Regras do registro:

- nunca remova um patch que já saiu em release;
- o identificador é único, ordenável e nunca deve ser reutilizado;
- o callback precisa ser idempotente, pois uma interrupção pode fazê-lo rodar
  novamente;
- retorne `true` ou `null` para concluir, `false`/`WP_Error` para tentar de novo,
  ou `array( 'complete' => bool, 'message' => string )`;
- migrações grandes devem guardar seu próprio cursor e retornar incompleto até
  terminar, sem prender uma requisição por muito tempo.

Depois de oito falhas, o estado passa a `failed`, novas tentativas automáticas
são interrompidas e um aviso é exibido aos administradores. O inventário pode
ser consultado por `Geek_Cube_Studio_Update_Patches::inventory()`.
