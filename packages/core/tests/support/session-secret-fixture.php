<?php
final class FD_Test_Session_Secret {

    public const SECRET = 'test-session-secret';

    public static function hash(): string {
        return hash( 'sha256', self::SECRET );
    }
}
