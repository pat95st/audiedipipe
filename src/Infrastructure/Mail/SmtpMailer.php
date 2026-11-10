<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Mail;

/**
 * Dependency free SMTP client with STARTTLS/SMTPS and AUTH PLAIN/LOGIN.
 */
final class SmtpMailer implements Mailer
{
    /** @var resource|null */
    private $socket;

    /** @var list<string> */
    private array $transcript = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly int $timeout = 20,
    ) {
    }

    public function send(Message $message): void
    {
        if ($this->host === '' || $this->fromEmail === '') {
            throw new MailException('SMTP is not configured.');
        }
        if ($message->to === []) {
            return;
        }
        $this->transcript = [];

        try {
            $this->connect();
            $this->expect(220);
            $hostname = gethostname() ?: 'localhost';
            $capabilities = $this->ehlo($hostname);
            if ($this->encryption === 'tls') {
                if (!str_contains($capabilities, 'STARTTLS')) {
                    throw new MailException('Server does not support STARTTLS.');
                }
                $this->command('STARTTLS', 220);
                if ($this->socket === null || stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                    throw new MailException('TLS negotiation failed.');
                }
                $capabilities = $this->ehlo($hostname);
            }
            if ($this->username !== '') {
                $this->authenticate($capabilities);
            }
            $this->command('MAIL FROM:<' . $this->fromEmail . '>', 250);
            foreach ($message->to as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            $this->command('DATA', 354);
            $mime = $message->toMime($this->fromEmail, $this->fromName, $hostname);
            // dot stuffing
            $mime = (string) preg_replace('/^\./m', '..', $mime);
            $this->write($mime . "\r\n.");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            if ($this->socket !== null) {
                fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    /**
     * @return list<string> protocol log without credentials (for the settings test page)
     */
    public function transcript(): array
    {
        return $this->transcript;
    }

    private function connect(): void
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MailException(sprintf('Cannot connect to %s: %s (%d)', $remote, $errstr, $errno));
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    private function ehlo(string $hostname): string
    {
        return $this->command('EHLO ' . $hostname, 250);
    }

    private function authenticate(string $capabilities): void
    {
        if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $capabilities) === 1) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), 235, true);

            return;
        }
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($this->username), 334, true);
        $this->command(base64_encode($this->password), 235, true);
    }

    /**
     * @param int|list<int> $expected
     */
    private function command(string $line, array|int $expected, bool $secret = false): string
    {
        $this->write($line, $secret);

        return $this->expect($expected);
    }

    private function write(string $data, bool $secret = false): void
    {
        if ($this->socket === null) {
            throw new MailException('Not connected.');
        }
        $this->transcript[] = 'C: ' . ($secret ? '***' : (strlen($data) > 200 ? substr($data, 0, 200) . '...' : $data));
        if (fwrite($this->socket, $data . "\r\n") === false) {
            throw new MailException('Write to SMTP server failed.');
        }
    }

    /**
     * @param int|list<int> $expected
     */
    private function expect(array|int $expected): string
    {
        if ($this->socket === null) {
            throw new MailException('Not connected.');
        }
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $this->transcript[] = 'S: ' . trim($response);
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $expected, true)) {
            throw new MailException('Unexpected SMTP response: ' . trim($response));
        }

        return $response;
    }
}
