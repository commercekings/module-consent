<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

/** Configuration evidence only: no visitor identifiers, cookies or customer records. */
class Evidence
{
    public function __construct(
        private readonly ConfiguredSource $configured,
        private readonly SourcePool $sources,
        private readonly PurposePool $purposes,
        private readonly Config $config
    ) {
    }

    public function snapshot(?string $policyContent = null): array
    {
        $source = $this->configured->get();
        $banner = $source ? $this->sources->getBanner($source->getCode()) : null;
        $purposes = [];
        foreach ($this->purposes->getCodes() as $code) {
            $purposes[$code] = [
                'label' => (string)__($this->purposes->getLabel($code)),
                'terms_revision' => $this->purposes->getTermsRevision($code),
                'cookie_patterns' => $this->purposes->getCookiePatterns($code),
            ];
        }
        return [
            'format' => 1,
            'exported_at' => gmdate('c'),
            'source' => $source?->getCode(),
            'source_version' => $source?->getVersion(),
            'banner_revision' => $this->config->getRevision(),
            'categories' => $source?->getCategories() ?? [],
            'purposes' => $purposes,
            'client_config' => $this->configured->getClientConfig(),
            'banner_config' => $banner ? json_decode($banner->getConfigJson(), true, 512, JSON_THROW_ON_ERROR) : null,
            'privacy_url' => $this->config->getPrivacyUrl(),
            'policy_content' => $policyContent,
            'policy_sha256' => $policyContent === null ? null : hash('sha256', $policyContent),
            'retention' => [
                'cookie_lifetime_days' => $this->config->getCookieLifetimeDays(),
                'keep_after_expiry_or_withdrawal_days' => $this->config->getKeepDays(),
            ],
        ];
    }
}
