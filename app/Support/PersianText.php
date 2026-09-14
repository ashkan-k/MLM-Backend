<?php

namespace App\Support;

class PersianText
{
    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        } else {
            $value = strtr($value, self::presentationForms());
        }

        $value = strtr($value, [
            'ي' => 'ی',
            'ى' => 'ی',
            'ك' => 'ک',
            'ة' => 'ه',
            'ؤ' => 'و',
            'إ' => 'ا',
            'أ' => 'ا',
            'ٱ' => 'ا',
        ]);

        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /** @return array<string, string> */
    private static function presentationForms(): array
    {
        return [
            "\u{FE8D}" => 'ا', "\u{FE8E}" => 'ا',
            "\u{FE8F}" => 'ب', "\u{FE90}" => 'ب', "\u{FE91}" => 'ب', "\u{FE92}" => 'ب',
            "\u{FE93}" => 'ه', "\u{FE94}" => 'ه',
            "\u{FE95}" => 'ت', "\u{FE96}" => 'ت', "\u{FE97}" => 'ت', "\u{FE98}" => 'ت',
            "\u{FE99}" => 'ث', "\u{FE9A}" => 'ث', "\u{FE9B}" => 'ث', "\u{FE9C}" => 'ث',
            "\u{FE9D}" => 'ج', "\u{FE9E}" => 'ج', "\u{FE9F}" => 'ج', "\u{FEA0}" => 'ج',
            "\u{FEA1}" => 'ح', "\u{FEA2}" => 'ح', "\u{FEA3}" => 'ح', "\u{FEA4}" => 'ح',
            "\u{FEA5}" => 'خ', "\u{FEA6}" => 'خ', "\u{FEA7}" => 'خ', "\u{FEA8}" => 'خ',
            "\u{FEA9}" => 'د', "\u{FEAA}" => 'د',
            "\u{FEAB}" => 'ذ', "\u{FEAC}" => 'ذ',
            "\u{FEAD}" => 'ر', "\u{FEAE}" => 'ر',
            "\u{FEAF}" => 'ز', "\u{FEB0}" => 'ز',
            "\u{FEB1}" => 'س', "\u{FEB2}" => 'س', "\u{FEB3}" => 'س', "\u{FEB4}" => 'س',
            "\u{FEB5}" => 'ش', "\u{FEB6}" => 'ش', "\u{FEB7}" => 'ش', "\u{FEB8}" => 'ش',
            "\u{FEB9}" => 'ص', "\u{FEBA}" => 'ص', "\u{FEBB}" => 'ص', "\u{FEBC}" => 'ص',
            "\u{FEBD}" => 'ض', "\u{FEBE}" => 'ض', "\u{FEBF}" => 'ض', "\u{FEC0}" => 'ض',
            "\u{FEC1}" => 'ط', "\u{FEC2}" => 'ط', "\u{FEC3}" => 'ط', "\u{FEC4}" => 'ط',
            "\u{FEC5}" => 'ظ', "\u{FEC6}" => 'ظ', "\u{FEC7}" => 'ظ', "\u{FEC8}" => 'ظ',
            "\u{FEC9}" => 'ع', "\u{FECA}" => 'ع', "\u{FECB}" => 'ع', "\u{FECC}" => 'ع',
            "\u{FECD}" => 'غ', "\u{FECE}" => 'غ', "\u{FECF}" => 'غ', "\u{FED0}" => 'غ',
            "\u{FED1}" => 'ف', "\u{FED2}" => 'ف', "\u{FED3}" => 'ف', "\u{FED4}" => 'ف',
            "\u{FED5}" => 'ق', "\u{FED6}" => 'ق', "\u{FED7}" => 'ق', "\u{FED8}" => 'ق',
            "\u{FED9}" => 'ک', "\u{FEDA}" => 'ک', "\u{FEDB}" => 'ک', "\u{FEDC}" => 'ک',
            "\u{FEDD}" => 'ل', "\u{FEDE}" => 'ل', "\u{FEDF}" => 'ل', "\u{FEE0}" => 'ل',
            "\u{FEE1}" => 'م', "\u{FEE2}" => 'م', "\u{FEE3}" => 'م', "\u{FEE4}" => 'م',
            "\u{FEE5}" => 'ن', "\u{FEE6}" => 'ن', "\u{FEE7}" => 'ن', "\u{FEE8}" => 'ن',
            "\u{FEE9}" => 'ه', "\u{FEEA}" => 'ه', "\u{FEEB}" => 'ه', "\u{FEEC}" => 'ه',
            "\u{FEED}" => 'و', "\u{FEEE}" => 'و',
            "\u{FEEF}" => 'ی', "\u{FEF0}" => 'ی',
            "\u{FEF1}" => 'ی', "\u{FEF2}" => 'ی', "\u{FEF3}" => 'ی', "\u{FEF4}" => 'ی',
        ];
    }
}
