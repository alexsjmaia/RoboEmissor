<?php

declare(strict_types=1);

const FAST_BASE_URL = 'http://192.168.1.41/onneenfe';
const FAST_LOGIN_URL = FAST_BASE_URL . '/Login.aspx';
const FAST_EMITIR_URL = FAST_BASE_URL . '/EmitirNFe.aspx';
const FAST_USER = 'admin';
const FAST_PASSWORD = 'admin';
const FAST_CHROMEDRIVER_PORT = 9515;
const FAST_TIMEOUT = 2;
const FAST_ROBOEMISSOR_DIR = 'C:\\RoboEmissor';
const FAST_CHROMEDRIVER_EXE = FAST_ROBOEMISSOR_DIR . '\\chromedriver-win64\\chromedriver.exe';
const FAST_CURL_EXE = FAST_ROBOEMISSOR_DIR . '\\curl-8.19.0\\curl.exe';
const FAST_CURL_EXE_ALT = FAST_ROBOEMISSOR_DIR . '\\curl-8.19.0\\bin\\curl.exe';
const FAST_LOG_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'logs';

final class FastChromeAutomation
{
    private string $baseUrl;
    private ?string $sessionId = null;
    private string $logPath;

    public function __construct()
    {
        $this->baseUrl = 'http://127.0.0.1:' . FAST_CHROMEDRIVER_PORT;
        if (!is_dir(FAST_LOG_DIR)) {
            @mkdir(FAST_LOG_DIR, 0777, true);
        }

        $timestamp = date('Ymd_His');
        $this->logPath = FAST_LOG_DIR . DIRECTORY_SEPARATOR . 'automation_fast_' . $timestamp . '.log';
    }

    public function run(): void
    {
        @file_put_contents($this->logPath, '');
        $this->log('Inicio');
        $this->ensureChromeDriver();
        $this->createSession();

        try {
            $this->openUrl(FAST_LOGIN_URL);
            $this->windowsLogin();
            $this->openUrl(FAST_EMITIR_URL);
            $this->focusFirstDateField();
            $this->typeFirstDayAndSearchWithKeyboard();
            $this->sendAllNotas();
            $this->saveScreenshot(__DIR__ . DIRECTORY_SEPARATOR . 'login_result.png');
            $this->log('Fim com sucesso');
        } finally {
            $this->log('Encerrando sessao e fechando o Chrome');
            $this->deleteSession();
        }
    }

    private function ensureChromeDriver(): void
    {
        if ($this->isResponsive()) {
            return;
        }

        if (!is_file(FAST_CHROMEDRIVER_EXE)) {
            throw new RuntimeException('ChromeDriver nao encontrado em ' . FAST_CHROMEDRIVER_EXE);
        }

        $command =
            'start "" /B "' . FAST_CHROMEDRIVER_EXE . '" --port=' . FAST_CHROMEDRIVER_PORT;
        pclose(popen($command, 'r'));

        if (!$this->waitUntil(fn (): bool => $this->isResponsive(), 2)) {
            throw new RuntimeException('ChromeDriver nao respondeu na porta ' . FAST_CHROMEDRIVER_PORT . '.');
        }
    }

    private function isResponsive(): bool
    {
        try {
            $response = $this->httpRequest('GET', '/status');
            return is_array($response);
        } catch (Throwable) {
            return false;
        }
    }

    private function createSession(): void
    {
        $response = $this->httpRequest('POST', '/session', [
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
        ]);

        $this->sessionId = $response['value']['sessionId'] ?? $response['sessionId'] ?? null;
        if (!is_string($this->sessionId) || $this->sessionId === '') {
            throw new RuntimeException('Nao foi possivel criar a sessao do Chrome.');
        }
    }

    private function openUrl(string $url): void
    {
        $this->sessionRequest('POST', '/url', ['url' => $url]);
        usleep(50000);
        $this->executeScript('window.location.href = arguments[0];', [$url]);
        usleep(50000);
        $this->log('URL aberta: ' . $url);
    }

