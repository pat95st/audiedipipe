<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\I18n;

use AudiEdiPipe\Infrastructure\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    public function test_ships_with_german_and_english(): void
    {
        $available = $this->translator()->available();
        self::assertArrayHasKey('de', $available);
        self::assertArrayHasKey('en', $available);
    }

    public function test_unknown_locale_falls_back_to_english(): void
    {
        $translator = $this->translator('xx');
        self::assertSame('en', $translator->locale());
    }

    public function test_missing_key_returns_key_itself(): void
    {
        self::assertSame('this.key.does.not.exist', $this->translator()->trans('this.key.does.not.exist'));
    }

    public function test_placeholders_are_replaced(): void
    {
        $dir = sys_get_temp_dir() . '/aep_i18n_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/en.json', json_encode(['greeting' => 'Hello {name}!']));

        try {
            $translator = new Translator($dir, 'en');
            self::assertSame('Hello Patrick!', $translator->trans('greeting', ['name' => 'Patrick']));
        } finally {
            @unlink($dir . '/en.json');
            @rmdir($dir);
        }
    }

    public function test_accept_language_negotiation(): void
    {
        $translator = $this->translator();
        self::assertSame('de', $translator->negotiate('de-DE,de;q=0.9,en;q=0.8', 'en'));
        self::assertSame('en', $translator->negotiate('fr-FR,fr;q=0.9', 'en'));
    }

    public function test_translation_keys_match_between_locales(): void
    {
        $en = $this->translator('en')->catalogue('en');
        $de = $this->translator('de')->catalogue('de');
        self::assertSame([], array_keys(array_diff_key($en, $de)), 'German is missing keys present in English');
        self::assertSame([], array_keys(array_diff_key($de, $en)), 'English is missing keys present in German');
    }

    private function translator(string $locale = 'en'): Translator
    {
        return new Translator(APP_ROOT . '/lang', $locale);
    }
}
