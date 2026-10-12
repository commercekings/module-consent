<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api;

/** Optional source registration: the same translated configuration rendered by its banner. */
interface BannerConfigInterface
{
    public function getConfigJson(): string;
}
