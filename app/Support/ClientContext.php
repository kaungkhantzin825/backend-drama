<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What the mobile app says about itself on every request.
 *
 * The Flutter client sends these headers on every call (see ApiClient):
 *   X-App-Platform  android | ios | web
 *   X-App-Version   1.4.2
 *   X-Device-Id     the stable install id
 *   X-AB-Group      the ad-experiment variant it was assigned
 *
 * They are client-supplied, so they are trimmed and length-capped here and only
 * ever used for analytics, version gating and blocking — never for money.
 */
final class ClientContext
{
    public function __construct(
        public readonly string $platform,
        public readonly string $appVersion,
        public readonly ?string $deviceId,
        public readonly string $abGroup,
        public readonly ?string $ip,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $platform = strtolower(self::header($request, 'X-App-Platform', 16));

        return new self(
            platform: in_array($platform, ['android', 'ios', 'web'], true) ? $platform : '',
            appVersion: preg_match('/^\d+(\.\d+){0,3}$/', $v = self::header($request, 'X-App-Version', 24)) ? $v : '',
            deviceId: self::header($request, 'X-Device-Id', 128) ?: null,
            abGroup: self::header($request, 'X-AB-Group', 32),
            ip: $request->ip(),
        );
    }

    private static function header(Request $request, string $name, int $max): string
    {
        return mb_substr(trim((string) $request->header($name, '')), 0, $max);
    }
}