    private function windowsLogin(): void
    {
        $this->log('Login por SendKeys');
        $this->runPowerShell(<<<'POWERSHELL'
Add-Type -AssemblyName Microsoft.VisualBasic
$wshell = New-Object -ComObject WScript.Shell
Start-Sleep -Milliseconds 50
[Microsoft.VisualBasic.Interaction]::AppActivate('Onnee NF-e') | Out-Null
Start-Sleep -Milliseconds 50
$wshell.SendKeys('admin')
Start-Sleep -Milliseconds 50
$wshell.SendKeys('{TAB}')
Start-Sleep -Milliseconds 50
$wshell.SendKeys('admin')
Start-Sleep -Milliseconds 50
$wshell.SendKeys('{TAB}')
Start-Sleep -Milliseconds 50
$wshell.SendKeys('{ENTER}')
POWERSHELL);
    }

    private function focusFirstDateField(): void
    {
        $this->log('Focando primeiro campo de data');
        usleep(50000);

        $result = $this->executeScript(<<<'JS'
const dateInputs = Array.from(document.querySelectorAll('input')).filter((element) =>
  String(element.placeholder || '').toLowerCase().includes('dd/mm/aaaa')
);

const firstFilterRow =
  document.querySelector('.portlet-body .row') ||
  document.querySelector('.row');

const fallbackInputs = firstFilterRow
  ? Array.from(firstFilterRow.querySelectorAll('input')).filter((element) => element.type !== 'hidden')
  : [];

const firstInput = dateInputs[0] || fallbackInputs[0] || null;
if (!firstInput) {
  return { ok: false, step: 'date_not_found' };
}

firstInput.focus();
firstInput.click();
return { ok: true };
JS);

        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            $step = is_array($result) ? (string) ($result['step'] ?? 'unknown') : 'invalid';
            throw new RuntimeException('Falha ao focar o campo de data. Etapa: ' . $step);
        }
    }

    private function typeFirstDayAndSearchWithKeyboard(): void
    {
        $currentMonth = (int) date('n');
        $month = str_pad((string) ($currentMonth === 1 ? 12 : $currentMonth - 1), 2, '0', STR_PAD_LEFT);
        $year = date('Y');
        $numericValue = '01' . $month . $year;
        $this->log('Digitando filtro: ' . $numericValue);

        $this->runPowerShell(<<<POWERSHELL
Add-Type -AssemblyName Microsoft.VisualBasic
\$wshell = New-Object -ComObject WScript.Shell
Start-Sleep -Milliseconds 80
[Microsoft.VisualBasic.Interaction]::AppActivate('Onnee NF-e') | Out-Null
Start-Sleep -Milliseconds 80
\$wshell.SendKeys('^a')
Start-Sleep -Milliseconds 30
\$wshell.SendKeys('{BACKSPACE}')
Start-Sleep -Milliseconds 30
\$wshell.SendKeys('$numericValue')
POWERSHELL);

        usleep(50000);

        $this->clickSearchButton();
    }

    private function clickSearchButton(): void
    {
        $this->log('Clicando na lupa');

        $result = $this->executeScript(<<<'JS'
function clickElement(element) {
  if (!element) {
    return false;
  }

  ['mouseover', 'mousedown', 'mouseup', 'click'].forEach((eventName) => {
    element.dispatchEvent(new MouseEvent(eventName, { bubbles: true, cancelable: true, view: window }));
  });

  if (typeof element.click === 'function') {
    element.click();
  }

  return true;
}

const buttons = Array.from(document.querySelectorAll('button, a'));
const searchButton =
  document.querySelector('button .fa-search')?.closest('button') ||
  buttons.find((element) => element.querySelector('.fa-search, .glyphicon-search')) ||
  buttons.find((element) => {
    const text = String(element.innerText || element.textContent || '').toLowerCase();
    return text.includes('buscar') || text.includes('pesquisar');
  });

if (!searchButton) {
  return { ok: false, step: 'search_button_not_found' };
}

clickElement(searchButton);
return { ok: true };
JS);

        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            $step = is_array($result) ? (string) ($result['step'] ?? 'unknown') : 'invalid';
            throw new RuntimeException('Falha ao clicar na lupa. Etapa: ' . $step);
        }

        usleep(50000);
    }

    private function sendAllNotas(): void
    {
        $this->log('Iniciando envio em loop');

        $guard = 0;
        while ($guard < 200) {
            $guard++;

            $state = $this->prepareFirstCheckboxForSelection();

            if (!is_array($state)) {
                throw new RuntimeException('Resposta invalida no loop de envio.');
            }

            $reason = (string) ($state['reason'] ?? 'desconhecido');
            $rowText = (string) ($state['rowText'] ?? '');
            $this->log('Estado do loop: ' . $reason . ($rowText !== '' ? ' | ' . $rowText : ''));
            if (isset($state['diagnostics']) && is_array($state['diagnostics'])) {
                $this->log('Diagnostico: ' . json_encode($state['diagnostics'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            if (($state['done'] ?? false) === true) {
                return;
            }

            if ($reason === 'nf_com_erro') {
                sleep(1);
                continue;
            }

            $alreadyChecked = ($state['checked'] ?? false) === true;
            if (!$alreadyChecked) {
                $this->toggleFocusedCheckboxWithKeyboard();

                if (!$this->isPreparedCheckboxChecked()) {
                    $this->toggleFocusedCheckboxWithKeyboard();
                }

                if (!$this->isPreparedCheckboxChecked()) {
                    throw new RuntimeException('Nao foi possivel marcar o checkbox da primeira NF.');
                }
            }

            $this->clickSendButton();
            sleep(2);
            $this->dismissAlertsIfAny();
        }

        throw new RuntimeException('Loop de envio excedeu o limite de iteracoes.');
    }

    private function prepareFirstCheckboxForSelection(): array
    {
        $state = $this->executeScript(<<<'JS'
function normalize(text) {
  return String(text || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .toLowerCase()
    .trim();
}

function clickElement(element) {
  if (!element) {
    return false;
  }

  ['mouseover', 'mousedown', 'mouseup', 'click'].forEach((eventName) => {
    element.dispatchEvent(new MouseEvent(eventName, { bubbles: true, cancelable: true, view: window }));
  });

  if (typeof element.click === 'function') {
    element.click();
  }

  return true;
}

const noData = Array.from(document.querySelectorAll('body *')).some((element) => {
  const text = String(element.textContent || '').toLowerCase();
  return text.includes('nenhuma nota encontrada');
});

const listNotas = document.getElementById('listNotas');
if (!listNotas) {
  return { done: true, reason: 'list_notas_nao_encontrada' };
}

const checkboxes = Array.from(listNotas.querySelectorAll('input[type="checkbox"]')).filter((element) => {
  if (!(element instanceof HTMLInputElement)) {
    return false;
  }

  if (element.disabled || element.offsetParent === null) {
    return false;
  }
  return true;
});

const candidateRows = Array.from(listNotas.children).filter((row) => {
  if (!(row instanceof HTMLElement) || row.offsetParent === null) {
    return false;
  }

  const text = normalize(row.textContent || '');
  return /\b\d{4,}\b/.test(text);
});

const checkboxRows = checkboxes.map((checkbox) => {
  const rowContainer =
    checkbox.closest('tr') ||
    checkbox.closest('.row') ||
    checkbox.closest('li') ||
    checkbox.parentElement;

  return {
    checkbox,
    rowContainer,
    text: normalize(rowContainer?.textContent || '')
  };
});

function hasWarning(rowContainer) {
  if (!rowContainer) {
    return false;
  }

  return Array.from(rowContainer.querySelectorAll('.glyphicon, .fa, span, i, button, a')).some((element) => {
    const className = String(element.className || '').toLowerCase();
    const text = String(element.textContent || '').trim();
    return className.includes('warning') || className.includes('alert') || text === '!';
  });
}

const rowsWithoutWarning = checkboxRows.filter((item) => !hasWarning(item.rowContainer));

const preferredRow =
  rowsWithoutWarning.find((item) => item.text.includes('5750') && item.text.includes('jovelina dos santos teixeira carvalho')) ||
  rowsWithoutWarning[0] ||
  null;

if (!preferredRow || !preferredRow.checkbox) {
    const firstCandidateRow = candidateRows[0] || null;
    const firstCandidateHtml = firstCandidateRow ? String(firstCandidateRow.outerHTML || '').slice(0, 2000) : '';
    const firstCandidateText = firstCandidateRow ? String(firstCandidateRow.textContent || '').trim().slice(0, 300) : '';
    const firstCandidateInputs = firstCandidateRow ? firstCandidateRow.querySelectorAll('input').length : 0;
    const firstCandidateLabels = firstCandidateRow ? firstCandidateRow.querySelectorAll('label').length : 0;
  const firstCandidateButtons = firstCandidateRow ? firstCandidateRow.querySelectorAll('button,a,span,div,td').length : 0;
  const listNotasHtml = String(listNotas.innerHTML || '').slice(0, 4000);

  return {
    done: true,
    reason: noData ? 'nenhuma_nota' : 'sem_checkbox',
      diagnostics: {
        totalCheckboxes: checkboxes.length,
        candidateRows: candidateRows.length,
        rowsWithoutWarning: rowsWithoutWarning.length,
        firstCandidateText,
        firstCandidateHtml,
        listNotasHtml,
        firstCandidateInputs,
        firstCandidateLabels,
      firstCandidateButtons
    }
  };
}

const firstCheckbox = preferredRow.checkbox;
const rowContainer = preferredRow.rowContainer;

firstCheckbox.scrollIntoView({ block: 'center', inline: 'center' });
firstCheckbox.focus();
firstCheckbox.setAttribute('data-robo-target', '1');

const firstCell =
  rowContainer?.querySelector('td:first-child') ||
  rowContainer?.querySelector('div:first-child') ||
  rowContainer?.querySelector('.checker, .checkbox, label') ||
  firstCheckbox.parentElement;

clickElement(firstCheckbox);
if (!firstCheckbox.checked) {
  clickElement(firstCell);
}
if (!firstCheckbox.checked) {
  clickElement(firstCheckbox.parentElement);
}
if (!firstCheckbox.checked) {
  firstCheckbox.checked = true;
  firstCheckbox.dispatchEvent(new Event('input', { bubbles: true }));
  firstCheckbox.dispatchEvent(new Event('change', { bubbles: true }));
}

return {
  done: false,
  reason: 'checkbox_preparado',
  rowText: String(rowContainer?.textContent || '').trim().slice(0, 120),
  checked: firstCheckbox.checked === true,
  diagnostics: {
    totalCheckboxes: checkboxes.length,
    candidateRows: candidateRows.length,
    rowsWithoutWarning: rowsWithoutWarning.length,
    targetHtml: String((rowContainer instanceof HTMLElement ? rowContainer.outerHTML : '') || '').slice(0, 2000)
  }
};
JS);

        return is_array($state) ? $state : ['done' => true, 'reason' => 'estado_invalido'];
    }

    private function toggleFocusedCheckboxWithKeyboard(): void
    {
        $this->log('Marcando checkbox por teclado');
        $this->runPowerShell(<<<'POWERSHELL'
Add-Type -AssemblyName Microsoft.VisualBasic
$wshell = New-Object -ComObject WScript.Shell
Start-Sleep -Milliseconds 60
[Microsoft.VisualBasic.Interaction]::AppActivate('Onnee NF-e') | Out-Null
Start-Sleep -Milliseconds 60
$wshell.SendKeys(' ')
POWERSHELL);
        usleep(120000);
    }

    private function isPreparedCheckboxChecked(): bool
    {
        $result = $this->executeScript(<<<'JS'
const target = document.querySelector('input[data-robo-target="1"]');
return target ? target.checked === true : false;
JS);

        return $result === true;
    }

    private function clickSendButton(): void
    {
        $result = $this->executeScript(<<<'JS'
function clickElement(element) {
  if (!element) {
    return false;
  }

  ['mouseover', 'mousedown', 'mouseup', 'click'].forEach((eventName) => {
    element.dispatchEvent(new MouseEvent(eventName, { bubbles: true, cancelable: true, view: window }));
  });

  if (typeof element.click === 'function') {
    element.click();
  }

  return true;
}

const buttons = Array.from(document.querySelectorAll('button, a, input[type="button"], input[type="submit"]'));
const sendButton =
  buttons.find((element) => {
    const text = String(element.innerText || element.textContent || element.value || '').toLowerCase();
    return text.includes('enviar nota fiscal');
  }) ||
  buttons.find((element) => {
    const text = String(element.innerText || element.textContent || element.value || '').toLowerCase();
    return text.includes('enviar');
  });

if (!sendButton) {
  return { ok: false, step: 'botao_enviar_nao_encontrado' };
}

clickElement(sendButton);
return { ok: true };
JS);

        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            $step = is_array($result) ? (string) ($result['step'] ?? 'unknown') : 'invalid';
            throw new RuntimeException('Falha ao clicar em Enviar Nota Fiscal. Etapa: ' . $step);
        }
    }

    private function dismissAlertsIfAny(): void
    {
        $this->executeScript(<<<'JS'
const buttons = Array.from(document.querySelectorAll('button, a, input'));
const okButton = buttons.find((element) => {
  const text = String(element.innerText || element.textContent || element.value || '').toLowerCase().trim();
  return text === 'ok' || text === 'sim' || text === 'confirmar' || text === 'fechar';
});

if (okButton) {
  ['mouseover', 'mousedown', 'mouseup', 'click'].forEach((eventName) => {
    okButton.dispatchEvent(new MouseEvent(eventName, { bubbles: true, cancelable: true, view: window }));
  });
  if (typeof okButton.click === 'function') {
    okButton.click();
  }
}

return true;
JS);
    }

    private function saveScreenshot(string $path): void
    {
        $response = $this->sessionRequest('GET', '/screenshot');
        $base64 = $response['value'] ?? null;
        if (!is_string($base64) || $base64 === '') {
            return;
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

    private function sessionRequest(string $method, string $path, ?array $payload = null): array
    {
        if ($this->sessionId === null) {
            throw new RuntimeException('Sessao nao criada.');
        }

        return $this->httpRequest($method, '/session/' . $this->sessionId . $path, $payload);
    }

    private function httpRequest(string $method, string $path, ?array $payload = null): array
    {
        $curlExe = is_file(FAST_CURL_EXE) ? FAST_CURL_EXE : FAST_CURL_EXE_ALT;
        if (is_file($curlExe)) {
            return $this->httpRequestWithCurlExe($curlExe, $method, $path, $payload);
        }

        return $this->httpRequestWithStreams($method, $path, $payload);
    }

    private function httpRequestWithCurlExe(string $curlExe, string $method, string $path, ?array $payload): array
    {
        $commandParts = [
            '"' . $curlExe . '"',
            '--silent',
            '--show-error',
            '--max-time',
            (string) FAST_TIMEOUT,
            '--request',
            $method,
            '--header',
            '"Content-Type: application/json"',
        ];

        if ($payload !== null) {
            $commandParts[] = '--data';
            $commandParts[] = escapeshellarg((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $commandParts[] = escapeshellarg($this->baseUrl . $path);

        $output = [];
        $exitCode = 0;
        exec(implode(' ', $commandParts) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException('Falha HTTP: ' . implode(PHP_EOL, $output));
        }

        $decoded = json_decode(implode(PHP_EOL, $output), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta invalida do ChromeDriver.');
        }

        if (isset($decoded['value']['error'])) {
            throw new RuntimeException((string) ($decoded['value']['message'] ?? 'Erro do ChromeDriver.'));
        }

        return $decoded;
    }

    private function httpRequestWithStreams(string $method, string $path, ?array $payload): array
    {
        $content = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => "Content-Type: application/json\r\nConnection: close",
                'content' => $content,
                'timeout' => FAST_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($this->baseUrl . $path, false, $context);
        if ($raw === false) {
            throw new RuntimeException('Falha na comunicacao com o ChromeDriver.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta invalida do ChromeDriver.');
        }

        if (isset($decoded['value']['error'])) {
            throw new RuntimeException((string) ($decoded['value']['message'] ?? 'Erro do ChromeDriver.'));
        }

        return $decoded;
    }

    private function runPowerShell(string $script): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'robo_fast_ps_');
        if ($tempFile === false) {
            throw new RuntimeException('Falha ao criar script temporario.');
        }

        $psFile = $tempFile . '.ps1';
        @rename($tempFile, $psFile);
        file_put_contents($psFile, $script);

        $output = [];
        $exitCode = 0;
        exec('powershell -ExecutionPolicy Bypass -File ' . escapeshellarg($psFile) . ' 2>&1', $output, $exitCode);
        @unlink($psFile);

        if ($exitCode !== 0) {
            throw new RuntimeException('Falha PowerShell: ' . implode(PHP_EOL, $output));
        }
    }

    private function waitUntil(callable $condition, int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }
            usleep(10000);
        }
        return false;
    }

    private function log(string $message): void
    {
        @file_put_contents($this->logPath, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}

try {
    (new FastChromeAutomation())->run();
    echo "Automacao rapida executada com sucesso." . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erro: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Logs em: ' . FAST_LOG_DIR . PHP_EOL);
    exit(1);
}
