<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api;

use CommerceKings\Consent\Api\Data\SourceChoice;

/**
 * A consent source - a cookie banner. Implemented by source modules (e.g. CommerceKings_ConsentVanilla) and
 * registered in di.xml (Model\SourcePool "sources") with its JS adapter and cookie readers. A site module
 * or optional preset defines categories, descriptions and purpose mappings, shown read-only in admin.
 * The shop's decisions - chosen source, cookie name, revision, cookie lifetime - are admin settings
 * (SourceConfigInterface), so a package upgrade never changes them. A source only
 * reports the visitor's choice; recording, validity and withdrawal belong to this module.
 */
interface SourceInterface
{
    public const CODE_LENGTH = 32;

    /**
     * Short code stored with every consent, e.g. "vanilla-cookieconsent": at most CODE_LENGTH (32) characters, the
     * same as the source's key in SourcePool - a longer one is refused when the source is registered.
     */
    public function getCode(): string;

    /** The version of the library behind the source, e.g. "3.1.0" (newest cookie format it reads). */
    public function getVersion(): string;

    /**
     * The source categories in display order - the switches the visitor sees:
     *   key => ['label' => string, 'description' => string, 'required' => bool,
     *           'purposes' => string[] (purposes the category grants)] (+ source-specific keys).
     * Feature modules own tracking, content loading and cookie cleanup.
     * Labels and descriptions are translated (Magento i18n).
     */
    public function getCategories(): array;

    /**
     * The current visitor's latest saved choice, or null when there is none or it can't be trusted
     * (unreadable, made for another revision than SourceConfigInterface::getRevision()).
     */
    public function getChoice(): ?SourceChoice;

    /** Settings for the source's JS adapter (printed in #commercekings-consent-config). */
    public function getClientConfig(): array;
}
