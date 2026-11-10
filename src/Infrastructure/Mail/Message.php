<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Mail;

final class Message
{
    /**
     * @param list<string> $to
     */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
    ) {
    }

    /**
     * Build the RFC 5322 message (headers + body) for the given sender.
     */
    public function toMime(string $fromEmail, string $fromName, string $hostname): string
    {
        $boundary = 'aep_' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>',
            'To: ' . implode(', ', $this->to),
            'Subject: ' . self::encodeHeader($this->subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $hostname . '>',
            'MIME-Version: 1.0',
            'X-Mailer: audiEdiPipe',
        ];
        if ($this->html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: quoted-printable';
            $body = quoted_printable_encode($this->text);
        } else {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                . quoted_printable_encode($this->text) . "\r\n"
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                . quoted_printable_encode($this->html) . "\r\n"
                . '--' . $boundary . "--\r\n";
        }
        $body = (string) preg_replace("/(?<!\r)\n/", "\r\n", $body);

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    public static function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }
}
