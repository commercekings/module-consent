<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api;

/**
 * Settings every banner shares (admin: Consent). Which banner shows needs no setting: the banner of the
 * chosen source (SourceConfigInterface::getSourceCode()) does; None = no banner.
 */
interface BannerSettingsInterface
{
    /** The privacy / cookie policy page as a full URL, '' = no link in the banner. */
    public function getPrivacyUrl(): string;
}
