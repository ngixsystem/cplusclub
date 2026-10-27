<?php

namespace App\Domain\Monitoring;

/** Texts the club staff see in Telegram when a PC's network link drops and comes back. */
final class LinkMessages
{
    public static function speed(int $mbps): string
    {
        return $mbps >= 1000 ? rtrim(rtrim(number_format($mbps / 1000, 1, ',', ''), '0'), ',').' Гбит/с' : $mbps.' Мбит/с';
    }

    public static function down(string $place, int $link, int $expected): string
    {
        return "⚠️ {$place}\n"
            ."Сетевой кабель работает на ".self::speed($link).' вместо '.self::speed($expected).".\n\n"
            ."Пожалуйста, проверьте кабель у этого ПК:\n"
            ."1. Отключите сетевой кабель от компьютера и подключите заново до щелчка.\n"
            ."2. Если рядом есть розетка или свитч, переподключите кабель и с той стороны.\n"
            ."3. Осмотрите кабель: нет ли переломов и пережатий.\n\n"
            .'После проверки нажмите «✅ Переподключил». Если не помогло — «❌ Не помогло», и мы сразу создадим заявку. Когда скорость восстановится, сюда придёт подтверждение.';
    }

    public static function restored(string $place, int $link): string
    {
        return "✅ {$place}\nСеть восстановлена: ".self::speed($link).'. Спасибо!';
    }
}
