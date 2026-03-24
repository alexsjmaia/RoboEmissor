<?php

declare(strict_types=1);

const LOGIN_URL = 'http://192.168.1.41/onneenfe/Login.aspx';
const LOGIN_USER = 'admin';
const LOGIN_PASSWORD = 'admin';
const CHROMEDRIVER_PORT = 9515;
const DEFAULT_TIMEOUT_SECONDS = 20;
const ROBOEMISSOR_DIR = 'C:\\RoboEmissor';
const PHP_EXE_PATH = ROBOEMISSOR_DIR . '\\php\\php.exe';
const CHROMEDRIVER_EXE_PATH = ROBOEMISSOR_DIR . '\\chromedriver-win64\\chromedriver.exe';
const CURL_EXE_PATH = ROBOEMISSOR_DIR . '\\curl-8.19.0\\curl.exe';
const CURL_EXE_ALT_PATH = ROBOEMISSOR_DIR . '\\curl-8.19.0\\bin\\curl.exe';

final class ChromeLoginAutomation
{
    private string $baseUrl;
    private ?string $sessionId = null;
    private ?string $chromeDriverCommand = null;
    private string $chromeDriverLogPath;
    private string $automationLogPath;
    /** @var string[] */
    private array $chromeDriverCandidates = [];

    public function __construct()
    {
        $this->baseUrl = 'http://127.0.0.1:' . CHROMEDRIVER_PORT;
        $this->chromeDriverLogPath = __DIR__ . DIRECTORY_SEPARATOR . 'chromedriver.log';
        $this->automationLogPath = __DIR__ . DIRECTORY_SEPARATOR . 'automation.log';
    }

    public function run(): void
    {
        @file_put_contents($this->automationLogPath, '');
        $this->log('Inicio da automacao');
        $this->ensureChromeDriverIsRunning();
        $this->log('ChromeDriver pronto');
        $this->createSession();
        $this->log('Sessao criada: ' . ($this->sessionId ?? 'sem id'));

        try {
            $this->log('Navegando para ' . LOGIN_URL);
            $this->navigateTo(LOGIN_URL);
            $this->log('URL atual depois da navegacao: ' . $this->getCurrentUrlFromPage());
            $this->fillCredentialsAndSubmitWithFallback();
            $this->log('Credenciais preenchidas e formulario enviado');
            $this->openEmitirNFeAfterLogin();
            $this->log('Tela Emitir NF-e aberta');
            $this->filterNotasFromFirstDayOfMonth();
            $this->log('Filtro de notas aplicado');
            $this->saveScreenshot(__DIR__ . DIRECTORY_SEPARATOR . 'login_result.png');
            $this->log('Screenshot salvo');
        } finally {
            $this->log('Sessao mantida aberta por solicitacao do usuario');
        }
    }

    private function ensureChromeDriverIsRunning(): void
    {
        if ($this->isChromeDriverResponsive()) {
            return;
        }

        $command = $this->discoverChromeDriverCommand();
        if ($command === null) {
            throw new RuntimeException(
                "Nao encontrei o ChromeDriver. Coloque o chromedriver.exe nesta pasta " .
                "ou adicione-o ao PATH do Windows."
            );
        }

        $this->chromeDriverCommand = $command;
        @file_put_contents($this->chromeDriverLogPath, '');

        if (DIRECTORY_SEPARATOR === '\\') {
            $startupCommand =
                'start "" /B "' .
                trim($command, '"') .
                '" --port=' . CHROMEDRIVER_PORT .
                ' --verbose --log-path="' . $this->chromeDriverLogPath . '"';
            pclose(popen($startupCommand, 'r'));
        } else {
            exec(
                $command .
                ' --port=' . CHROMEDRIVER_PORT .
                ' --verbose --log-path=' . escapeshellarg($this->chromeDriverLogPath) .
                ' > /dev/null 2>&1 &'
            );
        }

        $started = $this->waitUntil(fn (): bool => $this->isChromeDriverResponsive(), 15);
        if (!$started) {
            $message = 'O ChromeDriver nao respondeu na porta ' . CHROMEDRIVER_PORT . '.';
            $logExcerpt = $this->readChromeDriverLog();
            if ($logExcerpt !== null) {
                $message .= ' Log do ChromeDriver:' . PHP_EOL . $logExcerpt;
            }

            throw new RuntimeException($message);
        }
    }

