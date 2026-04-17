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
    private string $chromeDriverStdoutLogPath;

    public function __construct()
    {
        $this->baseUrl = 'http://127.0.0.1:' . FAST_CHROMEDRIVER_PORT;
        if (!is_dir(FAST_LOG_DIR)) {
            @mkdir(FAST_LOG_DIR, 0777, true);
        }

        $timestamp = date('Ymd_His');
        $this->logPath = FAST_LOG_DIR . DIRECTORY_SEPARATOR . 'automation_fast_' . $timestamp . '.log';
        $this->chromeDriverStdoutLogPath = FAST_LOG_DIR . DIRECTORY_SEPARATOR . 'chromedriver_stdout_' . $timestamp . '.log';
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
            'start "" /B cmd /c ""' .
            FAST_CHROMEDRIVER_EXE .
            '" --port=' . FAST_CHROMEDRIVER_PORT .
            ' > "' . $this->chromeDriverStdoutLogPath . '" 2>&1"';
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

        $opened = $this->waitUntil(function () use ($url): bool {
            $currentUrl = $this->getCurrentUrl();
            if ($currentUrl === $url || str_contains($currentUrl, '/onneenfe/')) {
                return true;
            }

            if ($currentUrl === '' || $currentUrl === 'data:,' || $currentUrl === 'about:blank') {
                $this->executeScript('window.location.href = arguments[0];', [$url]);
            }

            return false;
        }, 8);

        if (!$opened) {
            throw new RuntimeException('Nao foi possivel abrir a URL: ' . $url . '. Atual: ' . $this->getCurrentUrl());
        }

        usleep(150000);
        $this->log('URL aberta: ' . $url);
    }

    private function getCurrentUrl(): string
    {
        $result = $this->executeScript('return window.location.href;');
        return is_string($result) ? $result : '';
    }

    private function windowsLogin(): void
    {
        $this->log('Login por DOM');

        $ready = $this->waitUntil(function (): bool {
            $result = $this->executeScript(<<<'JS'
return !!(document.getElementById('txt_user') && document.getElementById('txt_password') && document.getElementById('btn_entrar'));
JS);
            return $result === true;
        }, 10);

        if (!$ready) {
            throw new RuntimeException('Campos de login nao ficaram disponiveis.');
        }

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
}

function clickElement(element) {
  if (!element) return false;
  ['mouseover', 'mousedown', 'mouseup', 'click'].forEach((eventName) => {
    element.dispatchEvent(new MouseEvent(eventName, { bubbles: true, cancelable: true, view: window }));
  });
  if (typeof element.click === 'function') {
    element.click();
  }
  return true;
}

const user = document.getElementById('txt_user');
const pass = document.getElementById('txt_password');
const button = document.getElementById('btn_entrar');
if (!user || !pass || !button) {
  return { ok: false };
}

setValue(user, arguments[0]);
setValue(pass, arguments[1]);
clickElement(button);
return { ok: true };
JS, [FAST_USER, FAST_PASSWORD]);

        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            throw new RuntimeException('Falha no login por DOM.');
        }

        usleep(250000);
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
        $isoValue = $year . '-' . $month . '-01';
        $this->log('Aplicando filtro por DOM: ' . $isoValue);

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
}

function clickElement(element) {
  if (!element) return false;
  ['mouseover', 'mousedown', 'mouseup', 'click'].forEach((eventName) => {
    element.dispatchEvent(new MouseEvent(eventName, { bubbles: true, cancelable: true, view: window }));
  });
  if (typeof element.click === 'function') {
    element.click();
  }
  return true;
}

const dateInput = document.getElementById('ipt_dtInicial');
const searchButton = document.getElementById('btn_pesquisar');
if (!dateInput) {
  return { ok: false, step: 'date_input_not_found' };
}
if (!searchButton) {
  return { ok: false, step: 'search_button_not_found' };
}

setValue(dateInput, arguments[0]);
clickElement(searchButton);
return { ok: true };
JS, [$isoValue]);

        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            $step = is_array($result) ? (string) ($result['step'] ?? 'unknown') : 'invalid';
            throw new RuntimeException('Falha ao aplicar filtro. Etapa: ' . $step);
        }

        usleep(250000);
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
  document.getElementById('btn_pesquisar') ||
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

            if (($state['checked'] ?? false) !== true) {
                throw new RuntimeException('Nao foi possivel marcar o checkbox da primeira NF.');
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
firstCheckbox.click();
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
if (!firstCheckbox.checked && window.jQuery) {
  window.jQuery(firstCheckbox).prop('checked', true).trigger('input').trigger('change').trigger('click');
}
if (!firstCheckbox.checked && rowContainer && window.jQuery) {
  window.jQuery(rowContainer).find('input[type="checkbox"]').prop('checked', true).trigger('input').trigger('change');
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
