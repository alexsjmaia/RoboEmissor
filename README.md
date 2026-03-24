# Automacao de login em PHP

Este projeto abre o Google Chrome, acessa a URL `http://192.168.1.41/onneenfe/Login.aspx` e tenta fazer login com:

- usuario: `admin`
- senha: `admin`

## Requisitos

- Google Chrome instalado
- PHP em `C:\RoboEmissor\php\php.exe`
- ChromeDriver em `C:\RoboEmissor\chromedriver-win64\chromedriver.exe`
- Opcional: `curl.exe` em `C:\RoboEmissor\curl-8.19.0\curl.exe`

## Como usar

1. Garanta que estes caminhos existam na maquina:

- `C:\RoboEmissor\php\php.exe`
- `C:\RoboEmissor\chromedriver-win64\chromedriver.exe`
- `C:\RoboEmissor\curl-8.19.0\curl.exe` se a extensao `curl` do PHP nao estiver habilitada

2. Abra o terminal nesta pasta.
3. Execute:

```powershell
php .\login_automation.php
```

## Resultado

- O Chrome sera aberto de forma visivel.
- Ao final da execucao, o script salva um screenshot em `login_result.png`.

## Observacao importante

Como eu nao tive acesso direto a essa tela de login aqui no ambiente, o script identifica os campos por heuristica usando nomes comuns como `usuario`, `login`, `senha`, `password` e botoes como `Entrar` ou `Login`.

Se essa tela usar ids ou nomes muito diferentes, me passe o HTML da pagina ou um print com os campos inspecionados que eu ajusto os seletores para ficar 100% preciso.