    private function discoverChromeDriverCommand(): ?string
    {
        $candidates = [
            getenv('CHROMEDRIVER_PATH') ?: null,
            __DIR__ . DIRECTORY_SEPARATOR . 'chromedriver.exe',
            CHROMEDRIVER_EXE_PATH,
            ROBOEMISSOR_DIR . '\\chromedriver.exe',
        ];

        $this->chromeDriverCandidates = [];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $this->chromeDriverCandidates[] = $candidate;
            }

            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return '"' . $candidate . '"';
            }
        }

        $output = [];
        $exitCode = 0;
        @exec('where chromedriver 2>nul', $output, $exitCode);
        if ($exitCode === 0 && !empty($output[0])) {
            return '"' . trim($output[0]) . '"';
        }

        return null;
    }

    public function getChromeDriverCandidates(): array
    {
        return $this->chromeDriverCandidates;
    }

    public function getAutomationLogPath(): string
    {
        return $this->automationLogPath;
    }

    private function readChromeDriverLog(): ?string
    {
        if (!is_file($this->chromeDriverLogPath)) {
            return null;
        }

        $content = trim((string) @file_get_contents($this->chromeDriverLogPath));
        if ($content === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_slice($lines, -12);

        return implode(PHP_EOL, $lines);
    }

    private function isChromeDriverResponsive(): bool
    {
        try {
            $response = $this->httpRequest('GET', '/status');
            return isset($response['value']['ready']) || isset($response['value']['message']);
        } catch (Throwable) {
            return false;
        }
    }

    private function createSession(): void
    {
        $payload = [
            'capabilities' => [
                'alwaysMatch' => [
                    'browserName' => 'chrome',
                    'pageLoadStrategy' => 'none',
                    'goog:chromeOptions' => [
                        'args' => [
                            '--start-maximized',
                            '--disable-notifications',
                            '--disable-popup-blocking',
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->httpRequest('POST', '/session', $payload);
        $this->sessionId = $response['value']['sessionId'] ?? $response['sessionId'] ?? null;

        if ($this->sessionId === null) {
            throw new RuntimeException('Nao foi possivel criar a sessao do Chrome.');
        }
    }

    private function navigateTo(string $url): void
    {
        $this->sessionRequest('POST', '/url', ['url' => $url]);

        $navigated = $this->waitUntil(function () use ($url): bool {
            $currentUrl = $this->getCurrentUrlFromPage();
            $this->log('URL observada na pagina: ' . $currentUrl);

            if ($currentUrl === $url || str_contains($currentUrl, '/onneenfe/')) {
                return true;
            }

            if ($currentUrl === 'data:,' || $currentUrl === '' || $currentUrl === 'about:blank') {
                $this->executeScript('window.location.href = arguments[0];', [$url]);
            }

            return false;
        }, DEFAULT_TIMEOUT_SECONDS);

        if (!$navigated) {
            throw new RuntimeException(
                'Nao foi possivel navegar ate a tela de login. URL atual: ' . $this->getCurrentUrlFromPage()
            );
        }
    }

    private function waitForPageReady(): void
    {
        $ready = $this->waitUntil(function (): bool {
            $state = $this->executeScript('return document.readyState;');
            return $state === 'complete' || $state === 'interactive';
        }, DEFAULT_TIMEOUT_SECONDS);

        if (!$ready) {
            throw new RuntimeException('A pagina nao terminou de carregar dentro do tempo esperado.');
        }
    }

    private function fillCredentialsAndSubmit(): void
    {
        $this->waitForLoginFields();
        $this->log('Campos de login encontrados');

        $usernameElement = $this->findBestInput([
            'usuario', 'usuário', 'user', 'username', 'login', 'email', 'txtlogin', 'txtusuario',
        ], false);

        $passwordElement = $this->findBestInput([
            'password', 'senha', 'txtsenha', 'pass',
        ], true);

        if ($usernameElement === null) {
            throw new RuntimeException('Nao encontrei o campo de usuario na tela de login.');
        }

        if ($passwordElement === null) {
            throw new RuntimeException('Nao encontrei o campo de senha na tela de login.');
        }

        $this->setElementValue($usernameElement, LOGIN_USER);
        $this->log('Usuario preenchido');
        $this->setElementValue($passwordElement, LOGIN_PASSWORD);
        $this->log('Senha preenchida');

        $submitted = $this->clickSubmitButton();
        if (!$submitted) {
            $this->submitNearestForm($passwordElement);
            $this->log('Formulario enviado por submit');
            return;
        }

        $this->log('Botao Entrar clicado');
    }

    private function fillCredentialsAndSubmitDirect(): void
    {
        $this->waitForLoginFields();
        $this->log('Campos de login encontrados');

        $result = $this->executeScript(<<<'JS'
function normalize(text) {
  return String(text || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();
}

function findInput(kind) {
  const inputs = Array.from(document.querySelectorAll('input'));

  const byId = {
    user: ['txt_usuario', 'txt_login', 'txtlogin', 'username', 'login', 'usuario'],
    password: ['txt_password', 'txt_senha', 'txtsenha', 'password', 'senha']
  };

  for (const id of byId[kind]) {
    const element = document.getElementById(id);
    if (element) {
      return element;
    }
  }

  return inputs.find((element) => {
    const text = normalize([
      element.id || '',
      element.name || '',
      element.placeholder || '',
      element.className || ''
    ].join(' '));

    if (kind === 'password') {
      return element.type === 'password' || text.includes('password') || text.includes('senha');
    }

    return text.includes('usuario') || text.includes('user') || text.includes('login');
  }) || null;
}

function setValue(element, value) {
  const descriptor =
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value') ||
    Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value');

  element.focus();
  if (descriptor && typeof descriptor.set === 'function') {
    descriptor.set.call(element, value);
  } else {
    element.value = value;
  }
  element.dispatchEvent(new Event('input', { bubbles: true }));
  element.dispatchEvent(new Event('change', { bubbles: true }));
  element.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
}

function findSubmit() {
  const selectors = [
    '#btn_entrar',
    '#btn_login',
    'button[type="submit"]',
    'input[type="submit"]',
    'button.btn.btn-primary.btn-block',
    'button[class*="btn-primary"]',
    'a.btn.btn-primary'
  ];

  for (const selector of selectors) {
    const element = document.querySelector(selector);
    if (element) {
      return element;
    }
  }

  const candidates = Array.from(document.querySelectorAll('button, input, a'));
  return candidates.find((element) => {
    const text = normalize(element.innerText || element.value || '');
    return text.includes('entrar') || text.includes('login') || text.includes('acessar');
  }) || null;
}

const user = findInput('user');
const password = findInput('password');

if (!user) {
  return { ok: false, step: 'user_not_found' };
}

if (!password) {
  return { ok: false, step: 'password_not_found' };
}

setValue(user, arguments[0]);
setValue(password, arguments[1]);

const submit = findSubmit();
if (submit) {
  submit.click();
  return {
    ok: true,
    step: 'clicked',
    userId: user.id || user.name || user.placeholder || '',
    passwordId: password.id || password.name || password.placeholder || '',
    submitText: submit.innerText || submit.value || submit.id || ''
  };
}

const form = password.closest('form') || user.closest('form');
if (form) {
  form.submit();
  return {
    ok: true,
    step: 'submitted_form',
    userId: user.id || user.name || user.placeholder || '',
    passwordId: password.id || password.name || password.placeholder || ''
  };
}

return { ok: false, step: 'submit_not_found' };
JS, [LOGIN_USER, LOGIN_PASSWORD]);

        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            $step = is_array($result) ? (string) ($result['step'] ?? 'unknown') : 'invalid_result';
            throw new RuntimeException('Nao foi possivel preencher e enviar o login. Etapa: ' . $step);
        }

        $this->log('Login direto executado: ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function fillCredentialsAndSubmitWithFallback(): void
    {
        try {
            $this->fillCredentialsAndSubmitDirect();
        } catch (Throwable $exception) {
            $this->log('Falha no login via WebDriver: ' . $exception->getMessage());

            if (DIRECTORY_SEPARATOR !== '\\') {
                throw $exception;
            }

            $this->performWindowsSendKeysLogin();
        }
    }

    private function performWindowsSendKeysLogin(): void
    {
        $this->log('Tentando login via automacao da janela do Windows');

        $script = <<<'POWERSHELL'
Add-Type -AssemblyName Microsoft.VisualBasic
$wshell = New-Object -ComObject WScript.Shell
Start-Sleep -Seconds 2

$titles = @(
    'Onnee NF-e',
    'Google Chrome',
    'Chrome'
)

$activated = $false
foreach ($title in $titles) {
    if ([Microsoft.VisualBasic.Interaction]::AppActivate($title)) {
        $activated = $true
        break
    }
}

if (-not $activated) {
    throw 'Nao foi possivel ativar a janela do Chrome.'
}

Start-Sleep -Milliseconds 700
$wshell.SendKeys('admin')
Start-Sleep -Milliseconds 300
$wshell.SendKeys('{TAB}')
Start-Sleep -Milliseconds 300
$wshell.SendKeys('admin')
Start-Sleep -Milliseconds 300
$wshell.SendKeys('{TAB}')
Start-Sleep -Milliseconds 300
$wshell.SendKeys('{ENTER}')
POWERSHELL;

        $tempFile = tempnam(sys_get_temp_dir(), 'roboemissor_ps_');
        if ($tempFile === false) {
            throw new RuntimeException('Nao foi possivel criar o arquivo temporario para a automacao do Windows.');
        }

        $psFile = $tempFile . '.ps1';
        @rename($tempFile, $psFile);
        file_put_contents($psFile, $script);

        $command = 'powershell -ExecutionPolicy Bypass -File ' . escapeshellarg($psFile);
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);
        @unlink($psFile);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Falha no login via automacao da janela do Windows: ' . implode(PHP_EOL, $output)
            );
        }

        $this->log('Login via automacao da janela do Windows executado');
    }

    private function openEmitirNFeAfterLogin(): void
    {
        sleep(3);

        try {
            $this->openEmitirNFeByMenu();
            return;
        } catch (Throwable $exception) {
            $this->log('Falha ao abrir Emitir NF-e pelo menu: ' . $exception->getMessage());
        }

        $this->openEmitirNFeDirectly();
    }

    private function openEmitirNFeByMenu(): void
    {
        $result = $this->executeScript(<<<'JS'
function normalize(text) {
  return String(text || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
}

function findByText(selector, expectedTexts) {
  const elements = Array.from(document.querySelectorAll(selector));
  for (const element of elements) {
    const text = normalize(element.innerText || element.textContent || element.value || '');
    for (const expected of expectedTexts) {
      if (text.includes(normalize(expected))) {
        return element;
      }
    }
  }
  return null;
}

const menu = findByText('a, span, li', ['Nota Fiscal de Saida', 'Nota Fiscal de Saída']);
if (menu) {
  menu.click();
}

const submenu = findByText('a, span, li', ['Emitir NF-e']);
if (!submenu) {
  return { ok: false, step: 'submenu_not_found' };
}

submenu.click();
return {
  ok: true,
  step: 'clicked_menu',
  currentUrl: window.location.href
};
JS);

        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            $step = is_array($result) ? (string) ($result['step'] ?? 'unknown') : 'invalid_result';
            throw new RuntimeException('Etapa: ' . $step);
        }

        $opened = $this->waitUntil(function (): bool {
            $url = $this->getCurrentUrlFromPage();
            return str_contains(strtolower($url), 'emitirnfe.aspx');
        }, DEFAULT_TIMEOUT_SECONDS);

        if (!$opened) {
            throw new RuntimeException('A URL EmitirNFe.aspx nao abriu apos o clique do menu.');
        }

        $this->log('Menu lateral acionado com sucesso');
    }

    private function openEmitirNFeDirectly(): void
    {
        $targetUrl = 'http://192.168.1.41/onneenfe/EmitirNFe.aspx';
        $this->log('Abrindo Emitir NF-e diretamente em ' . $targetUrl);
        $this->navigateTo($targetUrl);
    }

    private function filterNotasFromFirstDayOfMonth(): void
    {
        sleep(2);

        $result = $this->executeScript(<<<'JS'
function setValue(element, value) {
  const descriptor =
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value') ||
    Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value');

  element.focus();
  if (descriptor && typeof descriptor.set === 'function') {
    descriptor.set.call(element, value);
  } else {
    element.value = value;
  }
  element.dispatchEvent(new Event('input', { bubbles: true }));
  element.dispatchEvent(new Event('change', { bubbles: true }));
  element.blur();
}

const dateInputs = Array.from(document.querySelectorAll('input')).filter((element) => {
  const placeholder = String(element.placeholder || '').toLowerCase();
  return placeholder.includes('dd/mm/aaaa');
});

const firstFilterRow =
  document.querySelector('.portlet-body .row') ||
  document.querySelector('.row');

const fallbackInputs = firstFilterRow
  ? Array.from(firstFilterRow.querySelectorAll('input')).filter((element) => element.type !== 'hidden')
  : [];

const firstInput = dateInputs[0] || fallbackInputs[0] || null;
if (!firstInput) {
  return { ok: false, step: 'date_input_not_found' };
}

const now = new Date();
const numericValue = `01${String(now.getMonth() + 1).padStart(2, '0')}${now.getFullYear()}`;
setValue(firstInput, numericValue);

const searchButton =
  document.querySelector('button .fa-search')?.closest('button') ||
  Array.from(document.querySelectorAll('button')).find((element) => {
    const icon = element.querySelector('.fa-search, .glyphicon-search');
    return icon !== null;
  }) ||
  document.querySelector('button[class*="btn-primary"]') ||
  Array.from(document.querySelectorAll('button, a')).find((element) => {
    const text = String(element.innerText || element.textContent || '').toLowerCase();
    return text.includes('buscar') || text.includes('pesquisar');
  });

if (!searchButton) {
  return { ok: false, step: 'search_button_not_found' };
}

searchButton.click();
return { ok: true, step: 'search_clicked', value: numericValue };
JS);

        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            $step = is_array($result) ? (string) ($result['step'] ?? 'unknown') : 'invalid_result';
            throw new RuntimeException('Nao foi possivel aplicar o filtro de notas. Etapa: ' . $step);
        }

        $this->log('Filtro aplicado com valor numerico: ' . (string) ($result['value'] ?? ''));
    }

    private function waitForLoginFields(): void
    {
        $found = $this->waitUntil(function (): bool {
            $result = $this->executeScript(<<<'JS'
const inputs = Array.from(document.querySelectorAll('input'));
const hasUser = inputs.some((element) => {
  const text = String(element.placeholder || element.name || element.id || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();
  return text.includes('usuario') || text.includes('user') || text.includes('login');
});
const hasPassword = inputs.some((element) => element.type === 'password');
return hasUser && hasPassword;
JS);

            return $result === true;
        }, DEFAULT_TIMEOUT_SECONDS);

        if (!$found) {
            throw new RuntimeException('A tela de login abriu, mas os campos de usuario e senha nao ficaram disponiveis.');
        }
    }

    private function findBestInput(array $keywords, bool $passwordField): ?array
    {
        $script = <<<'JS'
const keywords = arguments[0];
const mustBePassword = arguments[1];
const inputs = Array.from(document.querySelectorAll('input'));

function normalize(text) {
  return String(text || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();
}

function score(element) {
  const data = [
    element.name || '',
    element.id || '',
    element.placeholder || '',
    element.title || '',
    element.getAttribute('aria-label') || '',
    element.getAttribute('autocomplete') || '',
    element.className || '',
  ].join(' ');
  const normalizedData = normalize(data);

  let points = 0;
  for (const keyword of keywords) {
    if (normalizedData.includes(normalize(keyword))) {
      points += 10;
    }
  }

  if (mustBePassword && element.type === 'password') {
    points += 100;
  }

  if (!mustBePassword && ['text', 'email', 'tel'].includes(element.type)) {
    points += 20;
  }

  if (element.offsetParent !== null) {
    points += 5;
  }

  return points;
}

const filtered = inputs.filter((element) => {
  if (mustBePassword) {
    return element.type === 'password';
  }
  return element.type !== 'hidden' && element.type !== 'password';
});

const directMatch = filtered.find((element) => {
  const placeholder = normalize(element.placeholder || '');
  if (mustBePassword) {
    return placeholder.includes('password') || placeholder.includes('senha');
  }

  return placeholder.includes('usuario') || placeholder.includes('user') || placeholder.includes('login');
});

if (directMatch) {
  return directMatch;
}

filtered.sort((a, b) => score(b) - score(a));
return filtered.length ? filtered[0] : null;
JS;

        $result = $this->executeScript($script, [$keywords, $passwordField]);
        return is_array($result) ? $result : null;
    }

    private function setElementValue(array $element, string $value): void
    {
        $script = <<<'JS'
const element = arguments[0];
const value = arguments[1];
const prototype = Object.getPrototypeOf(element);
const descriptor = Object.getOwnPropertyDescriptor(prototype, 'value');

element.focus();
if (descriptor && typeof descriptor.set === 'function') {
  descriptor.set.call(element, value);
} else {
  element.value = value;
}
element.dispatchEvent(new Event('input', { bubbles: true }));
element.dispatchEvent(new Event('change', { bubbles: true }));
element.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: 'Enter' }));
return true;
JS;

        $this->executeScript($script, [$element, $value]);
    }

    private function clickSubmitButton(): bool
    {
        $script = <<<'JS'
const selectors = [
  'button[type="submit"]',
  'input[type="button"]',
  'input[type="submit"]',
  'button.btn.btn-primary.btn-block',
  'button[class*="btn-primary"]',
  'button[id*="login" i]',
  'button[name*="login" i]',
  'input[id*="login" i]',
  'input[name*="login" i]',
  'button[id*="entrar" i]',
  'button[name*="entrar" i]',
  'input[id*="entrar" i]',
  'input[name*="entrar" i]',
];

for (const selector of selectors) {
  const element = document.querySelector(selector);
  if (element) {
    element.click();
    return true;
  }
}

const candidates = Array.from(document.querySelectorAll('button, input[type="button"], a'));
const match = candidates.find((element) => {
  const text = String(element.innerText || element.value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();
  return text.includes('entrar') || text.includes('login') || text.includes('acessar');
});

if (match) {
  match.click();
  return true;
}

return false;
JS;

        return (bool) $this->executeScript($script);
    }

    private function submitNearestForm(array $element): void
    {
        $script = <<<'JS'
const field = arguments[0];
const form = field.closest('form');
if (form) {
  form.submit();
  return true;
}
return false;
JS;

        $submitted = (bool) $this->executeScript($script, [$element]);
        if (!$submitted) {
            throw new RuntimeException('Nao foi possivel submeter o formulario de login.');
        }
    }

    private function saveScreenshot(string $path): void
    {
        $response = $this->sessionRequest('GET', '/screenshot');
        $base64 = $response['value'] ?? null;

        if (!is_string($base64) || $base64 === '') {
            throw new RuntimeException('Nao foi possivel capturar o screenshot da execucao.');
        }

        file_put_contents($path, base64_decode($base64, true));
    }

    private function deleteSession(): void
    {
        if ($this->sessionId === null) {
            return;
        }

        try {
            $this->sessionRequest('DELETE', '');
        } catch (Throwable) {
        } finally {
            $this->sessionId = null;
        }
    }

    private function executeScript(string $script, array $args = []): mixed
    {
        $response = $this->sessionRequest('POST', '/execute/sync', [
            'script' => $script,
            'args' => $args,
        ]);

        return $response['value'] ?? null;
    }

    private function getCurrentUrlFromPage(): string
    {
        $url = $this->executeScript('return window.location.href;');

        return is_string($url) ? $url : '';
    }

    private function sessionRequest(string $method, string $path, ?array $payload = null): array
    {
        if ($this->sessionId === null) {
            throw new RuntimeException('A sessao do navegador ainda nao foi criada.');
        }

        return $this->httpRequest($method, '/session/' . $this->sessionId . $path, $payload);
    }

    private function httpRequest(string $method, string $path, ?array $payload = null): array
    {
        if (is_file(CURL_EXE_PATH) || is_file(CURL_EXE_ALT_PATH)) {
            return $this->httpRequestWithCurlExe($method, $path, $payload);
        }

        if (function_exists('curl_init')) {
            return $this->httpRequestLegacyPhpCurl($method, $path, $payload);
        }

        return $this->httpRequestWithPhpStreams($method, $path, $payload);
    }

    private function httpRequestWithPhpStreams(string $method, string $path, ?array $payload = null): array
    {
        $this->log('HTTP streams ' . $method . ' ' . $path);
        $headers = [
            'Content-Type: application/json',
            'Connection: close',
        ];

        $content = null;
        if ($payload !== null) {
            $content = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($content === false) {
                throw new RuntimeException('Falha ao serializar a requisicao para JSON.');
            }

            $headers[] = 'Content-Length: ' . strlen($content);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'timeout' => DEFAULT_TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($this->baseUrl . $path, false, $context);
        if ($raw === false) {
            $error = error_get_last();
            $message = $error['message'] ?? 'falha desconhecida';
            throw new RuntimeException('Falha na comunicacao com o ChromeDriver: ' . $message);
        }

        $statusCode = $this->extractHttpStatusCode($http_response_header ?? []);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta invalida do ChromeDriver: ' . $raw);
        }

        if ($statusCode >= 400 || isset($decoded['value']['error'])) {
            $message = $decoded['value']['message'] ?? ('HTTP ' . $statusCode);
            throw new RuntimeException('Erro do ChromeDriver: ' . $message);
        }

        return $decoded;
    }

    private function extractHttpStatusCode(array $headers): int
    {
        foreach ($headers as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function httpRequestWithPhpCurl(string $method, string $path, ?array $payload = null): array
    {
        if (function_exists('curl_init')) {
            return $this->httpRequestLegacyPhpCurl($method, $path, $payload);
        }

        return $this->httpRequestWithCurlExe($method, $path, $payload);
    }

    private function httpRequestLegacyPhpCurl(string $method, string $path, ?array $payload = null): array
    {
        $this->log('PHP curl ' . $method . ' ' . $path);
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar a requisicao HTTP.');
        }

        $headers = ['Content-Type: application/json'];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => DEFAULT_TIMEOUT_SECONDS,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Falha na comunicacao com o ChromeDriver: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta invalida do ChromeDriver: ' . $raw);
        }

        if ($statusCode >= 400 || isset($decoded['value']['error'])) {
            $message = $decoded['value']['message'] ?? ('HTTP ' . $statusCode);
            throw new RuntimeException('Erro do ChromeDriver: ' . $message);
        }

        return $decoded;
    }

    private function httpRequestWithCurlExe(string $method, string $path, ?array $payload = null): array
    {
        $this->log('curl.exe ' . $method . ' ' . $path);
        $curlExe = is_file(CURL_EXE_PATH) ? CURL_EXE_PATH : CURL_EXE_ALT_PATH;
        if (!is_file($curlExe)) {
            throw new RuntimeException(
                'A extensao curl do PHP nao esta habilitada e o curl.exe nao foi encontrado em ' .
                CURL_EXE_PATH . ' nem em ' . CURL_EXE_ALT_PATH . '.'
            );
        }

        $commandParts = [
            '"' . $curlExe . '"',
            '--silent',
            '--show-error',
            '--max-time',
            (string) DEFAULT_TIMEOUT_SECONDS,
            '--request',
            $method,
            '--header',
            '"Content-Type: application/json"',
        ];

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Falha ao serializar a requisicao para JSON.');
            }

            $commandParts[] = '--data';
            $commandParts[] = escapeshellarg($json);
        }

        $commandParts[] = escapeshellarg($this->baseUrl . $path);

        $output = [];
        $exitCode = 0;
        exec(implode(' ', $commandParts) . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Falha na comunicacao com o ChromeDriver: ' . implode(PHP_EOL, $output));
        }

        $raw = implode(PHP_EOL, $output);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta invalida do ChromeDriver: ' . $raw);
        }

        if (isset($decoded['value']['error'])) {
            $message = $decoded['value']['message'] ?? 'Erro desconhecido do ChromeDriver.';
            throw new RuntimeException('Erro do ChromeDriver: ' . $message);
        }

        return $decoded;
    }

    private function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents($this->automationLogPath, $line, FILE_APPEND);
    }

    private function waitUntil(callable $condition, int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }

            usleep(250000);
        }

        return false;
    }
}

try {
    $automation = new ChromeLoginAutomation();
    $automation->run();
    echo "Automacao executada com sucesso. Screenshot salvo em login_result.png" . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erro: ' . $exception->getMessage() . PHP_EOL);
    if (isset($automation) && method_exists($automation, 'getChromeDriverCandidates')) {
        $candidates = $automation->getChromeDriverCandidates();
        if ($candidates !== []) {
            fwrite(STDERR, 'Locais verificados para o ChromeDriver:' . PHP_EOL);
            foreach ($candidates as $candidate) {
                fwrite(STDERR, ' - ' . $candidate . PHP_EOL);
            }
        }
    }
    if (isset($automation) && method_exists($automation, 'getAutomationLogPath')) {
        fwrite(STDERR, 'Log da automacao: ' . $automation->getAutomationLogPath() . PHP_EOL);
    }
    exit(1);
}
