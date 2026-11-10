<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Process;

/**
 * Runs external programs without a shell (proc_open with argument arrays).
 * Falls back to exec() on hosts where proc_open is disabled.
 */
class ProcessRunner
{
    /**
     * @param list<string>               $command
     * @param array<string, string>|null $env
     */
    public function run(array $command, int $timeoutSeconds = 3600, ?string $stdin = null, ?array $env = null): ProcessResult
    {
        if (!function_exists('proc_open')) {
            return $this->runWithExec($command);
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // PHP >= 8.3 emits a warning when the binary cannot be spawned (missing,
        // not executable). Report that as a failed result instead, so it never
        // leaks into HTML/JSON output on hosts with display_errors enabled.
        error_clear_last();
        $process = @proc_open($command, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            $reason = error_get_last()['message'] ?? '';

            return new ProcessResult(127, '', trim('Unable to start process: ' . ($command[0] ?? '') . ' ' . $reason));
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = time();
        $timedOut = false;
        $exitCode = -1;
        for (;;) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 1);
            if ($changed !== false) {
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 65536);
                    if ($chunk === false) {
                        continue;
                    }
                    if ($pipe === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                        // keep memory bounded for very chatty processes
                        if (strlen($stderr) > 2_000_000) {
                            $stderr = substr($stderr, -1_000_000);
                        }
                    }
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                $exitCode = (int) $status['exitcode'];

                break;
            }
            if (time() - $start > $timeoutSeconds) {
                proc_terminate($process, 9);
                $timedOut = true;
                $exitCode = 124;

                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        if ($exitCode === -1) {
            $exitCode = $closeCode;
        }

        return new ProcessResult($exitCode, $stdout, $stderr, $timedOut);
    }

    public function commandExists(string $binary): bool
    {
        if (str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }
        $result = $this->run(['sh', '-c', 'command -v ' . escapeshellarg($binary)], 10);

        return $result->successful() && trim($result->stdout) !== '';
    }

    /**
     * @param list<string> $command
     */
    private function runWithExec(array $command): ProcessResult
    {
        if (!function_exists('exec')) {
            return new ProcessResult(127, '', 'Neither proc_open nor exec are available on this host.');
        }
        // Keep stdout and stderr apart (callers parse stdout, e.g. ffprobe JSON).
        $errorFile = tempnam(sys_get_temp_dir(), 'aep_err_');
        $line = implode(' ', array_map('escapeshellarg', $command));
        $line .= $errorFile !== false ? ' 2>' . escapeshellarg($errorFile) : ' 2>/dev/null';
        $output = [];
        $code = 0;
        exec($line, $output, $code);
        $stderr = '';
        if ($errorFile !== false) {
            $stderr = (string) @file_get_contents($errorFile);
            @unlink($errorFile);
        }

        return new ProcessResult($code, implode("\n", $output), $stderr);
    }
}
